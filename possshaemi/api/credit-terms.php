<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Credit Terms", "tblcredit_term", "credit_term_id", "term_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class CreditTerm {

    public function getAllCreditTerms($status) {
        include "connection-pdo.php";

        $sql = "SELECT credit_term_id, term_name, due_days, is_active FROM tblcredit_term";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY due_days ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveCreditTermsForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT credit_term_id, term_name, due_days FROM tblcredit_term WHERE is_active = 1 ORDER BY due_days ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCreditTerms($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT credit_term_id, term_name, due_days, is_active FROM tblcredit_term WHERE term_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY due_days ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertCreditTerm($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $termName = trim($data["term_name"] ?? "");
        $dueDays = $data["due_days"] ?? null;

        if ($termName === "" || $dueDays === null || !is_numeric($dueDays) || $dueDays < 0) {
            return ["success" => false, "message" => "Term name and a valid non-negative due days value are required."];
        }

        $sql = "INSERT INTO tblcredit_term (term_name, due_days, is_active) VALUES (:term_name, :due_days, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":term_name", $termName, PDO::PARAM_STR);
        $stmt->bindParam(":due_days", $dueDays, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Credit term added successfully.", "credit_term_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add credit term."];
    }

    public function updateCreditTerm($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $creditTermId = $data["credit_term_id"] ?? null;
        $termName = trim($data["term_name"] ?? "");
        $dueDays = $data["due_days"] ?? null;

        if (!$creditTermId || $termName === "" || $dueDays === null || !is_numeric($dueDays) || $dueDays < 0) {
            return ["success" => false, "message" => "Credit term ID, term name, and a valid non-negative due days value are required."];
        }

        $sql = "UPDATE tblcredit_term SET term_name = :term_name, due_days = :due_days WHERE credit_term_id = :credit_term_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":term_name", $termName, PDO::PARAM_STR);
        $stmt->bindParam(":due_days", $dueDays, PDO::PARAM_INT);
        $stmt->bindParam(":credit_term_id", $creditTermId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Credit term updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update credit term."];
    }

    public function deleteCreditTerm($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $creditTermId = $data["credit_term_id"] ?? null;

        if (!$creditTermId) {
            return ["success" => false, "message" => "Credit term ID is required."];
        }

        $checkSql = "SELECT COUNT(*) AS total FROM tblar_account WHERE credit_term_id = :id AND account_status = 'Active'";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bindParam(":id", $creditTermId, PDO::PARAM_INT);
        $checkStmt->execute();
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($check["total"] > 0) {
            return ["success" => false, "message" => "Cannot deactivate: " . $check["total"] . " active AR account(s) use this term."];
        }

        $sql = "UPDATE tblcredit_term SET is_active = 0 WHERE credit_term_id = :credit_term_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":credit_term_id", $creditTermId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Credit term deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate credit term."];
    }

    public function restoreCreditTerm($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $creditTermId = $data["credit_term_id"] ?? null;

        if (!$creditTermId) {
            return ["success" => false, "message" => "Credit term ID is required."];
        }

        $sql = "UPDATE tblcredit_term SET is_active = 1 WHERE credit_term_id = :credit_term_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":credit_term_id", $creditTermId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Credit term restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore credit term."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new CreditTerm();

switch ($operation) {
    case "getAllCreditTerms":
        echo json_encode($obj->getAllCreditTerms($status));
        break;
    case "getActiveCreditTermsForDropdown":
        echo json_encode($obj->getActiveCreditTermsForDropdown());
        break;
    case "searchCreditTerms":
        echo json_encode($obj->searchCreditTerms($keyword, $status));
        break;
    case "insertCreditTerm":
        echo json_encode($obj->insertCreditTerm($json));
        break;
    case "updateCreditTerm":
        echo json_encode($obj->updateCreditTerm($json));
        break;
    case "deleteCreditTerm":
        echo json_encode($obj->deleteCreditTerm($json));
        break;
    case "restoreCreditTerm":
        echo json_encode($obj->restoreCreditTerm($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}