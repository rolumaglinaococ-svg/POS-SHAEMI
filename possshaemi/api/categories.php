<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Categories", "tblproduct_category", "category_id", "category_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class ProductCategory {

    public function getAllCategories($status) {
        include "connection-pdo.php";

        $sql = "SELECT category_id, category_name, description, is_active FROM tblproduct_category";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY category_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveCategoriesForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT category_id, category_name FROM tblproduct_category WHERE is_active = 1 ORDER BY category_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCategories($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT category_id, category_name, description, is_active FROM tblproduct_category WHERE category_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY category_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertCategory($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $categoryName = trim($data["category_name"] ?? "");
        $description = trim($data["description"] ?? "");

        if ($categoryName === "") {
            return ["success" => false, "message" => "Category name is required."];
        }

        $sql = "INSERT INTO tblproduct_category (category_name, description, is_active) VALUES (:category_name, :description, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":category_name", $categoryName, PDO::PARAM_STR);
        $stmt->bindParam(":description", $description, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Category added successfully.", "category_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add category."];
    }

    public function updateCategory($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $categoryId = $data["category_id"] ?? null;
        $categoryName = trim($data["category_name"] ?? "");
        $description = trim($data["description"] ?? "");

        if (!$categoryId || $categoryName === "") {
            return ["success" => false, "message" => "Category ID and category name are required."];
        }

        $sql = "UPDATE tblproduct_category SET category_name = :category_name, description = :description WHERE category_id = :category_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":category_name", $categoryName, PDO::PARAM_STR);
        $stmt->bindParam(":description", $description, PDO::PARAM_STR);
        $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Category updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update category."];
    }

    public function deleteCategory($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $categoryId = $data["category_id"] ?? null;

        if (!$categoryId) {
            return ["success" => false, "message" => "Category ID is required."];
        }

        $checkSql = "SELECT COUNT(*) AS total FROM tblproduct WHERE category_id = :id AND is_active = 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindParam(":id", $categoryId, PDO::PARAM_INT);
        $checkStmt->execute();
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($check["total"] > 0) {
            return ["success" => false, "message" => "Cannot deactivate: " . $check["total"] . " active product(s) belong to this category."];
        }

        $sql = "UPDATE tblproduct_category SET is_active = 0 WHERE category_id = :category_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Category deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate category."];
    }

    public function restoreCategory($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $categoryId = $data["category_id"] ?? null;

        if (!$categoryId) {
            return ["success" => false, "message" => "Category ID is required."];
        }

        $sql = "UPDATE tblproduct_category SET is_active = 1 WHERE category_id = :category_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":category_id", $categoryId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Category restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore category."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new ProductCategory();

switch ($operation) {
    case "getAllCategories":
        echo json_encode($obj->getAllCategories($status));
        break;
    case "getActiveCategoriesForDropdown":
        echo json_encode($obj->getActiveCategoriesForDropdown());
        break;
    case "searchCategories":
        echo json_encode($obj->searchCategories($keyword, $status));
        break;
    case "insertCategory":
        echo json_encode($obj->insertCategory($json));
        break;
    case "updateCategory":
        echo json_encode($obj->updateCategory($json));
        break;
    case "deleteCategory":
        echo json_encode($obj->deleteCategory($json));
        break;
    case "restoreCategory":
        echo json_encode($obj->restoreCategory($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}