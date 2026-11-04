<?php
require_once "session-guard.php";
requireRole(["Administrator"]);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Dashboard {

    public function getCounts() {
        include "connection-pdo.php";

        $sql = "SELECT
                    (SELECT COUNT(*) FROM tblproduct WHERE is_active = 1) AS products,
                    (SELECT COUNT(*) FROM tblproduct_category WHERE is_active = 1) AS categories,
                    (SELECT COUNT(*) FROM tblcustomer WHERE is_active = 1) AS customers,
                    (SELECT COUNT(*) FROM tblemployee WHERE is_active = 1) AS employees,
                    (SELECT COUNT(*) FROM tblsuki_card WHERE is_active = 1) AS suki_cards,
                    (SELECT COUNT(*) FROM tblcounter WHERE is_active = 1) AS counters,
                    (SELECT COUNT(*) FROM tblrole WHERE is_active = 1) AS roles,
                    (SELECT COUNT(*) FROM tblprice_tier WHERE is_active = 1) AS price_tiers,
                    (SELECT COUNT(*) FROM tblcredit_term WHERE is_active = 1) AS credit_terms,
                    (SELECT COUNT(*) FROM tbldiscount WHERE is_active = 1) AS discounts,
                    (SELECT COUNT(*) FROM tblpayment_type WHERE is_active = 1) AS payment_types,
                    (SELECT COUNT(*) FROM tblproduct_price) AS product_prices,
                    (SELECT COUNT(*) FROM tblar_account WHERE account_status = 'Active') AS ar_accounts,
                    (SELECT COUNT(*) FROM tblar_account WHERE account_status = 'Pending') AS pending_ar,
                    (SELECT COUNT(*) FROM tblsale WHERE sale_status = 'Completed' AND DATE(sale_date) = CURDATE()) AS sales_today,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM tblsale WHERE sale_status = 'Completed' AND DATE(sale_date) = CURDATE()) AS sales_today_total,
                    (SELECT COUNT(*) FROM tblsale WHERE sale_status = 'Voided') AS voided_sales";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";

$obj = new Dashboard();

switch ($operation) {
    case "getCounts":
        echo json_encode($obj->getCounts());
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
