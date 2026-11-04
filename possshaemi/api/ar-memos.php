<?php
require_once "session-guard.php";
requireRole(["Administrator", "Manager"]);
require_once "ar-common.php";

header("Content-Type: application/json");

/*
 * Credit / Debit Memos for AR.
 *
 * A memo adjusts ONE credit invoice. It has NO financial effect while Pending.
 * Approval (Administrator/Supervisor credentials, resolved exactly like payment
 * corrections) applies it in a single transaction:
 *   - the memo flips Pending -> Approved (that flip is the double-apply guard),
 *   - tblar_account.current_balance moves by the memo amount
 *     (credit memo: down, debit memo: up) so POS credit checks see it at once,
 *   - the shared invoice calculation (arOpenInvoicesSql) counts Approved memos,
 *     so FIFO, aging, dashboard and reconciliation all agree.
 * Memos never touch tblpayment / tblpayment_allocation: they are not payments
 * and never count as collections.
 * Posted memos are never edited or deleted; an Approved memo can only be
 * Reversed (approver + reason), which undoes its balance effect. Every step
 * writes a tblar_audit row.
 */
class ArMemo {

    private $types = [
        "credit" => [
            "table" => "tblcredit_memo", "id_col" => "credit_memo_id", "prefix" => "CM",
            "entity" => "credit_memo", "sign" => -1,
            "categories" => ["Returned goods", "Damaged goods", "Price correction", "Overcharge", "Other"],
        ],
        "debit" => [
            "table" => "tbldebit_memo", "id_col" => "debit_memo_id", "prefix" => "DM",
            "entity" => "debit_memo", "sign" => 1,
            "categories" => ["Omitted charge", "Late fee", "Other"],
        ],
    ];

    private function cfg($type) {
        if (!isset($this->types[$type])) throw new ArException("Invalid memo type.");
        return $this->types[$type];
    }

    /* Customers with an Active AR account (for the create-memo picker). */
    public function getMemoCustomers() {
        include "connection-pdo.php";
        $sql = "SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, ar.current_balance
                FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                WHERE ar.account_status = 'Active' ORDER BY customer_name ASC";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* All credit invoices of one account (paid ones included: a debit memo can reopen them). */
    public function getMemoInvoices($arAccountId) {
        include "connection-pdo.php";
        $stmt = $conn->prepare("SELECT oi.invoice_id, oi.receipt_number, oi.sale_date, oi.due_date, oi.amount,
                                       oi.outstanding, oi.approved_credit_memos, oi.approved_debit_memos
                                FROM (" . arOpenInvoicesSql() . ") oi
                                WHERE oi.ar_account_id = :ar
                                ORDER BY oi.due_date ASC, oi.invoice_id ASC");
        $stmt->bindValue(":ar", (int)$arAccountId, PDO::PARAM_INT);
        $stmt->execute();
        return ["success" => true, "invoices" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function getMemos($type) {
        include "connection-pdo.php";
        $cfg = $this->cfg($type);
        $t = $cfg["table"]; $idc = $cfg["id_col"];
        $sql = "SELECT m.$idc AS memo_id, m.reference_no, m.invoice_id, m.category, m.memo_date, m.amount, m.reason, m.status,
                       m.created_by, m.approved_at,
                       s.receipt_number, inv.due_date,
                       CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       CONCAT(cb.first_name, ' ', cb.last_name) AS created_by_name,
                       CONCAT(ab.first_name, ' ', ab.last_name) AS approved_by_name
                FROM $t m
                INNER JOIN tblinvoice inv ON inv.invoice_id = m.invoice_id
                INNER JOIN tblsale s ON s.sale_id = inv.sale_id
                INNER JOIN tblar_account ar ON ar.ar_account_id = inv.ar_account_id
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                LEFT JOIN tblemployee cb ON cb.employee_id = m.created_by
                LEFT JOIN tblemployee ab ON ab.employee_id = m.approved_by
                ORDER BY m.$idc DESC";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMemoAudit($type, $memoId) {
        include "connection-pdo.php";
        $cfg = $this->cfg($type);
        $stmt = $conn->prepare("SELECT a.action, a.reason, a.created_at,
                                       CONCAT(e.first_name, ' ', e.last_name) AS performed_by,
                                       CONCAT(ab.first_name, ' ', ab.last_name) AS authorized_by
                                FROM tblar_audit a
                                INNER JOIN tblemployee e ON e.employee_id = a.employee_id
                                LEFT JOIN tblemployee ab ON ab.employee_id = a.authorized_by
                                WHERE a.entity_type = :t AND a.entity_id = :id ORDER BY a.audit_id ASC");
        $stmt->bindValue(":t", $cfg["entity"], PDO::PARAM_STR);
        $stmt->bindValue(":id", (int)$memoId, PDO::PARAM_INT);
        $stmt->execute();
        return ["success" => true, "rows" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function invoiceOutstanding($conn, $invoiceId) {
        $stmt = $conn->prepare("SELECT outstanding, ar_account_id FROM (" . arOpenInvoicesSql() . ") oi WHERE oi.invoice_id = :id");
        $stmt->bindValue(":id", (int)$invoiceId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createMemo($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $cfg = $this->cfg((string)($data["type"] ?? ""));
            $invoiceId = (int)($data["invoice_id"] ?? 0);
            $amountCents = arCents($data["amount"] ?? 0);
            $category = trim((string)($data["category"] ?? ""));
            $reason = arReason($data);
            $employeeId = (int)$_SESSION["employee_id"];

            if ($invoiceId <= 0) throw new ArException("Select the invoice this memo applies to.");
            if ($amountCents <= 0) throw new ArException("Memo amount must be greater than zero.");
            if (!in_array($category, $cfg["categories"], true)) throw new ArException("Select a valid category.");

            $inv = $this->invoiceOutstanding($conn, $invoiceId);
            if (!$inv) throw new ArException("Invoice not found, or it is not a credit sale of a completed transaction.");
            $outstanding = arCents($inv["outstanding"]);
            if ($cfg["sign"] < 0) {
                if ($outstanding <= 0) throw new ArException("This invoice is already fully paid. A credit memo can only reduce an outstanding balance.");
                if ($amountCents > $outstanding) throw new ArException("Credit memo exceeds the invoice's outstanding balance of ₱" . number_format($outstanding / 100, 2) . ".");
            }

            $conn->beginTransaction();

            // Late fee policy (2026-10-22, D-C): a manual Late fee memo takes
            // the NEXT period of the same per-invoice ledger the automatic
            // generator uses — reference LF-<invoice>-P<period>, unique-key
            // backed — so a manual and an automatic fee can never cover the
            // same overdue period. The account row lock serializes creators.
            $isLateFee = ($cfg["entity"] === "debit_memo" && $category === "Late fee");
            $lfPeriod = 0;
            if ($isLateFee) {
                $lk = $conn->prepare("SELECT ar_account_id FROM tblar_account WHERE ar_account_id = :ar FOR UPDATE");
                $lk->bindValue(":ar", (int)$inv["ar_account_id"], PDO::PARAM_INT);
                $lk->execute();
                $lfPeriod = arCoveredLateFeePeriod($conn, $invoiceId) + 1;
            }

            // Reference: PREFIX-yymmdd-#### (database date), unique-key backed, retried on collision.
            $day = $conn->query("SELECT DATE_FORMAT(CURDATE(), '%y%m%d')")->fetchColumn();
            $like = $cfg["prefix"] . "-" . $day . "-%";
            $cnt = $conn->prepare("SELECT COUNT(*) FROM {$cfg["table"]} WHERE reference_no LIKE :l");
            $cnt->bindValue(":l", $like, PDO::PARAM_STR);
            $cnt->execute();
            $seq = (int)$cnt->fetchColumn();

            $ins = $conn->prepare("INSERT INTO {$cfg["table"]} (reference_no, invoice_id, category, amount, reason, status, created_by)
                                   VALUES (:ref, :inv, :cat, :amt, :reason, 'Pending', :emp)");
            $memoId = 0; $reference = "";
            for ($try = 0; $try < 5; $try++) {
                $reference = $isLateFee
                    ? "LF-" . $invoiceId . "-P" . $lfPeriod
                    : $cfg["prefix"] . "-" . $day . "-" . str_pad((string)($seq + 1 + $try), 4, "0", STR_PAD_LEFT);
                try {
                    $ins->bindValue(":ref", $reference, PDO::PARAM_STR);
                    $ins->bindValue(":inv", $invoiceId, PDO::PARAM_INT);
                    $ins->bindValue(":cat", $category, PDO::PARAM_STR);
                    $ins->bindValue(":amt", arMoney($amountCents));
                    $ins->bindValue(":reason", $reason, PDO::PARAM_STR);
                    $ins->bindValue(":emp", $employeeId, PDO::PARAM_INT);
                    $ins->execute();
                    $memoId = (int)$conn->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                    if ($isLateFee) $lfPeriod = arCoveredLateFeePeriod($conn, $invoiceId) + 1;
                }
            }
            if ($memoId <= 0) throw new ArException("Could not generate a unique memo reference. Try again.");

            $auditAfter = ["reference" => $reference, "invoice_id" => $invoiceId, "amount" => arMoney($amountCents), "category" => $category, "status" => "Pending"];
            if ($isLateFee) $auditAfter["late_fee_period"] = $lfPeriod;
            arAudit($conn, $cfg["entity"], $memoId, "Created", (int)$inv["ar_account_id"],
                null, $auditAfter,
                $reason, $employeeId);

            $conn->commit();
            $msg = ucfirst($data["type"]) . " memo $reference created (Pending approval). It has no effect on balances until approved.";
            if ($isLateFee) $msg = "Late fee memo $reference created (Pending approval) — period $lfPeriod of this invoice's late-fee ledger, so the automatic policy will not charge that period again.";
            return ["success" => true, "message" => $msg, "memo_id" => $memoId, "reference_no" => $reference];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to create the memo. Nothing was changed."];
        }
    }

    public function approveMemo($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $cfg = $this->cfg((string)($data["type"] ?? ""));
            $memoId = (int)($data["memo_id"] ?? 0);
            if ($memoId <= 0) throw new ArException("Memo is required.");
            $approver = arResolveApprover($conn, $data, ["Administrator", "Supervisor"]);
            $employeeId = (int)$_SESSION["employee_id"];

            $conn->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $conn->beginTransaction();

            $find = $conn->prepare("SELECT inv.ar_account_id, m.invoice_id FROM {$cfg["table"]} m INNER JOIN tblinvoice inv ON inv.invoice_id = m.invoice_id WHERE m.{$cfg["id_col"]} = :id");
            $find->bindValue(":id", $memoId, PDO::PARAM_INT);
            $find->execute();
            $found = $find->fetch(PDO::FETCH_ASSOC);
            if (!$found) throw new ArException("Memo not found.");
            $arId = (int)$found["ar_account_id"];

            $acc = $conn->prepare("SELECT current_balance FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $acc->bindValue(":id", $arId, PDO::PARAM_INT);
            $acc->execute();
            $account = $acc->fetch(PDO::FETCH_ASSOC);

            $lock = $conn->prepare("SELECT status, amount, invoice_id, reference_no FROM {$cfg["table"]} WHERE {$cfg["id_col"]} = :id FOR UPDATE");
            $lock->bindValue(":id", $memoId, PDO::PARAM_INT);
            $lock->execute();
            $memo = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$memo) throw new ArException("Memo not found.");
            if ($memo["status"] !== "Pending") throw new ArException("This memo is already " . $memo["status"] . ". Only a Pending memo can be approved.");
            $amountCents = arCents($memo["amount"]);

            if ($cfg["sign"] < 0) {
                // Re-check at approval time: the invoice may have been paid since creation.
                $inv = $this->invoiceOutstanding($conn, (int)$memo["invoice_id"]);
                $outstanding = $inv ? arCents($inv["outstanding"]) : 0;
                if ($amountCents > $outstanding) {
                    throw new ArException("Cannot approve: the credit memo (₱" . number_format($amountCents / 100, 2) . ") now exceeds the invoice's outstanding balance (₱" . number_format($outstanding / 100, 2) . "). Reject it or reverse payments first.");
                }
            }

            $upd = $conn->prepare("UPDATE {$cfg["table"]} SET status = 'Approved', approved_by = :ab, approved_at = NOW() WHERE {$cfg["id_col"]} = :id");
            $upd->bindValue(":ab", $approver["employee_id"], PDO::PARAM_INT);
            $upd->bindValue(":id", $memoId, PDO::PARAM_INT);
            $upd->execute();

            $delta = $cfg["sign"] * $amountCents;
            $bal = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance + :d WHERE ar_account_id = :id");
            $bal->bindValue(":d", arMoney($delta));
            $bal->bindValue(":id", $arId, PDO::PARAM_INT);
            $bal->execute();

            arAudit($conn, $cfg["entity"], $memoId, "Approved", $arId,
                ["balance" => (float)$account["current_balance"], "status" => "Pending"],
                ["balance" => arMoney(arCents($account["current_balance"]) + $delta), "status" => "Approved", "amount" => arMoney($amountCents)],
                null, $employeeId, $approver["employee_id"]);

            $conn->commit();
            return ["success" => true, "message" => "Memo {$memo["reference_no"]} approved by {$approver["name"]} and applied to the balance."];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to approve the memo. Nothing was changed."];
        }
    }

    public function rejectMemo($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $cfg = $this->cfg((string)($data["type"] ?? ""));
            $memoId = (int)($data["memo_id"] ?? 0);
            if ($memoId <= 0) throw new ArException("Memo is required.");
            $reason = arReason($data);
            $employeeId = (int)$_SESSION["employee_id"];

            $conn->beginTransaction();
            $lock = $conn->prepare("SELECT status, invoice_id FROM {$cfg["table"]} WHERE {$cfg["id_col"]} = :id FOR UPDATE");
            $lock->bindValue(":id", $memoId, PDO::PARAM_INT);
            $lock->execute();
            $memo = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$memo) throw new ArException("Memo not found.");
            if ($memo["status"] !== "Pending") throw new ArException("Only a Pending memo can be rejected.");

            $inv = $this->invoiceOutstanding($conn, (int)$memo["invoice_id"]);
            $upd = $conn->prepare("UPDATE {$cfg["table"]} SET status = 'Rejected' WHERE {$cfg["id_col"]} = :id");
            $upd->bindValue(":id", $memoId, PDO::PARAM_INT);
            $upd->execute();

            arAudit($conn, $cfg["entity"], $memoId, "Rejected", $inv ? (int)$inv["ar_account_id"] : null,
                ["status" => "Pending"], ["status" => "Rejected"], $reason, $employeeId);

            $conn->commit();
            return ["success" => true, "message" => "Memo rejected. It never affected any balance."];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to reject the memo. Nothing was changed."];
        }
    }

    public function reverseMemo($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $cfg = $this->cfg((string)($data["type"] ?? ""));
            $memoId = (int)($data["memo_id"] ?? 0);
            if ($memoId <= 0) throw new ArException("Memo is required.");
            $reason = arReason($data);
            $approver = arResolveApprover($conn, $data, ["Administrator", "Supervisor"]);
            $employeeId = (int)$_SESSION["employee_id"];

            $conn->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $conn->beginTransaction();

            $find = $conn->prepare("SELECT inv.ar_account_id FROM {$cfg["table"]} m INNER JOIN tblinvoice inv ON inv.invoice_id = m.invoice_id WHERE m.{$cfg["id_col"]} = :id");
            $find->bindValue(":id", $memoId, PDO::PARAM_INT);
            $find->execute();
            $arId = (int)$find->fetchColumn();
            if ($arId <= 0) throw new ArException("Memo not found.");

            $acc = $conn->prepare("SELECT current_balance FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $acc->bindValue(":id", $arId, PDO::PARAM_INT);
            $acc->execute();
            $account = $acc->fetch(PDO::FETCH_ASSOC);

            $lock = $conn->prepare("SELECT status, amount, invoice_id, reference_no FROM {$cfg["table"]} WHERE {$cfg["id_col"]} = :id FOR UPDATE");
            $lock->bindValue(":id", $memoId, PDO::PARAM_INT);
            $lock->execute();
            $memo = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$memo) throw new ArException("Memo not found.");
            if ($memo["status"] !== "Approved") throw new ArException("Only an Approved memo can be reversed.");
            $amountCents = arCents($memo["amount"]);

            if ($cfg["sign"] > 0) {
                // Undoing a debit memo lowers the invoice balance; the customer may
                // already have paid that charge, which would drive it negative.
                $inv = $this->invoiceOutstanding($conn, (int)$memo["invoice_id"]);
                $outstanding = $inv ? arCents($inv["outstanding"]) : 0;
                if ($outstanding < $amountCents) {
                    throw new ArException("Cannot reverse: the customer has already paid part of this debit memo. Reverse the payment first, or settle the difference with a credit memo.");
                }
            }

            $upd = $conn->prepare("UPDATE {$cfg["table"]} SET status = 'Reversed' WHERE {$cfg["id_col"]} = :id");
            $upd->bindValue(":id", $memoId, PDO::PARAM_INT);
            $upd->execute();

            $delta = -($cfg["sign"] * $amountCents);
            $bal = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance + :d WHERE ar_account_id = :id");
            $bal->bindValue(":d", arMoney($delta));
            $bal->bindValue(":id", $arId, PDO::PARAM_INT);
            $bal->execute();

            arAudit($conn, $cfg["entity"], $memoId, "Reversed", $arId,
                ["balance" => (float)$account["current_balance"], "status" => "Approved"],
                ["balance" => arMoney(arCents($account["current_balance"]) + $delta), "status" => "Reversed", "amount" => arMoney($amountCents)],
                $reason, $employeeId, $approver["employee_id"]);

            $conn->commit();
            return ["success" => true, "message" => "Memo {$memo["reference_no"]} reversed with {$approver["name"]}'s approval. Its balance effect has been undone."];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to reverse the memo. Nothing was changed."];
        }
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_POST["json"] ?? "";
$isPost = ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST";
$writeOps = ["createMemo", "approveMemo", "rejectMemo", "reverseMemo"];

if (in_array($operation, $writeOps, true) && !$isPost) {
    echo json_encode(["success" => false, "message" => "This operation requires POST."]);
    exit;
}

$obj = new ArMemo();

try {
    switch ($operation) {
        case "getMemoCustomers": echo json_encode($obj->getMemoCustomers()); break;
        case "getMemoInvoices": echo json_encode($obj->getMemoInvoices($_GET["ar_account_id"] ?? 0)); break;
        case "getMemos": echo json_encode($obj->getMemos($_GET["type"] ?? "")); break;
        case "getMemoAudit": echo json_encode($obj->getMemoAudit($_GET["type"] ?? "", $_GET["memo_id"] ?? 0)); break;
        case "createMemo": echo json_encode($obj->createMemo($json)); break;
        case "approveMemo": echo json_encode($obj->approveMemo($json)); break;
        case "rejectMemo": echo json_encode($obj->rejectMemo($json)); break;
        case "reverseMemo": echo json_encode($obj->reverseMemo($json)); break;
        default: echo json_encode(["success" => false, "message" => "Invalid operation."]);
    }
} catch (ArException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Unable to complete the request."]);
}
