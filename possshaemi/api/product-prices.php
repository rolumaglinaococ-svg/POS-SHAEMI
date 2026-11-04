<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
startActivityLogging("Product Prices", "tblproduct_price", "product_price_id", "CONCAT((SELECT p.product_name FROM tblproduct p WHERE p.product_id = tblproduct_price.product_id), ' - ', (SELECT t.tier_name FROM tblprice_tier t WHERE t.price_tier_id = tblproduct_price.price_tier_id))");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class ProductPrice {

    public function getAllProductPrices($status, $categoryId = 0) {
        include "connection-pdo.php";

        $sql = "SELECT pp.product_price_id, pp.product_id, p.product_name, p.category_id, pc.category_name,
                       pp.price_tier_id, pt.tier_name, pp.price, 1 AS is_active
                FROM tblproduct_price pp
                INNER JOIN tblproduct p ON pp.product_id = p.product_id
                INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id
                INNER JOIN tblprice_tier pt ON pp.price_tier_id = pt.price_tier_id
                WHERE 1 = 1";

        // tblproduct_price has no is_active column any more: every row is a live price,
        // so the "inactive" filter can never match anything.
        if ($status === "inactive") {
            $sql .= " AND 1 = 0";
        }

        if ($categoryId > 0) {
            $sql .= " AND p.category_id = :category_id";
        }

        $sql .= " ORDER BY p.product_name ASC, pt.tier_name ASC";

        $stmt = $conn->prepare($sql);
        if ($categoryId > 0) {
            $stmt->bindValue(":category_id", $categoryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchProductPrices($keyword, $status, $categoryId = 0) {
        include "connection-pdo.php";

        $sql = "SELECT pp.product_price_id, pp.product_id, p.product_name, p.category_id, pc.category_name,
                       pp.price_tier_id, pt.tier_name, pp.price, 1 AS is_active
                FROM tblproduct_price pp
                INNER JOIN tblproduct p ON pp.product_id = p.product_id
                INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id
                INNER JOIN tblprice_tier pt ON pp.price_tier_id = pt.price_tier_id
                WHERE p.product_name LIKE :keyword";

        // tblproduct_price has no is_active column any more: every row is a live price,
        // so the "inactive" filter can never match anything.
        if ($status === "inactive") {
            $sql .= " AND 1 = 0";
        }

        if ($categoryId > 0) {
            $sql .= " AND p.category_id = :category_id";
        }

        $sql .= " ORDER BY p.product_name ASC, pt.tier_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        if ($categoryId > 0) {
            $stmt->bindValue(":category_id", $categoryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All price rows for one product, used to fill the "Manage Price" modal. */
    public function getPricesForProduct($productId) {
        include "connection-pdo.php";

        $sql = "SELECT pp.product_price_id, pp.product_id, pp.price_tier_id, pt.tier_name, pp.price, 1 AS is_active
                FROM tblproduct_price pp
                INNER JOIN tblprice_tier pt ON pp.price_tier_id = pt.price_tier_id
                WHERE pp.product_id = :product_id
                ORDER BY pt.tier_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":product_id", $productId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertProductPrice($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $productId = $data["product_id"] ?? null;
        $priceTierId = $data["price_tier_id"] ?? null;
        $price = $data["price"] ?? null;

        if (!$productId || !$priceTierId || $price === null || !is_numeric($price) || $price < 0) {
            return ["success" => false, "message" => "Product, price tier, and a valid non-negative price are required."];
        }

        $productCheck = $conn->prepare("SELECT is_active FROM tblproduct WHERE product_id = :id");
        $productCheck->bindParam(":id", $productId, PDO::PARAM_INT);
        $productCheck->execute();
        $product = $productCheck->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected product is not active."];
        }

        $tierCheck = $conn->prepare("SELECT is_active FROM tblprice_tier WHERE price_tier_id = :id");
        $tierCheck->bindParam(":id", $priceTierId, PDO::PARAM_INT);
        $tierCheck->execute();
        $tier = $tierCheck->fetch(PDO::FETCH_ASSOC);

        if (!$tier || (int)$tier["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected price tier is not active."];
        }

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblproduct_price WHERE product_id = :pid AND price_tier_id = :tid");
        $dupCheck->bindParam(":pid", $productId, PDO::PARAM_INT);
        $dupCheck->bindParam(":tid", $priceTierId, PDO::PARAM_INT);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "A price already exists for this product and price tier combination."];
        }

        $sql = "INSERT INTO tblproduct_price (product_id, price_tier_id, price)
                VALUES (:product_id, :price_tier_id, :price)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);
        $stmt->bindParam(":price", $price);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Product price added successfully.", "product_price_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add product price."];
    }

    public function updateProductPrice($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $productPriceId = $data["product_price_id"] ?? null;
        $price = $data["price"] ?? null;

        if (!$productPriceId || $price === null || !is_numeric($price) || $price < 0) {
            return ["success" => false, "message" => "Product price ID and a valid non-negative price are required."];
        }

        $beforeStmt = $conn->prepare("SELECT pp.product_price_id, pp.product_id, pp.price_tier_id, pp.price,
                                             p.product_name, t.tier_name
                                      FROM tblproduct_price pp
                                      INNER JOIN tblproduct p ON p.product_id = pp.product_id
                                      INNER JOIN tblprice_tier t ON t.price_tier_id = pp.price_tier_id
                                      WHERE pp.product_price_id = :id");
        $beforeStmt->bindParam(":id", $productPriceId, PDO::PARAM_INT);
        $beforeStmt->execute();
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            return ["success" => false, "message" => "Product price not found."];
        }

        try {
            $conn->beginTransaction();
            $sql = "UPDATE tblproduct_price SET price = :price WHERE product_price_id = :product_price_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":price", $price);
            $stmt->bindParam(":product_price_id", $productPriceId, PDO::PARAM_INT);
            $stmt->execute();

            // Same audit shape as the Edit Product price snapshot:
            // tier_prices keyed by tier NAME ("Regular: 55"), and no
            // reference_label — the record label already identifies it.
            auditEvent($conn, [
                "module" => "Product Prices",
                "action" => "Updated",
                "record_id" => (int)$productPriceId,
                "record_label" => $before["product_name"] . " - " . $before["tier_name"],
                "details" => "Product price updated successfully.",
                "before" => ["tier_prices" => [$before["tier_name"] => (float)$before["price"]]],
                "after" => ["tier_prices" => [$before["tier_name"] => round((float)$price, 2)]]
            ]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to update product price."];
        }

        return ["success" => true, "message" => "Product price updated successfully."];
    }

    public function deleteProductPrice($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $productPriceId = $data["product_price_id"] ?? null;

        if (!$productPriceId) {
            return ["success" => false, "message" => "Product price ID is required."];
        }

        // No is_active column on PRODUCT_PRICE: removing a tier price deletes the row.
        // (The activity log entry for this action will have no record label, because the row is gone.)
        $sql = "DELETE FROM tblproduct_price WHERE product_price_id = :product_price_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":product_price_id", $productPriceId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Product price removed successfully."];
        }

        return ["success" => false, "message" => "Failed to remove product price."];
    }

    public function restoreProductPrice($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $productPriceId = $data["product_price_id"] ?? null;

        if (!$productPriceId) {
            return ["success" => false, "message" => "Product price ID is required."];
        }

        // Removed prices are deleted, so there is nothing to restore any more.
        return ["success" => false, "message" => "Restore is no longer available. Add the price again instead."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";
$categoryId = (int)($_GET["category_id"] ?? 0);
$productId = (int)($_GET["product_id"] ?? 0);

$obj = new ProductPrice();

switch ($operation) {
    case "getAllProductPrices":
        echo json_encode($obj->getAllProductPrices($status, $categoryId));
        break;
    case "searchProductPrices":
        echo json_encode($obj->searchProductPrices($keyword, $status, $categoryId));
        break;
    case "getPricesForProduct":
        echo json_encode($obj->getPricesForProduct($productId));
        break;
    case "insertProductPrice":
        echo json_encode($obj->insertProductPrice($json));
        break;
    case "updateProductPrice":
        echo json_encode($obj->updateProductPrice($json));
        break;
    case "deleteProductPrice":
        echo json_encode($obj->deleteProductPrice($json));
        break;
    case "restoreProductPrice":
        echo json_encode($obj->restoreProductPrice($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}