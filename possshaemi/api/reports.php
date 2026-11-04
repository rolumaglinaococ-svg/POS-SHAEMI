<?php
require_once "session-guard.php";
requireRole(["Administrator", "Cashier"]);
header("Content-Type: application/json");

/* Read-only reporting endpoint. Cashiers are always limited to their own
 * completed sales; Administrators receive the whole-store view. */
function reportRange($from, $to) {
    $from = trim((string)$from); $to = trim((string)$to);
    $valid = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($valid, $from) || !preg_match($valid, $to) || $from > $to) {
        throw new InvalidArgumentException("Choose a valid report date or date range.");
    }
    return [$from, $to];
}

try {
    include "connection-pdo.php";
    [$from, $to] = reportRange($_GET["date_from"] ?? date("Y-m-d"), $_GET["date_to"] ?? date("Y-m-d"));
    [$productFrom, $productTo] = reportRange($_GET["product_date_from"] ?? $from, $_GET["product_date_to"] ?? $to);
    $isAdmin = ($_SESSION["role"] ?? "") === "Administrator";
    $employeeId = (int)($_SESSION["employee_id"] ?? 0);
    $scope = $isAdmin ? "" : " AND s.employee_id = :employee_id";
    $bind = [":from" => $from, ":to" => $to];
    if (!$isAdmin) $bind[":employee_id"] = $employeeId;

    $summarySql = "SELECT COUNT(*) AS transaction_count,
                          COALESCE(SUM(s.total_amount), 0) AS total_sales,
                          COALESCE(SUM((SELECT SUM(si.quantity) FROM tblsale_item si WHERE si.sale_id = s.sale_id)), 0) AS items_sold,
                          COALESCE(AVG(s.total_amount), 0) AS average_transaction,
                          COALESCE(SUM((SELECT SUM(si.subtotal) FROM tblsale_item si WHERE si.sale_id = s.sale_id) - s.total_amount), 0) AS total_discount
                   FROM tblsale s
                   WHERE s.sale_status = 'Completed' AND DATE(s.sale_date) BETWEEN :from AND :to" . $scope;
    $stmt = $conn->prepare($summarySql); $stmt->execute($bind); $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $paymentsSql = "SELECT pt.type_name,
                           COALESCE(SUM(CASE WHEN LOWER(pt.type_name) = 'cash' THEN GREATEST(pay.amount_paid - s.change_amount, 0) ELSE pay.amount_paid END), 0) AS amount
                    FROM tblpayment pay
                    INNER JOIN tblpayment_type pt ON pt.payment_type_id = pay.payment_type_id
                    INNER JOIN tblinvoice inv ON inv.invoice_id = pay.invoice_id
                    INNER JOIN tblsale s ON s.sale_id = inv.sale_id
                    WHERE s.sale_status = 'Completed' AND DATE(s.sale_date) BETWEEN :from AND :to" . $scope . "
                    GROUP BY pt.payment_type_id, pt.type_name ORDER BY pt.type_name";
    $stmt = $conn->prepare($paymentsSql); $stmt->execute($bind); $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $arSql = "SELECT COALESCE(SUM(inv.amount), 0) FROM tblinvoice inv
              INNER JOIN tblsale s ON s.sale_id = inv.sale_id
              WHERE inv.transaction_type = 'Credit' AND s.sale_status = 'Completed'
                AND DATE(s.sale_date) BETWEEN :from AND :to" . $scope;
    $stmt = $conn->prepare($arSql); $stmt->execute($bind); $arSales = (float)$stmt->fetchColumn();

    // AR collections are intentionally separate: they are payments on invoices
    // without a sale record and must never be added to sales revenue.
    // Net of reversals/adjustments (tblpayment_allocation), so reversed payments are not counted.
    $collectionSql = "SELECT COALESCE(SUM(a.amount), 0) FROM tblpayment_allocation a
                      WHERE DATE(a.created_at) BETWEEN :from AND :to";
    $stmt = $conn->prepare($collectionSql); $stmt->execute([":from" => $from, ":to" => $to]); $arCollections = (float)$stmt->fetchColumn();

    $productSql = "SELECT p.product_name, SUM(si.quantity) AS quantity_sold, SUM(si.subtotal) AS sales_amount
                   FROM tblsale_item si INNER JOIN tblsale s ON s.sale_id = si.sale_id
                   INNER JOIN tblproduct p ON p.product_id = si.product_id
                   WHERE s.sale_status = 'Completed' AND DATE(s.sale_date) BETWEEN :product_from AND :product_to" . $scope . "
                   GROUP BY p.product_id, p.product_name ORDER BY quantity_sold DESC, p.product_name ASC LIMIT 10";
    $productBind = $bind; unset($productBind[":from"], $productBind[":to"]);
    $productBind[":product_from"] = $productFrom; $productBind[":product_to"] = $productTo;
    $stmt = $conn->prepare($productSql); $stmt->execute($productBind); $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $trendSql = "SELECT DATE(s.sale_date) AS sale_day, COALESCE(SUM(s.total_amount), 0) AS total_sales
                 FROM tblsale s WHERE s.sale_status = 'Completed' AND DATE(s.sale_date) BETWEEN :from AND :to" . $scope . "
                 GROUP BY DATE(s.sale_date) ORDER BY sale_day";
    $stmt = $conn->prepare($trendSql); $stmt->execute($bind); $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = ["success" => true, "scope" => $isAdmin ? "store" : "cashier", "date_from" => $from, "date_to" => $to,
               "summary" => $summary, "payment_methods" => $paymentMethods, "ar_sales" => $arSales,
               "ar_collections" => $arCollections, "product_date_from" => $productFrom, "product_date_to" => $productTo, "top_products" => $topProducts, "trend" => $trend];
    if ($isAdmin && ($_GET["include_audit"] ?? "0") === "1") {
        $audit = $conn->prepare("SELECT receipt_number, sale_date, total_amount, sale_status, void_reason, voided_at
            FROM tblsale WHERE sale_status IN ('Voided', 'Cancelled') AND DATE(sale_date) BETWEEN :from AND :to ORDER BY sale_date DESC");
        $audit->execute([":from" => $from, ":to" => $to]); $result["audit"] = $audit->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($result);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(["success" => false, "message" => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(["success" => false, "message" => "Unable to load reports."]);
}
