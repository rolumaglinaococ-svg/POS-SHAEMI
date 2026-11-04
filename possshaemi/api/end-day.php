<?php
require_once "session-guard.php";
require_once "audit-logger.php";
requireRole(["Administrator"]);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

/*
 * End-Day Report (Z-read) — Admin panel.
 *
 * The page session is Administrator; Finalize and Reopen are authorized by
 * typed Supervisor OR Administrator credentials (same model as verifySupervisor
 * in pos.php). Finalizing snapshots the day's totals into tblend_day and is
 * allowed even with documented discrepancies; it is blocked only when a shift
 * is still open, a closed shift lacks authorization, or a deposit is
 * unverified. Reopening a finalized day needs credentials + a reason and is
 * recorded in tblend_day_event; it never touches closed shifts.
 */

class EndDayException extends Exception {}

class EndDay {

    private function verifyAuthorizer($username, $password, &$failureReason = null) {
        include "connection-pdo.php";

        $username = strtolower(trim((string)$username));
        if ($username === "" || $password === "") {
            $failureReason = "Enter both the username and password.";
            return null;
        }

        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.username, e.password,
                       e.is_active AS employee_active, r.role_name, r.is_active AS role_active
                FROM tblemployee e
                INNER JOIN tblrole r ON e.role_id = r.role_id
                WHERE LOWER(TRIM(e.username)) = :username
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            $failureReason = "No employee account was found for that username.";
            return null;
        }
        if ((int)$employee["employee_active"] !== 1) {
            $failureReason = "This employee account is inactive.";
            return null;
        }
        if ((int)$employee["role_active"] !== 1) {
            $failureReason = "This employee role is inactive.";
            return null;
        }
        if (!in_array(strtolower(trim($employee["role_name"])), ["supervisor", "administrator"], true)) {
            $failureReason = "This account must be assigned the Supervisor or Administrator role.";
            return null;
        }
        if (password_get_info($employee["password"])["algo"] === null) {
            $failureReason = "The saved password is not a valid password hash. Reset and save it again in Employee Management.";
            return null;
        }
        if (!password_verify($password, $employee["password"])) {
            $failureReason = "The password does not match the saved password for this account.";
            return null;
        }

        return [
            "employee_id" => (int)$employee["employee_id"],
            "display_name" => $employee["first_name"] . " " . $employee["last_name"],
            "role_name" => $employee["role_name"]
        ];
    }

    private function employeeLabel($conn, $id) {
        if (!$id) {
            return null;
        }
        $st = $conn->prepare("SELECT e.first_name, e.last_name, r.role_name
                              FROM tblemployee e
                              INNER JOIN tblrole r ON r.role_id = e.role_id
                              WHERE e.employee_id = :id");
        $st->bindValue(":id", (int)$id, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? ["name" => trim($row["first_name"] . " " . $row["last_name"]),
                       "role" => $row["role_name"]] : null;
    }

    // Cash figures for one shift over its window [opened_at, closed_at|NOW).
    private function shiftCash($conn, $shift, $now) {
        $employeeId = (int)$shift["employee_id"];
        $counterId = (int)$shift["counter_id"];
        $start = $shift["opened_at"];
        // Open shift: open-ended window (a NOW() bound would drop activity
        // timestamped in the current second — see pos.php buildShiftReport).
        $end = !empty($shift["closed_at"]) ? $shift["closed_at"] : "9999-12-31 23:59:59";
        $scope = "s.counter_id = :c AND s.employee_id = :e
                  AND s.sale_date >= :start AND s.sale_date < :end";
        $bind = function ($stmt) use ($counterId, $employeeId, $start, $end) {
            $stmt->bindValue(":c", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $stmt->bindValue(":start", $start);
            $stmt->bindValue(":end", $end);
        };

        $payStmt = $conn->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) AS cash_paid
                                   FROM tblpayment p
                                   INNER JOIN tblinvoice i ON i.invoice_id = p.invoice_id
                                   INNER JOIN tblsale s ON s.sale_id = i.sale_id
                                   INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                                   WHERE $scope AND s.sale_status = 'Completed'
                                     AND pt.type_name = 'Cash'");
        $bind($payStmt);
        $payStmt->execute();
        $cashPaid = (float)$payStmt->fetchColumn();

        $chgStmt = $conn->prepare("SELECT COALESCE(SUM(s.change_amount), 0)
                                   FROM tblsale s
                                   WHERE $scope AND s.sale_status = 'Completed'");
        $bind($chgStmt);
        $chgStmt->execute();
        $cashReceived = round($cashPaid - (float)$chgStmt->fetchColumn(), 2);

        $liftStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0)
                                    FROM tblcash_float
                                    WHERE counter_id = :c AND employee_id = :e
                                      AND float_type = 'Lift'
                                      AND ((shift_id IS NOT NULL AND shift_id = :sid)
                                           OR (shift_id IS NULL AND created_at >= :start AND created_at < :end))");
        $liftStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $liftStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $liftStmt->bindValue(":sid", (int)$shift["shift_id"], PDO::PARAM_INT);
        $liftStmt->bindValue(":start", $start);
        $liftStmt->bindValue(":end", $end);
        $liftStmt->execute();
        $lifts = round((float)$liftStmt->fetchColumn(), 2);

        $expected = round((float)$shift["opening_cash"] + $cashReceived - $lifts, 2);
        return ["cash_received" => $cashReceived, "lifts" => $lifts, "expected" => $expected];
    }

    public function buildReport($conn, $date) {
        $now = $conn->query("SELECT NOW()")->fetchColumn();

        // ---- Shifts of the day ----
        $shiftStmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                            c.counter_name, c.counter_number
                                     FROM tblcashier_shift sh
                                     INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                     INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                     WHERE sh.shift_date = :d
                                     ORDER BY c.counter_number ASC, sh.opened_at ASC");
        $shiftStmt->bindValue(":d", $date);
        $shiftStmt->execute();
        $shiftRows = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

        $shifts = [];
        $sum = ["opening" => 0.0, "expected" => 0.0, "actual" => 0.0, "difference" => 0.0,
                "lifts" => 0.0, "deposit" => 0.0, "deposit_verified" => 0.0, "deposit_diff" => 0.0];
        $discrepancies = [];
        $openCount = 0;
        $allAuthorized = true;
        $allDepositsVerified = true;

        foreach ($shiftRows as $row) {
            $cash = $this->shiftCash($conn, $row, $now);
            $isClosed = $row["shift_status"] === "Closed";
            if (!$isClosed) {
                $openCount++;
            }
            // Closed shifts report their stored snapshot; open shifts the
            // live computation.
            $expected = $isClosed && $row["expected_cash"] !== null
                ? round((float)$row["expected_cash"], 2) : $cash["expected"];
            $actual = $row["actual_cash"] === null ? null : round((float)$row["actual_cash"], 2);
            $difference = $row["cash_difference"] === null ? null : round((float)$row["cash_difference"], 2);
            $depDiff = $row["deposit_difference"] === null ? null : round((float)$row["deposit_difference"], 2);
            $authorizer = $this->employeeLabel($conn, $row["authorized_by"]);
            $verifier = $this->employeeLabel($conn, $row["deposit_verified_by"]);

            if ($isClosed && !$row["authorized_by"]) {
                $allAuthorized = false;
            }
            if ($isClosed && (!$row["deposit_verified_by"] || trim((string)$row["deposit_bag_no"]) === "")) {
                $allDepositsVerified = false;
            }

            $sum["opening"] += (float)$row["opening_cash"];
            $sum["expected"] += $expected;
            $sum["lifts"] += $cash["lifts"];
            if ($actual !== null) {
                $sum["actual"] += $actual;
            }
            if ($difference !== null) {
                $sum["difference"] += $difference;
            }
            if ($row["deposit_amount"] !== null) {
                $sum["deposit"] += (float)$row["deposit_amount"];
            }
            if ($row["deposit_verified_amount"] !== null) {
                $sum["deposit_verified"] += (float)$row["deposit_verified_amount"];
            }
            if ($depDiff !== null) {
                $sum["deposit_diff"] += $depDiff;
            }

            $cashierName = trim($row["first_name"] . " " . $row["last_name"]);
            if ($difference !== null && abs($difference) > 0.004) {
                $discrepancies[] = [
                    "type" => $difference < 0 ? "Shortage" : "Excess",
                    "amount" => $difference,
                    "shift_id" => (int)$row["shift_id"],
                    "cashier_name" => $cashierName,
                    "counter_name" => $row["counter_name"],
                    "notes" => $row["closing_notes"]
                ];
            }
            if ($depDiff !== null && abs($depDiff) > 0.004) {
                $discrepancies[] = [
                    "type" => "Deposit difference",
                    "amount" => $depDiff,
                    "shift_id" => (int)$row["shift_id"],
                    "cashier_name" => $cashierName,
                    "counter_name" => $row["counter_name"],
                    "bag_no" => $row["deposit_bag_no"],
                    "notes" => null
                ];
            }

            $shifts[] = [
                "shift_id" => (int)$row["shift_id"],
                "cashier_name" => $cashierName,
                "employee_id" => (int)$row["employee_id"],
                "counter_name" => $row["counter_name"],
                "counter_number" => (int)$row["counter_number"],
                "opened_at" => $row["opened_at"],
                "closed_at" => $row["closed_at"],
                "status" => $row["shift_status"],
                "opening_cash" => round((float)$row["opening_cash"], 2),
                "cash_received" => $cash["cash_received"],
                "cash_lifts" => $cash["lifts"],
                "expected_cash" => $expected,
                "actual_cash" => $actual,
                "cash_difference" => $difference,
                "closing_notes" => $row["closing_notes"],
                "authorized_by" => $authorizer,
                "deposit" => [
                    "bag_no" => $row["deposit_bag_no"],
                    "amount" => $row["deposit_amount"] === null ? null : round((float)$row["deposit_amount"], 2),
                    "verified_amount" => $row["deposit_verified_amount"] === null ? null : round((float)$row["deposit_verified_amount"], 2),
                    "difference" => $depDiff,
                    "verified_by" => $verifier,
                    "verified_at" => $row["deposit_verified_at"]
                ]
            ];
        }

        // ---- Day sales aggregates (date based, like reports.php) ----
        $dayScope = "DATE(s.sale_date) = :d";
        $sumStmt = $conn->prepare("SELECT COUNT(*) AS cnt,
                                          COALESCE(SUM(s.total_amount), 0) AS net
                                   FROM tblsale s
                                   WHERE $dayScope AND s.sale_status = 'Completed'");
        $sumStmt->bindValue(":d", $date);
        $sumStmt->execute();
        $saleSum = $sumStmt->fetch(PDO::FETCH_ASSOC);

        $grossStmt = $conn->prepare("SELECT COALESCE(SUM(si.subtotal), 0)
                                     FROM tblsale_item si
                                     INNER JOIN tblsale s ON s.sale_id = si.sale_id
                                     WHERE $dayScope AND s.sale_status = 'Completed'");
        $grossStmt->bindValue(":d", $date);
        $grossStmt->execute();
        $gross = round((float)$grossStmt->fetchColumn(), 2);
        $net = round((float)$saleSum["net"], 2);

        $voidStmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total_amount), 0) AS total
                                    FROM tblsale s
                                    WHERE $dayScope AND s.sale_status = 'Voided'");
        $voidStmt->bindValue(":d", $date);
        $voidStmt->execute();
        $void = $voidStmt->fetch(PDO::FETCH_ASSOC);

        // Payment breakdown; cash is net of change given (reports.php rule).
        $payStmt = $conn->prepare("SELECT pt.type_name,
                                          COALESCE(SUM(CASE WHEN LOWER(pt.type_name) = 'cash'
                                                            THEN GREATEST(p.amount_paid - s.change_amount, 0)
                                                            ELSE p.amount_paid END), 0) AS total
                                   FROM tblpayment p
                                   INNER JOIN tblinvoice i ON i.invoice_id = p.invoice_id
                                   INNER JOIN tblsale s ON s.sale_id = i.sale_id
                                   INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                                   WHERE $dayScope AND s.sale_status = 'Completed'
                                   GROUP BY pt.type_name");
        $payStmt->bindValue(":d", $date);
        $payStmt->execute();
        $payments = [];
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[] = ["type_name" => $row["type_name"], "total" => round((float)$row["total"], 2)];
        }

        $arStmt = $conn->prepare("SELECT COALESCE(SUM(i.amount), 0)
                                  FROM tblinvoice i
                                  INNER JOIN tblsale s ON s.sale_id = i.sale_id
                                  WHERE $dayScope AND s.sale_status = 'Completed'
                                    AND i.transaction_type = 'Credit'");
        $arStmt->bindValue(":d", $date);
        $arStmt->execute();
        $arTotal = round((float)$arStmt->fetchColumn(), 2);

        // ---- Unattributed sales (WARNING only, never blocks) ----
        $unattributed = $this->unattributedSales($conn, $date);

        // ---- End-Day record, if any ----
        $endDay = null;
        $edStmt = $conn->prepare("SELECT * FROM tblend_day WHERE business_date = :d LIMIT 1");
        $edStmt->bindValue(":d", $date);
        $edStmt->execute();
        $edRow = $edStmt->fetch(PDO::FETCH_ASSOC);
        if ($edRow) {
            $events = [];
            $evStmt = $conn->prepare("SELECT ev.*, e.first_name, e.last_name, r.role_name
                                      FROM tblend_day_event ev
                                      INNER JOIN tblemployee e ON e.employee_id = ev.actor_id
                                      INNER JOIN tblrole r ON r.role_id = e.role_id
                                      WHERE ev.end_day_id = :id
                                      ORDER BY ev.created_at ASC, ev.event_id ASC");
            $evStmt->bindValue(":id", (int)$edRow["end_day_id"], PDO::PARAM_INT);
            $evStmt->execute();
            foreach ($evStmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
                $events[] = [
                    "event_type" => $ev["event_type"],
                    "actor" => ["name" => trim($ev["first_name"] . " " . $ev["last_name"]), "role" => $ev["role_name"]],
                    "reason" => $ev["reason"],
                    "created_at" => $ev["created_at"]
                ];
            }
            $endDay = [
                "end_day_id" => (int)$edRow["end_day_id"],
                "status" => $edRow["day_status"],
                "finalized_by" => $this->employeeLabel($conn, $edRow["finalized_by"]),
                "finalized_at" => $edRow["finalized_at"],
                "finalize_count" => (int)$edRow["finalize_count"],
                "snapshot" => [
                    "shifts_count" => (int)$edRow["shifts_count"],
                    "total_transactions" => (int)$edRow["total_transactions"],
                    "net_sales" => (float)$edRow["net_sales"],
                    "total_expected_cash" => (float)$edRow["total_expected_cash"],
                    "total_actual_cash" => (float)$edRow["total_actual_cash"],
                    "total_cash_difference" => (float)$edRow["total_cash_difference"],
                    "total_deposit_difference" => (float)$edRow["total_deposit_difference"],
                    "discrepancy_count" => (int)$edRow["discrepancy_count"]
                ],
                "events" => $events
            ];
        }

        $checks = [
            "has_shifts" => count($shifts) > 0,
            "all_closed" => count($shifts) > 0 && $openCount === 0,
            "open_count" => $openCount,
            "all_authorized" => $allAuthorized,
            "all_deposits_verified" => $allDepositsVerified,
            "discrepancy_count" => count($discrepancies)
        ];
        $checks["can_finalize"] = $checks["has_shifts"] && $checks["all_closed"]
            && $checks["all_authorized"] && $checks["all_deposits_verified"]
            && !($endDay && $endDay["status"] === "Finalized");

        return [
            "success" => true,
            "date" => $date,
            "shifts" => $shifts,
            "totals" => [
                "transactions" => (int)$saleSum["cnt"],
                "gross" => $gross,
                "discount" => round($gross - $net, 2),
                "net" => $net,
                "voided_count" => (int)$void["cnt"],
                "voided_total" => round((float)$void["total"], 2),
                "ar_total" => $arTotal,
                "opening" => round($sum["opening"], 2),
                "cash_lifts" => round($sum["lifts"], 2),
                "expected_cash" => round($sum["expected"], 2),
                "actual_cash" => round($sum["actual"], 2),
                "cash_difference" => round($sum["difference"], 2),
                "deposit_amount" => round($sum["deposit"], 2),
                "deposit_verified" => round($sum["deposit_verified"], 2),
                "deposit_difference" => round($sum["deposit_diff"], 2)
            ],
            "payments" => $payments,
            "checks" => $checks,
            "discrepancies" => $discrepancies,
            "unattributed" => $unattributed,
            "end_day" => $endDay
        ];
    }

    private function unattributedSales($conn, $date) {
        $stmt = $conn->prepare("SELECT s.sale_id, s.employee_id, s.counter_id, s.sale_date, s.total_amount
                                FROM tblsale s
                                WHERE DATE(s.sale_date) = :d AND s.sale_status = 'Completed'
                                ORDER BY s.sale_date");
        $stmt->bindValue(":d", $date);
        $stmt->execute();
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$sales) {
            return ["count" => 0, "total" => 0.0];
        }
        // Windows from every shift that could cover these sales (any date —
        // a shift opened the day before and still open also attributes).
        $winStmt = $conn->prepare("SELECT employee_id, counter_id, opened_at, closed_at
                                   FROM tblcashier_shift
                                   WHERE DATE(opened_at) <= :d");
        $winStmt->bindValue(":d", $date);
        $winStmt->execute();
        $windows = $winStmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        $total = 0.0;
        foreach ($sales as $sale) {
            $covered = false;
            foreach ($windows as $w) {
                if ((int)$w["employee_id"] !== (int)$sale["employee_id"]
                    || (int)$w["counter_id"] !== (int)$sale["counter_id"]) {
                    continue;
                }
                $end = $w["closed_at"] ?: "9999-12-31 23:59:59";
                if ($sale["sale_date"] >= $w["opened_at"] && $sale["sale_date"] < $end) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $count++;
                $total += (float)$sale["total_amount"];
            }
        }
        return ["count" => $count, "total" => round($total, 2)];
    }

    private function validDate($date) {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && date("Y-m-d", strtotime($date)) === $date;
    }

    public function getEndDayReport($date) {
        include "connection-pdo.php";
        if (!$this->validDate($date)) {
            return ["success" => false, "message" => "Choose a valid date."];
        }
        try {
            return $this->buildReport($conn, $date);
        } catch (Exception $e) {
            return ["success" => false, "message" => "Unable to load the End-Day report."];
        }
    }

    public function finalizeEndDay($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        $date = $data["date"] ?? "";
        if (!$this->validDate($date)) {
            return ["success" => false, "message" => "Choose a valid date."];
        }

        $reason = null;
        $authorizer = $this->verifyAuthorizer(
            $data["auth_user"] ?? "", $data["auth_pass"] ?? "", $reason);
        if (!$authorizer) {
            return ["success" => false, "message" => "Authorization failed. " . ($reason ?? "Invalid Supervisor/Administrator credentials.")];
        }

        try {
            $conn->beginTransaction();

            $lock = $conn->prepare("SELECT * FROM tblend_day WHERE business_date = :d FOR UPDATE");
            $lock->bindValue(":d", $date);
            $lock->execute();
            $existing = $lock->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing["day_status"] === "Finalized") {
                throw new EndDayException("This day is already finalized. Reopen it first if it must be corrected.");
            }

            $report = $this->buildReport($conn, $date);
            $checks = $report["checks"];
            if (!$checks["has_shifts"]) {
                throw new EndDayException("No shifts recorded for this date. Nothing to finalize.");
            }
            if (!$checks["all_closed"]) {
                throw new EndDayException($checks["open_count"] . " shift(s) are still open. All shifts must be closed first.");
            }
            if (!$checks["all_authorized"]) {
                throw new EndDayException("A closed shift is missing its Supervisor/Administrator authorization.");
            }
            if (!$checks["all_deposits_verified"]) {
                throw new EndDayException("A closed shift is missing its verified cash deposit.");
            }
            // Discrepancies and unattributed sales never block: they are
            // documented in the snapshot/report, not resolved by finalizing.

            $t = $report["totals"];
            $paymentsMap = [];
            foreach ($report["payments"] as $p) {
                $paymentsMap[$p["type_name"]] = $p["total"];
            }

            $upsert = $conn->prepare("INSERT INTO tblend_day
                (business_date, day_status, shifts_count, total_transactions, gross_sales,
                 total_discounts, net_sales, ar_credit_total, voided_count, voided_total,
                 total_opening_cash, total_cash_lifts, total_expected_cash, total_actual_cash,
                 total_cash_difference, total_deposit_amount, total_deposit_verified,
                 total_deposit_difference, discrepancy_count, payments_json,
                 finalized_by, finalized_at, finalize_count)
                VALUES (:d, 'Finalized', :shifts, :txns, :gross, :disc, :net, :ar, :vcount, :vtotal,
                        :opening, :lifts, :expected, :actual, :diff, :dep, :depv, :depdiff,
                        :disccount, :payments, :by, NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    day_status = 'Finalized',
                    shifts_count = VALUES(shifts_count),
                    total_transactions = VALUES(total_transactions),
                    gross_sales = VALUES(gross_sales),
                    total_discounts = VALUES(total_discounts),
                    net_sales = VALUES(net_sales),
                    ar_credit_total = VALUES(ar_credit_total),
                    voided_count = VALUES(voided_count),
                    voided_total = VALUES(voided_total),
                    total_opening_cash = VALUES(total_opening_cash),
                    total_cash_lifts = VALUES(total_cash_lifts),
                    total_expected_cash = VALUES(total_expected_cash),
                    total_actual_cash = VALUES(total_actual_cash),
                    total_cash_difference = VALUES(total_cash_difference),
                    total_deposit_amount = VALUES(total_deposit_amount),
                    total_deposit_verified = VALUES(total_deposit_verified),
                    total_deposit_difference = VALUES(total_deposit_difference),
                    discrepancy_count = VALUES(discrepancy_count),
                    payments_json = VALUES(payments_json),
                    finalized_by = VALUES(finalized_by),
                    finalized_at = VALUES(finalized_at),
                    finalize_count = finalize_count + 1");
            $upsert->bindValue(":d", $date);
            $upsert->bindValue(":shifts", count($report["shifts"]), PDO::PARAM_INT);
            $upsert->bindValue(":txns", $t["transactions"], PDO::PARAM_INT);
            $upsert->bindValue(":gross", $t["gross"]);
            $upsert->bindValue(":disc", $t["discount"]);
            $upsert->bindValue(":net", $t["net"]);
            $upsert->bindValue(":ar", $t["ar_total"]);
            $upsert->bindValue(":vcount", $t["voided_count"], PDO::PARAM_INT);
            $upsert->bindValue(":vtotal", $t["voided_total"]);
            $upsert->bindValue(":opening", $t["opening"]);
            $upsert->bindValue(":lifts", $t["cash_lifts"]);
            $upsert->bindValue(":expected", $t["expected_cash"]);
            $upsert->bindValue(":actual", $t["actual_cash"]);
            $upsert->bindValue(":diff", $t["cash_difference"]);
            $upsert->bindValue(":dep", $t["deposit_amount"]);
            $upsert->bindValue(":depv", $t["deposit_verified"]);
            $upsert->bindValue(":depdiff", $t["deposit_difference"]);
            $upsert->bindValue(":disccount", count($report["discrepancies"]), PDO::PARAM_INT);
            $upsert->bindValue(":payments", json_encode($paymentsMap));
            $upsert->bindValue(":by", (int)$authorizer["employee_id"], PDO::PARAM_INT);
            $upsert->execute();

            $endDayId = $existing ? (int)$existing["end_day_id"] : (int)$conn->lastInsertId();
            $event = $conn->prepare("INSERT INTO tblend_day_event (end_day_id, event_type, actor_id, reason)
                                     VALUES (:id, 'Finalized', :actor, NULL)");
            $event->bindValue(":id", $endDayId, PDO::PARAM_INT);
            $event->bindValue(":actor", (int)$authorizer["employee_id"], PDO::PARAM_INT);
            $event->execute();

            auditEvent($conn, [
                "module" => "End-Day",
                "action" => "Finalized",
                "record_id" => $endDayId,
                "record_label" => "End-Day " . $date,
                "details" => "End-Day finalized.",
                "before" => ["day_status" => $existing ? $existing["day_status"] : "Open"],
                "after" => ["day_status" => "Finalized", "totals" => $t, "shifts_count" => count($report["shifts"]),
                            "discrepancy_count" => count($report["discrepancies"])],
                "authorized_by" => (int)$authorizer["employee_id"],
                "authorized_role" => $authorizer["role_name"],
                "reference_label" => $date
            ]);

            $conn->commit();
        } catch (EndDayException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => "Failed to finalize the End-Day report. Nothing was saved."];
        }

        $result = $this->buildReport($conn, $date);
        $result["message"] = "End-Day finalized by " . $authorizer["display_name"] . " (" . $authorizer["role_name"] . ").";
        return $result;
    }

    public function reopenEndDay($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        $date = $data["date"] ?? "";
        $reopenReason = trim((string)($data["reason"] ?? ""));
        if (!$this->validDate($date)) {
            return ["success" => false, "message" => "Choose a valid date."];
        }
        if ($reopenReason === "") {
            return ["success" => false, "message" => "A reason is required to reopen a finalized day."];
        }

        $reason = null;
        $authorizer = $this->verifyAuthorizer(
            $data["auth_user"] ?? "", $data["auth_pass"] ?? "", $reason);
        if (!$authorizer) {
            return ["success" => false, "message" => "Authorization failed. " . ($reason ?? "Invalid Supervisor/Administrator credentials.")];
        }

        try {
            $conn->beginTransaction();

            $lock = $conn->prepare("SELECT * FROM tblend_day WHERE business_date = :d FOR UPDATE");
            $lock->bindValue(":d", $date);
            $lock->execute();
            $existing = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new EndDayException("This day has not been finalized.");
            }
            if ($existing["day_status"] !== "Finalized") {
                throw new EndDayException("This day is not finalized.");
            }

            // Only the day reopens. finalized_by/at of the last finalize are
            // kept; the Reopened event (with its reason) preserves the trail.
            // Closed shifts are never reopened or altered.
            $upd = $conn->prepare("UPDATE tblend_day SET day_status = 'Open' WHERE end_day_id = :id");
            $upd->bindValue(":id", (int)$existing["end_day_id"], PDO::PARAM_INT);
            $upd->execute();

            $event = $conn->prepare("INSERT INTO tblend_day_event (end_day_id, event_type, actor_id, reason)
                                     VALUES (:id, 'Reopened', :actor, :reason)");
            $event->bindValue(":id", (int)$existing["end_day_id"], PDO::PARAM_INT);
            $event->bindValue(":actor", (int)$authorizer["employee_id"], PDO::PARAM_INT);
            $event->bindValue(":reason", $reopenReason, PDO::PARAM_STR);
            $event->execute();

            auditEvent($conn, [
                "module" => "End-Day",
                "action" => "Reopened",
                "record_id" => (int)$existing["end_day_id"],
                "record_label" => "End-Day " . $date,
                "details" => "End-Day reopened.",
                "before" => ["day_status" => "Finalized"],
                "after" => ["day_status" => "Open"],
                "reason" => $reopenReason,
                "authorized_by" => (int)$authorizer["employee_id"],
                "authorized_role" => $authorizer["role_name"],
                "reference_label" => $date
            ]);

            $conn->commit();
        } catch (EndDayException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => "Failed to reopen the day. Nothing was saved."];
        }

        $result = $this->buildReport($conn, $date);
        $result["message"] = "End-Day reopened by " . $authorizer["display_name"] . " (" . $authorizer["role_name"] . ").";
        return $result;
    }

    public function getEndDayHistory($limit) {
        include "connection-pdo.php";
        $limit = max(1, min(100, (int)$limit));
        $stmt = $conn->prepare("SELECT ed.*, e.first_name, e.last_name, r.role_name
                                FROM tblend_day ed
                                LEFT JOIN tblemployee e ON e.employee_id = ed.finalized_by
                                LEFT JOIN tblrole r ON r.role_id = e.role_id
                                ORDER BY ed.business_date DESC
                                LIMIT $limit");
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                "business_date" => $row["business_date"],
                "status" => $row["day_status"],
                "shifts_count" => (int)$row["shifts_count"],
                "net_sales" => (float)$row["net_sales"],
                "total_cash_difference" => (float)$row["total_cash_difference"],
                "finalize_count" => (int)$row["finalize_count"],
                "finalized_by" => $row["finalized_by"]
                    ? ["name" => trim($row["first_name"] . " " . $row["last_name"]), "role" => $row["role_name"]]
                    : null,
                "finalized_at" => $row["finalized_at"]
            ];
        }
        return ["success" => true, "history" => $rows];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$date = $_GET["date"] ?? $_POST["date"] ?? "";
$limit = $_GET["limit"] ?? 30;

$obj = new EndDay();

switch ($operation) {
    case "getEndDayReport":
        echo json_encode($obj->getEndDayReport($date));
        break;
    case "finalizeEndDay":
        echo json_encode($obj->finalizeEndDay($json));
        break;
    case "reopenEndDay":
        echo json_encode($obj->reopenEndDay($json));
        break;
    case "getEndDayHistory":
        echo json_encode($obj->getEndDayHistory($limit));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
