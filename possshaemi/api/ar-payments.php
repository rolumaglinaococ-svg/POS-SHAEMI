<?php
require_once "session-guard.php";
requireRole(["Administrator", "Manager"]);
require_once "activity-logger.php";
require_once "ar-common.php";
startActivityLogging("AR Payments", "tblpayment", "payment_id",
    "CONCAT('Payment #', payment_id, ' - ', (SELECT CONCAT(c.first_name, ' ', c.last_name) FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id WHERE ar.ar_account_id = tblpayment.ar_account_id))");

header("Content-Type: application/json");

class ArPayment {

    // Suki Points and Gift Certificates depend on state AR does not manage, so they cannot settle a receivable.
    private $excludedMethods = ["suki points", "gift certificate"];

    public function getPaymentMethods() {
        include "connection-pdo.php";
        $stmt = $conn->query("SELECT payment_type_id, type_name FROM tblpayment_type WHERE is_active = 1 AND LOWER(type_name) NOT IN ('suki points', 'gift certificate') ORDER BY type_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Active accounts that currently owe something. */
    public function getPayableAccounts() {
        include "connection-pdo.php";
        $sql = "SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, ar.current_balance,
                       COALESCE(SUM(oi.outstanding), 0) AS outstanding
                FROM tblar_account ar
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                INNER JOIN (" . arOpenInvoicesSql() . ") oi ON oi.ar_account_id = ar.ar_account_id AND oi.outstanding > 0
                WHERE ar.account_status = 'Active'
                GROUP BY ar.ar_account_id, c.first_name, c.last_name, ar.current_balance
                ORDER BY customer_name ASC";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOpenInvoices($arAccountId) {
        include "connection-pdo.php";
        arSyncAccountPolicy($conn, (int)$arAccountId); // late fee / auto-hold catch-up before balances are shown
        $stmt = $conn->prepare("SELECT oi.invoice_id, oi.receipt_number, oi.sale_date, oi.due_date, oi.amount, oi.outstanding,
                                       (oi.amount - oi.outstanding) AS paid, oi.days_overdue
                                FROM (" . arOpenInvoicesSql() . ") oi
                                WHERE oi.ar_account_id = :ar AND oi.outstanding > 0
                                ORDER BY oi.due_date ASC, oi.invoice_id ASC");
        $stmt->bindValue(":ar", (int)$arAccountId, PDO::PARAM_INT);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $acc = $conn->prepare("SELECT current_balance, credit_limit, credit_status FROM tblar_account WHERE ar_account_id = :ar");
        $acc->bindValue(":ar", (int)$arAccountId, PDO::PARAM_INT);
        $acc->execute();
        return ["success" => true, "account" => $acc->fetch(PDO::FETCH_ASSOC), "invoices" => $invoices];
    }

    /* Server-side paged payment history for the AR Dashboard's Recent Payments card. */
    public function getPaymentsPage($page, $pageSize) {
        include "connection-pdo.php";
        $sizes = [5, 10, 15, 25, 50];
        $size = in_array((int)$pageSize, $sizes, true) ? (int)$pageSize : 10;
        $page = max(1, (int)$page);

        $total = (int)$conn->query("SELECT COUNT(*) FROM tblpayment p INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment' WHERE p.ar_account_id IS NOT NULL")->fetchColumn();
        $pages = max(1, (int)ceil($total / $size));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $size;

        $sql = "SELECT p.payment_id, p.payment_date, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, p.ar_account_id,
                       pt.type_name, p.reference_no, p.amount_paid, p.payment_status,
                       COALESCE((SELECT SUM(a.amount) FROM tblpayment_allocation a WHERE a.payment_id = p.payment_id), 0) AS net_amount,
                       EXISTS(SELECT 1 FROM tblpayment_allocation a WHERE a.payment_id = p.payment_id AND a.amount < 0) AS has_reversals
                FROM tblpayment p
                INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment'
                INNER JOIN tblar_account ar ON ar.ar_account_id = p.ar_account_id
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                ORDER BY p.payment_id DESC LIMIT $size OFFSET $offset";
        return ["success" => true, "rows" => $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC),
                "total" => $total, "page" => $page, "page_size" => $size, "total_pages" => $pages];
    }

    /* Payment history. net_amount = original payment less reversals/adjustments. */
    public function getPayments($arAccountId, $from, $to, $limit) {
        include "connection-pdo.php";
        $sql = "SELECT p.payment_id, p.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, p.payment_date,
                       pt.type_name, p.reference_no, p.amount_paid, p.payment_status,
                       COALESCE((SELECT SUM(a.amount) FROM tblpayment_allocation a WHERE a.payment_id = p.payment_id), 0) AS net_amount,
                       CONCAT(e.first_name, ' ', e.last_name) AS recorded_by
                FROM tblpayment p
                INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment'
                INNER JOIN tblar_account ar ON ar.ar_account_id = p.ar_account_id
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                LEFT JOIN tblemployee e ON e.employee_id = p.created_by
                WHERE DATE(p.payment_date) BETWEEN :from AND :to";
        $params = [":from" => $from, ":to" => $to];
        if ((int)$arAccountId > 0) {
            $sql .= " AND p.ar_account_id = :ar";
            $params[":ar"] = (int)$arAccountId;
        }
        $limit = max(1, min((int)$limit, 500));
        $sql .= " ORDER BY p.payment_id DESC LIMIT " . $limit;
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPaymentDetail($paymentId) {
        include "connection-pdo.php";
        $stmt = $conn->prepare("SELECT p.payment_id, p.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, p.payment_date,
                                       pt.type_name, p.reference_no, p.amount_paid, p.payment_status,
                                       ar.current_balance AS account_balance,
                                       CONCAT(e.first_name, ' ', e.last_name) AS recorded_by
                                FROM tblpayment p
                                INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment'
                                INNER JOIN tblar_account ar ON ar.ar_account_id = p.ar_account_id
                                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                                INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                                LEFT JOIN tblemployee e ON e.employee_id = p.created_by
                                WHERE p.payment_id = :id");
        $stmt->bindValue(":id", (int)$paymentId, PDO::PARAM_INT);
        $stmt->execute();
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) return ["success" => false, "message" => "Payment not found."];

        $alloc = $conn->prepare("SELECT a.invoice_id, s.receipt_number, inv.due_date, inv.amount AS invoice_amount, SUM(a.amount) AS net_amount,
                                        SUM(CASE WHEN a.amount > 0 THEN a.amount ELSE 0 END) AS applied_amount
                                 FROM tblpayment_allocation a
                                 INNER JOIN tblinvoice inv ON inv.invoice_id = a.invoice_id
                                 INNER JOIN tblsale s ON s.sale_id = inv.sale_id
                                 WHERE a.payment_id = :id GROUP BY a.invoice_id, s.receipt_number, inv.due_date, inv.amount ORDER BY a.invoice_id");
        $alloc->bindValue(":id", (int)$paymentId, PDO::PARAM_INT);
        $alloc->execute();

        $rev = $conn->prepare("SELECT r.reversal_id, r.reversal_type, r.amount, r.reason, r.created_at,
                                      CONCAT(cb.first_name, ' ', cb.last_name) AS performed_by,
                                      CONCAT(ab.first_name, ' ', ab.last_name) AS authorized_by
                               FROM tblpayment_reversal r
                               INNER JOIN tblemployee cb ON cb.employee_id = r.created_by
                               INNER JOIN tblemployee ab ON ab.employee_id = r.authorized_by
                               WHERE r.payment_id = :id ORDER BY r.reversal_id");
        $rev->bindValue(":id", (int)$paymentId, PDO::PARAM_INT);
        $rev->execute();

        return ["success" => true, "payment" => $payment, "allocations" => $alloc->fetchAll(PDO::FETCH_ASSOC), "reversals" => $rev->fetchAll(PDO::FETCH_ASSOC)];
    }

    // Records one payment and allocates it across one or more credit invoices.
    // Everything (payment, allocations, balance, audit) is one transaction; the account row lock
    // serialises concurrent payments for the same customer.
    public function recordPayment($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $arId = (int)($data["ar_account_id"] ?? 0);
            arSyncAccountPolicy($conn, $arId); // policy catch-up in its own transaction, before the payment transaction opens
            $typeId = (int)($data["payment_type_id"] ?? 0);
            $token = trim((string)($data["token"] ?? ""));
            $reference = trim((string)($data["reference_no"] ?? ""));
            $amountCents = arCents($data["amount"] ?? 0);
            $allocInput = $data["allocations"] ?? [];

            if ($arId <= 0 || $typeId <= 0) throw new ArException("Customer and payment method are required.");
            if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $token)) throw new ArException("Invalid request token. Reload the page and try again.");
            if ($amountCents <= 0) throw new ArException("Payment amount must be greater than zero.");
            if (strlen($reference) > 50) throw new ArException("Reference number cannot exceed 50 characters.");
            if (!is_array($allocInput) || count($allocInput) === 0) throw new ArException("Allocate the payment to at least one credit sale.");

            $alloc = [];
            foreach ($allocInput as $row) {
                $invId = (int)($row["invoice_id"] ?? 0);
                $cents = arCents($row["amount"] ?? 0);
                if ($invId <= 0 || $cents <= 0) throw new ArException("Each allocation needs an invoice and an amount greater than zero.");
                if (isset($alloc[$invId])) throw new ArException("The same credit sale appears more than once in the allocation.");
                $alloc[$invId] = $cents;
            }
            if (array_sum($alloc) !== $amountCents) {
                throw new ArException("Allocated total (" . number_format(array_sum($alloc) / 100, 2) . ") must equal the payment amount (" . number_format($amountCents / 100, 2) . ").");
            }

            $employeeId = (int)$_SESSION["employee_id"];
            // READ COMMITTED: after the account row is locked, every check sees the latest committed payments/allocations.
            $conn->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $conn->beginTransaction();

            $acc = $conn->prepare("SELECT ar_account_id, current_balance, account_status FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $acc->bindValue(":id", $arId, PDO::PARAM_INT);
            $acc->execute();
            $account = $acc->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new ArException("AR account not found.");
            if ($account["account_status"] !== "Active") throw new ArException("Payments can only be recorded on an Active AR account.");

            // Duplicate check 1: same request token already stored.
            $dup = $conn->prepare("SELECT payment_id FROM tblpayment WHERE idempotency_token = :t");
            $dup->bindValue(":t", $token, PDO::PARAM_STR);
            $dup->execute();
            if ($dup->fetch()) throw new ArException("This payment was already recorded. Refresh the payment list to check.");

            $typeStmt = $conn->prepare("SELECT type_name, is_active FROM tblpayment_type WHERE payment_type_id = :id");
            $typeStmt->bindValue(":id", $typeId, PDO::PARAM_INT);
            $typeStmt->execute();
            $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$type || (int)$type["is_active"] !== 1) throw new ArException("Selected payment method is not active.");
            $typeName = strtolower(trim($type["type_name"]));
            if (in_array($typeName, $this->excludedMethods, true)) throw new ArException("This payment method cannot be used to settle AR.");
            if ($typeName !== "cash" && $reference === "") throw new ArException("A reference number is required for " . $type["type_name"] . " payments.");

            // Duplicate check 2: the same non-cash reference cannot be used twice (reversed payments excluded).
            if ($reference !== "") {
                $refStmt = $conn->prepare("SELECT payment_id FROM tblpayment WHERE payment_type_id = :t AND reference_no = :r AND ar_account_id IS NOT NULL AND payment_status <> 'Reversed' LIMIT 1");
                $refStmt->bindValue(":t", $typeId, PDO::PARAM_INT);
                $refStmt->bindValue(":r", $reference, PDO::PARAM_STR);
                $refStmt->execute();
                if ($refStmt->fetch()) throw new ArException("Reference number already used on payment for this method.");
            }

            if ($amountCents > arCents($account["current_balance"])) {
                throw new ArException("Payment exceeds the account's current balance (" . number_format((float)$account["current_balance"], 2) . "). Overpayments are not accepted.");
            }

            // Lock and validate every target invoice; outstanding is recomputed after the lock.
            $ids = array_keys($alloc);
            $in = implode(",", array_fill(0, count($ids), "?"));
            $invStmt = $conn->prepare("SELECT inv.invoice_id, inv.amount, inv.due_date, s.receipt_number FROM tblinvoice inv
                                       INNER JOIN tblsale s ON s.sale_id = inv.sale_id
                                       WHERE inv.invoice_id IN ($in) AND inv.ar_account_id = ? AND inv.transaction_type = 'Credit' AND s.sale_status = 'Completed'
                                       FOR UPDATE");
            $invStmt->execute(array_merge($ids, [$arId]));
            $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($invoices) !== count($ids)) throw new ArException("One or more selected credit sales are not valid for this customer (voided or not credit).");

            $invMeta = [];
            foreach ($invoices as $inv) $invMeta[(int)$inv["invoice_id"]] = $inv;

            $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM tblpayment_allocation WHERE invoice_id = :id");
            $outBefore = [];
            foreach ($invoices as $inv) {
                $paidStmt->bindValue(":id", (int)$inv["invoice_id"], PDO::PARAM_INT);
                $paidStmt->execute();
                $outstanding = arCents($inv["amount"]) - arCents($paidStmt->fetchColumn());
                $outBefore[(int)$inv["invoice_id"]] = $outstanding;
                if ($alloc[(int)$inv["invoice_id"]] > $outstanding) {
                    throw new ArException("Over-allocation: invoice #" . $inv["invoice_id"] . " only has " . number_format($outstanding / 100, 2) . " outstanding.");
                }
            }

            $payDoc = $conn->prepare("INSERT INTO tblinvoice (ar_account_id, sale_id, transaction_type, amount, due_date) VALUES (:ar, NULL, 'Payment', :amt, NULL)");
            $payDoc->bindValue(":ar", $arId, PDO::PARAM_INT);
            $payDoc->bindValue(":amt", arMoney($amountCents));
            $payDoc->execute();
            $docId = (int)$conn->lastInsertId();

            $pay = $conn->prepare("INSERT INTO tblpayment (ar_account_id, payment_type_id, invoice_id, payment_date, amount_paid, reference_no, payment_status, created_by, idempotency_token)
                                   VALUES (:ar, :t, :doc, NOW(), :amt, :ref, 'Posted', :emp, :tok)");
            $pay->bindValue(":ar", $arId, PDO::PARAM_INT);
            $pay->bindValue(":t", $typeId, PDO::PARAM_INT);
            $pay->bindValue(":doc", $docId, PDO::PARAM_INT);
            $pay->bindValue(":amt", arMoney($amountCents));
            $pay->bindValue(":ref", $reference === "" ? null : $reference, $reference === "" ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $pay->bindValue(":emp", $employeeId, PDO::PARAM_INT);
            $pay->bindValue(":tok", $token, PDO::PARAM_STR);
            $pay->execute();
            $paymentId = (int)$conn->lastInsertId();

            $allocIns = $conn->prepare("INSERT INTO tblpayment_allocation (payment_id, invoice_id, amount, created_by) VALUES (:p, :i, :a, :e)");
            foreach ($alloc as $invId => $cents) {
                $allocIns->bindValue(":p", $paymentId, PDO::PARAM_INT);
                $allocIns->bindValue(":i", $invId, PDO::PARAM_INT);
                $allocIns->bindValue(":a", arMoney($cents));
                $allocIns->bindValue(":e", $employeeId, PDO::PARAM_INT);
                $allocIns->execute();
            }

            $bal = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance - :amt WHERE ar_account_id = :id");
            $bal->bindValue(":amt", arMoney($amountCents));
            $bal->bindValue(":id", $arId, PDO::PARAM_INT);
            $bal->execute();

            $afterAlloc = [];
            foreach ($alloc as $invId => $cents) $afterAlloc[] = ["invoice_id" => $invId, "amount" => arMoney($cents)];
            arAudit($conn, "payment", $paymentId, "Recorded", $arId,
                ["balance" => (float)$account["current_balance"]],
                ["balance" => arMoney(arCents($account["current_balance"]) - $amountCents), "amount" => arMoney($amountCents), "method" => $type["type_name"], "reference" => $reference, "allocations" => $afterAlloc],
                null, $employeeId);

            $conn->commit();

            // Receipt data for the printable AR payment receipt (fresh copy; reprints use getPaymentDetail).
            $custStmt = $conn->prepare("SELECT CONCAT(c.first_name, ' ', c.last_name) FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id WHERE ar.ar_account_id = :id");
            $custStmt->bindValue(":id", $arId, PDO::PARAM_INT);
            $custStmt->execute();
            $customerName = (string)$custStmt->fetchColumn();
            $payStmt = $conn->prepare("SELECT p.payment_date, CONCAT(e.first_name, ' ', e.last_name) AS recorded_by FROM tblpayment p LEFT JOIN tblemployee e ON e.employee_id = p.created_by WHERE p.payment_id = :id");
            $payStmt->bindValue(":id", $paymentId, PDO::PARAM_INT);
            $payStmt->execute();
            $payRow = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $lines = [];
            foreach ($alloc as $invId => $centsVal) {
                $meta = $invMeta[$invId] ?? [];
                $lines[] = [
                    "receipt_number" => $meta["receipt_number"] ?? "",
                    "due_date" => $meta["due_date"] ?? null,
                    "invoice_amount" => arMoney(arCents($meta["amount"] ?? 0)),
                    "applied" => arMoney($centsVal),
                    "remaining_after" => arMoney(($outBefore[$invId] ?? $centsVal) - $centsVal),
                ];
            }
            $receipt = [
                "payment_id" => $paymentId,
                "customer_name" => $customerName,
                "payment_date" => $payRow["payment_date"] ?? null,
                "recorded_by" => $payRow["recorded_by"] ?? "",
                "type_name" => $type["type_name"],
                "reference_no" => $reference === "" ? null : $reference,
                "amount" => arMoney($amountCents),
                "balance_before" => (float)$account["current_balance"],
                "balance_after" => arMoney(arCents($account["current_balance"]) - $amountCents),
                "lines" => $lines,
            ];
            return ["success" => true, "message" => "Payment of ₱" . number_format($amountCents / 100, 2) . " recorded.", "payment_id" => $paymentId, "receipt" => $receipt];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return ["success" => false, "message" => "This payment was already recorded."];
            return ["success" => false, "message" => "Failed to record the payment. Nothing was changed."];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to record the payment. Nothing was changed."];
        }
    }

    public function reversePayment($json) {
        return $this->correctPayment($json, "Reversal");
    }

    public function adjustPayment($json) {
        return $this->correctPayment($json, "Adjustment");
    }

    // Reversal = undo the whole payment. Adjustment = undo part of it, per invoice.
    // Both append negative allocation rows; the original payment row is never edited or deleted.
    private function correctPayment($json, $kind) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $paymentId = (int)($data["payment_id"] ?? 0);
            if ($paymentId <= 0) throw new ArException("Payment ID is required.");
            $reason = arReason($data);
            $approver = arResolveApprover($conn, $data, ["Administrator", "Supervisor"]);
            $employeeId = (int)$_SESSION["employee_id"];

            $requested = [];
            if ($kind === "Adjustment") {
                foreach (($data["adjustments"] ?? []) as $row) {
                    $invId = (int)($row["invoice_id"] ?? 0);
                    $cents = arCents($row["amount"] ?? 0);
                    if ($cents < 0 || $invId <= 0) throw new ArException("Invalid adjustment line.");
                    if ($cents === 0) continue;
                    if (isset($requested[$invId])) throw new ArException("Duplicate invoice in adjustment.");
                    $requested[$invId] = $cents;
                }
                if (count($requested) === 0) throw new ArException("Enter an amount to take back on at least one credit sale.");
            }

            // READ COMMITTED: after the account row is locked, every check sees the latest committed payments/allocations.
            $conn->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $conn->beginTransaction();

            // Lock order is always account first, then payment.
            $find = $conn->prepare("SELECT p.ar_account_id FROM tblpayment p INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment' WHERE p.payment_id = :id");
            $find->bindValue(":id", $paymentId, PDO::PARAM_INT);
            $find->execute();
            $arId = (int)$find->fetchColumn();
            if ($arId <= 0) throw new ArException("AR payment not found.");

            $acc = $conn->prepare("SELECT current_balance FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $acc->bindValue(":id", $arId, PDO::PARAM_INT);
            $acc->execute();
            $account = $acc->fetch(PDO::FETCH_ASSOC);

            $lock = $conn->prepare("SELECT payment_status FROM tblpayment WHERE payment_id = :id FOR UPDATE");
            $lock->bindValue(":id", $paymentId, PDO::PARAM_INT);
            $lock->execute();
            $status = $lock->fetchColumn();
            if ($status === "Reversed") throw new ArException("This payment has already been fully reversed.");

            $netStmt = $conn->prepare("SELECT invoice_id, SUM(amount) AS net FROM tblpayment_allocation WHERE payment_id = :id GROUP BY invoice_id HAVING SUM(amount) > 0");
            $netStmt->bindValue(":id", $paymentId, PDO::PARAM_INT);
            $netStmt->execute();
            $net = [];
            foreach ($netStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $net[(int)$r["invoice_id"]] = arCents($r["net"]);
            if (count($net) === 0) throw new ArException("Nothing remains to reverse on this payment.");

            $take = [];
            if ($kind === "Reversal") {
                $take = $net;
            } else {
                foreach ($requested as $invId => $cents) {
                    if (!isset($net[$invId])) throw new ArException("Invoice #$invId has nothing left to adjust on this payment.");
                    if ($cents > $net[$invId]) throw new ArException("Adjustment on invoice #$invId exceeds the amount applied (" . number_format($net[$invId] / 100, 2) . ").");
                    $take[$invId] = $cents;
                }
            }
            $totalCents = array_sum($take);
            $remaining = array_sum($net) - $totalCents;

            $revIns = $conn->prepare("INSERT INTO tblpayment_reversal (payment_id, reversal_type, amount, reason, authorized_by, created_by) VALUES (:p, :t, :a, :r, :au, :e)");
            $revIns->bindValue(":p", $paymentId, PDO::PARAM_INT);
            $revIns->bindValue(":t", $kind, PDO::PARAM_STR);
            $revIns->bindValue(":a", arMoney($totalCents));
            $revIns->bindValue(":r", $reason, PDO::PARAM_STR);
            $revIns->bindValue(":au", $approver["employee_id"], PDO::PARAM_INT);
            $revIns->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $revIns->execute();
            $reversalId = (int)$conn->lastInsertId();

            $allocIns = $conn->prepare("INSERT INTO tblpayment_allocation (payment_id, invoice_id, amount, reversal_id, created_by) VALUES (:p, :i, :a, :rv, :e)");
            foreach ($take as $invId => $cents) {
                $allocIns->bindValue(":p", $paymentId, PDO::PARAM_INT);
                $allocIns->bindValue(":i", $invId, PDO::PARAM_INT);
                $allocIns->bindValue(":a", arMoney(-$cents));
                $allocIns->bindValue(":rv", $reversalId, PDO::PARAM_INT);
                $allocIns->bindValue(":e", $employeeId, PDO::PARAM_INT);
                $allocIns->execute();
            }

            $bal = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance + :amt WHERE ar_account_id = :id");
            $bal->bindValue(":amt", arMoney($totalCents));
            $bal->bindValue(":id", $arId, PDO::PARAM_INT);
            $bal->execute();

            $newStatus = $remaining > 0 ? "Adjusted" : "Reversed";
            $st = $conn->prepare("UPDATE tblpayment SET payment_status = :s WHERE payment_id = :id");
            $st->bindValue(":s", $newStatus, PDO::PARAM_STR);
            $st->bindValue(":id", $paymentId, PDO::PARAM_INT);
            $st->execute();

            $lines = [];
            foreach ($take as $invId => $cents) $lines[] = ["invoice_id" => $invId, "amount" => arMoney($cents)];
            arAudit($conn, strtolower($kind), $reversalId, $kind === "Reversal" ? "Reversed" : "Adjusted", $arId,
                ["balance" => (float)$account["current_balance"], "payment_status" => $status, "applied" => arMoney(array_sum($net))],
                ["balance" => arMoney(arCents($account["current_balance"]) + $totalCents), "payment_status" => $newStatus, "applied" => arMoney($remaining), "taken_back" => $lines],
                $reason, $employeeId, $approver["employee_id"]);

            $conn->commit();
            return ["success" => true, "payment_id" => $paymentId,
                    "message" => ($kind === "Reversal" ? "Payment reversed" : "Payment adjusted") . " (₱" . number_format($totalCents / 100, 2) . " returned to balance). Approved by " . $approver["name"] . ". Reason: " . $reason];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to save the correction. Nothing was changed."];
        }
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_POST["json"] ?? "";
$isPost = ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST";
$writeOps = ["recordPayment", "reversePayment", "adjustPayment"];

if (in_array($operation, $writeOps, true) && !$isPost) {
    echo json_encode(["success" => false, "message" => "This operation requires POST."]);
    exit;
}

function arRange() {
    try {
        return arDateRange($_GET["date_from"] ?? "", $_GET["date_to"] ?? "");
    } catch (ArException $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }
}

$obj = new ArPayment();

try {
    switch ($operation) {
        case "getPaymentMethods": echo json_encode($obj->getPaymentMethods()); break;
        case "getPayableAccounts": echo json_encode($obj->getPayableAccounts()); break;
        case "getOpenInvoices": echo json_encode($obj->getOpenInvoices($_GET["ar_account_id"] ?? 0)); break;
        case "getPayments":
            [$from, $to] = arRange();
            echo json_encode($obj->getPayments($_GET["ar_account_id"] ?? 0, $from, $to, $_GET["limit"] ?? 200));
            break;
        case "getPaymentsPage": echo json_encode($obj->getPaymentsPage($_GET["page"] ?? 1, $_GET["page_size"] ?? 5)); break;
        case "getPaymentDetail": echo json_encode($obj->getPaymentDetail($_GET["payment_id"] ?? 0)); break;
        case "recordPayment": echo json_encode($obj->recordPayment($json)); break;
        case "reversePayment": echo json_encode($obj->reversePayment($json)); break;
        case "adjustPayment": echo json_encode($obj->adjustPayment($json)); break;
        default: echo json_encode(["success" => false, "message" => "Invalid operation."]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Unable to complete the request."]);
}
