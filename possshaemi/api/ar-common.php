<?php
/*
 * ar-common.php
 * Helpers shared by the AR Manager APIs (ar-payments.php, ar-reports.php, ar-accounts.php).
 * Money is compared in integer cents to avoid floating point drift.
 */

class ArException extends Exception {}

require_once __DIR__ . "/audit-logger.php";

/* Records a failed AR approver verification (attempted username only). */
function arLogApproverFailure($attemptedUsername, $category) {
    auditAttempt([
        "module" => "AR",
        "action" => "ApproverDenied",
        "outcome" => "Denied",
        "record_label" => $attemptedUsername !== "" ? $attemptedUsername : null,
        "details" => "AR approver verification failed.",
        "reason" => $category
    ]);
}

function arCents($value) {
    return (int)round(((float)$value) * 100);
}

function arMoney($cents) {
    return round($cents / 100, 2);
}

/*
 * Every credit invoice of a Completed sale with its outstanding amount.
 * outstanding = invoice amount
 *             + APPROVED debit memos (additional charges)
 *             - APPROVED credit memos (reductions)
 *             - net allocations (payments less reversals).
 * Only memos with status 'Approved' count; Pending/Rejected/Reversed memos
 * have no financial effect. Memos are never payments, so they never appear
 * in collection totals (those sum tblpayment_allocation only).
 * Voided sales are excluded, so they never appear in aging or overdue figures.
 */
function arOpenInvoicesSql() {
    return "SELECT inv.invoice_id, inv.ar_account_id, inv.sale_id, inv.amount, inv.due_date, inv.transaction_date,
                   s.receipt_number, s.sale_date,
                   COALESCE((SELECT SUM(cm.amount) FROM tblcredit_memo cm WHERE cm.invoice_id = inv.invoice_id AND cm.status = 'Approved'), 0) AS approved_credit_memos,
                   COALESCE((SELECT SUM(dm.amount) FROM tbldebit_memo dm WHERE dm.invoice_id = inv.invoice_id AND dm.status = 'Approved'), 0) AS approved_debit_memos,
                   (inv.amount
                    + COALESCE((SELECT SUM(dm2.amount) FROM tbldebit_memo dm2 WHERE dm2.invoice_id = inv.invoice_id AND dm2.status = 'Approved'), 0)
                    - COALESCE((SELECT SUM(cm2.amount) FROM tblcredit_memo cm2 WHERE cm2.invoice_id = inv.invoice_id AND cm2.status = 'Approved'), 0)
                    - COALESCE((SELECT SUM(a.amount) FROM tblpayment_allocation a WHERE a.invoice_id = inv.invoice_id), 0)) AS outstanding,
                   DATEDIFF(CURDATE(), COALESCE(inv.due_date, DATE(inv.transaction_date))) AS days_overdue
            FROM tblinvoice inv
            INNER JOIN tblsale s ON s.sale_id = inv.sale_id
            WHERE inv.transaction_type = 'Credit' AND inv.ar_account_id IS NOT NULL AND s.sale_status = 'Completed'";
}

function arValidDate($value) {
    $d = DateTime::createFromFormat("Y-m-d", (string)$value);
    return $d && $d->format("Y-m-d") === $value;
}

/* Validates a date range; maximum 366 days so trend queries stay bounded. */
function arDateRange($from, $to) {
    $from = trim((string)$from);
    $to = trim((string)$to);
    if ($from === "") $from = date("Y-m-d");
    if ($to === "") $to = $from;
    if (!arValidDate($from) || !arValidDate($to) || $from > $to) {
        throw new ArException("Choose a valid date or date range.");
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
        throw new ArException("Date range cannot exceed 366 days.");
    }
    return [$from, $to];
}

/*
 * Resolves who approves a sensitive action.
 *   - An Administrator already signed in approves their own action.
 *   - Anyone else must supply the username/password of an active employee whose role is in $roles.
 * Returns ["employee_id" => int, "name" => string]; throws ArException otherwise.
 */
function arResolveApprover($conn, array $data, array $roles) {
    $roles = array_map("strtolower", $roles);

    if (($_SESSION["role"] ?? "") === "Administrator" && in_array("administrator", $roles, true)) {
        return ["employee_id" => (int)$_SESSION["employee_id"], "name" => $_SESSION["display_name"] ?? "Administrator"];
    }

    $username = strtolower(trim((string)($data["approver_username"] ?? "")));
    $password = (string)($data["approver_password"] ?? "");
    if ($username === "" || $password === "") {
        arLogApproverFailure($username, "Approver credentials not supplied");
        throw new ArException("Approval required: enter the " . implode(" or ", array_map("ucfirst", $roles)) . " username and password.");
    }

    $stmt = $conn->prepare("SELECT e.employee_id, e.first_name, e.last_name, e.password, e.is_active, r.role_name, r.is_active AS role_active
                            FROM tblemployee e INNER JOIN tblrole r ON e.role_id = r.role_id
                            WHERE LOWER(TRIM(e.username)) = :username LIMIT 1");
    $stmt->bindValue(":username", $username, PDO::PARAM_STR);
    $stmt->execute();
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    $generic = "Approval failed. Check the approver's username, password and role.";
    if (!$emp || (int)$emp["is_active"] !== 1 || (int)$emp["role_active"] !== 1) {
        arLogApproverFailure($username, "Approver account not found or inactive");
        throw new ArException($generic);
    }
    if (!in_array(strtolower(trim($emp["role_name"])), $roles, true)) {
        arLogApproverFailure($username, "Approver role not allowed");
        throw new ArException($generic);
    }
    if (password_get_info($emp["password"])["algo"] === null || !password_verify($password, $emp["password"])) {
        arLogApproverFailure($username, "Wrong approver password");
        throw new ArException($generic);
    }

    return ["employee_id" => (int)$emp["employee_id"], "name" => $emp["first_name"] . " " . $emp["last_name"]];
}

/* Writes one audit row. Call inside the caller's open transaction. */
function arAudit($conn, $entityType, $entityId, $action, $arAccountId, $before, $after, $reason, $employeeId, $authorizedBy = null) {
    $stmt = $conn->prepare("INSERT INTO tblar_audit (entity_type, entity_id, action, ar_account_id, before_json, after_json, reason, employee_id, authorized_by)
                            VALUES (:t, :id, :a, :ar, :b, :af, :r, :e, :au)");
    $stmt->bindValue(":t", $entityType, PDO::PARAM_STR);
    $stmt->bindValue(":id", (int)$entityId, PDO::PARAM_INT);
    $stmt->bindValue(":a", $action, PDO::PARAM_STR);
    $stmt->bindValue(":ar", $arAccountId, $arAccountId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(":b", $before === null ? null : json_encode($before), $before === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(":af", $after === null ? null : json_encode($after), $after === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(":r", $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(":e", (int)$employeeId, PDO::PARAM_INT);
    $stmt->bindValue(":au", $authorizedBy, $authorizedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();

    // Central Audit Log row (Audit Logs build): written here, inside
    // arAudit() itself, on the SAME connection and transaction, so one
    // arAudit() call always produces exactly one tblar_audit row + one
    // central row, and both roll back together if either write fails.
    $arAuditId = (int)$conn->lastInsertId();
    $moduleMap = [
        "payment" => "AR Payments", "reversal" => "AR Payments", "adjustment" => "AR Payments",
        "credit_memo" => "AR Memos", "debit_memo" => "AR Memos",
        "ar_account" => "AR Accounts", "credit_settings" => "AR Accounts"
    ];
    $actorLookup = function ($id) use ($conn) {
        if (!$id) return null;
        $q = $conn->prepare("SELECT CONCAT(e.first_name, ' ', e.last_name) AS name, r.role_name AS role
                             FROM tblemployee e INNER JOIN tblrole r ON e.role_id = r.role_id
                             WHERE e.employee_id = :id");
        $q->bindValue(":id", (int)$id, PDO::PARAM_INT);
        $q->execute();
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $actorInfo = $actorLookup((int)$employeeId);
    $authInfo = $authorizedBy !== null ? $actorLookup((int)$authorizedBy) : null;
    auditEvent($conn, [
        "module" => $moduleMap[$entityType] ?? "AR",
        "action" => $action,
        "employee_id" => $actorInfo ? (int)$employeeId : null,
        "actor_name" => $actorInfo ? $actorInfo["name"] : "System",
        "actor_role" => $actorInfo ? $actorInfo["role"] : "System",
        "record_id" => (int)$entityId,
        "record_label" => $entityType . " #" . (int)$entityId,
        "details" => $action . " " . $entityType . " #" . (int)$entityId . ($arAccountId !== null ? " (AR account #" . (int)$arAccountId . ")" : ""),
        "before" => $before,
        "after" => $after,
        "reason" => $reason,
        "authorized_by" => $authInfo ? (int)$authorizedBy : null,
        "authorized_role" => $authInfo ? $authInfo["role"] : null,
        "reference_label" => "AR Audit #" . $arAuditId
    ]);
}

function arReason($data) {
    $reason = trim((string)($data["reason"] ?? ""));
    $len = function_exists("mb_strlen") ? mb_strlen($reason) : strlen($reason);
    if ($len < 5) throw new ArException("A reason of at least 5 characters is required.");
    if ($len > 255) throw new ArException("Reason cannot exceed 255 characters.");
    return $reason;
}

/* =====================================================================
 * Late Fee & Automatic Credit Hold policy (approved by brix 2026-10-22).
 *
 *   - Late fee: 2% per FULL chargeable month on the unpaid invoice
 *     balance, excluding previous late fees. Grace: 3 calendar days
 *     after the due date ("chargeable" time starts at due_date + 3).
 *     No compounding. Fees are real debit memos (category 'Late fee',
 *     status Approved at birth, created_by/approved_by NULL = system),
 *     so every existing AR figure includes them with no formula changes.
 *   - Duplicate prevention: automatic AND manual late fees share ONE
 *     per-invoice period ledger. Every late fee carries the reference
 *     LF-<invoice_id>-P<period>; reference_no is UNIQUE in the database.
 *     A period counts as covered when a memo with that reference exists
 *     in status Approved, Pending or Reversed (a reversed fee was a
 *     deliberate cancellation — it is never silently re-charged).
 *   - Automatic hold: any open invoice at 15+ days overdue places the
 *     account on Hold (credit_hold_source = 'AutoOverdue', migration
 *     20261022_ar_hold_provenance). The policy NEVER releases a hold;
 *     only the AR Manager releases it (releaseCreditHold in
 *     ar-accounts.php, reason required). After a release, the auto rule
 *     re-engages only when a NEW invoice reaches 15 days overdue —
 *     the invoices already overdue at release time are remembered in
 *     the HoldReleased audit row (D-E).
 *   - Fees apply only to invoices ISSUED on/after the activation date
 *     (D-F); the hold also covers invoices issued before it (D-G).
 *   - Evaluation is lazy (no cron in the store stack): call sites run
 *     arSyncAccountPolicy() whenever an account is touched. Every run
 *     is idempotent, so missed days simply catch up period by period.
 * ===================================================================== */
define("AR_POLICY_START_DATE", "");           // "" = policy OFF. Set to "YYYY-MM-DD" to activate (after customers sign the updated credit terms).
define("AR_LATE_FEE_RATE_PERCENT", 2);        // per full chargeable month
define("AR_LATE_FEE_GRACE_DAYS", 3);          // calendar days after the due date
define("AR_AUTO_HOLD_DAYS", 15);              // days overdue that trip the automatic hold

function arPolicyActive($today) {
    $start = trim((string)AR_POLICY_START_DATE);
    if ($start === "" || !arValidDate($start)) return false;
    return $today >= $start;
}

/* True when the S1 hold-provenance columns exist (cached per request). */
function arHoldColumnsExist($conn) {
    static $exists = null;
    if ($exists === null) {
        $n = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblar_account' AND COLUMN_NAME = 'credit_hold_source'")->fetchColumn();
        $exists = ((int)$n) > 0;
    }
    return $exists;
}

/* Adds $months calendar months to a Y-m-d date, clamping the day to the
 * target month's length (Jan 31 + 1 month = Feb 28/29, never Mar 3). */
function arAddMonthsClamped($ymd, $months) {
    $d = DateTime::createFromFormat("Y-m-d", $ymd);
    $y = (int)$d->format("Y"); $m = (int)$d->format("n"); $day = (int)$d->format("j");
    $total = ($y * 12 + ($m - 1)) + (int)$months;
    $ty = intdiv($total, 12); $tm = ($total % 12) + 1;
    $dim = (int)date("t", mktime(0, 0, 0, $tm, 1, $ty));
    return sprintf("%04d-%02d-%02d", $ty, $tm, min($day, $dim));
}

/* Whole calendar months completed from $startYmd to $todayYmd (anniversary method). */
function arFullMonthsBetween($startYmd, $todayYmd) {
    if ($todayYmd <= $startYmd) return 0;
    $a = explode("-", $startYmd); $b = explode("-", $todayYmd);
    $months = ((int)$b[0] - (int)$a[0]) * 12 + ((int)$b[1] - (int)$a[1]);
    if ($months > 0 && arAddMonthsClamped($startYmd, $months) > $todayYmd) $months--;
    return max(0, $months);
}

/* Highest late-fee period already covered for an invoice (0 = none). */
function arCoveredLateFeePeriod($conn, $invoiceId) {
    $stmt = $conn->prepare("SELECT reference_no FROM tbldebit_memo
                            WHERE invoice_id = :id AND status IN ('Approved', 'Pending', 'Reversed')
                              AND reference_no LIKE :pat");
    $stmt->bindValue(":id", (int)$invoiceId, PDO::PARAM_INT);
    $stmt->bindValue(":pat", "LF-" . (int)$invoiceId . "-P%", PDO::PARAM_STR);
    $stmt->execute();
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        if (preg_match("/-P(\d+)$/", (string)$ref, $m)) $max = max($max, (int)$m[1]);
    }
    return $max;
}

/*
 * Applies the policy to ONE account. ASSUMES the caller manages the
 * transaction (the account row is locked here with FOR UPDATE).
 * Returns a summary array. Never releases a hold.
 */
function arApplyPolicyInTxn($conn, $arAccountId) {
    $summary = ["fees_created" => 0, "fees_total" => 0.0, "hold_placed" => false];
    $today = $conn->query("SELECT CURDATE()")->fetchColumn();
    if (!arPolicyActive($today)) return $summary;

    $hasHoldCols = arHoldColumnsExist($conn);
    $cols = "ar_account_id, account_status, credit_status, current_balance" .
            ($hasHoldCols ? ", credit_hold_source, credit_hold_released_at" : "");
    $lock = $conn->prepare("SELECT $cols FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
    $lock->bindValue(":id", (int)$arAccountId, PDO::PARAM_INT);
    $lock->execute();
    $acct = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$acct || $acct["account_status"] !== "Active") return $summary;

    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $balanceCents = arCents($acct["current_balance"]);

    $invStmt = $conn->prepare("SELECT oi.* FROM (" . arOpenInvoicesSql() . ") oi
                               WHERE oi.ar_account_id = :ar AND oi.outstanding > 0
                               ORDER BY oi.invoice_id ASC");
    $invStmt->bindValue(":ar", (int)$arAccountId, PDO::PARAM_INT);
    $invStmt->execute();
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---- Late fees ---- */
    if ($invoices) {
        $ids = array_map(function ($r) { return (int)$r["invoice_id"]; }, $invoices);
        $lfStmt = $conn->query("SELECT invoice_id, COALESCE(SUM(amount), 0) AS lf FROM tbldebit_memo
                                WHERE status = 'Approved' AND category = 'Late fee'
                                  AND invoice_id IN (" . implode(",", $ids) . ") GROUP BY invoice_id");
        $lfByInvoice = [];
        foreach ($lfStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $lfByInvoice[(int)$r["invoice_id"]] = arCents($r["lf"]);

        $insMemo = $conn->prepare("INSERT INTO tbldebit_memo (reference_no, invoice_id, category, memo_date, amount, reason, status, created_by, approved_by, approved_at)
                                   VALUES (:ref, :inv, 'Late fee', NOW(), :amt, :reason, 'Approved', NULL, NULL, NOW())");
        $start = trim((string)AR_POLICY_START_DATE);
        foreach ($invoices as $inv) {
            $invoiceId = (int)$inv["invoice_id"];
            // D-F: fees only on invoices issued on/after activation.
            if (substr((string)$inv["sale_date"], 0, 10) < $start) continue;
            $due = !empty($inv["due_date"]) ? $inv["due_date"] : substr((string)$inv["transaction_date"], 0, 10);
            $graceEnd = date("Y-m-d", strtotime($due . " +" . AR_LATE_FEE_GRACE_DAYS . " days"));
            $periodsDue = arFullMonthsBetween($graceEnd, $today);
            if ($periodsDue <= 0) continue;
            // Fee base: outstanding minus approved late fees (never compounded).
            $baseCents = arCents($inv["outstanding"]) - (isset($lfByInvoice[$invoiceId]) ? $lfByInvoice[$invoiceId] : 0);
            if ($baseCents <= 0) continue;
            $feeCents = (int)round($baseCents * ((float)AR_LATE_FEE_RATE_PERCENT) / 100);
            if ($feeCents <= 0) continue;
            $covered = arCoveredLateFeePeriod($conn, $invoiceId);
            for ($p = $covered + 1; $p <= $periodsDue; $p++) {
                $reference = "LF-" . $invoiceId . "-P" . $p;
                $reason = "Auto late fee P" . $p . " — " . AR_LATE_FEE_RATE_PERCENT . "% of ₱" . number_format($baseCents / 100, 2) .
                          " unpaid on " . $inv["receipt_number"] . " (due " . $due . " + " . AR_LATE_FEE_GRACE_DAYS . "d grace).";
                try {
                    $insMemo->bindValue(":ref", $reference, PDO::PARAM_STR);
                    $insMemo->bindValue(":inv", $invoiceId, PDO::PARAM_INT);
                    $insMemo->bindValue(":amt", arMoney($feeCents));
                    $insMemo->bindValue(":reason", $reason, PDO::PARAM_STR);
                    $insMemo->execute();
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) === 1062) continue; // already charged (unique reference)
                    throw $e;
                }
                $memoId = (int)$conn->lastInsertId();
                $beforeBal = $balanceCents;
                $balanceCents += $feeCents;
                arAudit($conn, "debit_memo", $memoId, "AutoLateFee", (int)$arAccountId,
                    ["current_balance" => arMoney($beforeBal)],
                    ["current_balance" => arMoney($balanceCents), "reference" => $reference, "period" => $p, "invoice_id" => $invoiceId, "fee" => arMoney($feeCents)],
                    $reason, $employeeId);
                $summary["fees_created"]++;
                $summary["fees_total"] += arMoney($feeCents);
            }
        }
        if ($summary["fees_created"] > 0) {
            $upd = $conn->prepare("UPDATE tblar_account SET current_balance = :b WHERE ar_account_id = :id");
            $upd->bindValue(":b", arMoney($balanceCents));
            $upd->bindValue(":id", (int)$arAccountId, PDO::PARAM_INT);
            $upd->execute();
        }
    }

    /* ---- Automatic credit hold ---- */
    if ($hasHoldCols && $acct["credit_status"] !== "Hold") {
        $trip = null;
        foreach ($invoices as $inv) {
            if ((int)$inv["days_overdue"] >= AR_AUTO_HOLD_DAYS) {
                if ($trip === null || (int)$inv["days_overdue"] > (int)$trip["days_overdue"]) $trip = $inv;
            }
        }
        if ($trip !== null) {
            $place = true;
            if (!empty($acct["credit_hold_released_at"])) {
                // D-E suppression: skip invoices already overdue at release time.
                $rel = $conn->prepare("SELECT after_json FROM tblar_audit
                                       WHERE entity_type = 'ar_account' AND action = 'HoldReleased' AND ar_account_id = :ar
                                       ORDER BY audit_id DESC LIMIT 1");
                $rel->bindValue(":ar", (int)$arAccountId, PDO::PARAM_INT);
                $rel->execute();
                $after = json_decode((string)($rel->fetchColumn() ?: ""), true);
                $releasedIds = isset($after["overdue_invoice_ids"]) && is_array($after["overdue_invoice_ids"])
                    ? array_map("intval", $after["overdue_invoice_ids"]) : [];
                $place = false; $trip = null;
                foreach ($invoices as $inv) {
                    if ((int)$inv["days_overdue"] >= AR_AUTO_HOLD_DAYS && !in_array((int)$inv["invoice_id"], $releasedIds, true)) {
                        if ($trip === null || (int)$inv["days_overdue"] > (int)$trip["days_overdue"]) $trip = $inv;
                        $place = true;
                    }
                }
            }
            if ($place) {
                $upd = $conn->prepare("UPDATE tblar_account
                                       SET credit_status = 'Hold', credit_hold_source = 'AutoOverdue',
                                           credit_hold_at = NOW(), credit_hold_invoice_id = :inv
                                       WHERE ar_account_id = :id");
                $upd->bindValue(":inv", (int)$trip["invoice_id"], PDO::PARAM_INT);
                $upd->bindValue(":id", (int)$arAccountId, PDO::PARAM_INT);
                $upd->execute();
                arAudit($conn, "ar_account", (int)$arAccountId, "AutoHold", (int)$arAccountId,
                    ["credit_status" => $acct["credit_status"]],
                    ["credit_status" => "Hold", "invoice_id" => (int)$trip["invoice_id"], "receipt_number" => $trip["receipt_number"], "days_overdue" => (int)$trip["days_overdue"]],
                    "Invoice " . $trip["receipt_number"] . " reached " . (int)$trip["days_overdue"] . " days overdue (automatic hold at " . AR_AUTO_HOLD_DAYS . ").",
                    $employeeId);
                $summary["hold_placed"] = true;
            }
        }
    }

    return $summary;
}

/* Standalone sync (own transaction). Returns the summary, or null when the
 * account could not be synced — callers on read paths must never break. */
function arSyncAccountPolicy($conn, $arAccountId) {
    if ((int)$arAccountId <= 0) return null;
    $own = !$conn->inTransaction();
    try {
        if ($own) $conn->beginTransaction();
        $summary = arApplyPolicyInTxn($conn, (int)$arAccountId);
        if ($own) $conn->commit();
        return $summary;
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) $conn->rollBack();
        return null;
    }
}

/* Syncs every Active account that currently has an open credit invoice.
 * Best-effort per account; used by AR dashboard / monitor / aging loads. */
function arSyncAllOpenAccounts($conn) {
    try {
        $ids = $conn->query("SELECT DISTINCT ar_account_id FROM (" . arOpenInvoicesSql() . ") oi WHERE oi.outstanding > 0")
                    ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) arSyncAccountPolicy($conn, (int)$id);
    } catch (Throwable $e) {
        // Read paths stay available even if the policy cannot run.
    }
}
