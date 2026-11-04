<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
startActivityLogging("Sales", "tblsale", "sale_id", "receipt_number");

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class SaleException extends Exception {}

class Sale {

    // Suki Points earning rule (updated 2026-10-29): ₱400 = 1 point,
    // with cumulative progress in tblsuki_card.suki_progress_amount.
    // Redemption value is unchanged: 1 point = ₱1.
    private $pesosPerPoint = 400;
    // Rate in effect before this update. Used ONLY as a fallback for
    // sales that have no ledger row (i.e. sales made before the
    // migration), so voiding an old sale reverses exactly what it
    // originally earned instead of the new ₱400 amount. Existing
    // points_balance values are never rewritten by the migration.
    private $oldPesosPerPoint = 100;

    private function sukiTableExists($conn, $table) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
            $stmt->bindValue(":table", $table, PDO::PARAM_STR);
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function sukiColumnExists($conn, $table, $column) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
            $stmt->bindValue(":table", $table, PDO::PARAM_STR);
            $stmt->bindValue(":column", $column, PDO::PARAM_STR);
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Correct points_earned for one sale: ledger row first (new sales,
    // exact points + eligible amount + progress), old ₱100 rate second
    // (pre-migration sales, no ledger row), derived ₱400 rate last
    // (only when neither applies, e.g. migration not run).
    private function sukiCorrectSalePoints($conn, &$sale) {
        $sale["suki_eligible_amount"] = 0;
        $sale["suki_progress_before"] = 0;
        $sale["suki_progress_after"] = 0;
        if (empty($sale["suki_card_id"])) return;
        if ($this->sukiTableExists($conn, "tblsuki_point_transaction")) {
            $stmt = $conn->prepare("SELECT points_change, eligible_amount, progress_before, progress_after FROM tblsuki_point_transaction WHERE sale_id = :sale_id AND transaction_type = 'EARN' ORDER BY transaction_id ASC LIMIT 1");
            $stmt->bindValue(":sale_id", (int)$sale["sale_id"], PDO::PARAM_INT);
            $stmt->execute();
            $led = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($led) {
                $sale["points_earned"] = (int)$led["points_change"];
                $sale["suki_eligible_amount"] = (float)$led["eligible_amount"];
                $sale["suki_progress_before"] = (float)$led["progress_before"];
                $sale["suki_progress_after"] = (float)$led["progress_after"];
                return;
            }
        }
        // No ledger row: pre-migration sale, earned at ₱100.
        $total = (float)($sale["total_amount"] ?? 0);
        $redeemedCash = (float)($sale["points_redeemed"] ?? 0);
        $sale["points_earned"] = (int)floor(max(0, $total - $redeemedCash) / $this->oldPesosPerPoint);
    }

    // ERD-aligned schema: tblsale no longer stores customer / AR account / discount / payment type /
    // subtotal / discount amount / points. They are read back from the related tables:
    //   AR account    -> tblinvoice.ar_account_id           (invoice.sale_id = sale.sale_id)
    //   payment type  -> tblpayment.payment_type_id         (payment.invoice_id = invoice.invoice_id)
    //   discount      -> tblsale_item.discount_id
    //   subtotal      -> SUM(tblsale_item.subtotal), discount amount = subtotal - total
    //   customer      -> via the sale's AR account, else via its Suki card
    //   points earned -> ledger row if present, else old ₱100 rate for
    //   pre-migration sales (see sukiCorrectSalePoints)
    private $saleJoins = " LEFT JOIN tblinvoice inv ON inv.sale_id = s.sale_id
                           LEFT JOIN tblar_account ar ON ar.ar_account_id = inv.ar_account_id
                           LEFT JOIN tblsuki_card sc ON sc.suki_card_id = s.suki_card_id
                           LEFT JOIN tblcustomer cu ON cu.customer_id = COALESCE(ar.customer_id, sc.customer_id)
                           LEFT JOIN tblpayment pay ON pay.payment_id = (
                                SELECT MIN(p2.payment_id) FROM tblpayment p2
                                WHERE p2.invoice_id = inv.invoice_id AND inv.transaction_type = 'Cash')
                           LEFT JOIN tblpayment_type pt ON pt.payment_type_id = pay.payment_type_id
                           LEFT JOIN (SELECT sale_id, SUM(subtotal) AS items_subtotal, MAX(discount_id) AS discount_id
                                      FROM tblsale_item GROUP BY sale_id) it ON it.sale_id = s.sale_id
                           LEFT JOIN tbldiscount d ON d.discount_id = it.discount_id ";

    private $baseSelect = "SELECT s.sale_id, s.receipt_number, s.sale_date AS created_at, c.counter_name,
                                  CONCAT(e.first_name, ' ', e.last_name) AS cashier_name,
                                  CONCAT(cu.first_name, ' ', cu.last_name) AS customer_name,
                                  pt.type_name,
                                  (SELECT GROUP_CONCAT(pt2.type_name ORDER BY pay2.payment_id SEPARATOR ' + ')
                                     FROM tblpayment pay2
                                     INNER JOIN tblpayment_type pt2 ON pay2.payment_type_id = pt2.payment_type_id
                                     WHERE pay2.invoice_id = inv.invoice_id) AS payment_types,
                                  inv.ar_account_id, s.total_amount, s.sale_status, s.void_reason
                           FROM tblsale s
                           INNER JOIN tblcounter c ON s.counter_id = c.counter_id
                           INNER JOIN tblemployee e ON s.employee_id = e.employee_id";

    public function getAllSales($status, $date) {
        include "connection-pdo.php";

        $sql = $this->baseSelect . $this->saleJoins . " WHERE 1 = 1";

        if ($status === "completed") {
            $sql .= " AND s.sale_status = 'Completed'";
        } elseif ($status === "voided") {
            $sql .= " AND s.sale_status = 'Voided'";
        }

        if ($date !== "") {
            $sql .= " AND DATE(s.sale_date) = :sale_date";
        }

        $sql .= " ORDER BY s.sale_id DESC LIMIT 300";

        $stmt = $conn->prepare($sql);
        if ($date !== "") {
            $stmt->bindParam(":sale_date", $date, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchSales($keyword, $status, $date) {
        include "connection-pdo.php";

        $sql = $this->baseSelect . $this->saleJoins . " WHERE (s.receipt_number LIKE :keyword
                                         OR e.first_name LIKE :keyword OR e.last_name LIKE :keyword
                                         OR cu.first_name LIKE :keyword OR cu.last_name LIKE :keyword)";

        if ($status === "completed") {
            $sql .= " AND s.sale_status = 'Completed'";
        } elseif ($status === "voided") {
            $sql .= " AND s.sale_status = 'Voided'";
        }

        if ($date !== "") {
            $sql .= " AND DATE(s.sale_date) = :sale_date";
        }

        $sql .= " ORDER BY s.sale_id DESC LIMIT 300";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        if ($date !== "") {
            $stmt->bindParam(":sale_date", $date, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSaleDetail($saleId) {
        include "connection-pdo.php";

        if (!$saleId) {
            return ["success" => false, "message" => "Sale ID is required."];
        }

        $sql = "SELECT s.sale_id, s.receipt_number, c.counter_name,
                       CONCAT(e.first_name, ' ', e.last_name) AS cashier_name,
                       CONCAT(cu.first_name, ' ', cu.last_name) AS customer_name,
                       inv.ar_account_id, d.discount_name, pt.type_name,
                       COALESCE(it.items_subtotal, 0) AS subtotal,
                       ROUND(COALESCE(it.items_subtotal, 0) - s.total_amount, 2) AS discount_amount,
                       s.vat_amount, s.total_amount, s.amount_tendered,
                       s.change_amount,
                       COALESCE((SELECT SUM(pr.points_used) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0) AS points_redeemed,
                       IF(s.suki_card_id IS NULL, 0, FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0), 0) / :pesos_per_point)) AS points_earned,
                       s.sale_status, s.void_reason, s.voided_at,
                       CONCAT(v.first_name, ' ', v.last_name) AS voided_by_name, s.sale_date AS created_at
                FROM tblsale s
                INNER JOIN tblcounter c ON s.counter_id = c.counter_id
                INNER JOIN tblemployee e ON s.employee_id = e.employee_id" . $this->saleJoins . "
                LEFT JOIN tblemployee v ON s.voided_by = v.employee_id
                WHERE s.sale_id = :sale_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":pesos_per_point", $this->pesosPerPoint, PDO::PARAM_INT);
        $stmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $stmt->execute();
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            return ["success" => false, "message" => "Sale not found."];
        }

        $itemStmt = $conn->prepare("SELECT si.sale_item_id, p.product_name, p.unit, si.unit_price, si.quantity, si.subtotal AS line_total
                                    FROM tblsale_item si
                                    INNER JOIN tblproduct p ON si.product_id = p.product_id
                                    WHERE si.sale_id = :sale_id ORDER BY si.sale_item_id ASC");
        $itemStmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $itemStmt->execute();
        $sale["items"] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentStmt = $conn->prepare("SELECT pay.payment_id, pay.amount_paid, pay.reference_no, pay.gc_id, pay.gcc_id, gcc.code AS gcc_code, pt.type_name
                                       FROM tblpayment pay INNER JOIN tblpayment_type pt ON pay.payment_type_id = pt.payment_type_id
                                       INNER JOIN tblinvoice inv2 ON pay.invoice_id = inv2.invoice_id
                                       LEFT JOIN tblgift_certificate_code gcc ON pay.gcc_id = gcc.gcc_id
                                       WHERE inv2.sale_id = :sale_id ORDER BY pay.payment_id ASC");
        $paymentStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT); $paymentStmt->execute();
        $sale["payments"] = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        $sale["points_redeemed"] = (int)$sale["points_redeemed"];
        $this->sukiCorrectSalePoints($conn, $sale);

        return ["success" => true, "sale" => $sale];
    }

    private function refreshGiftCertificateStatuses($conn, $saleId) {
        $idStmt = $conn->prepare("SELECT DISTINCT pay.gc_id FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id WHERE inv.sale_id = :sale_id AND pay.gc_id IS NOT NULL");
        $idStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT); $idStmt->execute();
        $gcIds = $idStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($gcIds as $gcId) {
            $gcStmt = $conn->prepare("SELECT gc_value, expiration_date, status FROM tblgift_certificate WHERE gc_id = :id FOR UPDATE");
            $gcStmt->bindValue(":id", (int)$gcId, PDO::PARAM_INT); $gcStmt->execute();
            $gc = $gcStmt->fetch(PDO::FETCH_ASSOC);
            if (!$gc || strtolower((string)$gc["status"]) === "cancelled") continue;
            $usedStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid),0) FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id INNER JOIN tblsale ss ON inv.sale_id = ss.sale_id WHERE pay.gc_id = :gc_id AND ss.sale_status = 'Completed'");
            $usedStmt->bindValue(":gc_id", (int)$gcId, PDO::PARAM_INT); $usedStmt->execute();
            $remaining = round((float)$gc["gc_value"] - (float)$usedStmt->fetchColumn(), 2);
            $status = $remaining <= 0.001 ? "Fully Used" : ((!empty($gc["expiration_date"]) && $gc["expiration_date"] < date("Y-m-d")) ? "Expired" : "Active");
            $update = $conn->prepare("UPDATE tblgift_certificate SET status = :status, date_redeemed = CASE WHEN :status2 = 'Fully Used' THEN COALESCE(date_redeemed, CURDATE()) ELSE NULL END WHERE gc_id = :id");
            $update->bindValue(":status", $status, PDO::PARAM_STR); $update->bindValue(":status2", $status, PDO::PARAM_STR); $update->bindValue(":id", (int)$gcId, PDO::PARAM_INT); $update->execute();
        }
    }

    // SOFT DELETE - a sale is never removed, it is marked Voided.
    // The AR balance and Suki points it created are reversed in the same transaction.
    public function voidSale($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $saleId = $data["sale_id"] ?? null;
        $reason = trim($data["reason"] ?? "");
        $voidedBy = (int)$_SESSION["employee_id"];

        if (!$saleId || $reason === "") {
            return ["success" => false, "message" => "Sale ID and a void reason are required."];
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT s.sale_status, s.receipt_number,
                                           (SELECT inv.ar_account_id FROM tblinvoice inv WHERE inv.sale_id = s.sale_id LIMIT 1) AS ar_account_id,
                                           s.suki_card_id, s.total_amount,
                                           COALESCE((SELECT SUM(pr.points_used) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0) AS points_redeemed,
                                           IF(s.suki_card_id IS NULL, 0, FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0), 0) / :pesos_per_point)) AS points_earned
                                    FROM tblsale s WHERE s.sale_id = :id FOR UPDATE");
            $stmt->bindValue(":pesos_per_point", $this->pesosPerPoint, PDO::PARAM_INT);
            $stmt->bindParam(":id", $saleId, PDO::PARAM_INT);
            $stmt->execute();
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new SaleException("Sale not found.");
            }
            if ($sale["sale_status"] !== "Completed") {
                throw new SaleException("Only a completed sale can be voided.");
            }
            // Void must reverse exactly what the sale earned, at the
            // rate in effect when it was sold (ledger, else ₱100).
            $sale["sale_id"] = $saleId;
            $this->sukiCorrectSalePoints($conn, $sale);

            if ($sale["ar_account_id"] !== null) {
                // AR Manager guard: a credit sale that still has payments applied must have them reversed first.
                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(a.amount), 0) FROM tblpayment_allocation a INNER JOIN tblinvoice i ON i.invoice_id = a.invoice_id WHERE i.sale_id = :sid");
                $paidStmt->bindParam(":sid", $saleId, PDO::PARAM_INT);
                $paidStmt->execute();
                if ((float)$paidStmt->fetchColumn() > 0.004) {
                    throw new SaleException("This credit sale has customer payments applied. Reverse those payments in the AR Manager before voiding it.");
                }
                $arStmt = $conn->prepare("UPDATE tblar_account SET current_balance = GREATEST(current_balance - :total, 0) WHERE ar_account_id = :id");
                $arStmt->bindParam(":total", $sale["total_amount"]);
                $arStmt->bindParam(":id", $sale["ar_account_id"], PDO::PARAM_INT);
                $arStmt->execute();
            }

            if ($sale["suki_card_id"] !== null && ((int)$sale["points_earned"] > 0 || (int)$sale["points_redeemed"] > 0 || (float)($sale["suki_eligible_amount"] ?? 0) > 0)) {
                if ($this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount")) {
                    // Reverse this sale's eligible spending from
                    // progress, clamped at 0. Limitation, stated in the
                    // report: if a later sale already consumed this
                    // progress to earn a point, that later point is
                    // NOT retroactively removed; the void only removes
                    // this sale's own points and the remaining progress.
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = GREATEST(points_balance - :earned + :redeemed, 0), suki_progress_amount = GREATEST(suki_progress_amount - :eligible, 0) WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":earned", (int)$sale["points_earned"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":redeemed", (int)$sale["points_redeemed"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":eligible", (float)($sale["suki_eligible_amount"] ?? 0));
                    $pointsStmt->bindValue(":id", $sale["suki_card_id"], PDO::PARAM_INT);
                } else {
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = GREATEST(points_balance - :earned + :redeemed, 0) WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":earned", (int)$sale["points_earned"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":redeemed", (int)$sale["points_redeemed"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":id", $sale["suki_card_id"], PDO::PARAM_INT);
                }
                $pointsStmt->execute();
                if ($this->sukiTableExists($conn, "tblsuki_point_transaction")) {
                    // REVERSAL uses NULL sale_id on purpose: the UNIQUE
                    // index on (sale_id, transaction_type) must block
                    // a second EARN for the same sale, but a void ->
                    // restore -> void cycle must still be able to
                    // write a second REVERSAL (MySQL treats NULLs as
                    // distinct in a unique index). The receipt number
                    // in notes keeps the audit link to the sale.
                    $revStmt = $conn->prepare("INSERT INTO tblsuki_point_transaction (suki_card_id, sale_id, transaction_type, points_change, eligible_amount, progress_before, progress_after, balance_before, balance_after, notes) VALUES (:card_id, NULL, 'REVERSAL', :points, :eligible, 0, 0, 0, 0, :notes)");
                    $revStmt->bindValue(":card_id", $sale["suki_card_id"], PDO::PARAM_INT);
                    $revStmt->bindValue(":points", -((int)$sale["points_earned"]) + (int)$sale["points_redeemed"], PDO::PARAM_INT);
                    $revStmt->bindValue(":eligible", -((float)($sale["suki_eligible_amount"] ?? 0)));
                    $revStmt->bindValue(":notes", "Void of sale " . $sale["receipt_number"], PDO::PARAM_STR);
                    $revStmt->execute();
                }
            }

            $voidStmt = $conn->prepare("UPDATE tblsale SET sale_status = 'Voided', void_reason = :reason, voided_by = :voided_by, voided_at = NOW() WHERE sale_id = :id");
            $voidStmt->bindParam(":reason", $reason, PDO::PARAM_STR);
            $voidStmt->bindParam(":voided_by", $voidedBy, PDO::PARAM_INT);
            $voidStmt->bindParam(":id", $saleId, PDO::PARAM_INT);
            $voidStmt->execute();
            $this->refreshGiftCertificateStatuses($conn, $saleId);

            // Permanent audit record of the void (reason + voider) —
            // survives a later restore, which clears tblsale's fields.
            auditEvent($conn, [
                "module" => "Sales",
                "action" => "Voided",
                "record_id" => (int)$saleId,
                "record_label" => $sale["receipt_number"],
                "details" => "Sale voided.",
                "before" => ["sale_status" => "Completed", "total_amount" => $sale["total_amount"],
                             "ar_account_id" => $sale["ar_account_id"], "suki_card_id" => $sale["suki_card_id"]],
                "after" => ["sale_status" => "Voided"],
                "reason" => $reason,
                "reference_label" => $sale["receipt_number"]
            ]);

            $conn->commit();

            return ["success" => true, "message" => "Sale voided. Reason: " . $reason];
        } catch (SaleException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => "Failed to void the sale. Nothing was changed."];
        }
    }

    public function restoreSale($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $saleId = $data["sale_id"] ?? null;

        if (!$saleId) {
            return ["success" => false, "message" => "Sale ID is required."];
        }

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT s.sale_status, s.receipt_number, s.void_reason, s.voided_by, s.voided_at,
                                           (SELECT inv.ar_account_id FROM tblinvoice inv WHERE inv.sale_id = s.sale_id LIMIT 1) AS ar_account_id,
                                           s.suki_card_id, s.total_amount,
                                           COALESCE((SELECT SUM(pr.points_used) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0) AS points_redeemed,
                                           IF(s.suki_card_id IS NULL, 0, FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0), 0) / :pesos_per_point)) AS points_earned
                                    FROM tblsale s WHERE s.sale_id = :id FOR UPDATE");
            $stmt->bindValue(":pesos_per_point", $this->pesosPerPoint, PDO::PARAM_INT);
            $stmt->bindParam(":id", $saleId, PDO::PARAM_INT);
            $stmt->execute();
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new SaleException("Sale not found.");
            }
            if ($sale["sale_status"] !== "Voided") {
                throw new SaleException("Only a voided sale can be restored.");
            }
            $sale["sale_id"] = $saleId;
            $this->sukiCorrectSalePoints($conn, $sale);

            if ($sale["ar_account_id"] !== null) {
                $arCheck = $conn->prepare("SELECT credit_limit, current_balance, account_status FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
                $arCheck->bindParam(":id", $sale["ar_account_id"], PDO::PARAM_INT);
                $arCheck->execute();
                $ar = $arCheck->fetch(PDO::FETCH_ASSOC);

                if (!$ar || $ar["account_status"] !== "Active") {
                    throw new SaleException("Cannot restore: the AR account is no longer active.");
                }
                if ((float)$ar["current_balance"] + (float)$sale["total_amount"] > (float)$ar["credit_limit"] + 0.001) {
                    throw new SaleException("Cannot restore: it would put the AR account over its credit limit.");
                }

                $arStmt = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance + :total WHERE ar_account_id = :id");
                $arStmt->bindParam(":total", $sale["total_amount"]);
                $arStmt->bindParam(":id", $sale["ar_account_id"], PDO::PARAM_INT);
                $arStmt->execute();
            }

            if ($sale["suki_card_id"] !== null && ((int)$sale["points_earned"] > 0 || (int)$sale["points_redeemed"] > 0 || (float)($sale["suki_eligible_amount"] ?? 0) > 0)) {
                if ($this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount")) {
                    // Restore progress, capped below ₱400. If the
                    // restored progress plus current progress reaches
                    // ₱400, the excess is kept as progress only up to
                    // ₱399.99 in this restore step; the next sale's
                    // cumulative calculation will convert any further
                    // spending correctly. Balance itself is restored
                    // exactly from the ledger / old-rate points.
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = points_balance + :earned - :redeemed, suki_progress_amount = LEAST(suki_progress_amount + :eligible, 399.99) WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":earned", (int)$sale["points_earned"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":redeemed", (int)$sale["points_redeemed"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":eligible", (float)($sale["suki_eligible_amount"] ?? 0));
                    $pointsStmt->bindValue(":id", $sale["suki_card_id"], PDO::PARAM_INT);
                } else {
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = points_balance + :earned - :redeemed WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":earned", (int)$sale["points_earned"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":redeemed", (int)$sale["points_redeemed"], PDO::PARAM_INT);
                    $pointsStmt->bindValue(":id", $sale["suki_card_id"], PDO::PARAM_INT);
                }
                $pointsStmt->execute();
            }

            $restoreStmt = $conn->prepare("UPDATE tblsale SET sale_status = 'Completed', void_reason = NULL, voided_by = NULL, voided_at = NULL WHERE sale_id = :id");
            $restoreStmt->bindParam(":id", $saleId, PDO::PARAM_INT);
            $restoreStmt->execute();
            $this->refreshGiftCertificateStatuses($conn, $saleId);

            auditEvent($conn, [
                "module" => "Sales",
                "action" => "Restored",
                "record_id" => (int)$saleId,
                "record_label" => $sale["receipt_number"],
                "details" => "Sale restored.",
                "before" => ["sale_status" => "Voided", "void_reason" => $sale["void_reason"],
                             "voided_by" => $sale["voided_by"], "voided_at" => $sale["voided_at"],
                             "total_amount" => $sale["total_amount"]],
                "after" => ["sale_status" => "Completed"],
                "reference_label" => $sale["receipt_number"]
            ]);

            $conn->commit();

            return ["success" => true, "message" => "Sale restored successfully."];
        } catch (SaleException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => "Failed to restore the sale. Nothing was changed."];
        }
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "completed";
$keyword = trim($_GET["keyword"] ?? "");
$date = trim($_GET["date"] ?? "");
$saleId = $_GET["sale_id"] ?? 0;

$obj = new Sale();

switch ($operation) {
    case "getAllSales":
        echo json_encode($obj->getAllSales($status, $date));
        break;
    case "searchSales":
        echo json_encode($obj->searchSales($keyword, $status, $date));
        break;
    case "getSaleDetail":
        echo json_encode($obj->getSaleDetail($saleId));
        break;
    case "voidSale":
        echo json_encode($obj->voidSale($json));
        break;
    case "restoreSale":
        echo json_encode($obj->restoreSale($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
