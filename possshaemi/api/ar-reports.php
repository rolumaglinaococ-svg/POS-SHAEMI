<?php
require_once "session-guard.php";
requireRole(["Administrator", "Manager"]);
require_once "ar-common.php";
header("Content-Type: application/json");

/* Read-only AR reporting. AR cash flow only: no supplier, expense, inventory or whole-business figures.
 *
 * ACTUAL collections  = tblpayment_allocation rows, net of reversals, by the date they were posted.
 * EXPECTED collections = outstanding credit-invoice balances by their existing due_date (an estimate).
 */
class ArReport {

    private function dayList($from, $to) {
        $days = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $days[] = date("Y-m-d", $t);
        return $days;
    }

    public function getDashboard($from, $to) {
        include "connection-pdo.php";
        arSyncAllOpenAccounts($conn); // late fee / auto-hold policy catch-up (idempotent)
        $open = arOpenInvoicesSql();

        $tot = $conn->query("SELECT COALESCE(SUM(outstanding), 0) AS total_outstanding,
                                    COALESCE(SUM(CASE WHEN days_overdue > 0 THEN outstanding END), 0) AS total_overdue,
                                    COUNT(DISTINCT CASE WHEN days_overdue > 0 THEN ar_account_id END) AS overdue_accounts,
                                    COUNT(DISTINCT ar_account_id) AS accounts_with_balance
                             FROM ($open) oi WHERE oi.outstanding > 0")->fetch(PDO::FETCH_ASSOC);

        $p = [":from" => $from, ":to" => $to];

        $act = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount END), 0) AS gross_collected,
                                      COALESCE(-SUM(CASE WHEN amount < 0 THEN amount END), 0) AS reversed,
                                      COALESCE(SUM(amount), 0) AS net_collected
                               FROM tblpayment_allocation WHERE DATE(created_at) BETWEEN :from AND :to");
        $act->execute($p);
        $actual = $act->fetch(PDO::FETCH_ASSOC);

        $cnt = $conn->prepare("SELECT COUNT(*) FROM tblpayment WHERE ar_account_id IS NOT NULL AND invoice_id IN (SELECT invoice_id FROM tblinvoice WHERE transaction_type = 'Payment') AND DATE(payment_date) BETWEEN :from AND :to");
        $cnt->execute($p);
        $actual["payment_count"] = (int)$cnt->fetchColumn();

        $meth = $conn->prepare("SELECT pt.type_name, COALESCE(SUM(a.amount), 0) AS amount
                                FROM tblpayment_allocation a
                                INNER JOIN tblpayment pay ON pay.payment_id = a.payment_id
                                INNER JOIN tblpayment_type pt ON pt.payment_type_id = pay.payment_type_id
                                WHERE DATE(a.created_at) BETWEEN :from AND :to
                                GROUP BY pt.payment_type_id, pt.type_name ORDER BY amount DESC");
        $meth->execute($p);

        $exp = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN COALESCE(due_date, DATE(transaction_date)) BETWEEN :from AND :to THEN outstanding END), 0) AS due_in_range,
                                      COALESCE(SUM(CASE WHEN COALESCE(due_date, DATE(transaction_date)) < :from2 THEN outstanding END), 0) AS overdue_before_range
                               FROM ($open) oi WHERE oi.outstanding > 0");
        $exp->execute([":from" => $from, ":to" => $to, ":from2" => $from]);
        $expected = $exp->fetch(PDO::FETCH_ASSOC);

        $dailyAct = $conn->prepare("SELECT DATE(created_at) AS d, SUM(amount) AS v FROM tblpayment_allocation WHERE DATE(created_at) BETWEEN :from AND :to GROUP BY DATE(created_at)");
        $dailyAct->execute($p);
        $a = []; foreach ($dailyAct->fetchAll(PDO::FETCH_ASSOC) as $r) $a[$r["d"]] = (float)$r["v"];

        $dailyExp = $conn->prepare("SELECT COALESCE(due_date, DATE(transaction_date)) AS d, SUM(outstanding) AS v FROM ($open) oi
                                    WHERE oi.outstanding > 0 AND COALESCE(due_date, DATE(transaction_date)) BETWEEN :from AND :to GROUP BY d");
        $dailyExp->execute($p);
        $e = []; foreach ($dailyExp->fetchAll(PDO::FETCH_ASSOC) as $r) $e[$r["d"]] = (float)$r["v"];

        $daily = [];
        foreach ($this->dayList($from, $to) as $d) $daily[] = ["date" => $d, "actual" => round($a[$d] ?? 0, 2), "expected" => round($e[$d] ?? 0, 2)];

        // Monthly: 12 months ending with the month of $to.
        $mStart = date("Y-m-01", strtotime($to . " -11 months"));
        $mEnd = date("Y-m-t", strtotime($to));
        $mAct = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(amount) AS v FROM tblpayment_allocation WHERE DATE(created_at) BETWEEN :s AND :e GROUP BY m");
        $mAct->execute([":s" => $mStart, ":e" => $mEnd]);
        $ma = []; foreach ($mAct->fetchAll(PDO::FETCH_ASSOC) as $r) $ma[$r["m"]] = (float)$r["v"];
        $mExp = $conn->prepare("SELECT DATE_FORMAT(COALESCE(due_date, DATE(transaction_date)), '%Y-%m') AS m, SUM(outstanding) AS v FROM ($open) oi
                                WHERE oi.outstanding > 0 AND COALESCE(due_date, DATE(transaction_date)) BETWEEN :s AND :e GROUP BY m");
        $mExp->execute([":s" => $mStart, ":e" => $mEnd]);
        $me = []; foreach ($mExp->fetchAll(PDO::FETCH_ASSOC) as $r) $me[$r["m"]] = (float)$r["v"];
        $monthly = [];
        for ($i = 0; $i < 12; $i++) {
            $m = date("Y-m", strtotime($mStart . " +$i months"));
            $monthly[] = ["month" => $m, "actual" => round($ma[$m] ?? 0, 2), "expected" => round($me[$m] ?? 0, 2)];
        }

        // Collections today: net allocation movements recorded today (reversals
        // reduce today; approved memos never count — they are not collections).
        $today = $conn->query("SELECT COALESCE(SUM(amount), 0) AS net_collected,
                                      (SELECT COUNT(*) FROM tblpayment WHERE ar_account_id IS NOT NULL
                                        AND invoice_id IN (SELECT invoice_id FROM tblinvoice WHERE transaction_type = 'Payment')
                                        AND DATE(payment_date) = CURDATE()) AS payment_count
                               FROM tblpayment_allocation WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC);

        // Accounts with overdue invoices, worst first (dashboard follow-up list).
        $od = $conn->query("SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                                   SUM(oi.outstanding) AS overdue_amount, MIN(oi.due_date) AS oldest_due, MAX(oi.days_overdue) AS max_days_overdue
                            FROM ($open) oi
                            INNER JOIN tblar_account ar ON ar.ar_account_id = oi.ar_account_id
                            INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                            WHERE oi.outstanding > 0 AND oi.days_overdue > 0
                            GROUP BY ar.ar_account_id, c.first_name, c.last_name
                            ORDER BY overdue_amount DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

        // Invoices falling due in the next 7 days (including today).
        $up = $conn->query("SELECT oi.invoice_id, oi.receipt_number, oi.due_date, oi.outstanding,
                                   CONCAT(c.first_name, ' ', c.last_name) AS customer_name
                            FROM ($open) oi
                            INNER JOIN tblar_account ar ON ar.ar_account_id = oi.ar_account_id
                            INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                            WHERE oi.outstanding > 0 AND COALESCE(oi.due_date, DATE(oi.transaction_date)) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                            ORDER BY oi.due_date ASC, oi.outstanding DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

        return ["success" => true, "date_from" => $from, "date_to" => $to, "totals" => $tot, "actual" => $actual,
                "collected_today" => $today, "overdue_accounts_list" => $od, "upcoming_due" => $up,
                "actual_by_method" => $meth->fetchAll(PDO::FETCH_ASSOC), "expected" => $expected,
                "daily" => $daily, "monthly" => $monthly, "mismatch_count" => $this->mismatchCount($conn)];
    }

    private function mismatchCount($conn) {
        $sql = "SELECT COUNT(*) FROM tblar_account ar
                LEFT JOIN (SELECT ar_account_id, SUM(outstanding) AS o FROM (" . arOpenInvoicesSql() . ") x WHERE outstanding > 0 GROUP BY ar_account_id) t ON t.ar_account_id = ar.ar_account_id
                WHERE ABS(ar.current_balance - COALESCE(t.o, 0)) > 0.004";
        return (int)$conn->query($sql)->fetchColumn();
    }

    /* Per-customer credit position. Used by credit monitoring, overdue list and customer balances. */
    public function getCreditMonitor($keyword, $mode) {
        include "connection-pdo.php";
        arSyncAllOpenAccounts($conn); // late fee / auto-hold policy catch-up (idempotent)
        $holdCols = arHoldColumnsExist($conn) ? ", ar.credit_hold_source, ar.credit_hold_at, ar.credit_hold_invoice_id" : "";
        $sql = "SELECT ar.ar_account_id, c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.contact_number, ct.term_name, ct.due_days,
                       ar.credit_limit, ar.current_balance, (ar.credit_limit - ar.current_balance) AS available_credit,
                       ar.credit_status, ar.account_status" . $holdCols . ",
                       COALESCE(SUM(oi.outstanding), 0) AS outstanding,
                       COALESCE(SUM(CASE WHEN oi.days_overdue > 0 THEN oi.outstanding END), 0) AS overdue_amount,
                       MAX(CASE WHEN oi.days_overdue > 0 THEN oi.days_overdue END) AS max_days_overdue,
                       MIN(COALESCE(oi.due_date, DATE(oi.transaction_date))) AS earliest_due_date,
                       COUNT(oi.invoice_id) AS unpaid_invoice_count,
                       COALESCE(SUM(CASE WHEN oi.days_overdue > 0 THEN 1 ELSE 0 END), 0) AS overdue_invoice_count,
                       MIN(oi.transaction_date) AS oldest_outstanding_invoice_date,
                       (SELECT MAX(p.payment_date) FROM tblpayment p WHERE p.ar_account_id = ar.ar_account_id) AS last_payment_date
                FROM tblar_account ar
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                INNER JOIN tblcredit_term ct ON ct.credit_term_id = ar.credit_term_id
                LEFT JOIN (" . arOpenInvoicesSql() . ") oi ON oi.ar_account_id = ar.ar_account_id AND oi.outstanding > 0
                WHERE ar.account_status = 'Active'";
        if ($keyword !== "") $sql .= " AND (c.first_name LIKE :k OR c.last_name LIKE :k)";
        $sql .= " GROUP BY ar.ar_account_id, c.customer_id, c.first_name, c.last_name, c.contact_number, ct.term_name, ct.due_days, ar.credit_limit, ar.current_balance, ar.credit_status, ar.account_status" . ($holdCols !== "" ? ", ar.credit_hold_source, ar.credit_hold_at, ar.credit_hold_invoice_id" : "");
        if ($mode === "overdue") $sql .= " HAVING overdue_amount > 0 ORDER BY max_days_overdue DESC, overdue_amount DESC";
        elseif ($mode === "overlimit") $sql .= " HAVING available_credit < 0 ORDER BY available_credit ASC";
        elseif ($mode === "hold") $sql .= " HAVING credit_status IN ('Hold', 'Watch') ORDER BY credit_status DESC, customer_name";
        else $sql .= " ORDER BY overdue_amount DESC, current_balance DESC, customer_name ASC";
        $stmt = $conn->prepare($sql);
        if ($keyword !== "") $stmt->bindValue(":k", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAging() {
        include "connection-pdo.php";
        arSyncAllOpenAccounts($conn); // late fee / auto-hold policy catch-up (idempotent)
        $sql = "SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       SUM(CASE WHEN oi.days_overdue <= 0 THEN oi.outstanding ELSE 0 END) AS current_amt,
                       SUM(CASE WHEN oi.days_overdue BETWEEN 1 AND 30 THEN oi.outstanding ELSE 0 END) AS d1_30,
                       SUM(CASE WHEN oi.days_overdue BETWEEN 31 AND 60 THEN oi.outstanding ELSE 0 END) AS d31_60,
                       SUM(CASE WHEN oi.days_overdue BETWEEN 61 AND 90 THEN oi.outstanding ELSE 0 END) AS d61_90,
                       SUM(CASE WHEN oi.days_overdue > 90 THEN oi.outstanding ELSE 0 END) AS d90_plus,
                       SUM(oi.outstanding) AS total
                FROM (" . arOpenInvoicesSql() . ") oi
                INNER JOIN tblar_account ar ON ar.ar_account_id = oi.ar_account_id
                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                WHERE oi.outstanding > 0
                GROUP BY ar.ar_account_id, c.first_name, c.last_name
                ORDER BY d90_plus DESC, d61_90 DESC, total DESC";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $t = ["current_amt" => 0, "d1_30" => 0, "d31_60" => 0, "d61_90" => 0, "d90_plus" => 0, "total" => 0];
        foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += (float)$r[$k];
        foreach ($t as $k => $v) $t[$k] = round($v, 2);
        return ["success" => true, "as_of" => date("Y-m-d"), "rows" => $rows, "totals" => $t];
    }

    public function getSummary() {
        include "connection-pdo.php";
        $acc = $conn->query("SELECT SUM(account_status = 'Active') AS active_accounts, SUM(account_status = 'Pending') AS pending_accounts,
                                    SUM(account_status = 'Active' AND credit_status = 'Hold') AS hold_accounts,
                                    SUM(account_status = 'Active' AND credit_status = 'Watch') AS watch_accounts,
                                    COALESCE(SUM(CASE WHEN account_status = 'Active' THEN credit_limit END), 0) AS total_credit_limit,
                                    COALESCE(SUM(CASE WHEN account_status = 'Active' THEN current_balance END), 0) AS total_balance
                             FROM tblar_account")->fetch(PDO::FETCH_ASSOC);
        $open = $conn->query("SELECT COALESCE(SUM(outstanding), 0) AS total_outstanding,
                                     COALESCE(SUM(CASE WHEN days_overdue > 0 THEN outstanding END), 0) AS total_overdue,
                                     COUNT(*) AS open_invoices,
                                     COUNT(DISTINCT CASE WHEN days_overdue > 0 THEN ar_account_id END) AS overdue_accounts
                              FROM (" . arOpenInvoicesSql() . ") oi WHERE oi.outstanding > 0")->fetch(PDO::FETCH_ASSOC);
        $mtd = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM tblpayment_allocation WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
        return ["success" => true, "as_of" => date("Y-m-d"), "accounts" => $acc, "receivables" => $open,
                "collected_this_month" => round((float)$mtd, 2), "mismatch_count" => $this->mismatchCount($conn)];
    }

    public function getCollectionReport($from, $to) {
        include "connection-pdo.php";
        $p = [":from" => $from, ":to" => $to];
        $list = $conn->prepare("SELECT p.payment_id, p.payment_date, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, pt.type_name, p.reference_no,
                                       p.amount_paid, p.payment_status,
                                       COALESCE((SELECT SUM(a.amount) FROM tblpayment_allocation a WHERE a.payment_id = p.payment_id), 0) AS net_amount,
                                       CONCAT(e.first_name, ' ', e.last_name) AS recorded_by
                                FROM tblpayment p
                                INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment'
                                INNER JOIN tblar_account ar ON ar.ar_account_id = p.ar_account_id
                                INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                                INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                                LEFT JOIN tblemployee e ON e.employee_id = p.created_by
                                WHERE DATE(p.payment_date) BETWEEN :from AND :to ORDER BY p.payment_date ASC, p.payment_id ASC");
        $list->execute($p);
        $payments = $list->fetchAll(PDO::FETCH_ASSOC);

        $dash = $this->getDashboard($from, $to);
        return ["success" => true, "date_from" => $from, "date_to" => $to, "payments" => $payments, "actual" => $dash["actual"],
                "by_method" => $dash["actual_by_method"], "daily" => $dash["daily"], "monthly" => $dash["monthly"]];
    }

    /* Due-invoice list for the AR Manager. Outstanding = remaining balance
     * (outstanding from arOpenInvoicesSql) > 0. Date buckets use the
     * EFFECTIVE due date = COALESCE(due_date, DATE(transaction_date)), the
     * same fallback the dashboard uses; this_week is Monday-Sunday and
     * this_month is the current calendar month. Fully paid invoices never
     * appear here (they are not due); payment_status is still derived per
     * the contract so the same column can be reused elsewhere.
     * days_overdue is DATEDIFF(CURDATE(), effective due) when overdue, else 0.
     * amount_paid = original + approved debit memos - approved credit memos
     *               - remaining, which equals net allocations to the invoice.
     */
    public function getDueInvoices($status, $from, $to, $keyword, $page, $limit) {
        include "connection-pdo.php";
        arSyncAllOpenAccounts($conn); // late fee / auto-hold policy catch-up (idempotent)
        $page = max(1, (int)$page);
        $limit = (int)$limit;
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;
        $status = in_array($status, ["all_outstanding", "not_yet_due", "due_today", "overdue", "this_week", "this_month", "custom"], true) ? $status : "all_outstanding";

        $open = arOpenInvoicesSql();
        $effDue = "COALESCE(oi.due_date, DATE(oi.transaction_date))";
        $where = "oi.outstanding > 0";
        $params = [];
        switch ($status) {
            case "not_yet_due": $where .= " AND $effDue > CURDATE()"; break;
            case "due_today": $where .= " AND $effDue = CURDATE()"; break;
            case "overdue": $where .= " AND $effDue < CURDATE()"; break;
            case "this_week": $where .= " AND $effDue BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY)"; break;
            case "this_month": $where .= " AND $effDue BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"; break;
            case "custom":
                $from = trim((string)$from);
                $to = trim((string)$to);
                if (!arValidDate($from) || !arValidDate($to) || $from > $to) {
                    return ["success" => false, "message" => "Choose a valid custom date range."];
                }
                $where .= " AND $effDue BETWEEN :from AND :to";
                $params[":from"] = $from;
                $params[":to"] = $to;
                break;
            default: break; // all_outstanding
        }
        if ($keyword !== "") {
            $where .= " AND (CONCAT(c.first_name, ' ', c.last_name) LIKE :k OR oi.receipt_number LIKE :k)";
            $params[":k"] = "%" . $keyword . "%";
        }
        $base = "FROM ($open) oi
                 INNER JOIN tblar_account ar ON ar.ar_account_id = oi.ar_account_id
                 INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                 WHERE $where";

        $countStmt = $conn->prepare("SELECT COUNT(*) $base");
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $paidExpr = "(oi.amount + oi.approved_debit_memos - oi.approved_credit_memos - oi.outstanding)";
        $rowsStmt = $conn->prepare("SELECT oi.invoice_id, oi.receipt_number AS invoice_number, oi.ar_account_id, ar.customer_id,
                                           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                                           oi.transaction_date AS invoice_date, $effDue AS due_date,
                                           oi.amount AS original_amount, $paidExpr AS amount_paid,
                                           oi.outstanding AS remaining_balance,
                                           CASE WHEN $effDue < CURDATE() THEN DATEDIFF(CURDATE(), $effDue) ELSE 0 END AS days_overdue,
                                           CASE WHEN oi.outstanding <= 0 THEN 'Paid'
                                                WHEN $effDue < CURDATE() THEN 'Overdue'
                                                WHEN $paidExpr > 0 THEN 'Partial'
                                                ELSE 'Unpaid' END AS payment_status
                                    $base ORDER BY $effDue ASC, customer_name ASC LIMIT $limit OFFSET " . (($page - 1) * $limit));
        foreach ($params as $k => $v) $rowsStmt->bindValue($k, $v, PDO::PARAM_STR);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r["original_amount"] = round((float)$r["original_amount"], 2);
            $r["amount_paid"] = round((float)$r["amount_paid"], 2);
            $r["remaining_balance"] = round((float)$r["remaining_balance"], 2);
            $r["days_overdue"] = (int)$r["days_overdue"];
        }
        unset($r);
        return ["success" => true, "rows" => $rows, "total" => $total, "page" => $page, "limit" => $limit, "status" => $status];
    }

    public function getStatement($arAccountId) {
        include "connection-pdo.php";
        arSyncAccountPolicy($conn, (int)$arAccountId); // late fee / auto-hold catch-up for this account
        $id = (int)$arAccountId;
        $acc = $conn->prepare("SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, ct.term_name, ar.credit_limit, ar.current_balance, ar.credit_status
                               FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id INNER JOIN tblcredit_term ct ON ct.credit_term_id = ar.credit_term_id
                               WHERE ar.ar_account_id = :id");
        $acc->bindValue(":id", $id, PDO::PARAM_INT);
        $acc->execute();
        $account = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$account) return ["success" => false, "message" => "AR account not found."];

        $inv = $conn->prepare("SELECT oi.invoice_id, oi.receipt_number, oi.sale_date, oi.due_date, oi.amount, (oi.amount - oi.outstanding) AS paid, oi.outstanding, oi.days_overdue
                               FROM (" . arOpenInvoicesSql() . ") oi WHERE oi.ar_account_id = :id ORDER BY oi.sale_date DESC LIMIT 300");
        $inv->bindValue(":id", $id, PDO::PARAM_INT);
        $inv->execute();

        $pay = $conn->prepare("SELECT p.payment_id, p.payment_date, pt.type_name, p.reference_no, p.amount_paid, p.payment_status,
                                      COALESCE((SELECT SUM(a.amount) FROM tblpayment_allocation a WHERE a.payment_id = p.payment_id), 0) AS net_amount
                               FROM tblpayment p INNER JOIN tblinvoice pi ON pi.invoice_id = p.invoice_id AND pi.transaction_type = 'Payment'
                               INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                               WHERE p.ar_account_id = :id ORDER BY p.payment_id DESC LIMIT 300");
        $pay->bindValue(":id", $id, PDO::PARAM_INT);
        $pay->execute();

        /* Running-balance ledger, chronological. Convention: a positive
         * balance is what the customer still owes. Sources, in the system's
         * own semantics (no invented treatments):
         *  - Credit invoices on Completed sales: debit = invoice amount.
         *    (Voided sales are excluded, matching arOpenInvoicesSql; a
         *    credit sale with payments applied cannot be voided until those
         *    payments are reversed, so voided invoices contribute ~0.)
         *  - Allocation rows at face value: positive = payment credit,
         *    negative = reversal/adjustment debit (reversals append negative
         *    allocation rows; the original payment row is never edited, so
         *    no separate reversal row is added here — that would double
         *    count). Allocations are scoped to this account's invoices.
         *  - Approved memos only (a Reversed memo stops counting, matching
         *    the outstanding formula): credit memo = credit, debit memo =
         *    debit. Auto late fees are Approved debit memos, so they appear.
         * Same-day ordering is date, then source rank (charge, payment,
         * memo), then id; the final balance is order-independent. A mismatch
         * against the stored balance is reported, never hidden.
         */
        $ledgerStmt = $conn->prepare("
            (SELECT inv.transaction_date AS txn_date, 1 AS src_rank, inv.invoice_id AS src_id,
                    s.receipt_number AS reference_no,
                    CONCAT('Credit sale ', s.receipt_number) AS description,
                    inv.amount AS debit, 0 AS credit
             FROM tblinvoice inv INNER JOIN tblsale s ON s.sale_id = inv.sale_id
             WHERE inv.ar_account_id = :id AND inv.transaction_type = 'Credit' AND s.sale_status = 'Completed')
            UNION ALL
            (SELECT a.created_at AS txn_date, 2 AS src_rank, a.allocation_id AS src_id,
                    COALESCE(p.reference_no, CONCAT('PAY-', p.payment_id)) AS reference_no,
                    CASE WHEN a.reversal_id IS NOT NULL THEN CONCAT('Payment reversal / adjustment (payment #', p.payment_id, ')') ELSE CONCAT('Payment #', p.payment_id) END AS description,
                    CASE WHEN a.amount < 0 THEN -a.amount ELSE 0 END AS debit,
                    CASE WHEN a.amount > 0 THEN a.amount ELSE 0 END AS credit
             FROM tblpayment_allocation a INNER JOIN tblpayment p ON p.payment_id = a.payment_id
             WHERE a.invoice_id IN (SELECT invoice_id FROM tblinvoice WHERE ar_account_id = :id2))
            UNION ALL
            (SELECT m.memo_date AS txn_date, 3 AS src_rank, m.credit_memo_id AS src_id,
                    COALESCE(m.reference_no, CONCAT('CM-', m.credit_memo_id)) AS reference_no,
                    CONCAT('Credit memo', IF(m.category IS NOT NULL AND m.category <> '', CONCAT(' (', m.category, ')'), ''), IF(m.reason IS NOT NULL AND m.reason <> '', CONCAT(' — ', m.reason), '')) AS description,
                    0 AS debit, m.amount AS credit
             FROM tblcredit_memo m WHERE m.status = 'Approved' AND m.invoice_id IN (SELECT invoice_id FROM tblinvoice WHERE ar_account_id = :id3))
            UNION ALL
            (SELECT m.memo_date AS txn_date, 3 AS src_rank, m.debit_memo_id AS src_id,
                    COALESCE(m.reference_no, CONCAT('DM-', m.debit_memo_id)) AS reference_no,
                    CONCAT('Debit memo', IF(m.category IS NOT NULL AND m.category <> '', CONCAT(' (', m.category, ')'), ''), IF(m.reason IS NOT NULL AND m.reason <> '', CONCAT(' — ', m.reason), '')) AS description,
                    m.amount AS debit, 0 AS credit
             FROM tbldebit_memo m WHERE m.status = 'Approved' AND m.invoice_id IN (SELECT invoice_id FROM tblinvoice WHERE ar_account_id = :id4))
            ORDER BY txn_date ASC, src_rank ASC, src_id ASC");
        $ledgerStmt->bindValue(":id", $id, PDO::PARAM_INT);
        $ledgerStmt->bindValue(":id2", $id, PDO::PARAM_INT);
        $ledgerStmt->bindValue(":id3", $id, PDO::PARAM_INT);
        $ledgerStmt->bindValue(":id4", $id, PDO::PARAM_INT);
        $ledgerStmt->execute();
        $ledger = [];
        $running = 0.0;
        foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $running = round($running + (float)$r["debit"] - (float)$r["credit"], 2);
            $ledger[] = ["transaction_date" => $r["txn_date"], "reference_no" => $r["reference_no"],
                         "description" => $r["description"], "debit" => round((float)$r["debit"], 2),
                         "credit" => round((float)$r["credit"], 2), "running_balance" => $running];
        }
        $stored = round((float)$account["current_balance"], 2);
        $difference = round($running - $stored, 2);

        return ["success" => true, "account" => $account, "invoices" => $inv->fetchAll(PDO::FETCH_ASSOC), "payments" => $pay->fetchAll(PDO::FETCH_ASSOC),
                "ledger" => $ledger, "computed_balance" => $running, "stored_balance" => $stored,
                "balance_match" => abs($difference) <= 0.004, "balance_difference" => $difference];
    }

    /* Read-only: accounts whose stored balance differs from their open credit invoices. Nothing is auto-corrected. */
    public function getReconciliation() {
        include "connection-pdo.php";
        $sql = "SELECT ar.ar_account_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, ar.account_status, ar.current_balance,
                       COALESCE(t.o, 0) AS invoice_outstanding, (ar.current_balance - COALESCE(t.o, 0)) AS difference
                FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                LEFT JOIN (SELECT ar_account_id, SUM(outstanding) AS o FROM (" . arOpenInvoicesSql() . ") x WHERE outstanding > 0 GROUP BY ar_account_id) t ON t.ar_account_id = ar.ar_account_id
                WHERE ABS(ar.current_balance - COALESCE(t.o, 0)) > 0.004 ORDER BY ABS(ar.current_balance - COALESCE(t.o, 0)) DESC";
        return ["success" => true, "rows" => $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC)];
    }
}

$operation = $_GET["operation"] ?? "";
$obj = new ArReport();

try {
    switch ($operation) {
        case "getDashboard":
        case "getCollectionReport":
            [$from, $to] = arDateRange($_GET["date_from"] ?? "", $_GET["date_to"] ?? "");
            echo json_encode($operation === "getDashboard" ? $obj->getDashboard($from, $to) : $obj->getCollectionReport($from, $to));
            break;
        case "getCreditMonitor":
            $mode = in_array($_GET["mode"] ?? "", ["overdue", "overlimit", "hold"], true) ? $_GET["mode"] : "all";
            echo json_encode($obj->getCreditMonitor(trim($_GET["keyword"] ?? ""), $mode));
            break;
        case "getAging": echo json_encode($obj->getAging()); break;
        case "getSummary": echo json_encode($obj->getSummary()); break;
        case "getStatement": echo json_encode($obj->getStatement($_GET["ar_account_id"] ?? 0)); break;
        case "getDueInvoices":
            echo json_encode($obj->getDueInvoices(
                $_GET["status"] ?? "all_outstanding",
                $_GET["from"] ?? $_GET["date_from"] ?? "",
                $_GET["to"] ?? $_GET["date_to"] ?? "",
                trim($_GET["keyword"] ?? ""),
                (int)($_GET["page"] ?? 1),
                (int)($_GET["limit"] ?? 50)
            ));
            break;
        case "getReconciliation": echo json_encode($obj->getReconciliation()); break;
        default: echo json_encode(["success" => false, "message" => "Invalid operation."]);
    }
} catch (ArException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Unable to load the report."]);
}
