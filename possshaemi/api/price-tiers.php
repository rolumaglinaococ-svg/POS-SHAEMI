<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Price Tiers", "tblprice_tier", "price_tier_id", "tier_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class PriceTier {

    public function getAllPriceTiers($status) {
        include "connection-pdo.php";

        $sql = "SELECT price_tier_id, tier_name, is_active FROM tblprice_tier";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY tier_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActivePriceTiersForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT price_tier_id, tier_name FROM tblprice_tier WHERE is_active = 1 ORDER BY tier_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchPriceTiers($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT price_tier_id, tier_name, is_active FROM tblprice_tier WHERE tier_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY tier_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertPriceTier($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $tierName = trim($data["tier_name"] ?? "");

        if ($tierName === "") {
            return ["success" => false, "message" => "Tier name is required."];
        }

        $sql = "INSERT INTO tblprice_tier (tier_name, is_active) VALUES (:tier_name, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":tier_name", $tierName, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Price tier added successfully.", "price_tier_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add price tier."];
    }

    public function updatePriceTier($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $priceTierId = $data["price_tier_id"] ?? null;
        $tierName = trim($data["tier_name"] ?? "");

        if (!$priceTierId || $tierName === "") {
            return ["success" => false, "message" => "Price tier ID and tier name are required."];
        }

        $sql = "UPDATE tblprice_tier SET tier_name = :tier_name WHERE price_tier_id = :price_tier_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":tier_name", $tierName, PDO::PARAM_STR);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Price tier updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update price tier."];
    }

    public function deletePriceTier($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $priceTierId = $data["price_tier_id"] ?? null;

        if (!$priceTierId) {
            return ["success" => false, "message" => "Price tier ID is required."];
        }

        $checkSql = "SELECT
                        (SELECT COUNT(*) FROM tblcustomer WHERE price_tier_id = :id1 AND is_active = 1) +
                        (SELECT COUNT(*) FROM tblproduct_price WHERE price_tier_id = :id2) AS total";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindParam(":id1", $priceTierId, PDO::PARAM_INT);
        $checkStmt->bindParam(":id2", $priceTierId, PDO::PARAM_INT);
        $checkStmt->execute();
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($check["total"] > 0) {
            return ["success" => false, "message" => "Cannot deactivate: this tier is still used by active customers or product prices."];
        }

        $sql = "UPDATE tblprice_tier SET is_active = 0 WHERE price_tier_id = :price_tier_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Price tier deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate price tier."];
    }

    public function restorePriceTier($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $priceTierId = $data["price_tier_id"] ?? null;

        if (!$priceTierId) {
            return ["success" => false, "message" => "Price tier ID is required."];
        }

        $sql = "UPDATE tblprice_tier SET is_active = 1 WHERE price_tier_id = :price_tier_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Price tier restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore price tier."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new PriceTier();

switch ($operation) {
    case "getAllPriceTiers":
        echo json_encode($obj->getAllPriceTiers($status));
        break;
    case "getActivePriceTiersForDropdown":
        echo json_encode($obj->getActivePriceTiersForDropdown());
        break;
    case "searchPriceTiers":
        echo json_encode($obj->searchPriceTiers($keyword, $status));
        break;
    case "insertPriceTier":
        echo json_encode($obj->insertPriceTier($json));
        break;
    case "updatePriceTier":
        echo json_encode($obj->updatePriceTier($json));
        break;
    case "deletePriceTier":
        echo json_encode($obj->deletePriceTier($json));
        break;
    case "restorePriceTier":
        echo json_encode($obj->restorePriceTier($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}