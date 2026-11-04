<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Discounts", "tbldiscount", "discount_id", "discount_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Discount {

    public function getAllDiscounts($status) {
        include "connection-pdo.php";

        $sql = "SELECT discount_id, discount_name, discount_type, discount_value, is_active FROM tbldiscount";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY discount_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchDiscounts($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT discount_id, discount_name, discount_type, discount_value, is_active FROM tbldiscount WHERE discount_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY discount_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertDiscount($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $discountName = trim($data["discount_name"] ?? "");
        $discountType = trim($data["discount_type"] ?? "");
        $discountValue = $data["discount_value"] ?? null;

        if ($discountName === "" || !in_array($discountType, ["Percentage", "Fixed"]) ||
            $discountValue === null || !is_numeric($discountValue) || $discountValue < 0) {
            return ["success" => false, "message" => "Valid discount name, type (Percentage/Fixed), and a non-negative value are required."];
        }

        if ($discountType === "Percentage" && $discountValue > 100) {
            return ["success" => false, "message" => "Percentage discount cannot exceed 100."];
        }

        $sql = "INSERT INTO tbldiscount (discount_name, discount_type, discount_value, is_active)
                VALUES (:discount_name, :discount_type, :discount_value, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discount_name", $discountName, PDO::PARAM_STR);
        $stmt->bindParam(":discount_type", $discountType, PDO::PARAM_STR);
        $stmt->bindParam(":discount_value", $discountValue);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Discount added successfully.", "discount_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add discount."];
    }

    public function updateDiscount($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $discountId = $data["discount_id"] ?? null;
        $discountName = trim($data["discount_name"] ?? "");
        $discountType = trim($data["discount_type"] ?? "");
        $discountValue = $data["discount_value"] ?? null;

        if (!$discountId || $discountName === "" || !in_array($discountType, ["Percentage", "Fixed"]) ||
            $discountValue === null || !is_numeric($discountValue) || $discountValue < 0) {
            return ["success" => false, "message" => "Valid discount ID, name, type, and non-negative value are required."];
        }

        if ($discountType === "Percentage" && $discountValue > 100) {
            return ["success" => false, "message" => "Percentage discount cannot exceed 100."];
        }

        $sql = "UPDATE tbldiscount
                SET discount_name = :discount_name, discount_type = :discount_type, discount_value = :discount_value
                WHERE discount_id = :discount_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discount_name", $discountName, PDO::PARAM_STR);
        $stmt->bindParam(":discount_type", $discountType, PDO::PARAM_STR);
        $stmt->bindParam(":discount_value", $discountValue);
        $stmt->bindParam(":discount_id", $discountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Discount updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update discount."];
    }

    public function deleteDiscount($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $discountId = $data["discount_id"] ?? null;

        if (!$discountId) {
            return ["success" => false, "message" => "Discount ID is required."];
        }

        $sql = "UPDATE tbldiscount SET is_active = 0 WHERE discount_id = :discount_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discount_id", $discountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Discount deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate discount."];
    }

    public function restoreDiscount($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $discountId = $data["discount_id"] ?? null;

        if (!$discountId) {
            return ["success" => false, "message" => "Discount ID is required."];
        }

        $sql = "UPDATE tbldiscount SET is_active = 1 WHERE discount_id = :discount_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discount_id", $discountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Discount restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore discount."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Discount();

switch ($operation) {
    case "getAllDiscounts":
        echo json_encode($obj->getAllDiscounts($status));
        break;
    case "searchDiscounts":
        echo json_encode($obj->searchDiscounts($keyword, $status));
        break;
    case "insertDiscount":
        echo json_encode($obj->insertDiscount($json));
        break;
    case "updateDiscount":
        echo json_encode($obj->updateDiscount($json));
        break;
    case "deleteDiscount":
        echo json_encode($obj->deleteDiscount($json));
        break;
    case "restoreDiscount":
        echo json_encode($obj->restoreDiscount($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}