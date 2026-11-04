<?php
require_once "session-guard.php";
requireRole(["Cashier"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
require_once "ar-common.php"; // late fee / automatic credit hold policy (arSyncAccountPolicy)
startActivityLogging("Sales", "tblsale", "sale_id", "receipt_number");

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

// Thrown for problems the cashier can fix (empty cart, not enough cash, over credit limit...).
class PosException extends Exception {}

class Pos {

    private $vatRate = 0.12;
    // Set to false if the business is NOT VAT-registered: VAT lines are then
    // hidden on receipts and new sales store zero VAT. Default true preserves
    // current behavior (prices are VAT-inclusive; VAT is extracted as net x
    // 12/112, never added on top).
    private $vatEnabled = true;
    // Suki Points earning rule (updated 2026-10-29): every ₱400 in
    // eligible spending earns 1 point. Was ₱100 before this update;
    // existing points_balance values are NOT rewritten (see the
    // migration SQL). Redemption value is unchanged: 1 point = ₱1.
    private $pesosPerPoint = 400;

    // ERD-aligned schema: tblsale no longer stores customer / AR account / discount / payment type /
    // subtotal / discount amount / points. They are read back from the related tables:
    //   AR account    -> tblinvoice.ar_account_id           (invoice.sale_id = sale.sale_id)
    //   payment type  -> tblpayment.payment_type_id         (payment.invoice_id = invoice.invoice_id)
    //   discount      -> tblsale_item.discount_id
    //   subtotal      -> SUM(tblsale_item.subtotal), discount amount = subtotal - total
    //   customer      -> via the sale's AR account, else via its Suki card
    //   points earned -> FLOOR((progress_before + eligible_after_discount - redeemed) / pesosPerPoint)
    //   when the sale has a Suki card and is not charged to AR; the
    //   remainder stays in tblsuki_card.suki_progress_amount toward
    //   the next point. Redemption value is still 1 point = ₱1.
    private $saleJoins = " LEFT JOIN tblinvoice inv ON inv.sale_id = s.sale_id
                           LEFT JOIN tblar_account ar ON ar.ar_account_id = inv.ar_account_id
                           LEFT JOIN tblsuki_card sc ON sc.suki_card_id = s.suki_card_id
                           LEFT JOIN tblcustomer cu ON cu.customer_id = COALESCE(s.customer_id, ar.customer_id, sc.customer_id)
                           LEFT JOIN tblpayment pay ON pay.payment_id = (
                                SELECT MIN(p2.payment_id) FROM tblpayment p2
                                WHERE p2.invoice_id = inv.invoice_id AND inv.transaction_type = 'Cash')
                           LEFT JOIN tblpayment_type pt ON pt.payment_type_id = pay.payment_type_id
                           LEFT JOIN (SELECT sale_id, SUM(subtotal) AS items_subtotal, MAX(discount_id) AS discount_id
                                      FROM tblsale_item GROUP BY sale_id) it ON it.sale_id = s.sale_id
                           LEFT JOIN tbldiscount d ON d.discount_id = it.discount_id ";

    private function getDefaultPriceTierId($conn) {
        $sql = "SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1 ORDER BY (tier_name = 'Regular') DESC, price_tier_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row["price_tier_id"] : 0;
    }

    // Suki migration guards: the new columns/table are added by
    // sql/upgrade-suki-points-v1.sql, but the code must not break if
    // that migration has not run yet on a branch. When a column/table
    // is missing, progress is treated as 0 and every product as
    // eligible, which is exactly the pre-migration behaviour except
    // for the ₱400 divisor itself.
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

    private function sukiProgressFields($progress) {
        $progress = max(0, round((float)$progress, 2));
        if ($progress >= $this->pesosPerPoint) $progress = fmod($progress, $this->pesosPerPoint);
        $needed = round($this->pesosPerPoint - $progress, 2);
        if ($needed <= 0) $needed = (float)$this->pesosPerPoint;
        return ["suki_progress_amount" => $progress, "suki_amount_needed" => $needed, "suki_points_per_point" => $this->pesosPerPoint];
    }

    // The active tier NAMED Wholesale — the only tier whose price is ever
    // used for a product marked as a wholesale barcode (is_wholesale_barcode).
    private function getWholesalePriceTierId($conn) {
        $sql = "SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1 AND LOWER(tier_name) IN ('wholesale', 'wholesaler') ORDER BY price_tier_id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row["price_tier_id"] : 0;
    }

    // Wholesale-barcode ACCESS follows the Customer Type (brix's rule,
    // 2026-10-22): only a customer whose type is Wholesale/Wholesaler may
    // buy a marked wholesale-barcode product. Price still comes from the
    // customer's stored Price Tier; the Suki Card grants neither.
    private function customerHasWholesaleAccess($customerType) {
        return in_array(strtolower(trim((string)$customerType)), ["wholesale", "wholesaler"], true);
    }

    // Everything the POS screen needs to fill its dropdowns in one call.
    public function getPosLookups() {
        include "connection-pdo.php";

        $counterStmt = $conn->prepare("SELECT counter_id, counter_name, counter_number, status FROM tblcounter WHERE is_active = 1 ORDER BY counter_number ASC");
        $counterStmt->execute();

        $discountStmt = $conn->prepare("SELECT discount_id, discount_name, discount_type, discount_value FROM tbldiscount WHERE is_active = 1 ORDER BY discount_name ASC");
        $discountStmt->execute();

        $paymentStmt = $conn->prepare("SELECT payment_type_id, type_name FROM tblpayment_type WHERE is_active = 1 ORDER BY type_name ASC");
        $paymentStmt->execute();

        // Categories for the POS product-table filter dropdown. categories.php is Administrator-only,
        // so the Cashier screen gets its own read-only copy of the active category list here.
        $categoryStmt = $conn->prepare("SELECT category_id, category_name FROM tblproduct_category WHERE is_active = 1 ORDER BY category_name ASC");
        $categoryStmt->execute();

        return [
            "counters" => $counterStmt->fetchAll(PDO::FETCH_ASSOC),
            "discounts" => $discountStmt->fetchAll(PDO::FETCH_ASSOC),
            "payment_types" => $paymentStmt->fetchAll(PDO::FETCH_ASSOC),
            "categories" => $categoryStmt->fetchAll(PDO::FETCH_ASSOC),
            "default_price_tier_id" => $this->getDefaultPriceTierId($conn),
            "vat_rate" => $this->vatRate,
            "vat_enabled" => $this->vatEnabled,
            "pesos_per_point" => $this->pesosPerPoint
        ];
    }

    // Products the cashier can actually sell: active product + active category + a price for the tier
    // (falls back to the default tier price when the customer's tier has no price for that product).
    // $categoryId = 0 means "All Categories".
    public function getPosProducts($keyword, $priceTierId, $categoryId, $customerType = "", $barcodeOnly = false) {
        include "connection-pdo.php";

        $defaultTierId = $this->getDefaultPriceTierId($conn);
        $tierId = $priceTierId ? (int)$priceTierId : $defaultTierId;
        $wholesaleTierId = $this->getWholesalePriceTierId($conn);
        $categoryId = (int)$categoryId;

        // A marked wholesale-barcode product is priced ONLY from the Wholesale
        // tier (no fallback to Regular); every other product keeps the usual
        // tier price with Regular fallback.
        $sukiEligibleSelect = $this->sukiColumnExists($conn, "tblproduct", "is_suki_eligible") ? ", p.is_suki_eligible" : ", 1 AS is_suki_eligible";
        $sql = "SELECT p.product_id, p.barcode, p.product_name, p.unit, p.is_vatable, p.is_wholesale_barcode, pc.category_id, pc.category_name" . $sukiEligibleSelect . ",
                       CASE WHEN p.is_wholesale_barcode = 1 THEN wp.price ELSE COALESCE(pp.price, dp.price) END AS price
                FROM tblproduct p
                INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id
                LEFT JOIN tblproduct_price pp ON pp.product_id = p.product_id AND pp.price_tier_id = :tier_id
                LEFT JOIN tblproduct_price dp ON dp.product_id = p.product_id AND dp.price_tier_id = :default_tier_id
                LEFT JOIN tblproduct_price wp ON wp.product_id = p.product_id AND wp.price_tier_id = :wholesale_tier_id
                WHERE p.is_active = 1 AND pc.is_active = 1
                  AND (CASE WHEN p.is_wholesale_barcode = 1 THEN wp.price ELSE COALESCE(pp.price, dp.price) END) IS NOT NULL";

        // Barcode-only search (F2 product list and barcode scanning):
        // match the barcode only, never the product name. The product_id
        // branch is kept for the internal callers that look a cart line
        // up by id (reprice and held-sale restore), which do NOT send
        // barcode_only.
        if ($barcodeOnly) {
            $sql .= " AND p.barcode LIKE :keyword";
        } else {
            $sql .= " AND (p.product_name LIKE :keyword OR p.barcode LIKE :keyword
                       OR CAST(p.product_id AS CHAR) = :product_id_keyword)";
        }

        // Wholesale-barcode products are hidden from every customer who is
        // not a Wholesale customer: the regular cashier never sees them in
        // search results or scans. insertSale still rejects them as the
        // backstop if a request is forged past this filter.
        if (!in_array(strtolower(trim((string)$customerType)), ["wholesale", "wholesaler"], true)) {
            $sql .= " AND p.is_wholesale_barcode = 0";
        }

        if ($categoryId > 0) {
            $sql .= " AND pc.category_id = :category_id";
        }

        $sql .= " ORDER BY p.product_name ASC LIMIT 200";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":tier_id", $tierId, PDO::PARAM_INT);
        $stmt->bindParam(":default_tier_id", $defaultTierId, PDO::PARAM_INT);
        $stmt->bindParam(":wholesale_tier_id", $wholesaleTierId, PDO::PARAM_INT);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        if (!$barcodeOnly) {
            $stmt->bindValue(":product_id_keyword", $keyword, PDO::PARAM_STR);
        }
        if ($categoryId > 0) {
            $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPosCustomer($customerId) {
        include "connection-pdo.php";

        // Late fee / auto-hold catch-up for this customer's account before
        // its standing is shown or used (idempotent, best-effort: a policy
        // failure never blocks the cashier).
        $arLookup = $conn->prepare("SELECT ar_account_id FROM tblar_account WHERE customer_id = :id AND account_status = 'Active' ORDER BY ar_account_id ASC LIMIT 1");
        $arLookup->bindValue(":id", (int)$customerId, PDO::PARAM_INT);
        $arLookup->execute();
        $arIdRow = $arLookup->fetch(PDO::FETCH_ASSOC);
        if ($arIdRow) arSyncAccountPolicy($conn, (int)$arIdRow["ar_account_id"]);

        $hasHoldCols = arHoldColumnsExist($conn);
        $holdSelect = $hasHoldCols ? ", ar.credit_hold_source, ar.credit_hold_at, hi.receipt_number AS hold_receipt, hi.hold_days" : "";
        $holdJoin = $hasHoldCols ? " LEFT JOIN (SELECT inv.invoice_id, s2.receipt_number, DATEDIFF(CURDATE(), COALESCE(inv.due_date, DATE(inv.transaction_date))) AS hold_days FROM tblinvoice inv INNER JOIN tblsale s2 ON s2.sale_id = inv.sale_id) hi ON hi.invoice_id = ar.credit_hold_invoice_id" : "";

        $progressSelect = $this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount") ? ", sc.suki_progress_amount" : ", 0 AS suki_progress_amount";
        $sql = "SELECT c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS full_name, c.contact_number,
                       c.price_tier_id, pt.tier_name AS tier_name, c.customer_type,
                       sc.suki_card_id, sc.card_number, sc.points_balance" . $progressSelect . ",
                       ar.ar_account_id, ar.credit_limit, ar.current_balance, ar.credit_status" . $holdSelect . "
                FROM tblcustomer c
                INNER JOIN tblprice_tier pt ON c.price_tier_id = pt.price_tier_id
                LEFT JOIN tblsuki_card sc ON sc.suki_card_id = (
                    SELECT MIN(x.suki_card_id) FROM tblsuki_card x WHERE x.customer_id = c.customer_id AND x.is_active = 1)
                LEFT JOIN tblar_account ar ON ar.ar_account_id = (
                    SELECT MIN(a.ar_account_id) FROM tblar_account a WHERE a.customer_id = c.customer_id AND a.account_status = 'Active')" . $holdJoin . "
                WHERE c.customer_id = :customer_id AND c.is_active = 1
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":customer_id", (int)$customerId, PDO::PARAM_INT);
        $stmt->execute();
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $customer["hold_reason"] = $this->holdReasonText($customer);
            if (!empty($customer["suki_card_id"])) {
                $customer = array_merge($customer, $this->sukiProgressFields($customer["suki_progress_amount"] ?? 0));
            } else {
                $customer["suki_progress_amount"] = 0;
                $customer["suki_amount_needed"] = (float)$this->pesosPerPoint;
                $customer["suki_points_per_point"] = $this->pesosPerPoint;
            }
        }

        return $customer ?: null;
    }

    /* Cashier-facing explanation for an automatic hold (null otherwise). */
    private function holdReasonText($row) {
        if (($row["credit_status"] ?? "") !== "Hold") return null;
        if (($row["credit_hold_source"] ?? null) !== "AutoOverdue") return null;
        $receipt = $row["hold_receipt"] ?? null;
        $days = isset($row["hold_days"]) ? (int)$row["hold_days"] : null;
        if ($receipt !== null && $days !== null) {
            return "Automatic hold — invoice " . $receipt . " is " . $days . " days overdue. Only the AR Manager can release it.";
        }
        return "Automatic hold — an invoice is 15+ days overdue. Only the AR Manager can release it.";
    }

    // Customer lookup with price tier, Suki card and AR standing, so the cashier sees it all at once.
    public function getPosCustomers($keyword, $customerFilter = "all") {
        include "connection-pdo.php";

        $hasHoldCols = arHoldColumnsExist($conn);
        $holdSelect = $hasHoldCols ? ", ar.credit_hold_source, ar.credit_hold_at, hi.receipt_number AS hold_receipt, hi.hold_days" : "";
        $holdJoin = $hasHoldCols ? " LEFT JOIN (SELECT inv.invoice_id, s2.receipt_number, DATEDIFF(CURDATE(), COALESCE(inv.due_date, DATE(inv.transaction_date))) AS hold_days FROM tblinvoice inv INNER JOIN tblsale s2 ON s2.sale_id = inv.sale_id) hi ON hi.invoice_id = ar.credit_hold_invoice_id" : "";

        $progressSelect = $this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount") ? ", sc.suki_progress_amount" : ", 0 AS suki_progress_amount";
        $sql = "SELECT c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS full_name, c.contact_number,
                       c.price_tier_id, pt.tier_name AS tier_name, c.customer_type,
                       sc.suki_card_id, sc.card_number, sc.points_balance" . $progressSelect . ",
                       ar.ar_account_id, ar.credit_limit, ar.current_balance, ar.credit_status" . $holdSelect . "
                FROM tblcustomer c
                INNER JOIN tblprice_tier pt ON c.price_tier_id = pt.price_tier_id
                LEFT JOIN tblsuki_card sc ON sc.suki_card_id = (
                    SELECT MIN(x.suki_card_id) FROM tblsuki_card x WHERE x.customer_id = c.customer_id AND x.is_active = 1)
                LEFT JOIN tblar_account ar ON ar.ar_account_id = (
                    SELECT MIN(a.ar_account_id) FROM tblar_account a WHERE a.customer_id = c.customer_id AND a.account_status = 'Active')" . $holdJoin . "
                WHERE c.is_active = 1
                  AND (c.first_name LIKE :keyword OR c.last_name LIKE :keyword OR c.contact_number LIKE :keyword OR sc.card_number LIKE :keyword)"
                . ($customerFilter === "ar" ? " AND ar.ar_account_id IS NOT NULL"
                   : ($customerFilter === "suki" ? " AND sc.suki_card_id IS NOT NULL" : "")) . "
                ORDER BY c.last_name ASC
                LIMIT 15";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row["hold_reason"] = $this->holdReasonText($row);
            if (!empty($row["suki_card_id"])) {
                $row = array_merge($row, $this->sukiProgressFields($row["suki_progress_amount"] ?? 0));
            } else {
                $row["suki_progress_amount"] = 0;
                $row["suki_amount_needed"] = (float)$this->pesosPerPoint;
                $row["suki_points_per_point"] = $this->pesosPerPoint;
            }
        }
        unset($row);
        return $rows;
    }

    private function getSaleWithItems($conn, $saleId, $cashierId) {
        $sql = "SELECT s.sale_id, s.receipt_number, s.counter_id, c.counter_name, s.employee_id AS cashier_id,
                       CONCAT(e.first_name, ' ', e.last_name) AS cashier_name,
                       cu.customer_id, CONCAT(cu.first_name, ' ', cu.last_name) AS customer_name,
                       inv.ar_account_id, it.discount_id, d.discount_name, pay.payment_type_id, pt.type_name,
                       COALESCE(it.items_subtotal, 0) AS subtotal,
                       ROUND(COALESCE(it.items_subtotal, 0) - s.total_amount, 2) AS discount_amount,
                       s.vatable_sales, s.vat_exempt_sales, s.vat_amount, s.total_amount, s.amount_tendered,
                       s.change_amount,
                       IF(s.suki_card_id IS NULL, 0, FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice rinv ON rp.invoice_id = rinv.invoice_id WHERE rinv.sale_id = s.sale_id), 0), 0) / :pesos_per_point)) AS points_earned,
                       s.sale_status, s.void_reason, s.voided_at, s.sale_date AS created_at
                FROM tblsale s
                INNER JOIN tblcounter c ON s.counter_id = c.counter_id
                INNER JOIN tblemployee e ON s.employee_id = e.employee_id" . $this->saleJoins . "
                WHERE s.sale_id = :sale_id AND s.employee_id = :cashier_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":pesos_per_point", $this->pesosPerPoint, PDO::PARAM_INT);
        $stmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->execute();
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            return null;
        }

        $itemStmt = $conn->prepare("SELECT si.sale_item_id, si.product_id, p.product_name, p.unit, si.unit_price, si.quantity,
                                           p.is_vatable, si.subtotal AS line_total
                                    FROM tblsale_item si
                                    INNER JOIN tblproduct p ON si.product_id = p.product_id
                                    WHERE si.sale_id = :sale_id ORDER BY si.sale_item_id ASC");
        $itemStmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $itemStmt->execute();
        $sale["items"] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentDetailStmt = $conn->prepare("SELECT pay.payment_id, pay.amount_paid, pay.reference_no, pay.gc_id, pay.gcc_id, gcc.code AS gcc_code, pt.type_name
                                             FROM tblpayment pay
                                             INNER JOIN tblpayment_type pt ON pay.payment_type_id = pt.payment_type_id
                                             INNER JOIN tblinvoice inv2 ON pay.invoice_id = inv2.invoice_id
                                             LEFT JOIN tblgift_certificate_code gcc ON pay.gcc_id = gcc.gcc_id
                                             WHERE inv2.sale_id = :sale_id
                                             ORDER BY pay.payment_id ASC");
        $paymentDetailStmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $paymentDetailStmt->execute();
        $sale["payments"] = $paymentDetailStmt->fetchAll(PDO::FETCH_ASSOC);

        $redeemedStmt = $conn->prepare("SELECT COALESCE(SUM(pr.points_used), 0) AS points_used
                                        FROM tblpoints_redemption pr
                                        INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id
                                        INNER JOIN tblinvoice rinv ON rp.invoice_id = rinv.invoice_id
                                        WHERE rinv.sale_id = :sale_id");
        $redeemedStmt->bindParam(":sale_id", $saleId, PDO::PARAM_INT);
        $redeemedStmt->execute();
        $sale["points_redeemed"] = (int)$redeemedStmt->fetchColumn();
        // Receipt points: prefer the ledger row written at sale time
        // (cumulative progress makes points depend on progress_before,
        // not just this sale's amount). Without a ledger row, keep the
        // derived formula above — old sales will show the new ₱400
        // rate, which is a display limitation, not a balance rewrite.
        if ($this->sukiTableExists($conn, "tblsuki_point_transaction")) {
            $ledStmt = $conn->prepare("SELECT points_change, eligible_amount, progress_after FROM tblsuki_point_transaction WHERE sale_id = :sale_id AND transaction_type = 'EARN' ORDER BY transaction_id ASC LIMIT 1");
            $ledStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
            $ledStmt->execute();
            $led = $ledStmt->fetch(PDO::FETCH_ASSOC);
            if ($led) {
                $sale["points_earned"] = (int)$led["points_change"];
                $sale["suki_eligible_amount"] = (float)$led["eligible_amount"];
                $sale["suki_progress_after"] = (float)$led["progress_after"];
            }
        }

        return $sale;
    }

    public function getSaleDetail($saleId) {
        include "connection-pdo.php";

        if (!$saleId) {
            return ["success" => false, "message" => "Sale ID is required."];
        }

        $sale = $this->getSaleWithItems($conn, (int)$saleId, (int)$_SESSION["employee_id"]);

        if (!$sale) {
            return ["success" => false, "message" => "Sale not found."];
        }

        return ["success" => true, "sale" => $sale];
    }

    // A cashier only ever sees their own sales. Voided ones stay visible (marked Voided)
    // so the cashier knows when the Administrator voided a transaction.
    public function getMySales($date, $status) {
        include "connection-pdo.php";

        $cashierId = (int)$_SESSION["employee_id"];

        $sql = "SELECT s.sale_id, s.receipt_number, c.counter_name, cu.customer_id,
                       CONCAT(cu.first_name, ' ', cu.last_name) AS customer_name,
                       pt.type_name,
                       (SELECT GROUP_CONCAT(pt2.type_name ORDER BY pay2.payment_id SEPARATOR ' + ')
                          FROM tblpayment pay2 INNER JOIN tblpayment_type pt2 ON pay2.payment_type_id = pt2.payment_type_id
                          WHERE pay2.invoice_id = inv.invoice_id) AS payment_types,
                       inv.ar_account_id, s.total_amount, s.sale_status, s.void_reason, s.sale_date AS created_at
                FROM tblsale s
                INNER JOIN tblcounter c ON s.counter_id = c.counter_id" . $this->saleJoins . "
                WHERE s.employee_id = :cashier_id AND DATE(s.sale_date) = :sale_date";

        if ($status === "completed") {
            $sql .= " AND s.sale_status = 'Completed'";
        } elseif ($status === "voided") {
            $sql .= " AND s.sale_status = 'Voided'";
        }

        $sql .= " ORDER BY s.sale_id DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->bindParam(":sale_date", $date, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Today's Sales Summary cards on the Reports tab.
    public function getCashierSummary() {
        include "connection-pdo.php";

        $cashierId = (int)$_SESSION["employee_id"];

        $sql = "SELECT
                    COALESCE(SUM(s.sale_status = 'Completed'), 0) AS completed_count,
                    COALESCE(SUM(CASE WHEN s.sale_status = 'Completed' THEN s.total_amount ELSE 0 END), 0) AS completed_total,
                    COALESCE(SUM(s.sale_status = 'Voided'), 0) AS voided_count,
                    COALESCE(SUM(CASE WHEN s.sale_status = 'Completed' THEN COALESCE(it.items_subtotal, 0) - s.total_amount ELSE 0 END), 0) AS total_discount,
                    COALESCE((SELECT SUM(si.quantity) FROM tblsale_item si
                              INNER JOIN tblsale s2 ON si.sale_id = s2.sale_id
                              WHERE s2.employee_id = :cashier_id2 AND s2.sale_status = 'Completed' AND DATE(s2.sale_date) = CURDATE()), 0) AS items_sold
                FROM tblsale s
                LEFT JOIN (SELECT sale_id, SUM(subtotal) AS items_subtotal FROM tblsale_item GROUP BY sale_id) it ON it.sale_id = s.sale_id
                WHERE s.employee_id = :cashier_id AND DATE(s.sale_date) = CURDATE()";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->bindParam(":cashier_id2", $cashierId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // "Sales by Date" report - transactions / items sold / total sales for this cashier on one date.
    public function getSalesSummaryByDate($date) {
        include "connection-pdo.php";

        if (!$date) {
            return ["success" => false, "message" => "Date is required."];
        }

        $cashierId = (int)$_SESSION["employee_id"];

        $sql = "SELECT
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(s.total_amount), 0) AS total_sales,
                    COALESCE(SUM(COALESCE(it.items_subtotal, 0) - s.total_amount), 0) AS total_discount,
                    COALESCE((SELECT SUM(si.quantity) FROM tblsale_item si WHERE si.sale_id IN (
                        SELECT sale_id FROM tblsale WHERE employee_id = :cashier_id2 AND sale_status = 'Completed' AND DATE(sale_date) = :sale_date2
                    )), 0) AS items_sold
                FROM tblsale s
                LEFT JOIN (SELECT sale_id, SUM(subtotal) AS items_subtotal FROM tblsale_item GROUP BY sale_id) it ON it.sale_id = s.sale_id
                WHERE s.employee_id = :cashier_id AND s.sale_status = 'Completed' AND DATE(s.sale_date) = :sale_date";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->bindParam(":cashier_id2", $cashierId, PDO::PARAM_INT);
        $stmt->bindParam(":sale_date", $date, PDO::PARAM_STR);
        $stmt->bindParam(":sale_date2", $date, PDO::PARAM_STR);
        $stmt->execute();

        return ["success" => true, "summary" => $stmt->fetch(PDO::FETCH_ASSOC)];
    }

    // "Specific Product Sales" report - how much of one product this cashier sold,
    // on one date if given, or all-time if the date is left blank.
    public function getProductSalesReport($productId, $date) {
        include "connection-pdo.php";

        if (!$productId) {
            return ["success" => false, "message" => "Product is required."];
        }

        $cashierId = (int)$_SESSION["employee_id"];

        $sql = "SELECT p.product_name,
                       COALESCE(SUM(si.quantity), 0) AS quantity_sold,
                       COALESCE(SUM(si.subtotal), 0) AS total_sales
                FROM tblproduct p
                LEFT JOIN tblsale_item si ON si.product_id = p.product_id
                LEFT JOIN tblsale s ON si.sale_id = s.sale_id AND s.employee_id = :cashier_id AND s.sale_status = 'Completed'";

        if ($date !== "") {
            $sql .= " AND DATE(s.sale_date) = :sale_date";
        }

        $sql .= " WHERE p.product_id = :product_id GROUP BY p.product_id, p.product_name";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
        if ($date !== "") {
            $stmt->bindParam(":sale_date", $date, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Product not found."];
        }

        return ["success" => true, "report" => $row];
    }

    // "Most Purchased Product" - this cashier's all-time top seller by quantity.
    public function getMostPurchasedProduct() {
        include "connection-pdo.php";

        $cashierId = (int)$_SESSION["employee_id"];

        $sql = "SELECT p.product_name, SUM(si.quantity) AS quantity_sold, SUM(si.subtotal) AS total_sales
                FROM tblsale_item si
                INNER JOIN tblsale s ON si.sale_id = s.sale_id
                INNER JOIN tblproduct p ON si.product_id = p.product_id
                WHERE s.employee_id = :cashier_id AND s.sale_status = 'Completed'
                GROUP BY si.product_id, p.product_name
                ORDER BY quantity_sold DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":cashier_id", $cashierId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => true, "report" => null];
        }

        return ["success" => true, "report" => $row];
    }

    private function verifySupervisor($username, $password, &$failureReason = null) {
        include "connection-pdo.php";

        // Usernames and role names are administratively maintained values. Normalize
        // their comparison so a casing difference or an accidental trailing space in
        // a migrated role does not prevent an otherwise valid authorization.
        $username = strtolower(trim((string)$username));
        if ($username === "" || $password === "") {
            $failureReason = "Enter both the username and password.";
            return null;
        }

        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.username, e.password, r.role_name
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

        // Query the flags separately from the initial lookup so the operator can
        // correct a deactivated account/role instead of receiving a password error.
        $statusStmt = $conn->prepare("SELECT e.is_active AS employee_active, r.is_active AS role_active
                                      FROM tblemployee e INNER JOIN tblrole r ON e.role_id = r.role_id
                                      WHERE e.employee_id = :employee_id");
        $statusStmt->bindValue(":employee_id", (int)$employee["employee_id"], PDO::PARAM_INT);
        $statusStmt->execute();
        $status = $statusStmt->fetch(PDO::FETCH_ASSOC);

        if (!$status || (int)$status["employee_active"] !== 1) {
            $failureReason = "This employee account is inactive.";
            return null;
        }
        if ((int)$status["role_active"] !== 1) {
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

    // Verifies ANY active employee account (any role). Used for the deposit
    // verifier at End Shift: the double-count is done by another staff member
    // or a supervisor, not necessarily a Supervisor/Administrator.
    private function verifyEmployee($username, $password, &$failureReason = null) {
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

    public function authorizeAction($json) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        // Also accept the regular submitted form fields. The POS screen sends
        // both formats so authorization does not depend on one JSON field being
        // parsed by PHP.
        // Prefer regular multipart fields. Some browser autofill/password-manager
        // combinations submit these correctly while exposing an empty value to the
        // JavaScript payload builder.
        $username = $_POST["username"] ?? ($data["username"] ?? "");
        $password = $_POST["password"] ?? ($data["password"] ?? "");
        $failureReason = null;
        $supervisor = $this->verifySupervisor($username, $password, $failureReason);

        $actionName = preg_replace("/[^A-Za-z0-9 _\-]/", "", (string)($_POST["action"] ?? ($data["action"] ?? "authorizeAction")));
        if ($actionName === "") $actionName = "authorizeAction";

        if (!$supervisor) {
            auditAttempt([
                "module" => "Supervisor Authorization",
                "action" => $actionName,
                "outcome" => "Denied",
                "record_label" => trim((string)$username) !== "" ? trim((string)$username) : null,
                "details" => "Supervisor authorization denied.",
                "reason" => $failureReason ?? "Invalid Supervisor/Administrator credentials."
            ]);
            return ["success" => false, "message" => $failureReason ?? "Invalid Supervisor/Administrator credentials."];
        }

        auditStandalone([
            "module" => "Supervisor Authorization",
            "action" => $actionName,
            "outcome" => "Success",
            "details" => "Supervisor authorization approved for " . $supervisor["display_name"] . ".",
            "authorized_by" => $supervisor["employee_id"],
            "authorized_role" => $supervisor["role_name"]
        ]);

        return [
            "success" => true,
            "message" => "Authorization approved.",
            "authorized_by" => $supervisor["display_name"],
            "role" => $supervisor["role_name"]
        ];
    }

    // Verify-only check for the End-Shift deposit verifier (wizard Step 2).
    // UX only: closeShift re-verifies these credentials inside its own
    // transaction before anything is saved, so this endpoint authorizes
    // nothing by itself — it exists so a typo is caught in the step where
    // it is typed instead of after the whole wizard. Same rules as the
    // verifier check inside closeShift: any active employee, never the
    // cashier herself.
    public function verifyDepositVerifier($json) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        $username = $_POST["username"] ?? ($data["username"] ?? "");
        $password = $_POST["password"] ?? ($data["password"] ?? "");
        $failureReason = null;
        $verifier = $this->verifyEmployee($username, $password, $failureReason);
        if (!$verifier) {
            return ["success" => false, "message" => $failureReason ?? "Invalid verifier credentials."];
        }
        if ((int)$verifier["employee_id"] === (int)$_SESSION["employee_id"]) {
            return ["success" => false, "message" => "You cannot verify your own deposit. Another staff member or a supervisor must double-count the sealed cash."];
        }
        return [
            "success" => true,
            "message" => "Verifier confirmed.",
            "verified_by" => $verifier["display_name"],
            "role" => $verifier["role_name"]
        ];
    }

    public function getHeldTransactions() {
        include "connection-pdo.php";

        $sql = "SELECT h.held_id, h.counter_id, h.held_label, h.created_at,
                       c.counter_name,
                       CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, '')) AS customer_name,
                       JSON_LENGTH(JSON_EXTRACT(h.cart_json, '$.items')) AS item_count
                FROM tblheld_transaction h
                INNER JOIN tblcounter c ON h.counter_id = c.counter_id
                LEFT JOIN tblcustomer cu ON h.customer_id = cu.customer_id
                WHERE h.employee_id = :employee_id
                ORDER BY h.held_id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function holdTransaction($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = !empty($data["counter_id"]) ? (int)$data["counter_id"] : 0;
        $customerId = !empty($data["customer_id"]) ? (int)$data["customer_id"] : null;
        $discountId = !empty($data["discount_id"]) ? (int)$data["discount_id"] : null;
        $label = trim($data["held_label"] ?? "");
        $items = $data["items"] ?? [];

        if ($counterId <= 0 || !is_array($items) || count($items) === 0) {
            return ["success" => false, "message" => "A counter and at least one item are required."];
        }

        $cartJson = json_encode([
            "customer_id" => $customerId,
            "discount_id" => $discountId,
            "use_ar" => !empty($data["use_ar"]),
            "items" => $items
        ]);

        if ($cartJson === false) {
            return ["success" => false, "message" => "Could not save the held transaction."];
        }

        if ($label === "") {
            $label = "Held #" . date("His");
        }

        $sql = "INSERT INTO tblheld_transaction
                    (employee_id, counter_id, customer_id, discount_id, held_label, cart_json)
                VALUES
                    (:employee_id, :counter_id, :customer_id, :discount_id, :held_label, :cart_json)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $stmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
        $stmt->bindValue(":customer_id", $customerId, $customerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(":discount_id", $discountId, $discountId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(":held_label", $label, PDO::PARAM_STR);
        $stmt->bindValue(":cart_json", $cartJson, PDO::PARAM_STR);
        $stmt->execute();

        return ["success" => true, "message" => "Transaction held.", "held_id" => (int)$conn->lastInsertId()];
    }

    public function getHeldTransaction($heldId) {
        include "connection-pdo.php";

        $sql = "SELECT held_id, counter_id, customer_id, discount_id, held_label, cart_json, created_at
                FROM tblheld_transaction
                WHERE held_id = :held_id AND employee_id = :employee_id
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":held_id", (int)$heldId, PDO::PARAM_INT);
        $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Held transaction not found."];
        }

        $state = json_decode($row["cart_json"], true);
        if (!is_array($state)) {
            return ["success" => false, "message" => "Held transaction data is invalid."];
        }

        return [
            "success" => true,
            "held" => [
                "held_id" => (int)$row["held_id"],
                "counter_id" => (int)$row["counter_id"],
                "customer_id" => $row["customer_id"] !== null ? (int)$row["customer_id"] : null,
                "discount_id" => $row["discount_id"] !== null ? (int)$row["discount_id"] : null,
                "held_label" => $row["held_label"],
                "created_at" => $row["created_at"],
                "state" => $state
            ]
        ];
    }

    public function deleteHeldTransaction($heldId) {
        include "connection-pdo.php";

        try {
            $conn->beginTransaction();
            $sel = $conn->prepare("SELECT held_id, held_label FROM tblheld_transaction WHERE held_id = :held_id AND employee_id = :employee_id FOR UPDATE");
            $sel->bindValue(":held_id", (int)$heldId, PDO::PARAM_INT);
            $sel->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
            $sel->execute();
            $heldRow = $sel->fetch(PDO::FETCH_ASSOC);

            if (!$heldRow) {
                $conn->rollBack();
                return ["success" => false, "message" => "Held transaction not found."];
            }

            $stmt = $conn->prepare("DELETE FROM tblheld_transaction WHERE held_id = :held_id AND employee_id = :employee_id");
            $stmt->bindValue(":held_id", (int)$heldId, PDO::PARAM_INT);
            $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
            $stmt->execute();

            auditEvent($conn, [
                "module" => "Sales",
                "action" => "HeldRemoved",
                "record_id" => (int)$heldId,
                "record_label" => $heldRow["held_label"],
                "details" => "Held transaction removed.",
                "before" => ["held_id" => (int)$heldId, "held_label" => $heldRow["held_label"]]
            ]);
            $conn->commit();
            return ["success" => true, "message" => "Held transaction removed."];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to remove the held transaction. Nothing was changed."];
        }
    }

    // Shared denomination-breakdown validation for opening + replenishing a
    // float. Returns null when valid (or when no breakdown was supplied);
    // otherwise a cashier-readable error message. An empty array means "no
    // breakdown supplied". A sum mismatch fails the whole operation: the
    // caller validates BEFORE any insert, so nothing is half-saved.
    private function validateFloatDenominations($denominations, $amount) {
        if ($denominations === null) return null;
        if (!is_array($denominations) || count($denominations) === 0) return null;
        $allowed = [1000.0, 500.0, 200.0, 100.0, 50.0, 20.0, 10.0, 5.0, 1.0];
        $sum = 0.0;
        foreach ($denominations as $row) {
            if (!is_array($row)) return "Each denomination entry needs a denomination and a piece count.";
            $denom = isset($row["denomination"]) ? (float)$row["denomination"] : 0;
            if (!in_array($denom, $allowed, true)) return "Unsupported denomination: ₱" . number_format($denom, 2) . ". Use 1000, 500, 200, 100, 50, 20, 10, 5 or 1.";
            $pieces = $row["pieces"] ?? null;
            if (!is_int($pieces) && !(is_string($pieces) && ctype_digit($pieces))) return "Piece counts must be whole numbers.";
            $pieces = (int)$pieces;
            if ($pieces < 0) return "Piece counts cannot be negative.";
            $sum += $denom * $pieces;
        }
        if (abs(round($sum, 2) - round((float)$amount, 2)) > 0.01) {
            return "Denomination breakdown (₱" . number_format($sum, 2) . ") does not match the float amount (₱" . number_format((float)$amount, 2) . ").";
        }
        return null;
    }

    // Inserts one tblcash_float_detail row per denomination with pieces > 0.
    // Must be called inside the caller's transaction, after the float row.
    private function insertFloatDenominations($conn, $floatId, $denominations) {
        if (!is_array($denominations) || count($denominations) === 0) return;
        $stmt = $conn->prepare("INSERT INTO tblcash_float_detail (float_id, denomination, pieces, amount)
                                VALUES (:float_id, :denomination, :pieces, :amount)");
        foreach ($denominations as $row) {
            $pieces = (int)$row["pieces"];
            if ($pieces <= 0) continue;
            $denom = (float)$row["denomination"];
            $stmt->bindValue(":float_id", (int)$floatId, PDO::PARAM_INT);
            $stmt->bindValue(":denomination", $denom);
            $stmt->bindValue(":pieces", $pieces, PDO::PARAM_INT);
            $stmt->bindValue(":amount", round($denom * $pieces, 2));
            $stmt->execute();
        }
    }

    public function openCashFloat($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = !empty($data["counter_id"]) ? (int)$data["counter_id"] : 0;
        $amount = isset($data["amount"]) ? round((float)$data["amount"], 2) : -1;
        $employeeId = (int)$_SESSION["employee_id"];

        if ($counterId <= 0 || $amount < 0) {
            return ["success" => false, "message" => "Counter and a valid beginning float are required."];
        }

        // Validated up front so a breakdown mismatch fails the whole open
        // (including the already-open early return below) instead of
        // half-applying it.
        $denominations = $data["denominations"] ?? null;
        $denomError = $this->validateFloatDenominations($denominations, $amount);
        if ($denomError !== null) {
            return ["success" => false, "message" => $denomError];
        }

        try {
            // An Open shift on this counter under ANOTHER cashier blocks the
            // open: a counter can have only one active shift at a time.
            $counterOpen = $this->findOpenShiftForCounter($conn, $counterId);
            if ($counterOpen && (int)$counterOpen["employee_id"] !== $employeeId) {
                return ["success" => false, "message" =>
                    "Counter " . $counterOpen["counter_number"] . " already has an open shift under " .
                    trim($counterOpen["first_name"] . " " . $counterOpen["last_name"]) .
                    " (opened " . $counterOpen["opened_at"] . "). That shift must be closed first."];
            }

            // This cashier already has an Open shift on ANOTHER counter.
            $ownOpen = $this->findOpenShiftForEmployee($conn, $employeeId);
            if ($ownOpen && (int)$ownOpen["counter_id"] !== $counterId) {
                return ["success" => false, "message" =>
                    "You already have an open shift on Counter " . $ownOpen["counter_number"] .
                    " (opened " . $ownOpen["opened_at"] . "). Close it before opening another counter."];
            }

            if ($ownOpen) {
                // Open shift for this cashier + counter: the drawer is already
                // open for THIS shift (covers floats that predate the shift
                // row — ensureShiftRow backfills from the existing Beginning).
                $this->ensureShiftRow($conn, $employeeId, $counterId);
                $beginStmt = $conn->prepare("SELECT amount, created_at FROM tblcash_float
                                             WHERE counter_id = :c AND employee_id = :e
                                               AND float_type = 'Beginning'
                                               AND created_at >= :opened
                                             ORDER BY float_id ASC LIMIT 1");
                $beginStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
                $beginStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
                $beginStmt->bindValue(":opened", $ownOpen["opened_at"]);
                $beginStmt->execute();
                $begin = $beginStmt->fetch(PDO::FETCH_ASSOC);
                return [
                    "success" => true,
                    "already_open" => true,
                    "message" => "Today's cash drawer is already opened.",
                    "beginning_float" => $begin ? (float)$begin["amount"] : (float)$ownOpen["opening_cash"],
                    "opened_at" => $begin ? $begin["created_at"] : $ownOpen["opened_at"],
                    "shift_id" => (int)$ownOpen["shift_id"]
                ];
            }

            // No Open shift anywhere relevant: this Beginning float starts a
            // NEW shift — including when an earlier shift today is Closed
            // (multiple sequential shifts per day are allowed; the closed
            // shift stays untouched).
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO tblcash_float (counter_id, employee_id, float_type, amount)
                                    VALUES (:counter_id, :employee_id, 'Beginning', :amount)");
            $stmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
            $stmt->bindValue(":amount", $amount);
            $stmt->execute();
            $floatId = (int)$conn->lastInsertId();
            $this->insertFloatDenominations($conn, $floatId, $denominations);

            $shift = $this->ensureShiftRow($conn, $employeeId, $counterId);

            // Stamp the Beginning row with its shift.
            $stamp = $conn->prepare("UPDATE tblcash_float SET shift_id = :sid WHERE float_id = :fid");
            $stamp->bindValue(":sid", (int)$shift["shift_id"], PDO::PARAM_INT);
            $stamp->bindValue(":fid", $floatId, PDO::PARAM_INT);
            $stamp->execute();

            auditEvent($conn, [
                "module" => "Sales",
                "action" => "DrawerOpened",
                "record_id" => $floatId,
                "record_label" => "Beginning float",
                "details" => "Cash drawer opened with beginning float.",
                "after" => ["amount" => $amount, "counter_id" => $counterId, "shift_id" => (int)$shift["shift_id"]],
                "reference_label" => "Shift #" . (int)$shift["shift_id"]
            ]);
            $conn->commit();

            return ["success" => true, "message" => "Cash drawer opened.", "beginning_float" => $amount,
                    "shift_id" => (int)$shift["shift_id"]];
        } catch (PosException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            // The generated one-active-shift unique keys are the backstop for
            // two opens racing each other.
            $code = isset($e->errorInfo[0]) ? (string)$e->errorInfo[0] : "";
            $msg = isset($e->errorInfo[2]) ? (string)$e->errorInfo[2] : "";
            if ($code === "23000" && stripos($msg, "uq_active_") !== false) {
                return ["success" => false, "message" => "Another shift is already open for this counter or cashier. Close it before opening a new shift."];
            }
            return ["success" => false, "message" => "Failed to open the cash drawer. Nothing was saved."];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Drawer opened but the shift record failed: " . $e->getMessage()];
        }
    }

    public function getCashDrawer($counterId) {
        include "connection-pdo.php";

        $counterId = (int)$counterId;
        $employeeId = (int)$_SESSION["employee_id"];
        if ($counterId <= 0) {
            return ["success" => true, "opened" => false, "message" => "Select a counter."];
        }

        // The drawer belongs to the CURRENT OPEN shift, not to the day: a
        // second shift today must never see the first shift's figures.
        $shift = $this->findOpenShift($conn, $employeeId, $counterId);
        $shiftId = $shift ? (int)$shift["shift_id"] : 0;

        $begin = null;
        if ($shift) {
            // Prefer the Beginning float stamped with this shift; fall back
            // to the first unstamped Beginning at/after the shift opened.
            $beginStmt = $conn->prepare("SELECT float_id, amount, created_at
                                         FROM tblcash_float
                                         WHERE counter_id = :counter_id
                                           AND employee_id = :employee_id
                                           AND float_type = 'Beginning'
                                           AND (shift_id = :sid
                                                OR (shift_id IS NULL AND created_at >= :opened))
                                         ORDER BY (shift_id = :sid2) DESC, float_id ASC LIMIT 1");
            $beginStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
            $beginStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
            $beginStmt->bindValue(":sid", $shiftId, PDO::PARAM_INT);
            $beginStmt->bindValue(":sid2", $shiftId, PDO::PARAM_INT);
            $beginStmt->bindValue(":opened", $shift["opened_at"]);
            $beginStmt->execute();
            $begin = $beginStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Legacy state: a Beginning float exists today but no shift rows
            // at all for this cashier+counter today (pre-shift-table data).
            // Keep the old already-open behaviour so ensureShift can backfill.
            $anyShiftStmt = $conn->prepare("SELECT COUNT(*) FROM tblcashier_shift
                                            WHERE employee_id = :e AND counter_id = :c
                                              AND shift_date = CURDATE()");
            $anyShiftStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $anyShiftStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
            $anyShiftStmt->execute();
            if ((int)$anyShiftStmt->fetchColumn() === 0) {
                $beginStmt = $conn->prepare("SELECT float_id, amount, created_at
                                             FROM tblcash_float
                                             WHERE counter_id = :counter_id
                                               AND employee_id = :employee_id
                                               AND float_type = 'Beginning'
                                               AND DATE(created_at) = CURDATE()
                                             ORDER BY float_id DESC LIMIT 1");
                $beginStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
                $beginStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
                $beginStmt->execute();
                $begin = $beginStmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$begin) {
            return ["success" => true, "opened" => false, "beginning_float" => 0, "cash_sales" => 0, "cash_lifts" => 0, "cash_replenishments" => 0, "expected_cash" => 0, "shift_id" => null];
        }

        $start = $begin["created_at"];
        // Window end: open-ended while the shift is Open (see the note in
        // buildShiftReport) — a NOW() bound would drop activity timestamped
        // in the current second.
        $end = "9999-12-31 23:59:59";

        $salesStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid), 0)
                                     FROM tblpayment pay
                                     INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id
                                     INNER JOIN tblsale s ON inv.sale_id = s.sale_id
                                     INNER JOIN tblpayment_type pt ON pay.payment_type_id = pt.payment_type_id
                                     WHERE s.counter_id = :counter_id
                                       AND s.employee_id = :employee_id
                                       AND s.sale_status = 'Completed'
                                       AND pt.type_name = 'Cash'
                                       AND pay.payment_date >= :start_time
                                       AND s.sale_date >= :start_time
                                       AND s.sale_date < :end_time");
        $salesStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
        $salesStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
        $salesStmt->bindValue(":start_time", $start, PDO::PARAM_STR);
        $salesStmt->bindValue(":end_time", $end, PDO::PARAM_STR);
        $salesStmt->execute();
        $cashPaid = (float)$salesStmt->fetchColumn();

        // CORRECTED (2026-10-18): physical cash retained = cash tendered MINUS
        // change given. Summing raw amount_paid overstated the drawer whenever
        // change was given, because only cash overpay can create change.
        $changeStmt = $conn->prepare("SELECT COALESCE(SUM(s.change_amount), 0)
                                      FROM tblsale s
                                      WHERE s.counter_id = :counter_id
                                        AND s.employee_id = :employee_id
                                        AND s.sale_status = 'Completed'
                                        AND s.sale_date >= :start_time
                                        AND s.sale_date < :end_time");
        $changeStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
        $changeStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
        $changeStmt->bindValue(":start_time", $start, PDO::PARAM_STR);
        $changeStmt->bindValue(":end_time", $end, PDO::PARAM_STR);
        $changeStmt->execute();
        $cashSales = round($cashPaid - (float)$changeStmt->fetchColumn(), 2);

        // Lifts of THIS shift only: by stamp when present, else the window
        // since this shift's Beginning (a previous shift's lifts predate it).
        $liftStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0)
                                    FROM tblcash_float
                                    WHERE counter_id = :counter_id
                                      AND employee_id = :employee_id
                                      AND float_type = 'Lift'
                                      AND ((:sid > 0 AND shift_id = :sid2)
                                           OR (shift_id IS NULL AND created_at >= :start_time AND created_at < :end_time))");
        $liftStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
        $liftStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
        $liftStmt->bindValue(":sid", $shiftId, PDO::PARAM_INT);
        $liftStmt->bindValue(":sid2", $shiftId, PDO::PARAM_INT);
        $liftStmt->bindValue(":start_time", $start, PDO::PARAM_STR);
        $liftStmt->bindValue(":end_time", $end, PDO::PARAM_STR);
        $liftStmt->execute();
        $cashLifts = (float)$liftStmt->fetchColumn();

        // Replenishments of THIS shift only: same scoping as the lift sum
        // above (stamp when present, else the window since Beginning).
        $replenishStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0)
                                    FROM tblcash_float
                                    WHERE counter_id = :counter_id
                                      AND employee_id = :employee_id
                                      AND float_type = 'Replenish'
                                      AND ((:sid > 0 AND shift_id = :sid2)
                                           OR (shift_id IS NULL AND created_at >= :start_time AND created_at < :end_time))");
        $replenishStmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":employee_id", $employeeId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":sid", $shiftId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":sid2", $shiftId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":start_time", $start, PDO::PARAM_STR);
        $replenishStmt->bindValue(":end_time", $end, PDO::PARAM_STR);
        $replenishStmt->execute();
        $cashReplenishments = (float)$replenishStmt->fetchColumn();

        $beginning = (float)$begin["amount"];
        $expected = round($beginning + $cashSales + $cashReplenishments - $cashLifts, 2);

        return [
            "success" => true,
            "opened" => true,
            "beginning_float" => $beginning,
            "cash_sales" => round($cashSales, 2),
            "cash_lifts" => round($cashLifts, 2),
            "cash_replenishments" => round($cashReplenishments, 2),
            "expected_cash" => $expected,
            "opened_at" => $begin["created_at"],
            "shift_id" => $shiftId > 0 ? $shiftId : null
        ];
    }

    public function cashLift($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = !empty($data["counter_id"]) ? (int)$data["counter_id"] : 0;
        $amount = isset($data["amount"]) ? round((float)$data["amount"], 2) : 0;
        $notes = trim($data["notes"] ?? "Cash lift");
        $supervisor = $this->verifySupervisor($data["username"] ?? "", $data["password"] ?? "");

        if (!$supervisor) {
            auditAttempt([
                "module" => "Supervisor Authorization",
                "action" => "cashLift",
                "outcome" => "Denied",
                "record_label" => trim((string)($data["username"] ?? "")) !== "" ? trim((string)$data["username"]) : null,
                "details" => "Cash lift authorization denied.",
                "reason" => "Invalid Supervisor/Administrator credentials."
            ]);
            return ["success" => false, "message" => "Invalid Supervisor/Administrator credentials."];
        }
        if ($counterId <= 0 || $amount <= 0) {
            return ["success" => false, "message" => "Counter and a positive cash lift amount are required."];
        }

        $drawer = $this->getCashDrawer($counterId);
        if (!$drawer["opened"]) {
            return ["success" => false, "message" => "Open the cash drawer before performing a cash lift."];
        }
        if ($amount > (float)$drawer["expected_cash"]) {
            return ["success" => false, "message" => "Cash lift cannot exceed the expected cash in the drawer."];
        }

        try {
            $conn->beginTransaction();
            $openShift = $this->findOpenShift($conn, (int)$_SESSION["employee_id"], $counterId);
            $stmt = $conn->prepare("INSERT INTO tblcash_float
                                    (counter_id, employee_id, shift_id, float_type, amount, supervisor_id, notes)
                                    VALUES (:counter_id, :employee_id, :shift_id, 'Lift', :amount, :supervisor_id, :notes)");
            $stmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
            $stmt->bindValue(":shift_id", $openShift ? (int)$openShift["shift_id"] : null,
                             $openShift ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(":amount", $amount);
            $stmt->bindValue(":supervisor_id", $supervisor["employee_id"], PDO::PARAM_INT);
            $stmt->bindValue(":notes", $notes, PDO::PARAM_STR);
            $stmt->execute();
            $liftId = (int)$conn->lastInsertId();

            auditEvent($conn, [
                "module" => "Sales",
                "action" => "CashLift",
                "record_id" => $liftId,
                "record_label" => "Cash lift",
                "details" => "Cash lift recorded by " . $supervisor["display_name"] . ".",
                "after" => ["amount" => $amount, "expected_cash_before" => (float)$drawer["expected_cash"],
                            "counter_id" => $counterId, "shift_id" => $openShift ? (int)$openShift["shift_id"] : null],
                "authorized_by" => $supervisor["employee_id"],
                "authorized_role" => $supervisor["role_name"],
                "reference_label" => $openShift ? "Shift #" . (int)$openShift["shift_id"] : null
            ]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to record the cash lift. Nothing was saved."];
        }

        return [
            "success" => true,
            "message" => "Cash lift recorded by " . $supervisor["display_name"] . ".",
            "lift_amount" => $amount
        ];
    }

    // Add more change to an OPEN shift without logging out. Money IN, so it
    // is never a sale, income, customer payment, or cash lift. Authorization
    // mirrors cashLift exactly: Supervisor/Administrator credentials via
    // verifySupervisor, with the same denial audit. The float row is stamped
    // with the open shift, and the denomination breakdown (if supplied) is
    // saved in the same transaction.
    public function addCashFloat($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = !empty($data["counter_id"]) ? (int)$data["counter_id"] : 0;
        $amount = isset($data["amount"]) ? round((float)$data["amount"], 2) : 0;
        $notes = trim($data["notes"] ?? "Cash float replenishment");
        $supervisor = $this->verifySupervisor($data["username"] ?? "", $data["password"] ?? "");

        if (!$supervisor) {
            auditAttempt([
                "module" => "Supervisor Authorization",
                "action" => "addCashFloat",
                "outcome" => "Denied",
                "record_label" => trim((string)($data["username"] ?? "")) !== "" ? trim((string)$data["username"]) : null,
                "details" => "Cash float replenishment authorization denied.",
                "reason" => "Invalid Supervisor/Administrator credentials."
            ]);
            return ["success" => false, "message" => "Invalid Supervisor/Administrator credentials."];
        }
        if ($counterId <= 0 || $amount <= 0) {
            return ["success" => false, "message" => "Counter and a positive replenishment amount are required."];
        }
        $denominations = $data["denominations"] ?? null;
        $denomError = $this->validateFloatDenominations($denominations, $amount);
        if ($denomError !== null) {
            return ["success" => false, "message" => $denomError];
        }

        $openShift = $this->findOpenShift($conn, (int)$_SESSION["employee_id"], $counterId);
        if (!$openShift) {
            return ["success" => false, "message" => "No open shift for this counter. Open your cash float first."];
        }
        $drawer = $this->getCashDrawer($counterId);

        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO tblcash_float
                                    (counter_id, employee_id, shift_id, float_type, amount, supervisor_id, notes)
                                    VALUES (:counter_id, :employee_id, :shift_id, 'Replenish', :amount, :supervisor_id, :notes)");
            $stmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
            $stmt->bindValue(":shift_id", (int)$openShift["shift_id"], PDO::PARAM_INT);
            $stmt->bindValue(":amount", $amount);
            $stmt->bindValue(":supervisor_id", $supervisor["employee_id"], PDO::PARAM_INT);
            $stmt->bindValue(":notes", $notes, PDO::PARAM_STR);
            $stmt->execute();
            $floatId = (int)$conn->lastInsertId();
            $this->insertFloatDenominations($conn, $floatId, $denominations);

            auditEvent($conn, [
                "module" => "Sales",
                "action" => "CashReplenish",
                "record_id" => $floatId,
                "record_label" => "Cash float replenishment",
                "details" => "Cash float replenished by " . $supervisor["display_name"] . ".",
                "after" => ["amount" => $amount, "expected_cash_before" => (float)($drawer["expected_cash"] ?? 0),
                            "counter_id" => $counterId, "shift_id" => (int)$openShift["shift_id"]],
                "authorized_by" => $supervisor["employee_id"],
                "authorized_role" => $supervisor["role_name"],
                "reference_label" => "Shift #" . (int)$openShift["shift_id"]
            ]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to record the cash float replenishment. Nothing was saved."];
        }

        return ["success" => true,
                "message" => "Cash float replenished by " . $supervisor["display_name"] . ".",
                "float_id" => $floatId, "amount" => $amount];
    }

    // ====================================================================
    // Cashier End-of-Shift (reworked 2026-10-21 for multiple shifts/day)
    //
    // A shift's identity is its shift_id + Open status, NOT the date. Many
    // sequential shifts per (employee, counter, day) are allowed; at most one
    // Open shift exists per counter and per cashier at any moment (enforced
    // by the generated-column unique keys from the 20261021 migration, with
    // the checks in openCashFloat as the friendly front door). Closed shifts
    // are permanent: there is no reopen operation.
    // Sales attribute to a shift by time range (tblsale.sale_date between
    // opened_at/closed_at, same employee+counter); float rows are also
    // stamped with shift_id. Physical-cash formula: cash retained = cash
    // amount_paid MINUS change given.
    // ====================================================================

    // The Open shift for this cashier + counter, if any.
    private function findOpenShift($conn, $employeeId, $counterId) {
        $stmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                       c.counter_name, c.counter_number
                                FROM tblcashier_shift sh
                                INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                WHERE sh.employee_id = :e AND sh.counter_id = :c
                                  AND sh.shift_status = 'Open'
                                ORDER BY sh.opened_at DESC LIMIT 1");
        $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    // The Open shift on a counter, whoever owns it (conflict checks).
    private function findOpenShiftForCounter($conn, $counterId) {
        $stmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                       c.counter_name, c.counter_number
                                FROM tblcashier_shift sh
                                INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                WHERE sh.counter_id = :c AND sh.shift_status = 'Open'
                                ORDER BY sh.opened_at DESC LIMIT 1");
        $stmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    // The Open shift owned by a cashier, on any counter (conflict checks).
    private function findOpenShiftForEmployee($conn, $employeeId) {
        $stmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                       c.counter_name, c.counter_number
                                FROM tblcashier_shift sh
                                INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                WHERE sh.employee_id = :e AND sh.shift_status = 'Open'
                                ORDER BY sh.opened_at DESC LIMIT 1");
        $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    // All of today's shifts for this cashier + counter, earliest first.
    private function getTodayShifts($conn, $employeeId, $counterId) {
        $stmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                       c.counter_name, c.counter_number
                                FROM tblcashier_shift sh
                                INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                WHERE sh.employee_id = :e AND sh.counter_id = :c
                                  AND sh.shift_date = CURDATE()
                                ORDER BY sh.opened_at ASC");
        $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Returns the Open shift if one exists; otherwise creates a NEW shift
    // from the latest Beginning float that is not already consumed by a
    // closed shift (a float created after the most recent close on this
    // cashier+counter). Never attaches to or resurrects a Closed shift.
    private function ensureShiftRow($conn, $employeeId, $counterId) {
        $open = $this->findOpenShift($conn, $employeeId, $counterId);
        if ($open) {
            return $open;
        }
        // A Beginning float is available for a new shift when it is not
        // stamped to any shift yet and was not already consumed by a closed
        // one (>=, not >: a close and the next open can share the same
        // second; a stamped float is excluded regardless of timing).
        $floatStmt = $conn->prepare("SELECT f.amount, f.created_at FROM tblcash_float f
                                     WHERE f.counter_id = :c AND f.employee_id = :e
                                       AND f.float_type = 'Beginning'
                                       AND f.shift_id IS NULL
                                       AND DATE(f.created_at) = CURDATE()
                                       AND f.created_at >= COALESCE(
                                             (SELECT MAX(closed_at) FROM tblcashier_shift
                                               WHERE employee_id = :e2 AND counter_id = :c2
                                                 AND shift_status = 'Closed'),
                                             '1000-01-01 00:00:00')
                                     ORDER BY f.float_id DESC LIMIT 1");
        $floatStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $floatStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $floatStmt->bindValue(":c2", $counterId, PDO::PARAM_INT);
        $floatStmt->bindValue(":e2", $employeeId, PDO::PARAM_INT);
        $floatStmt->execute();
        $float = $floatStmt->fetch(PDO::FETCH_ASSOC);
        if (!$float) {
            throw new PosException("Open the cash drawer first.");
        }
        try {
            $ins = $conn->prepare("INSERT INTO tblcashier_shift
                                   (employee_id, counter_id, shift_date, opened_at, opening_cash)
                                   VALUES (:e, :c, DATE(:opened), :opened, :amount)");
            $ins->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $ins->bindValue(":c", $counterId, PDO::PARAM_INT);
            $ins->bindValue(":opened", $float["created_at"]);
            $ins->bindValue(":amount", (float)$float["amount"]);
            $ins->execute();
        } catch (PDOException $ex) {
            $code = isset($ex->errorInfo[0]) ? (string)$ex->errorInfo[0] : "";
            $msg = isset($ex->errorInfo[2]) ? (string)$ex->errorInfo[2] : "";
            if ($code === "23000" && stripos($msg, "uq_active_") !== false) {
                throw new PosException("Another shift is already open for this counter or cashier. Close it before opening a new shift.");
            }
            throw $ex;
        }
        $shift = $this->findOpenShift($conn, $employeeId, $counterId);
        if (!$shift) {
            throw new PosException("Unable to record the shift. Please try again.");
        }
        return $shift;
    }

    public function ensureShift($counterId) {
        include "connection-pdo.php";
        $counterId = (int)$counterId;
        $employeeId = (int)$_SESSION["employee_id"];
        if ($counterId <= 0) {
            return ["success" => false, "message" => "Select a counter."];
        }
        try {
            $shift = $this->ensureShiftRow($conn, $employeeId, $counterId);
            return ["success" => true, "shift" => $shift];
        } catch (PosException $e) {
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            return ["success" => false, "message" => "Unable to record the shift. Please try again."];
        }
    }

    public function getShiftStatus($counterId) {
        include "connection-pdo.php";
        $counterId = (int)$counterId;
        $employeeId = (int)$_SESSION["employee_id"];
        if ($counterId <= 0) {
            return ["success" => false, "message" => "Select a counter."];
        }
        $shift = $this->findOpenShift($conn, $employeeId, $counterId);
        $today = $this->getTodayShifts($conn, $employeeId, $counterId);
        $closedToday = false;
        $lastClosedShiftId = null;
        foreach ($today as $row) {
            if ($row["shift_status"] === "Closed") {
                $closedToday = true;
                $lastClosedShiftId = (int)$row["shift_id"];
            }
        }
        $counterOpen = $this->findOpenShiftForCounter($conn, $counterId);
        $counterOpenInfo = null;
        if ($counterOpen && (int)$counterOpen["employee_id"] !== $employeeId) {
            $counterOpenInfo = [
                "shift_id" => (int)$counterOpen["shift_id"],
                "cashier_name" => trim($counterOpen["first_name"] . " " . $counterOpen["last_name"]),
                "opened_at" => $counterOpen["opened_at"]
            ];
        }
        return ["success" => true, "shift" => $shift,
                "closed_today" => $closedToday,
                "last_closed_shift_id" => $lastClosedShiftId,
                "counter_open_shift" => $counterOpenInfo];
    }

    // Counter occupancy for the opening-cash gate: every counter the POS
    // offers (same set populateCounters shows: status Open + active),
    // with the Open shift currently on it (if any), so a cashier whose
    // terminal defaulted to a taken counter can see which counters are
    // free and whose shift is on the taken one.
    public function getCounterOccupancy() {
        include "connection-pdo.php";
        $employeeId = (int)$_SESSION["employee_id"];

        $counterStmt = $conn->prepare("SELECT counter_id, counter_name, counter_number
                                       FROM tblcounter
                                       WHERE status = 'Open' AND is_active = 1
                                       ORDER BY counter_number ASC");
        $counterStmt->execute();
        $counterRows = $counterStmt->fetchAll(PDO::FETCH_ASSOC);

        // At most one Open shift per counter is enforced by the generated
        // unique keys (uq_active_counter); if legacy data ever held more,
        // the latest one wins here.
        $shiftStmt = $conn->prepare("SELECT sh.counter_id, sh.shift_id, sh.employee_id,
                                            sh.opened_at, e.first_name, e.last_name
                                     FROM tblcashier_shift sh
                                     INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                     WHERE sh.shift_status = 'Open'
                                     ORDER BY sh.opened_at ASC");
        $shiftStmt->execute();
        $openByCounter = [];
        foreach ($shiftStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $openByCounter[(int)$row["counter_id"]] = [
                "shift_id" => (int)$row["shift_id"],
                "employee_id" => (int)$row["employee_id"],
                "cashier_name" => trim($row["first_name"] . " " . $row["last_name"]),
                "opened_at" => $row["opened_at"],
                "is_mine" => (int)$row["employee_id"] === $employeeId
            ];
        }

        $counters = [];
        foreach ($counterRows as $row) {
            $counterId = (int)$row["counter_id"];
            $counters[] = [
                "counter_id" => $counterId,
                "counter_name" => $row["counter_name"],
                "counter_number" => (int)$row["counter_number"],
                "open_shift" => isset($openByCounter[$counterId]) ? $openByCounter[$counterId] : null
            ];
        }
        return ["success" => true, "counters" => $counters];
    }

    private function buildShiftReport($conn, $shift) {
        $employeeId = (int)$shift["employee_id"];
        $counterId = (int)$shift["counter_id"];
        $start = $shift["opened_at"];
        // NOTE (2026-10-18, amended 2026-10-21): for a CLOSED shift the
        // window ends at closed_at. For an OPEN shift the window is
        // open-ended: the next shift cannot open until this one closes, so
        // nothing after opened_at can belong to another shift. The old
        // NOW() bound silently dropped sales/lifts timestamped in the same
        // second as the report (timestamps have 1-second resolution).
        $end = !empty($shift["closed_at"])
            ? $shift["closed_at"]
            : "9999-12-31 23:59:59";

        $bindScope = function ($stmt) use ($counterId, $employeeId, $start, $end) {
            $stmt->bindValue(":c", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $stmt->bindValue(":start", $start);
            $stmt->bindValue(":end", $end);
        };
        $scope = "s.counter_id = :c AND s.employee_id = :e
                  AND s.sale_date >= :start AND s.sale_date < :end";

        // Completed sales: count, net, change given.
        $sumStmt = $conn->prepare("SELECT COUNT(*) AS cnt,
                                          COALESCE(SUM(s.total_amount), 0) AS net,
                                          COALESCE(SUM(s.change_amount), 0) AS change_total
                                   FROM tblsale s
                                   WHERE $scope AND s.sale_status = 'Completed'");
        $bindScope($sumStmt);
        $sumStmt->execute();
        $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

        // Gross from line items (tblsale stores net only).
        $grossStmt = $conn->prepare("SELECT COALESCE(SUM(si.subtotal), 0) AS gross
                                    FROM tblsale_item si
                                    INNER JOIN tblsale s ON s.sale_id = si.sale_id
                                    WHERE $scope AND s.sale_status = 'Completed'");
        $bindScope($grossStmt);
        $grossStmt->execute();
        $gross = round((float)$grossStmt->fetchColumn(), 2);

        // Voided sales, attributed by sale_date even when voided later.
        $voidStmt = $conn->prepare("SELECT COUNT(*) AS cnt,
                                           COALESCE(SUM(s.total_amount), 0) AS total
                                    FROM tblsale s
                                    WHERE $scope AND s.sale_status = 'Voided'");
        $bindScope($voidStmt);
        $voidStmt->execute();
        $void = $voidStmt->fetch(PDO::FETCH_ASSOC);

        // Payment breakdown per type (Completed only; split payments stay separate rows).
        $payStmt = $conn->prepare("SELECT pt.type_name, COALESCE(SUM(p.amount_paid), 0) AS total
                                   FROM tblpayment p
                                   INNER JOIN tblinvoice i ON i.invoice_id = p.invoice_id
                                   INNER JOIN tblsale s ON s.sale_id = i.sale_id
                                   INNER JOIN tblpayment_type pt ON pt.payment_type_id = p.payment_type_id
                                   WHERE $scope AND s.sale_status = 'Completed'
                                   GROUP BY pt.type_name");
        $bindScope($payStmt);
        $payStmt->execute();
        $byType = [];
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byType[$row["type_name"]] = (float)$row["total"];
        }
        $payments = [];
        foreach (["Cash", "GCash", "Suki Points", "Gift Certificate"] as $typeName) {
            $payments[] = [
                "type_name" => $typeName,
                "total" => round(isset($byType[$typeName]) ? $byType[$typeName] : 0, 2)
            ];
        }

        // AR credit sales: invoice rows with no payment rows; never counted as cash.
        $arStmt = $conn->prepare("SELECT COALESCE(SUM(i.amount), 0) AS ar_total
                                  FROM tblinvoice i
                                  INNER JOIN tblsale s ON s.sale_id = i.sale_id
                                  WHERE $scope AND s.sale_status = 'Completed'
                                    AND i.transaction_type = 'Credit'");
        $bindScope($arStmt);
        $arStmt->execute();
        $arTotal = round((float)$arStmt->fetchColumn(), 2);

        // Cash reconciliation — CORRECTED physical-cash formula:
        // cash retained = cash amount_paid MINUS change given.
        $cashPaid = round(isset($byType["Cash"]) ? $byType["Cash"] : 0, 2);
        $changeTotal = round((float)$sum["change_total"], 2);
        $cashReceived = round($cashPaid - $changeTotal, 2);

        // Stamped lifts belong to their shift regardless of timestamps
        // (1-second resolution would otherwise leak a previous shift's lift
        // into this window when the handover shares a second). Unstamped
        // legacy rows keep the time-window rule.
        $liftStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS lifts
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

        // Replenishments: same scoping as the lift query above (stamped rows
        // belong to their shift regardless of timestamps; unstamped legacy
        // rows keep the time-window rule).
        $replenishStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS replenishments
                                    FROM tblcash_float
                                    WHERE counter_id = :c AND employee_id = :e
                                      AND float_type = 'Replenish'
                                      AND ((shift_id IS NOT NULL AND shift_id = :sid)
                                           OR (shift_id IS NULL AND created_at >= :start AND created_at < :end))");
        $replenishStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $replenishStmt->bindValue(":sid", (int)$shift["shift_id"], PDO::PARAM_INT);
        $replenishStmt->bindValue(":start", $start);
        $replenishStmt->bindValue(":end", $end);
        $replenishStmt->execute();
        $replenishments = round((float)$replenishStmt->fetchColumn(), 2);

        $opening = round((float)$shift["opening_cash"], 2);
        $expected = round($opening + $cashReceived + $replenishments - $lifts, 2);

        // Incomplete (held) transactions for this cashier + counter.
        $heldStmt = $conn->prepare("SELECT held_id, held_label, created_at
                                    FROM tblheld_transaction
                                    WHERE employee_id = :e AND counter_id = :c
                                    ORDER BY created_at");
        $heldStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
        $heldStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
        $heldStmt->execute();
        $held = $heldStmt->fetchAll(PDO::FETCH_ASSOC);

        $net = round((float)$sum["net"], 2);

        // Authorizer (Supervisor/Administrator who approved the close) and
        // deposit verifier names — set only on shifts closed after the
        // 20261021 migration; NULL on older rows.
        $lookupEmployee = function ($id) use ($conn) {
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
        };
        $authorizer = $lookupEmployee(isset($shift["authorized_by"]) ? $shift["authorized_by"] : null);
        $verifier = $lookupEmployee(isset($shift["deposit_verified_by"]) ? $shift["deposit_verified_by"] : null);

        return [
            "shift" => [
                "shift_id" => (int)$shift["shift_id"],
                "employee_id" => $employeeId,
                // Name/counter label come from the JOIN in getShiftReport.
                // closeShift calls this with the raw tblcashier_shift row
                // (no join), where these keys do not exist — reading them
                // directly raised PHP warnings that printed into the JSON
                // response and corrupted it (the close itself had already
                // committed, so the cashier saw a false "not closed"
                // error). Null-safe reads fix that; the post-close
                // getShiftReport call re-reads WITH the join, so the
                // displayed report still shows the real names.
                "cashier_name" => trim(($shift["first_name"] ?? "") . " " . ($shift["last_name"] ?? "")),
                "counter_id" => $counterId,
                "counter_name" => $shift["counter_name"] ?? null,
                "counter_number" => (int)($shift["counter_number"] ?? 0),
                "shift_date" => $shift["shift_date"],
                "opened_at" => $shift["opened_at"],
                "closed_at" => $shift["closed_at"],
                "opening_cash" => $opening,
                "shift_status" => $shift["shift_status"],
                "closing_notes" => isset($shift["closing_notes"]) ? $shift["closing_notes"] : null,
                "authorized_by" => $authorizer
            ],
            "deposit" => [
                // Deposit reference (2026-10-21, Option A — derived, NOT
                // stored): DEP-YYYYMMDD-#### from the shift's date + its
                // primary key, so it is unique by construction, needs no
                // sequence table / COUNT race, and exists for every shift
                // (open ones simply have no deposit yet). Following the
                // receipt convention OR-YYYYMMDD-##### already used for sales.
                "ref" => "DEP-" . str_replace("-", "", (string)$shift["shift_date"]) . "-" .
                         str_pad((string)(int)$shift["shift_id"], 4, "0", STR_PAD_LEFT),
                "bag_no" => isset($shift["deposit_bag_no"]) ? $shift["deposit_bag_no"] : null,
                "amount" => isset($shift["deposit_amount"]) && $shift["deposit_amount"] !== null
                    ? round((float)$shift["deposit_amount"], 2) : null,
                "verified_amount" => isset($shift["deposit_verified_amount"]) && $shift["deposit_verified_amount"] !== null
                    ? round((float)$shift["deposit_verified_amount"], 2) : null,
                "difference" => isset($shift["deposit_difference"]) && $shift["deposit_difference"] !== null
                    ? round((float)$shift["deposit_difference"], 2) : null,
                "verified_by" => $verifier,
                "verified_at" => isset($shift["deposit_verified_at"]) ? $shift["deposit_verified_at"] : null
            ],
            "sales" => [
                "count" => (int)$sum["cnt"],
                "gross" => $gross,
                "discount" => round($gross - $net, 2),
                "net" => $net,
                "voided_count" => (int)$void["cnt"],
                "voided_total" => round((float)$void["total"], 2)
            ],
            "payments" => $payments,
            "ar_total" => $arTotal,
            "cash" => [
                "opening_cash" => $opening,
                "cash_received" => $cashReceived,
                "cash_lifts" => $lifts,
                "cash_replenishments" => $replenishments,
                "expected_cash" => $expected,
                "actual_cash" => $shift["actual_cash"] === null ? null : round((float)$shift["actual_cash"], 2),
                "cash_difference" => $shift["cash_difference"] === null ? null : round((float)$shift["cash_difference"], 2)
            ],
            "held" => $held,
            "refunds" => [
                "total" => 0.00,
                "note" => "Refunds / paid-outs are not supported by the system."
            ]
        ];
    }

    public function getShiftReport($counterId, $shiftId = 0) {
        include "connection-pdo.php";
        $counterId = (int)$counterId;
        $employeeId = (int)$_SESSION["employee_id"];
        if ($counterId <= 0) {
            return ["success" => false, "message" => "Select a counter."];
        }
        if ((int)$shiftId > 0) {
            // Ownership check: cashiers may only view their own shifts.
            $stmt = $conn->prepare("SELECT sh.*, e.first_name, e.last_name,
                                           c.counter_name, c.counter_number
                                    FROM tblcashier_shift sh
                                    INNER JOIN tblemployee e ON e.employee_id = sh.employee_id
                                    INNER JOIN tblcounter c ON c.counter_id = sh.counter_id
                                    WHERE sh.shift_id = :id AND sh.employee_id = :e");
            $stmt->bindValue(":id", (int)$shiftId, PDO::PARAM_INT);
            $stmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                return ["success" => false, "message" => "Shift not found."];
            }
        } else {
            $shift = $this->findOpenShift($conn, $employeeId, $counterId);
            if (!$shift) {
                return ["success" => true, "shift" => null, "report" => null];
            }
        }
        return ["success" => true, "report" => $this->buildShiftReport($conn, $shift)];
    }

    public function closeShift($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        $counterId = (int)($data["counter_id"] ?? 0);
        $actualRaw = isset($data["actual_cash"]) ? $data["actual_cash"] : null;
        $actual = is_numeric($actualRaw) ? round((float)$actualRaw, 2) : null;
        $notes = trim((string)($data["closing_notes"] ?? ""));
        $employeeId = (int)$_SESSION["employee_id"];

        // Deposit (recorded at close; saved in the same transaction below).
        $bagNo = trim((string)($data["deposit_bag_no"] ?? ""));
        $sealedRaw = isset($data["deposit_amount"]) ? $data["deposit_amount"] : null;
        $sealed = is_numeric($sealedRaw) ? round((float)$sealedRaw, 2) : null;
        $verifiedRaw = isset($data["deposit_verified_amount"]) ? $data["deposit_verified_amount"] : null;
        $verifiedAmt = is_numeric($verifiedRaw) ? round((float)$verifiedRaw, 2) : null;

        if ($counterId <= 0) {
            return ["success" => false, "message" => "Select a counter."];
        }
        if ($actual === null || $actual < 0) {
            return ["success" => false, "message" => "Enter the actual cash counted (0 or more)."];
        }
        if ($bagNo === "") {
            return ["success" => false, "message" => "Enter the deposit bag label / number."];
        }
        if ($sealed === null || $sealed < 0) {
            return ["success" => false, "message" => "Enter the amount sealed in the bag (0 or more)."];
        }
        if ($verifiedAmt === null || $verifiedAmt < 0) {
            return ["success" => false, "message" => "Enter the verifier's counted amount (0 or more)."];
        }

        try {
            $conn->beginTransaction();

            // Lock the OPEN shift by identity (shift_id + status), not by
            // date — a shift opened before midnight still closes correctly.
            $lockStmt = $conn->prepare("SELECT * FROM tblcashier_shift
                                        WHERE employee_id = :e AND counter_id = :c
                                          AND shift_status = 'Open'
                                        ORDER BY opened_at DESC LIMIT 1
                                        FOR UPDATE");
            $lockStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $lockStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
            $lockStmt->execute();
            $shift = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                throw new PosException("No open shift found. Open the cash drawer first.");
            }

            // Supervisor/Administrator authorization is required on EVERY
            // close. The same credentials also cover the held-transaction
            // override below. A shortage or excess never blocks an
            // authorized close — the variance is recorded, not resolved.
            $authReason = null;
            $authorizer = $this->verifySupervisor(
                isset($data["supervisor_user"]) ? $data["supervisor_user"] : "",
                isset($data["supervisor_pass"]) ? $data["supervisor_pass"] : "",
                $authReason
            );
            if (!$authorizer) {
                auditAttempt([
                    "module" => "Supervisor Authorization",
                    "action" => "closeShift",
                    "outcome" => "Denied",
                    "record_label" => trim((string)($data["supervisor_user"] ?? "")) !== "" ? trim((string)$data["supervisor_user"]) : null,
                    "details" => "Shift close authorization denied.",
                    "reason" => $authReason ?? "Invalid credentials."
                ]);
                throw new PosException("Supervisor/Administrator authorization is required to close the shift. " .
                    ($authReason ?? "Invalid credentials."));
            }

            // No duplicate deposit-bag guard (removed 2026-10-29):
            // the bag label is a REUSABLE container, not a unique
            // sealed bag. "black bag" is the bag the admin uses to
            // carry cash, shift after shift, so reusing the same
            // label must close again. What stays unique per shift is
            // the deposit reference below (DEP-YYYYMMDD-####, built
            // from this shift's own shift_id), plus the shift_id,
            // date, amount, and verifier recorded with it — so two
            // shifts can both say "black bag" and still be told
            // apart in the report. The bag label itself is still
            // required (non-empty, checked above) and still saved
            // on this shift, just never blocked for reuse.

            // Deposit verifier: any other active employee (staff or
            // supervisor) who double-counted the sealed cash. Never the
            // cashier herself.
            $verifierReason = null;
            $verifier = $this->verifyEmployee(
                isset($data["verifier_user"]) ? $data["verifier_user"] : "",
                isset($data["verifier_pass"]) ? $data["verifier_pass"] : "",
                $verifierReason
            );
            if (!$verifier) {
                throw new PosException("Deposit verification failed. " . ($verifierReason ?? "Invalid verifier credentials."));
            }
            if ((int)$verifier["employee_id"] === $employeeId) {
                throw new PosException("You cannot verify your own deposit. Another staff member or a supervisor must double-count the sealed cash.");
            }
            // Documented, never blocking.
            $depositDifference = round($verifiedAmt - $sealed, 2);

            // Incomplete transactions: the authorization above satisfies the
            // override, but the cashier is told what is being overridden.
            $heldStmt = $conn->prepare("SELECT held_id, held_label FROM tblheld_transaction
                                        WHERE employee_id = :e AND counter_id = :c
                                        ORDER BY created_at");
            $heldStmt->bindValue(":e", $employeeId, PDO::PARAM_INT);
            $heldStmt->bindValue(":c", $counterId, PDO::PARAM_INT);
            $heldStmt->execute();
            $held = $heldStmt->fetchAll(PDO::FETCH_ASSOC);

            $report = $this->buildShiftReport($conn, $shift);
            $expected = $report["cash"]["expected_cash"];
            $difference = round($actual - $expected, 2);

            if (abs($difference) > 0.004 && $notes === "") {
                throw new PosException("Add a note explaining the cash difference of " .
                    $this->formatPeso($difference) . ".");
            }

            // Atomic close: the status guard makes duplicate closure
            // impossible. Closed shifts are permanent — nothing reopens them.
            $upd = $conn->prepare("UPDATE tblcashier_shift
                                   SET closed_at = NOW(),
                                       expected_cash = :exp,
                                       actual_cash = :act,
                                       cash_difference = :diff,
                                       closing_notes = :notes,
                                       shift_status = 'Closed',
                                       closed_by = :emp,
                                       authorized_by = :auth,
                                       deposit_bag_no = :bag,
                                       deposit_amount = :sealed,
                                       deposit_verified_amount = :verified,
                                       deposit_difference = :depdiff,
                                       deposit_verified_by = :verifier,
                                       deposit_verified_at = NOW()
                                   WHERE shift_id = :id AND shift_status = 'Open'");
            $upd->bindValue(":exp", $expected);
            $upd->bindValue(":act", $actual);
            $upd->bindValue(":diff", $difference);
            $upd->bindValue(":notes", $notes !== "" ? $notes : null, $notes !== "" ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $upd->bindValue(":emp", $employeeId, PDO::PARAM_INT);
            $upd->bindValue(":auth", (int)$authorizer["employee_id"], PDO::PARAM_INT);
            $upd->bindValue(":bag", $bagNo, PDO::PARAM_STR);
            $upd->bindValue(":sealed", $sealed);
            $upd->bindValue(":verified", $verifiedAmt);
            $upd->bindValue(":depdiff", $depositDifference);
            $upd->bindValue(":verifier", (int)$verifier["employee_id"], PDO::PARAM_INT);
            $upd->bindValue(":id", (int)$shift["shift_id"], PDO::PARAM_INT);
            $upd->execute();
            if ($upd->rowCount() === 0) {
                throw new PosException("This shift was already closed.");
            }

            $depositRef = "DEP-" . str_replace("-", "", (string)$shift["shift_date"]) . "-" . str_pad((string)(int)$shift["shift_id"], 4, "0", STR_PAD_LEFT);
            auditEvent($conn, [
                "module" => "Sales",
                "action" => "ShiftClosed",
                "record_id" => (int)$shift["shift_id"],
                "record_label" => "Shift #" . (int)$shift["shift_id"],
                "details" => "Shift closed. Cash difference " . $difference . ".",
                "before" => ["shift_status" => "Open", "opening_cash" => $shift["opening_cash"]],
                "after" => ["expected_cash" => $expected, "actual_cash" => $actual, "cash_difference" => $difference,
                            "deposit_bag_no" => $bagNo, "deposit_amount" => $sealed,
                            "deposit_verified_amount" => $verifiedAmt, "deposit_difference" => $depositDifference,
                            "deposit_verified_by" => (int)$verifier["employee_id"], "counter_id" => $counterId],
                "reason" => $notes !== "" ? $notes : null,
                "authorized_by" => (int)$authorizer["employee_id"],
                "authorized_role" => $authorizer["role_name"],
                "reference_label" => $depositRef
            ]);

            $conn->commit();
        } catch (PosException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ["success" => false, "message" => "Failed to close the shift. Nothing was saved."];
        }

        $result = $this->getShiftReport($counterId, (int)$shift["shift_id"]);
        $result["held_overridden"] = count($held ?? []);
        return $result;
    }

    private function formatPeso($amount) {
        return "\u{20B1}" . number_format((float)$amount, 2);
    }

    public function getPaymentReport($date) {
        include "connection-pdo.php";

        $date = trim((string)$date);
        if ($date === "") {
            $date = date("Y-m-d");
        }

        $cashStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid), 0)
                                    FROM tblpayment pay
                                    INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id
                                    INNER JOIN tblsale s ON inv.sale_id = s.sale_id
                                    INNER JOIN tblpayment_type pt ON pay.payment_type_id = pt.payment_type_id
                                    WHERE s.employee_id = :employee_id
                                      AND s.sale_status = 'Completed'
                                      AND pt.type_name = 'Cash'
                                      AND DATE(pay.payment_date) = :report_date");
        $cashStmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $cashStmt->bindValue(":report_date", $date, PDO::PARAM_STR);
        $cashStmt->execute();

        $gcashStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid), 0)
                                     FROM tblpayment pay
                                     INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id
                                     INNER JOIN tblsale s ON inv.sale_id = s.sale_id
                                     INNER JOIN tblpayment_type pt ON pay.payment_type_id = pt.payment_type_id
                                     WHERE s.employee_id = :employee_id
                                       AND s.sale_status = 'Completed'
                                       AND pt.type_name = 'GCash'
                                       AND DATE(pay.payment_date) = :report_date");
        $gcashStmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $gcashStmt->bindValue(":report_date", $date, PDO::PARAM_STR);
        $gcashStmt->execute();

        $arStmt = $conn->prepare("SELECT COALESCE(SUM(inv.amount), 0)
                                  FROM tblinvoice inv
                                  INNER JOIN tblsale s ON inv.sale_id = s.sale_id
                                  WHERE s.employee_id = :employee_id
                                    AND s.sale_status = 'Completed'
                                    AND inv.transaction_type = 'Credit'
                                    AND DATE(inv.transaction_date) = :report_date");
        $arStmt->bindValue(":employee_id", (int)$_SESSION["employee_id"], PDO::PARAM_INT);
        $arStmt->bindValue(":report_date", $date, PDO::PARAM_STR);
        $arStmt->execute();

        return [
            "success" => true,
            "date" => $date,
            "cash" => round((float)$cashStmt->fetchColumn(), 2),
            "gcash" => round((float)$gcashStmt->fetchColumn(), 2),
            "ar" => round((float)$arStmt->fetchColumn(), 2)
        ];
    }

    // Header-detail insert: tblsale (header) + tblsale_item (detail) inside ONE transaction.
    // Prices, discount, VAT, credit limit and points are all recalculated here on the server -
    // the browser only sends product ids and quantities.
    public function insertSale($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = !empty($data["counter_id"]) ? (int)$data["counter_id"] : 0;
        $customerId = !empty($data["customer_id"]) ? (int)$data["customer_id"] : null;
        $discountId = !empty($data["discount_id"]) ? (int)$data["discount_id"] : null;
        $useAr = !empty($data["use_ar"]);
        $payments = isset($data["payments"]) && is_array($data["payments"]) ? $data["payments"] : [];
        $items = isset($data["items"]) && is_array($data["items"]) ? $data["items"] : [];
        $cashierId = (int)$_SESSION["employee_id"];

        if ($counterId <= 0 || count($items) === 0) {
            return ["success" => false, "message" => "Select a counter and add at least one item."];
        }

        try {
            $cart = [];
            foreach ($items as $item) {
                $productId = (int)($item["product_id"] ?? 0);
                $quantity = (int)($item["quantity"] ?? 0);
                if ($productId <= 0 || $quantity <= 0 || $quantity > 999) {
                    throw new PosException("Every item needs a valid product and a quantity from 1 to 999.");
                }
                $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
            }

            $conn->beginTransaction();

            $counterStmt = $conn->prepare("SELECT status, is_active FROM tblcounter WHERE counter_id = :id FOR UPDATE");
            $counterStmt->bindValue(":id", $counterId, PDO::PARAM_INT);
            $counterStmt->execute();
            $counter = $counterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$counter || (int)$counter["is_active"] !== 1) throw new PosException("Selected counter is not available.");
            if ($counter["status"] !== "Open") throw new PosException("Selected counter is closed.");

            // Server-side enforcement of the opening-cash gate: no sale
            // without an open shift for this cashier + counter.
            $shift = $this->findOpenShift($conn, $cashierId, $counterId);
            if (!$shift) throw new PosException("No open shift for this counter. Open your cash float before selling.");

            $defaultTierId = $this->getDefaultPriceTierId($conn);
            $tierId = $defaultTierId;
            $customerType = "";
            $sukiCard = null;
            $arAccount = null;

            if ($customerId !== null) {
                $customerStmt = $conn->prepare("SELECT c.is_active, c.price_tier_id, c.customer_type
                           FROM tblcustomer c WHERE c.customer_id = :id");
                $customerStmt->bindValue(":id", $customerId, PDO::PARAM_INT);
                $customerStmt->execute();
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
                if (!$customer || (int)$customer["is_active"] !== 1) throw new PosException("Selected customer is not active.");
                $tierId = (int)$customer["price_tier_id"];
                $customerType = (string)($customer["customer_type"] ?? "");

                $sukiProgressSelect = $this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount") ? ", suki_progress_amount" : ", 0 AS suki_progress_amount";
                $sukiStmt = $conn->prepare("SELECT suki_card_id, card_number, points_balance" . $sukiProgressSelect . " FROM tblsuki_card WHERE customer_id = :id AND is_active = 1 ORDER BY suki_card_id ASC LIMIT 1 FOR UPDATE");
                $sukiStmt->bindValue(":id", $customerId, PDO::PARAM_INT);
                $sukiStmt->execute();
                $sukiCard = $sukiStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $arStmt = $conn->prepare("SELECT ar_account_id, credit_term_id, credit_limit, current_balance, credit_status FROM tblar_account WHERE customer_id = :id AND account_status = 'Active' ORDER BY ar_account_id ASC LIMIT 1 FOR UPDATE");
                $arStmt->bindValue(":id", $customerId, PDO::PARAM_INT);
                $arStmt->execute();
                $arAccount = $arStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($arAccount) {
                    // Late fee / auto-hold catch-up inside the sale transaction,
                    // so the Hold check and credit limit below use fresh figures.
                    // Best-effort: a policy failure never blocks the sale itself.
                    try {
                        arApplyPolicyInTxn($conn, (int)$arAccount["ar_account_id"]);
                        $hasHoldCols = arHoldColumnsExist($conn);
                        $reStmt = $conn->prepare("SELECT ar_account_id, credit_term_id, credit_limit, current_balance, credit_status" . ($hasHoldCols ? ", credit_hold_source, credit_hold_invoice_id" : "") . " FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
                        $reStmt->bindValue(":id", (int)$arAccount["ar_account_id"], PDO::PARAM_INT);
                        $reStmt->execute();
                        $fresh = $reStmt->fetch(PDO::FETCH_ASSOC);
                        if ($fresh) $arAccount = $fresh;
                    } catch (Throwable $policyError) {
                        // Continue with the figures already read.
                    }
                }
            }

            $wholesaleTierId = $this->getWholesalePriceTierId($conn);
            $sukiEligiblePriceSelect = $this->sukiColumnExists($conn, "tblproduct", "is_suki_eligible") ? ", p.is_suki_eligible" : ", 1 AS is_suki_eligible";
            $priceStmt = $conn->prepare("SELECT p.product_name, p.unit, p.is_vatable, p.is_wholesale_barcode" . $sukiEligiblePriceSelect . ", COALESCE(pp.price, dp.price) AS price, wp.price AS wholesale_price
                                         FROM tblproduct p
                                         INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id
                                         LEFT JOIN tblproduct_price pp ON pp.product_id = p.product_id AND pp.price_tier_id = :tier_id
                                         LEFT JOIN tblproduct_price dp ON dp.product_id = p.product_id AND dp.price_tier_id = :default_tier_id
                                         LEFT JOIN tblproduct_price wp ON wp.product_id = p.product_id AND wp.price_tier_id = :wholesale_tier_id
                                         WHERE p.product_id = :product_id AND p.is_active = 1 AND pc.is_active = 1");
            $lines = [];
            $subtotal = 0;
            $vatableGross = 0;
            foreach ($cart as $productId => $quantity) {
                $priceStmt->bindValue(":tier_id", $tierId, PDO::PARAM_INT);
                $priceStmt->bindValue(":default_tier_id", $defaultTierId, PDO::PARAM_INT);
                $priceStmt->bindValue(":wholesale_tier_id", $wholesaleTierId, PDO::PARAM_INT);
                $priceStmt->bindValue(":product_id", $productId, PDO::PARAM_INT);
                $priceStmt->execute();
                $product = $priceStmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) throw new PosException("A product in the cart is no longer available or has no price. Refresh the product list.");
                if ((int)$product["is_wholesale_barcode"] === 1) {
                    // Marked wholesale-barcode product: ACCESS follows the
                    // Customer Type, and ONLY the Wholesale price is used
                    // (never the tier price, never the Regular fallback).
                    if (!$this->customerHasWholesaleAccess($customerType)) {
                        throw new PosException($product["product_name"] . " is a wholesale-barcode product. Only a Wholesale customer may buy it — remove that line, or ring the contents with the regular product barcode.");
                    }
                    if ($product["wholesale_price"] === null) {
                        throw new PosException($product["product_name"] . " has no Wholesale price and cannot be sold.");
                    }
                    $product["price"] = $product["wholesale_price"];
                }
                if ($product["price"] === null) throw new PosException("A product in the cart is no longer available or has no price. Refresh the product list.");
                $unitPrice = round((float)$product["price"], 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $lines[] = [
                    "product_id" => $productId,
                    "product_name" => $product["product_name"],
                    "unit" => $product["unit"],
                    "unit_price" => $unitPrice,
                    "quantity" => $quantity,
                    "is_vatable" => (int)$product["is_vatable"],
                    "is_suki_eligible" => (int)($product["is_suki_eligible"] ?? 1),
                    "line_total" => $lineTotal
                ];
                $subtotal += $lineTotal;
                if ((int)$product["is_vatable"] === 1) $vatableGross += $lineTotal;
            }
            $subtotal = round($subtotal, 2);

            $discountAmount = 0;
            if ($discountId !== null) {
                $discountStmt = $conn->prepare("SELECT discount_type, discount_value, is_active FROM tbldiscount WHERE discount_id = :id");
                $discountStmt->bindValue(":id", $discountId, PDO::PARAM_INT);
                $discountStmt->execute();
                $discount = $discountStmt->fetch(PDO::FETCH_ASSOC);
                if (!$discount || (int)$discount["is_active"] !== 1) throw new PosException("Selected discount is not active.");
                if ($discount["discount_type"] === "Percentage") $discountAmount = round($subtotal * ((float)$discount["discount_value"] / 100), 2);
                else $discountAmount = min(round((float)$discount["discount_value"], 2), $subtotal);
            }

            $totalAmount = round($subtotal - $discountAmount, 2);
            $ratio = $subtotal > 0 ? $totalAmount / $subtotal : 0;
            $vatableNet = $vatableGross * $ratio;
            $vatAmount = round($vatableNet - ($vatableNet / (1 + $this->vatRate)), 2);

            $arAccountId = null;
            $changeAmount = 0;
            $amountTendered = 0;
            $validatedPayments = [];
            $pointsToRedeem = 0;
            $gcRemainingThisSale = [];
            $gccToRedeem = [];
            $gccUsedThisSale = [];

            if ($useAr) {
                if ($customerId === null || !$arAccount) throw new PosException("Charge to AR needs a customer with an active AR account.");
                if ($arAccount["credit_status"] === "Hold") {
                    $holdMsg = "This customer's credit is on Hold. Charge to AR is not allowed.";
                    if (($arAccount["credit_hold_source"] ?? null) === "AutoOverdue" && !empty($arAccount["credit_hold_invoice_id"])) {
                        try {
                            $hiStmt = $conn->prepare("SELECT s.receipt_number, DATEDIFF(CURDATE(), COALESCE(inv.due_date, DATE(inv.transaction_date))) AS d FROM tblinvoice inv INNER JOIN tblsale s ON s.sale_id = inv.sale_id WHERE inv.invoice_id = :id");
                            $hiStmt->bindValue(":id", (int)$arAccount["credit_hold_invoice_id"], PDO::PARAM_INT);
                            $hiStmt->execute();
                            $hi = $hiStmt->fetch(PDO::FETCH_ASSOC);
                            if ($hi) $holdMsg = "This customer's credit is on AUTOMATIC Hold — invoice " . $hi["receipt_number"] . " is " . (int)$hi["d"] . " days overdue. Only the AR Manager can release it. Cash sale is allowed.";
                        } catch (Throwable $ignored) {}
                    }
                    throw new PosException($holdMsg);
                }
                $availableCredit = (float)$arAccount["credit_limit"] - (float)$arAccount["current_balance"];
                if ($totalAmount > $availableCredit + 0.001) throw new PosException("Over credit limit. Available credit is only ₱" . number_format($availableCredit, 2) . ".");
                if (count($payments) > 0) throw new PosException("AR charging cannot be combined with other payment methods in this POS.");
                $arAccountId = (int)$arAccount["ar_account_id"];
            } else {
                if (count($payments) === 0) throw new PosException("Add at least one payment method.");

                $paymentTypeStmt = $conn->prepare("SELECT payment_type_id, type_name, is_active FROM tblpayment_type WHERE payment_type_id = :id");
                foreach ($payments as $index => $paymentInput) {
                    $paymentTypeId = !empty($paymentInput["payment_type_id"]) ? (int)$paymentInput["payment_type_id"] : 0;
                    $amount = isset($paymentInput["amount"]) ? round((float)$paymentInput["amount"], 2) : 0;
                    $referenceNo = trim((string)($paymentInput["reference_no"] ?? ""));
                    $gcId = !empty($paymentInput["gc_id"]) ? (int)$paymentInput["gc_id"] : null;
                    $gccCode = isset($paymentInput["gcc_code"]) ? strtoupper(trim((string)$paymentInput["gcc_code"])) : "";
                    $gccId = null;
                    $gccCodeApplied = "";

                    if ($paymentTypeId <= 0 || $amount <= 0) throw new PosException("Payment #" . ($index + 1) . " needs a valid payment type and positive amount.");
                    $paymentTypeStmt->bindValue(":id", $paymentTypeId, PDO::PARAM_INT);
                    $paymentTypeStmt->execute();
                    $paymentType = $paymentTypeStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$paymentType || (int)$paymentType["is_active"] !== 1) throw new PosException("One of the selected payment methods is no longer active.");
                    $typeName = strtolower(trim($paymentType["type_name"]));

                    if ($typeName === "suki points") {
                        if (!$sukiCard) throw new PosException("A Suki Card customer must be selected before using Suki Points.");
                        if ($gcId !== null || $referenceNo !== "") throw new PosException("Suki Points do not require a certificate or reference number.");
                        if (abs($amount - round($amount)) > 0.001) throw new PosException("Suki Points must be redeemed as whole points.");
                        $pointAmount = (int)round($amount);
                        if ($pointsToRedeem + $pointAmount > (int)$sukiCard["points_balance"]) {
                            throw new PosException("Insufficient Suki Points. Available balance is " . (int)$sukiCard["points_balance"] . " point(s).");
                        }
                        $pointsToRedeem += $pointAmount;
                    } elseif ($typeName === "gift certificate") {
                        if ($gcId) {
                            // Legacy path: Suki-bound certificate, partial redemption allowed.
                            if ($referenceNo !== "") throw new PosException("Gift Certificate payments use the certificate ID, not a reference number.");

                            $gcStmt = $conn->prepare("SELECT gc_id, suki_card_id, gc_value, expiration_date, status FROM tblgift_certificate WHERE gc_id = :id FOR UPDATE");
                            $gcStmt->bindValue(":id", $gcId, PDO::PARAM_INT);
                            $gcStmt->execute();
                            $gc = $gcStmt->fetch(PDO::FETCH_ASSOC);
                            if (!$gc) throw new PosException("Gift Certificate not found.");
                            if (strtolower((string)$gc["status"]) === "cancelled") throw new PosException("Gift Certificate Cancelled. This certificate can no longer be used.");
                            if (!empty($gc["expiration_date"]) && $gc["expiration_date"] < date("Y-m-d")) throw new PosException("Gift Certificate Expired. This certificate can no longer be used.");

                            $usedStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid), 0) FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id INNER JOIN tblsale ss ON inv.sale_id = ss.sale_id WHERE pay.gc_id = :gc_id AND ss.sale_status = 'Completed'");
                            $usedStmt->bindValue(":gc_id", $gcId, PDO::PARAM_INT);
                            $usedStmt->execute();
                            $used = (float)$usedStmt->fetchColumn();
                            $storedRemaining = round((float)$gc["gc_value"] - $used, 2);
                            $remaining = array_key_exists($gcId, $gcRemainingThisSale) ? $gcRemainingThisSale[$gcId] : $storedRemaining;
                            if ($remaining <= 0.001) throw new PosException("Gift Certificate Already Used. This certificate has no remaining balance.");
                            if ($amount > $remaining + 0.001) throw new PosException("Gift Certificate payment exceeds the remaining balance of ₱" . number_format($remaining, 2) . ".");
                            $gcRemainingThisSale[$gcId] = round($remaining - $amount, 2);
                        } elseif ($gccCode !== "") {
                            // Standalone code-based certificate: NO partial redemption.
                            // The applied amount may be capped below the face value;
                            // the excess is forfeited (never returned as change) and
                            // the certificate is consumed in full on sale completion.
                            if ($referenceNo !== "") throw new PosException("Gift Certificate payments use the certificate code, not a reference number.");
                            if (isset($gccUsedThisSale[$gccCode])) throw new PosException("Certificate " . $gccCode . " is already applied to this sale.");

                            $gccStmt = $conn->prepare("SELECT gcc_id, code, face_value, expiration_date, status FROM tblgift_certificate_code WHERE code = :code FOR UPDATE");
                            $gccStmt->bindValue(":code", $gccCode, PDO::PARAM_STR);
                            $gccStmt->execute();
                            $gcc = $gccStmt->fetch(PDO::FETCH_ASSOC);
                            if (!$gcc) throw new PosException("Gift certificate code not found.");
                            if ($gcc["status"] === "Voided") throw new PosException("This gift certificate has been voided and cannot be used.");
                            if ($gcc["status"] === "Redeemed") throw new PosException("This gift certificate has already been redeemed.");
                            if (!empty($gcc["expiration_date"]) && $gcc["expiration_date"] < date("Y-m-d")) throw new PosException("This gift certificate expired on " . $gcc["expiration_date"] . ".");

                            $faceValue = round((float)$gcc["face_value"], 2);
                            if ($amount > $faceValue + 0.001) throw new PosException("Gift certificate payment cannot exceed its face value of ₱" . number_format($faceValue, 2) . ".");
                            // The certificate is consumed in full further below, so the
                            // applied amount must be exactly min(face value, amount
                            // still due at this point in the payment list). A smaller
                            // hand-set amount would silently forfeit usable value.
                            $paidBefore = round(array_sum(array_column($validatedPayments, "amount")), 2);
                            $dueBefore = round($totalAmount - $paidBefore, 2);
                            $requiredAmount = min($faceValue, max($dueBefore, 0));
                            if (abs($amount - $requiredAmount) > 0.001) throw new PosException("Gift certificate " . $gcc["code"] . " is redeemed in full: the applied amount must be ₱" . number_format($requiredAmount, 2) . " (face value ₱" . number_format($faceValue, 2) . ").");
                            $gccId = (int)$gcc["gcc_id"];
                            $gccCodeApplied = $gcc["code"];
                            $gccUsedThisSale[$gccCode] = $gccId;
                            $gccToRedeem[$gccId] = ["code" => $gcc["code"], "face_value" => $faceValue];
                        } else {
                            throw new PosException("Gift Certificate payment requires a certificate ID or code.");
                        }
                    } else {
                        if ($gcId !== null) throw new PosException("Only Gift Certificate payments can use a certificate ID.");
                        if ($gccCode !== "") throw new PosException("Only Gift Certificate payments can use a certificate code.");
                    }

                    $validatedPayments[] = [
                        "payment_type_id" => $paymentTypeId,
                        "type_name" => $paymentType["type_name"],
                        "amount" => $amount,
                        "reference_no" => $referenceNo,
                        "gc_id" => $gcId,
                        "gcc_id" => $gccId,
                        "gcc_code" => $gccCodeApplied,
                        "suki_points" => $typeName === "suki points" ? (int)round($amount) : 0
                    ];
                }

                $totalPaid = round(array_sum(array_column($validatedPayments, "amount")), 2);
                $cashPaid = 0;
                foreach ($validatedPayments as $payment) {
                    if (strtolower(trim($payment["type_name"])) === "cash") $cashPaid += $payment["amount"];
                }
                if ($totalPaid + 0.001 < $totalAmount) throw new PosException("Insufficient payment. Remaining balance is ₱" . number_format($totalAmount - $totalPaid, 2) . ".");

                // Only cash may create change; non-cash methods may not overpay.
                $nonCashPaid = $totalPaid - $cashPaid;
                if ($nonCashPaid > $totalAmount + 0.001) throw new PosException("Non-cash payments cannot exceed the sale total.");
                $amountTendered = $totalPaid;
                $changeAmount = round(max(0, $cashPaid - max(0, $totalAmount - $nonCashPaid)), 2);
                if ($changeAmount > $cashPaid + 0.001) throw new PosException("Invalid cash/change combination.");
            }

            // Suki Points, ₱400 rule (2026-10-29): points are earned
            // only on ELIGIBLE spending, after discount, and only on
            // the part NOT paid with redeemed points. AR sales earn 0
            // (nothing is paid yet). Ineligible lines are excluded
            // line by line: eligible_subtotal * discount ratio.
            // The remainder carries forward in suki_progress_amount,
            // so ₱350 now + ₱50 later = 1 point, not 0 + 0.
            $sukiCardId = $sukiCard ? (int)$sukiCard["suki_card_id"] : null;
            $progressBefore = $sukiCard ? round((float)($sukiCard["suki_progress_amount"] ?? 0), 2) : 0;
            if ($progressBefore >= $this->pesosPerPoint) $progressBefore = fmod($progressBefore, $this->pesosPerPoint);
            $eligibleSubtotal = 0;
            foreach ($lines as $line) {
                if ((int)($line["is_suki_eligible"] ?? 1) === 1) $eligibleSubtotal += (float)$line["line_total"];
            }
            $eligibleAfterDiscount = round($eligibleSubtotal * $ratio, 2);
            $eligiblePaidAmount = $useAr ? 0 : max(0, round($eligibleAfterDiscount - $pointsToRedeem, 2));
            $pointsEarned = 0;
            $progressAfter = $progressBefore;
            if (!$useAr && $sukiCardId !== null && $eligiblePaidAmount > 0) {
                $totalForPoints = round($progressBefore + $eligiblePaidAmount, 2);
                $pointsEarned = (int)floor($totalForPoints / $this->pesosPerPoint);
                $progressAfter = round($totalForPoints - $pointsEarned * $this->pesosPerPoint, 2);
            }

            $tempReceipt = "TMP-" . uniqid();
            $vatableSales = round($vatableNet - $vatAmount, 2);
            $vatExemptSales = round(($subtotal - $vatableGross) * $ratio, 2);
            if (!$this->vatEnabled) {
                $vatableSales = 0;
                $vatExemptSales = 0;
                $vatAmount = 0;
            }

            $stmt = $conn->prepare("INSERT INTO tblsale\n                    (receipt_number, counter_id, employee_id, customer_id, suki_card_id,\n                     vatable_sales, vat_exempt_sales, vat_amount, total_amount, amount_tendered, change_amount, sale_status)\n                    VALUES\n                    (:receipt_number, :counter_id, :employee_id, :customer_id, :suki_card_id,\n                     :vatable_sales, :vat_exempt_sales, :vat_amount, :total_amount, :amount_tendered, :change_amount, 'Completed')");
            $stmt->bindValue(":receipt_number", $tempReceipt, PDO::PARAM_STR);
            $stmt->bindValue(":counter_id", $counterId, PDO::PARAM_INT);
            $stmt->bindValue(":employee_id", $cashierId, PDO::PARAM_INT);
            $stmt->bindValue(":customer_id", $customerId, $customerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(":suki_card_id", $sukiCardId, $sukiCardId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(":vatable_sales", $vatableSales);
            $stmt->bindValue(":vat_exempt_sales", $vatExemptSales);
            $stmt->bindValue(":vat_amount", $vatAmount);
            $stmt->bindValue(":total_amount", $totalAmount);
            $stmt->bindValue(":amount_tendered", $amountTendered);
            $stmt->bindValue(":change_amount", $changeAmount);
            $stmt->execute();
            $saleId = (int)$conn->lastInsertId();

            $receiptNumber = "OR-" . date("Ymd") . "-" . str_pad($saleId, 5, "0", STR_PAD_LEFT);
            $receiptStmt = $conn->prepare("UPDATE tblsale SET receipt_number = :receipt_number WHERE sale_id = :sale_id");
            $receiptStmt->bindValue(":receipt_number", $receiptNumber, PDO::PARAM_STR);
            $receiptStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
            $receiptStmt->execute();

            $itemStmt = $conn->prepare("INSERT INTO tblsale_item (sale_id, discount_id, product_id, quantity, unit_price, subtotal) VALUES (:sale_id, :discount_id, :product_id, :quantity, :unit_price, :subtotal)");
            foreach ($lines as $line) {
                $itemStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
                $itemStmt->bindValue(":discount_id", $discountId, $discountId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $itemStmt->bindValue(":product_id", $line["product_id"], PDO::PARAM_INT);
                $itemStmt->bindValue(":quantity", $line["quantity"], PDO::PARAM_INT);
                $itemStmt->bindValue(":unit_price", $line["unit_price"]);
                $itemStmt->bindValue(":subtotal", $line["line_total"]);
                $itemStmt->execute();
            }

            $dueDate = null;
            if ($arAccountId !== null) {
                $termStmt = $conn->prepare("SELECT due_days FROM tblcredit_term WHERE credit_term_id = :id");
                $termStmt->bindValue(":id", (int)$arAccount["credit_term_id"], PDO::PARAM_INT);
                $termStmt->execute();
                $term = $termStmt->fetch(PDO::FETCH_ASSOC);
                $dueDays = $term ? (int)$term["due_days"] : 0;
                $dueDate = date("Y-m-d", strtotime("+" . $dueDays . " days"));
            }

            $transactionType = $arAccountId !== null ? "Credit" : "Cash";
            $invoiceStmt = $conn->prepare("INSERT INTO tblinvoice (ar_account_id, sale_id, transaction_type, amount, due_date) VALUES (:ar_account_id, :sale_id, :transaction_type, :amount, :due_date)");
            $invoiceStmt->bindValue(":ar_account_id", $arAccountId, $arAccountId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $invoiceStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
            $invoiceStmt->bindValue(":transaction_type", $transactionType, PDO::PARAM_STR);
            $invoiceStmt->bindValue(":amount", $totalAmount);
            $invoiceStmt->bindValue(":due_date", $dueDate, $dueDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $invoiceStmt->execute();
            $invoiceId = (int)$conn->lastInsertId();

            $paymentIds = [];
            if ($arAccountId === null) {
                $paymentInsert = $conn->prepare("INSERT INTO tblpayment (payment_type_id, gc_id, gcc_id, invoice_id, payment_date, amount_paid, reference_no) VALUES (:payment_type_id, :gc_id, :gcc_id, :invoice_id, NOW(), :amount_paid, :reference_no)");
                $redemptionInsert = $conn->prepare("INSERT INTO tblpoints_redemption (suki_card_id, payment_id, points_used, redemption_date, cash_value) VALUES (:suki_card_id, :payment_id, :points_used, CURDATE(), :cash_value)");
                foreach ($validatedPayments as $payment) {
                    $paymentInsert->bindValue(":payment_type_id", $payment["payment_type_id"], PDO::PARAM_INT);
                    $paymentInsert->bindValue(":gc_id", $payment["gc_id"], $payment["gc_id"] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $paymentInsert->bindValue(":gcc_id", $payment["gcc_id"], $payment["gcc_id"] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $paymentInsert->bindValue(":invoice_id", $invoiceId, PDO::PARAM_INT);
                    $paymentInsert->bindValue(":amount_paid", $payment["amount"]);
                    $paymentInsert->bindValue(":reference_no", $payment["reference_no"] !== "" ? $payment["reference_no"] : null, $payment["reference_no"] !== "" ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $paymentInsert->execute();
                    $paymentId = (int)$conn->lastInsertId();
                    $paymentIds[] = $paymentId;

                    if ($payment["suki_points"] > 0) {
                        $redemptionInsert->bindValue(":suki_card_id", $sukiCardId, PDO::PARAM_INT);
                        $redemptionInsert->bindValue(":payment_id", $paymentId, PDO::PARAM_INT);
                        $redemptionInsert->bindValue(":points_used", $payment["suki_points"], PDO::PARAM_INT);
                        $redemptionInsert->bindValue(":cash_value", $payment["amount"]);
                        $redemptionInsert->execute();
                    }
                }
            }

            if ($arAccountId !== null) {
                $balanceStmt = $conn->prepare("UPDATE tblar_account SET current_balance = current_balance + :total WHERE ar_account_id = :id");
                $balanceStmt->bindValue(":total", $totalAmount);
                $balanceStmt->bindValue(":id", $arAccountId, PDO::PARAM_INT);
                $balanceStmt->execute();
            }

            if ($sukiCardId !== null && (!$useAr) && ($pointsEarned > 0 || $pointsToRedeem > 0 || $eligiblePaidAmount > 0)) {
                $netPointChange = $pointsEarned - $pointsToRedeem;
                if ((int)$sukiCard["points_balance"] + $netPointChange < 0) {
                    throw new PosException("Suki Points balance is no longer sufficient. Retry the sale.");
                }
                // Progress must move even when no whole point is earned
                // yet (e.g. ₱350 -> progress 350, 0 points). Without the
                // progress column (migration not run), this degrades to
                // the old points-only update, and progress stays 0.
                if ($this->sukiColumnExists($conn, "tblsuki_card", "suki_progress_amount")) {
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = points_balance + :points, suki_progress_amount = :progress WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":points", $netPointChange, PDO::PARAM_INT);
                    $pointsStmt->bindValue(":progress", $progressAfter);
                    $pointsStmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
                } else {
                    $pointsStmt = $conn->prepare("UPDATE tblsuki_card SET points_balance = points_balance + :points WHERE suki_card_id = :id");
                    $pointsStmt->bindValue(":points", $netPointChange, PDO::PARAM_INT);
                    $pointsStmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
                }
                $pointsStmt->execute();
                // Auditable earn record, one per sale. The UNIQUE index
                // on (sale_id, transaction_type) is the duplicate-award
                // guard: a second insert for the same sale_id fails
                // instead of awarding the same points twice. Redemptions
                // are already audited in tblpoints_redemption.
                if ($this->sukiTableExists($conn, "tblsuki_point_transaction")) {
                    $ledStmt = $conn->prepare("INSERT INTO tblsuki_point_transaction (suki_card_id, sale_id, transaction_type, points_change, eligible_amount, progress_before, progress_after, balance_before, balance_after, notes) VALUES (:card_id, :sale_id, 'EARN', :points, :eligible, :progress_before, :progress_after, :balance_before, :balance_after, :notes)");
                    $ledStmt->bindValue(":card_id", $sukiCardId, PDO::PARAM_INT);
                    $ledStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
                    $ledStmt->bindValue(":points", $pointsEarned, PDO::PARAM_INT);
                    $ledStmt->bindValue(":eligible", $eligiblePaidAmount);
                    $ledStmt->bindValue(":progress_before", $progressBefore);
                    $ledStmt->bindValue(":progress_after", $progressAfter);
                    $ledStmt->bindValue(":balance_before", (int)$sukiCard["points_balance"], PDO::PARAM_INT);
                    $ledStmt->bindValue(":balance_after", (int)$sukiCard["points_balance"] + $netPointChange, PDO::PARAM_INT);
                    $ledStmt->bindValue(":notes", "Sale " . $receiptNumber . " · ₱400 = 1 point", PDO::PARAM_STR);
                    $ledStmt->execute();
                }
            }

            if ($arAccountId === null && count($gcRemainingThisSale) > 0) {
                foreach (array_keys($gcRemainingThisSale) as $gcId) {
                    $gcStateStmt = $conn->prepare("SELECT gc_value, expiration_date, status FROM tblgift_certificate WHERE gc_id = :id FOR UPDATE");
                    $gcStateStmt->bindValue(":id", $gcId, PDO::PARAM_INT);
                    $gcStateStmt->execute();
                    $gcState = $gcStateStmt->fetch(PDO::FETCH_ASSOC);
                    if ($gcState && strtolower((string)$gcState["status"]) !== "cancelled") {
                        $usedStmt = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid), 0) FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id INNER JOIN tblsale ss ON inv.sale_id = ss.sale_id WHERE pay.gc_id = :gc_id AND ss.sale_status = 'Completed'");
                        $usedStmt->bindValue(":gc_id", $gcId, PDO::PARAM_INT);
                        $usedStmt->execute();
                        $used = (float)$usedStmt->fetchColumn();
                        $remaining = round((float)$gcState["gc_value"] - $used, 2);
                        $newStatus = $remaining <= 0.001 ? "Fully Used" : ((!empty($gcState["expiration_date"]) && $gcState["expiration_date"] < date("Y-m-d")) ? "Expired" : "Active");
                        $statusStmt = $conn->prepare("UPDATE tblgift_certificate SET status = :status, date_redeemed = CASE WHEN :status2 = 'Fully Used' THEN CURDATE() ELSE NULL END WHERE gc_id = :id");
                        $statusStmt->bindValue(":status", $newStatus, PDO::PARAM_STR);
                        $statusStmt->bindValue(":status2", $newStatus, PDO::PARAM_STR);
                        $statusStmt->bindValue(":id", $gcId, PDO::PARAM_INT);
                        $statusStmt->execute();
                    }
                }
            }

            // Standalone code-based certificates are consumed in full the moment
            // the sale commits. This runs inside the same transaction: if the
            // sale fails or is cancelled, the UPDATE rolls back with it and the
            // certificate is never left wrongly marked as redeemed. A voided
            // sale does NOT reactivate the certificate (spec rule).
            if ($arAccountId === null && count($gccToRedeem) > 0) {
                foreach ($gccToRedeem as $redeemGccId => $gccInfo) {
                    $redeemStmt = $conn->prepare("UPDATE tblgift_certificate_code SET status = 'Redeemed', redeemed_sale_id = :sale_id, redeemed_at = NOW() WHERE gcc_id = :id AND status = 'Available'");
                    $redeemStmt->bindValue(":sale_id", $saleId, PDO::PARAM_INT);
                    $redeemStmt->bindValue(":id", $redeemGccId, PDO::PARAM_INT);
                    $redeemStmt->execute();
                    if ($redeemStmt->rowCount() !== 1) {
                        throw new PosException("Gift certificate " . $gccInfo["code"] . " could not be redeemed. The sale was not completed.");
                    }
                }
            }

            // One composite Audit Log row for the whole sale (payments,
            // GC codes and Suki points ride inside after_json — never
            // separate rows). Inside the transaction: if this write
            // fails, the sale rolls back with it.
            $auditPayments = [];
            $auditGcCodes = [];
            foreach ($validatedPayments as $p) {
                $auditPayments[] = ["type" => $p["type_name"], "amount" => $p["amount"],
                                    "gc_id" => $p["gc_id"], "gcc_id" => $p["gcc_id"]];
                if (!empty($p["gcc_code"])) {
                    $auditGcCodes[] = ["code" => $p["gcc_code"], "applied" => $p["amount"]];
                }
            }
            auditEvent($conn, [
                "module" => "Sales",
                "action" => "Created",
                "record_id" => $saleId,
                "record_label" => $receiptNumber,
                "details" => "Sale " . $receiptNumber . " completed. Total " . number_format($totalAmount, 2) . "." . ($useAr ? " (charged to AR)" : ""),
                "after" => [
                    "total_amount" => $totalAmount, "amount_tendered" => $amountTendered, "change_amount" => $changeAmount,
                    "payments" => $auditPayments, "gc_codes" => $auditGcCodes,
                    "suki_card_id" => $sukiCardId, "suki_points_earned" => $pointsEarned, "suki_points_redeemed" => $pointsToRedeem,
                    "suki_points_balance_before" => $sukiCard ? (int)$sukiCard["points_balance"] : null,
                    "suki_points_balance_after" => $sukiCard ? (int)$sukiCard["points_balance"] + $pointsEarned - $pointsToRedeem : null,
                    "ar_account_id" => $arAccountId, "customer_id" => $customerId, "counter_id" => $counterId,
                    "invoice_id" => $invoiceId
                ],
                "reference_label" => $receiptNumber
            ]);

            $conn->commit();

            $message = "Sale " . $receiptNumber . " completed. Total ₱" . number_format($totalAmount, 2) . ".";
            if ($pointsToRedeem > 0) $message .= " Used " . $pointsToRedeem . " Suki Point(s).";
            if ($pointsEarned > 0) $message .= " Earned " . $pointsEarned . " new point(s).";
            if ($useAr) $message = "Sale " . $receiptNumber . " completed and charged to AR.";

            return [
                "success" => true,
                "message" => $message,
                "sale_id" => $saleId,
                "receipt_number" => $receiptNumber,
                "total_amount" => $totalAmount,
                "total_paid" => $useAr ? 0 : round(array_sum(array_column($validatedPayments, "amount")), 2),
                "change_amount" => $changeAmount,
                "points_redeemed" => $pointsToRedeem,
                "points_earned" => $pointsEarned,
                "suki_eligible_amount" => $eligiblePaidAmount,
                "suki_progress_before" => $progressBefore,
                "suki_progress_after" => $progressAfter,
                "suki_amount_needed" => round($this->pesosPerPoint - $progressAfter, 2) <= 0 ? (float)$this->pesosPerPoint : round($this->pesosPerPoint - $progressAfter, 2),
                "suki_points_per_point" => $this->pesosPerPoint,
                "suki_points_balance_after" => $sukiCard ? (int)$sukiCard["points_balance"] + $pointsEarned - $pointsToRedeem : null
            ];
        } catch (PosException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to complete the sale. Nothing was saved."];
        }
    }

}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "all";
$keyword = trim($_GET["keyword"] ?? "");
$priceTierId = $_GET["price_tier_id"] ?? 0;
$categoryId = $_GET["category_id"] ?? 0;
$saleId = $_GET["sale_id"] ?? 0;
$date = $_GET["date"] ?? date("Y-m-d");
$reportDate = trim($_GET["report_date"] ?? "");
$productId = $_GET["product_id"] ?? 0;
$heldId = $_GET["held_id"] ?? $_POST["held_id"] ?? 0;
$counterId = $_GET["counter_id"] ?? $_POST["counter_id"] ?? 0;
$shiftId = $_GET["shift_id"] ?? $_POST["shift_id"] ?? 0;
$customerId = $_GET["customer_id"] ?? $_POST["customer_id"] ?? 0;
$customerType = trim($_GET["customer_type"] ?? "");
$barcodeOnly = ($_GET["barcode_only"] ?? "") === "1";
$customerFilter = trim($_GET["customer_filter"] ?? "");
if ($customerFilter === "") {
    // Backwards compatibility with the older ar_only flag.
    $customerFilter = (($_GET["ar_only"] ?? "") === "1") ? "ar" : "all";
}
if (!in_array($customerFilter, ["ar", "suki", "all"], true)) $customerFilter = "all";

$obj = new Pos();

switch ($operation) {
    case "getPosLookups":
        echo json_encode($obj->getPosLookups());
        break;
    case "getPosProducts":
        echo json_encode($obj->getPosProducts($keyword, $priceTierId, $categoryId, $customerType, $barcodeOnly));
        break;
    case "getPosCustomers":
        echo json_encode($obj->getPosCustomers($keyword, $customerFilter));
        break;
    case "getPosCustomer":
        echo json_encode($obj->getPosCustomer($customerId));
        break;
    case "getSaleDetail":
        echo json_encode($obj->getSaleDetail($saleId));
        break;
    case "getMySales":
        echo json_encode($obj->getMySales($date, $status));
        break;
    case "getCashierSummary":
        echo json_encode($obj->getCashierSummary());
        break;
    case "getSalesSummaryByDate":
        echo json_encode($obj->getSalesSummaryByDate($reportDate));
        break;
    case "getProductSalesReport":
        echo json_encode($obj->getProductSalesReport($productId, $reportDate));
        break;
    case "getMostPurchasedProduct":
        echo json_encode($obj->getMostPurchasedProduct());
        break;
    case "authorizeAction":
        echo json_encode($obj->authorizeAction($json));
        break;
    case "verifyDepositVerifier":
        echo json_encode($obj->verifyDepositVerifier($json));
        break;
    case "getHeldTransactions":
        echo json_encode($obj->getHeldTransactions());
        break;
    case "holdTransaction":
        echo json_encode($obj->holdTransaction($json));
        break;
    case "getHeldTransaction":
        echo json_encode($obj->getHeldTransaction($heldId));
        break;
    case "deleteHeldTransaction":
        echo json_encode($obj->deleteHeldTransaction($heldId));
        break;
    case "openCashFloat":
        echo json_encode($obj->openCashFloat($json));
        break;
    case "getCashDrawer":
        echo json_encode($obj->getCashDrawer($counterId));
        break;
    case "cashLift":
        echo json_encode($obj->cashLift($json));
        break;
    case "addCashFloat":
        echo json_encode($obj->addCashFloat($json));
        break;
    case "getShiftStatus":
        echo json_encode($obj->getShiftStatus($counterId));
        break;
    case "getCounterOccupancy":
        echo json_encode($obj->getCounterOccupancy());
        break;
    case "ensureShift":
        echo json_encode($obj->ensureShift($counterId));
        break;
    case "getShiftReport":
        echo json_encode($obj->getShiftReport($counterId, $shiftId));
        break;
    case "closeShift":
        echo json_encode($obj->closeShift($json));
        break;
    case "getPaymentReport":
        echo json_encode($obj->getPaymentReport($reportDate));
        break;
    case "insertSale":
        echo json_encode($obj->insertSale($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
