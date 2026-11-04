<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Roles", "tblrole", "role_id", "role_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Role {

    public function getAllRoles($status) {
        include "connection-pdo.php";

        $sql = "SELECT role_id, role_name, is_active FROM tblrole";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY role_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveRolesForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT role_id, role_name FROM tblrole WHERE is_active = 1 ORDER BY role_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchRoles($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT role_id, role_name, is_active FROM tblrole WHERE role_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY role_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertRole($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $roleName = trim($data["role_name"] ?? "");

        if ($roleName === "") {
            return ["success" => false, "message" => "Role name is required."];
        }

        $sql = "INSERT INTO tblrole (role_name, is_active) VALUES (:role_name, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":role_name", $roleName, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Role added successfully.", "role_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add role."];
    }

    public function updateRole($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $roleId = $data["role_id"] ?? null;
        $roleName = trim($data["role_name"] ?? "");

        if (!$roleId || $roleName === "") {
            return ["success" => false, "message" => "Role ID and role name are required."];
        }

        $sql = "UPDATE tblrole SET role_name = :role_name WHERE role_id = :role_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":role_name", $roleName, PDO::PARAM_STR);
        $stmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Role updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update role."];
    }

    // SOFT DELETE - deactivate, never remove the row
    public function deleteRole($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $roleId = $data["role_id"] ?? null;

        if (!$roleId) {
            return ["success" => false, "message" => "Role ID is required."];
        }

        // Prevent deactivating a role that active employees still depend on
        $checkSql = "SELECT COUNT(*) AS total FROM tblemployee WHERE role_id = :role_id AND is_active = 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);
        $checkStmt->execute();
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($check["total"] > 0) {
            return ["success" => false, "message" => "Cannot deactivate: " . $check["total"] . " active employee(s) still use this role."];
        }

        $sql = "UPDATE tblrole SET is_active = 0 WHERE role_id = :role_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Role deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate role."];
    }

    public function restoreRole($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $roleId = $data["role_id"] ?? null;

        if (!$roleId) {
            return ["success" => false, "message" => "Role ID is required."];
        }

        $sql = "UPDATE tblrole SET is_active = 1 WHERE role_id = :role_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Role restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore role."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Role();

switch ($operation) {

    case "getAllRoles":
        echo json_encode($obj->getAllRoles($status));
        break;

    case "getActiveRolesForDropdown":
        echo json_encode($obj->getActiveRolesForDropdown());
        break;

    case "searchRoles":
        echo json_encode($obj->searchRoles($keyword, $status));
        break;

    case "insertRole":
        echo json_encode($obj->insertRole($json));
        break;

    case "updateRole":
        echo json_encode($obj->updateRole($json));
        break;

    case "deleteRole":
        echo json_encode($obj->deleteRole($json));
        break;

    case "restoreRole":
        echo json_encode($obj->restoreRole($json));
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}