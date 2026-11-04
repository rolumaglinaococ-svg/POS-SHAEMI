<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
startActivityLogging("Employees", "tblemployee", "employee_id", "CONCAT(first_name, ' ', last_name)");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Employee {

    /**
     * Strong password policy (approved 2026-10-21):
     * at least 8 characters, at least 3 digits, at least 1 symbol.
     * Applied only when a password is created or reset — never at login,
     * so existing accounts with older passwords are not locked out.
     * Returns an error message, or null when the password passes.
     */
    public function passwordPolicyError($password) {
        if (strlen($password) < 8) {
            return "Password must be at least 8 characters.";
        }
        if (preg_match_all("/[0-9]/", $password) < 3) {
            return "Password must contain at least 3 digits.";
        }
        if (!preg_match("/[^A-Za-z0-9]/", $password)) {
            return "Password must contain at least 1 symbol (e.g. ! @ # $ %).";
        }
        return null;
    }

    public function getAllEmployees($status) {
        include "connection-pdo.php";

        $sql = "SELECT e.employee_id, e.role_id, r.role_name, e.first_name, e.last_name,
                       e.contact_number, e.username, e.is_active
                FROM tblemployee e
                INNER JOIN tblrole r ON e.role_id = r.role_id";

        if ($status === "active") {
            $sql .= " WHERE e.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE e.is_active = 0";
        }

        $sql .= " ORDER BY e.last_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchEmployees($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT e.employee_id, e.role_id, r.role_name, e.first_name, e.last_name,
                       e.contact_number, e.username, e.is_active
                FROM tblemployee e
                INNER JOIN tblrole r ON e.role_id = r.role_id
                WHERE (e.first_name LIKE :keyword OR e.last_name LIKE :keyword OR e.username LIKE :keyword)";

        if ($status === "active") {
            $sql .= " AND e.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND e.is_active = 0";
        }

        $sql .= " ORDER BY e.last_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveEmployeesForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT employee_id, CONCAT(first_name, ' ', last_name) AS full_name
                FROM tblemployee WHERE is_active = 1 ORDER BY last_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveApproversForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS full_name, r.role_name
                FROM tblemployee e INNER JOIN tblrole r ON e.role_id = r.role_id
                WHERE e.is_active = 1 AND r.is_active = 1
                  AND r.role_name IN ('Administrator', 'Manager')
                ORDER BY r.role_name ASC, e.last_name ASC, e.first_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertEmployee($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $roleId = $data["role_id"] ?? null;
        $firstName = trim($data["first_name"] ?? "");
        $lastName = trim($data["last_name"] ?? "");
        $contactNumber = trim($data["contact_number"] ?? "");
        $username = trim($data["username"] ?? "");
        $password = $data["password"] ?? "";

        if (!$roleId || $firstName === "" || $lastName === "" || $username === "" || $password === "") {
            return ["success" => false, "message" => "Role, first name, last name, username, and password are required."];
        }

        $policyError = $this->passwordPolicyError($password);
        if ($policyError !== null) {
            return ["success" => false, "message" => $policyError];
        }

        // Ensure role is active
        $roleCheck = $conn->prepare("SELECT is_active FROM tblrole WHERE role_id = :role_id");
        $roleCheck->bindParam(":role_id", $roleId, PDO::PARAM_INT);
        $roleCheck->execute();
        $role = $roleCheck->fetch(PDO::FETCH_ASSOC);

        if (!$role || (int)$role["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected role is not active."];
        }

        // Ensure username is unique
        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblemployee WHERE username = :username");
        $dupCheck->bindParam(":username", $username, PDO::PARAM_STR);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "Username already exists."];
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO tblemployee (role_id, first_name, last_name, contact_number, username, password, is_active)
                VALUES (:role_id, :first_name, :last_name, :contact_number, :username, :password, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);
        $stmt->bindParam(":first_name", $firstName, PDO::PARAM_STR);
        $stmt->bindParam(":last_name", $lastName, PDO::PARAM_STR);
        $stmt->bindParam(":contact_number", $contactNumber, PDO::PARAM_STR);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->bindParam(":password", $hashedPassword, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Employee added successfully.", "employee_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add employee."];
    }

    public function updateEmployee($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $employeeId = $data["employee_id"] ?? null;
        $roleId = $data["role_id"] ?? null;
        $firstName = trim($data["first_name"] ?? "");
        $lastName = trim($data["last_name"] ?? "");
        $contactNumber = trim($data["contact_number"] ?? "");
        $username = trim($data["username"] ?? "");
        $password = $data["password"] ?? "";

        if (!$employeeId || !$roleId || $firstName === "" || $lastName === "" || $username === "") {
            return ["success" => false, "message" => "Employee ID, role, first name, last name, and username are required."];
        }

        // Snapshot role/name BEFORE the update (audit before-values).
        $beforeStmt = $conn->prepare("SELECT e.role_id, r.role_name, CONCAT(e.first_name, ' ', e.last_name) AS full_name
                                      FROM tblemployee e INNER JOIN tblrole r ON r.role_id = e.role_id
                                      WHERE e.employee_id = :id");
        $beforeStmt->bindParam(":id", $employeeId, PDO::PARAM_INT);
        $beforeStmt->execute();
        $beforeEmp = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblemployee WHERE username = :username AND employee_id != :employee_id");
        $dupCheck->bindParam(":username", $username, PDO::PARAM_STR);
        $dupCheck->bindParam(":employee_id", $employeeId, PDO::PARAM_INT);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "Username already exists."];
        }

        if ($password !== "") {
            $policyError = $this->passwordPolicyError($password);
            if ($policyError !== null) {
                return ["success" => false, "message" => $policyError];
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE tblemployee
                    SET role_id = :role_id, first_name = :first_name, last_name = :last_name,
                        contact_number = :contact_number, username = :username, password = :password
                    WHERE employee_id = :employee_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":password", $hashedPassword, PDO::PARAM_STR);
        } else {
            $sql = "UPDATE tblemployee
                    SET role_id = :role_id, first_name = :first_name, last_name = :last_name,
                        contact_number = :contact_number, username = :username
                    WHERE employee_id = :employee_id";
            $stmt = $conn->prepare($sql);
        }

        $stmt->bindParam(":role_id", $roleId, PDO::PARAM_INT);
        $stmt->bindParam(":first_name", $firstName, PDO::PARAM_STR);
        $stmt->bindParam(":last_name", $lastName, PDO::PARAM_STR);
        $stmt->bindParam(":contact_number", $contactNumber, PDO::PARAM_STR);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->bindParam(":employee_id", $employeeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            // Field-sensitive audit split: a role change and/or a password
            // reset each get their OWN audit row (never any password value
            // or hash), and the generic logger is suppressed for this
            // request. Ordinary edits keep the generic logger's row.
            $roleChanged = $beforeEmp && (int)$beforeEmp["role_id"] !== (int)$roleId;
            $passwordReset = $password !== "";
            if ($roleChanged || $passwordReset) {
                $newRoleStmt = $conn->prepare("SELECT role_name FROM tblrole WHERE role_id = :id");
                $newRoleStmt->bindParam(":id", $roleId, PDO::PARAM_INT);
                $newRoleStmt->execute();
                $newRoleName = $newRoleStmt->fetchColumn() ?: (string)$roleId;
                $label = trim($firstName . " " . $lastName);
                if ($roleChanged) {
                    auditEvent($conn, [
                        "module" => "Employees",
                        "action" => "RoleChanged",
                        "record_id" => (int)$employeeId,
                        "record_label" => $label,
                        "details" => "Employee role changed.",
                        "before" => ["role_id" => (int)$beforeEmp["role_id"], "role_name" => $beforeEmp["role_name"]],
                        "after" => ["role_id" => (int)$roleId, "role_name" => $newRoleName]
                    ]);
                }
                if ($passwordReset) {
                    auditEvent($conn, [
                        "module" => "Employees",
                        "action" => "PasswordReset",
                        "record_id" => (int)$employeeId,
                        "record_label" => $label,
                        "details" => "Employee password was reset.",
                        "after" => ["password_reset" => true]
                    ]);
                }
            }
            return ["success" => true, "message" => "Employee updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update employee."];
    }

    public function deleteEmployee($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $employeeId = $data["employee_id"] ?? null;

        if (!$employeeId) {
            return ["success" => false, "message" => "Employee ID is required."];
        }

        if ((int)$employeeId === (int)$_SESSION["employee_id"]) {
            return ["success" => false, "message" => "You cannot deactivate your own account."];
        }

        $sql = "UPDATE tblemployee SET is_active = 0 WHERE employee_id = :employee_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":employee_id", $employeeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Employee deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate employee."];
    }

    public function restoreEmployee($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $employeeId = $data["employee_id"] ?? null;

        if (!$employeeId) {
            return ["success" => false, "message" => "Employee ID is required."];
        }

        $sql = "UPDATE tblemployee SET is_active = 1 WHERE employee_id = :employee_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":employee_id", $employeeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Employee restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore employee."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Employee();

switch ($operation) {

    case "getAllEmployees":
        echo json_encode($obj->getAllEmployees($status));
        break;

    case "searchEmployees":
        echo json_encode($obj->searchEmployees($keyword, $status));
        break;

    case "getActiveEmployeesForDropdown":
        echo json_encode($obj->getActiveEmployeesForDropdown());
        break;
    case "getActiveApproversForDropdown":
        echo json_encode($obj->getActiveApproversForDropdown());
        break;

    case "insertEmployee":
        echo json_encode($obj->insertEmployee($json));
        break;

    case "updateEmployee":
        echo json_encode($obj->updateEmployee($json));
        break;

    case "deleteEmployee":
        echo json_encode($obj->deleteEmployee($json));
        break;

    case "restoreEmployee":
        echo json_encode($obj->restoreEmployee($json));
        break;

    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
