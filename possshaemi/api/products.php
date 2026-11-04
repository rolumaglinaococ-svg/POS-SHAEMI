<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
startActivityLogging("Products", "tblproduct", "product_id", "product_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Product {

    private function getDefaultPriceTierId($conn) {
        $stmt = $conn->prepare("SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1 ORDER BY (tier_name = 'Regular') DESC, price_tier_id ASC LIMIT 1");
        $stmt->execute();
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);
        return $tier ? (int)$tier["price_tier_id"] : 0;
    }

    // ERD: PRODUCT.unit_id -> PRODUCT_UNIT. The screen still works with the unit name as text,
    // so find the matching tblproduct_unit row (or create it) and use its id.
    private function getOrCreateUnitId($conn, $unitName) {
        $find = $conn->prepare("SELECT unit_id FROM tblproduct_unit WHERE unit_name = :name ORDER BY unit_id ASC LIMIT 1");
        $find->bindParam(":name", $unitName, PDO::PARAM_STR);
        $find->execute();
        $row = $find->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return (int)$row["unit_id"];
        }

        $code = strtoupper(substr(preg_replace('/\s+/', '', $unitName), 0, 10));
        $insert = $conn->prepare("INSERT INTO tblproduct_unit (unit_name, unit_code, is_active) VALUES (:name, :code, 1)");
        $insert->bindParam(":name", $unitName, PDO::PARAM_STR);
        $insert->bindParam(":code", $code, PDO::PARAM_STR);
        $insert->execute();

        return (int)$conn->lastInsertId();
    }

    public function getAllProducts($status) {
        include "connection-pdo.php";

        $sql = "SELECT p.product_id, p.barcode, p.category_id, p.unit_id, pc.category_name, p.product_name, p.unit,
                       p.is_vatable, p.is_active, p.is_wholesale_barcode
                FROM tblproduct p
                INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id";

        if ($status === "active") {
            $sql .= " WHERE p.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE p.is_active = 0";
        }

        $sql .= " ORDER BY p.product_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchProducts($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT p.product_id, p.barcode, p.category_id, p.unit_id, pc.category_name, p.product_name, p.unit,
                       p.is_vatable, p.is_active, p.is_wholesale_barcode
                FROM tblproduct p
                INNER JOIN tblproduct_category pc ON p.category_id = pc.category_id
                WHERE p.product_name LIKE :keyword OR p.barcode LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND p.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND p.is_active = 0";
        }

        $sql .= " ORDER BY p.product_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveProductsForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT product_id, barcode, product_name, unit FROM tblproduct WHERE is_active = 1 ORDER BY product_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertProduct($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $categoryId = $data["category_id"] ?? null;
        $barcode = trim($data["barcode"] ?? "");
        $productName = trim($data["product_name"] ?? "");
        $unitId = (int)($data["unit_id"] ?? 0);
        $isVatable = isset($data["is_vatable"]) ? (int)$data["is_vatable"] : 1;
        $isWholesaleBarcode = !empty($data["is_wholesale_barcode"]) ? 1 : 0;

        $tierPrices = $data["tier_prices"] ?? [];

        if (!$categoryId || !$unitId || $barcode === "" || $productName === "") {
            return ["success" => false, "message" => "Barcode, category, product name, and unit are required."];
        }
        if (!is_array($tierPrices)) return ["success" => false, "message" => "Invalid price-tier data."];

        $categoryCheck = $conn->prepare("SELECT is_active FROM tblproduct_category WHERE category_id = :id");
        $categoryCheck->bindParam(":id", $categoryId, PDO::PARAM_INT);
        $categoryCheck->execute();
        $category = $categoryCheck->fetch(PDO::FETCH_ASSOC);

        if (!$category || (int)$category["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected category is not active."];
        }
        $unitCheck = $conn->prepare("SELECT unit_name FROM tblproduct_unit WHERE unit_id = :id AND is_active = 1");
        $unitCheck->execute([":id" => $unitId]);
        $unitRow = $unitCheck->fetch(PDO::FETCH_ASSOC);
        if (!$unitRow) return ["success" => false, "message" => "Selected unit is not active."];
        $unit = $unitRow["unit_name"];

        $duplicate = $conn->prepare("SELECT COUNT(*) FROM tblproduct WHERE barcode = :barcode");
        $duplicate->bindValue(":barcode", $barcode, PDO::PARAM_STR);
        $duplicate->execute();
        if ((int)$duplicate->fetchColumn() > 0) return ["success" => false, "message" => "This barcode is already assigned to another product."];

        $activeTiers = $conn->query("SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $activeTierIds = array_map("intval", $activeTiers);
        $defaultTierId = $this->getDefaultPriceTierId($conn);
        $cleanPrices = [];
        foreach ($tierPrices as $tierId => $price) {
            if ($price === "" || $price === null) continue;
            $tierId = (int)$tierId;
            if (!in_array($tierId, $activeTierIds, true) || !is_numeric($price) || (float)$price < 0) {
                return ["success" => false, "message" => "Each entered tier price must be a valid non-negative amount."];
            }
            $cleanPrices[$tierId] = round((float)$price, 2);
        }
        if (!$defaultTierId || !array_key_exists($defaultTierId, $cleanPrices)) {
            return ["success" => false, "message" => "A price for the default price tier is required."];
        }

        try {
            $conn->beginTransaction();
            $sql = "INSERT INTO tblproduct (barcode, category_id, unit_id, product_name, unit, is_vatable, is_active, is_wholesale_barcode)
                    VALUES (:barcode, :category_id, :unit_id, :product_name, :unit, :is_vatable, 1, :is_wholesale_barcode)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":barcode", $barcode, PDO::PARAM_STR);
            $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);
            $stmt->bindParam(":unit_id", $unitId, PDO::PARAM_INT);
            $stmt->bindParam(":product_name", $productName, PDO::PARAM_STR);
            $stmt->bindParam(":unit", $unit, PDO::PARAM_STR);
            $stmt->bindParam(":is_vatable", $isVatable, PDO::PARAM_INT);
            $stmt->bindParam(":is_wholesale_barcode", $isWholesaleBarcode, PDO::PARAM_INT);
            $stmt->execute();
            $productId = (int)$conn->lastInsertId();

            $priceStmt = $conn->prepare("INSERT INTO tblproduct_price (product_id, price_tier_id, price) VALUES (:product_id, :price_tier_id, :price)");
            foreach ($cleanPrices as $tierId => $price) {
                $priceStmt->execute([":product_id" => $productId, ":price_tier_id" => $tierId, ":price" => $price]);
            }
            $conn->commit();
            return ["success" => true, "message" => "Product and price tiers added successfully.", "product_id" => $productId];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to add the product. No changes were saved."];
        }
    }

    public function updateProduct($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $productId = $data["product_id"] ?? null;
        $categoryId = $data["category_id"] ?? null;
        $barcode = trim($data["barcode"] ?? "");
        $productName = trim($data["product_name"] ?? "");
        $unitId = (int)($data["unit_id"] ?? 0);
        $isVatable = isset($data["is_vatable"]) ? (int)$data["is_vatable"] : 1;
        $isWholesaleBarcode = !empty($data["is_wholesale_barcode"]) ? 1 : 0;

        if (!$productId || !$categoryId || !$unitId || $barcode === "" || $productName === "") {
            return ["success" => false, "message" => "Barcode, category, product name, and unit are required."];
        }

        $duplicate = $conn->prepare("SELECT COUNT(*) FROM tblproduct WHERE barcode = :barcode AND product_id != :product_id");
        $duplicate->execute([":barcode" => $barcode, ":product_id" => $productId]);
        if ((int)$duplicate->fetchColumn() > 0) return ["success" => false, "message" => "This barcode is already assigned to another product."];

        $unitCheck = $conn->prepare("SELECT unit_name FROM tblproduct_unit WHERE unit_id = :id AND is_active = 1");
        $unitCheck->execute([":id" => $unitId]);
        $unitRow = $unitCheck->fetch(PDO::FETCH_ASSOC);
        if (!$unitRow) return ["success" => false, "message" => "Selected unit is not active."];
        $unit = $unitRow["unit_name"];

        // Tier prices (2026-10-22): the Edit Product form now shows the
        // price-by-tier section, so updateProduct accepts tier_prices like
        // insertProduct does. When the key is ABSENT, prices are untouched
        // (older callers unaffected). When present: same validation as
        // insert (active tiers only, non-negative, default-tier price
        // required); a tier left blank has its price REMOVED, matching the
        // form's meaning of blank. Price rows of inactive tiers are kept.
        $hasTierPrices = array_key_exists("tier_prices", $data);
        $cleanPrices = [];
        if ($hasTierPrices) {
            $tierPrices = $data["tier_prices"];
            if (!is_array($tierPrices)) return ["success" => false, "message" => "Invalid price-tier data."];
            $activeTiers = $conn->query("SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            $activeTierIds = array_map("intval", $activeTiers);
            $defaultTierId = $this->getDefaultPriceTierId($conn);
            foreach ($tierPrices as $tierId => $price) {
                if ($price === "" || $price === null) continue;
                $tierId = (int)$tierId;
                if (!in_array($tierId, $activeTierIds, true) || !is_numeric($price) || (float)$price < 0) {
                    return ["success" => false, "message" => "Each entered tier price must be a valid non-negative amount."];
                }
                $cleanPrices[$tierId] = round((float)$price, 2);
            }
            if (!$defaultTierId || !array_key_exists($defaultTierId, $cleanPrices)) {
                return ["success" => false, "message" => "A price for the default price tier is required."];
            }
        }

        // Snapshot current tier prices BEFORE the update (audit before).
        // The audit snapshot is keyed by tier NAME (not ID) so the Audit
        // Logs detail view reads "Regular: 55" instead of "1: 55".
        $pricesBefore = null;
        $tierNames = [];
        if ($hasTierPrices) {
            foreach ($conn->query("SELECT price_tier_id, tier_name FROM tblprice_tier")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tierNames[(int)$t["price_tier_id"]] = $t["tier_name"];
            }
            $pb = $conn->prepare("SELECT price_tier_id, price FROM tblproduct_price WHERE product_id = :id");
            $pb->execute([":id" => $productId]);
            $pricesBefore = [];
            foreach ($pb->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tierId = (int)$r["price_tier_id"];
                $pricesBefore[$tierNames[$tierId] ?? ("Tier #" . $tierId)] = (float)$r["price"];
            }
        }

        try {
            $conn->beginTransaction();

            $sql = "UPDATE tblproduct SET barcode = :barcode, category_id = :category_id, unit_id = :unit_id, product_name = :product_name,
                        unit = :unit, is_vatable = :is_vatable, is_wholesale_barcode = :is_wholesale_barcode
                    WHERE product_id = :product_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":barcode", $barcode, PDO::PARAM_STR);
            $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);
            $stmt->bindParam(":unit_id", $unitId, PDO::PARAM_INT);
            $stmt->bindParam(":product_name", $productName, PDO::PARAM_STR);
            $stmt->bindParam(":unit", $unit, PDO::PARAM_STR);
            $stmt->bindParam(":is_vatable", $isVatable, PDO::PARAM_INT);
            $stmt->bindParam(":is_wholesale_barcode", $isWholesaleBarcode, PDO::PARAM_INT);
            $stmt->bindParam(":product_id", $productId, PDO::PARAM_INT);
            $stmt->execute();

            if ($hasTierPrices) {
                $activeTiers = $conn->query("SELECT price_tier_id FROM tblprice_tier WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                $deleteStmt = $conn->prepare("DELETE FROM tblproduct_price WHERE product_id = :product_id AND price_tier_id = :price_tier_id");
                foreach ($activeTiers as $tierId) {
                    $tierId = (int)$tierId;
                    if (!array_key_exists($tierId, $cleanPrices)) {
                        $deleteStmt->execute([":product_id" => $productId, ":price_tier_id" => $tierId]);
                    }
                }
                $upsertStmt = $conn->prepare("INSERT INTO tblproduct_price (product_id, price_tier_id, price)
                        VALUES (:product_id, :price_tier_id, :price)
                        ON DUPLICATE KEY UPDATE price = VALUES(price)");
                foreach ($cleanPrices as $tierId => $price) {
                    $upsertStmt->execute([":product_id" => $productId, ":price_tier_id" => $tierId, ":price" => $price]);
                }

                // After-snapshot keyed by tier name, same shape as before.
                $tierPricesAfter = [];
                foreach ($cleanPrices as $tierId => $price) {
                    $tierPricesAfter[$tierNames[(int)$tierId] ?? ("Tier #" . (int)$tierId)] = $price;
                }
                auditEvent($conn, [
                    "module" => "Product Prices",
                    "action" => "Updated",
                    "record_id" => (int)$productId,
                    "record_label" => $productName,
                    "details" => "Product tier prices updated.",
                    "before" => ["tier_prices" => $pricesBefore],
                    "after" => ["tier_prices" => $tierPricesAfter]
                ]);
            }

            $conn->commit();
            return ["success" => true, "message" => $hasTierPrices ? "Product and price tiers updated successfully." : "Product updated successfully."];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to update product. No changes were saved."];
        }
    }

    // SOFT DELETE - products may already be referenced by SALE_ITEM history,
    // so the row is never removed; it's just excluded from active selection.
    public function deleteProduct($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $productId = $data["product_id"] ?? null;

        if (!$productId) {
            return ["success" => false, "message" => "Product ID is required."];
        }

        $sql = "UPDATE tblproduct SET is_active = 0 WHERE product_id = :product_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":product_id", $productId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Product deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate product."];
    }

    public function restoreProduct($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $productId = $data["product_id"] ?? null;

        if (!$productId) {
            return ["success" => false, "message" => "Product ID is required."];
        }

        $sql = "UPDATE tblproduct SET is_active = 1 WHERE product_id = :product_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":product_id", $productId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Product restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore product."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Product();

switch ($operation) {
    case "getAllProducts":
        echo json_encode($obj->getAllProducts($status));
        break;
    case "searchProducts":
        echo json_encode($obj->searchProducts($keyword, $status));
        break;
    case "getActiveProductsForDropdown":
        echo json_encode($obj->getActiveProductsForDropdown());
        break;
    case "insertProduct":
        echo json_encode($obj->insertProduct($json));
        break;
    case "updateProduct":
        echo json_encode($obj->updateProduct($json));
        break;
    case "deleteProduct":
        echo json_encode($obj->deleteProduct($json));
        break;
    case "restoreProduct":
        echo json_encode($obj->restoreProduct($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
