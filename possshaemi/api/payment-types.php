<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Payment Types", "tblpayment_type", "payment_type_id", "type_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class PaymentType {

    public function getAllPaymentTypes($status) {
        include "connection-pdo.php";

        $sql = "SELECT payment_type_id, type_name, is_active FROM tblpayment_type";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY type_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchPaymentTypes($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT payment_type_id, type_name, is_active FROM tblpayment_type WHERE type_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY type_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertPaymentType($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $typeName = trim($data["type_name"] ?? "");

        if ($typeName === "") {
            return ["success" => false, "message" => "Payment type name is required."];
        }

        $sql = "INSERT INTO tblpayment_type (type_name, is_active) VALUES (:type_name, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":type_name", $typeName, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Payment type added successfully.", "payment_type_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add payment type."];
    }

    public function updatePaymentType($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $paymentTypeId = $data["payment_type_id"] ?? null;
        $typeName = trim($data["type_name"] ?? "");

        if (!$paymentTypeId || $typeName === "") {
            return ["success" => false, "message" => "Payment type ID and name are required."];
        }

        $sql = "UPDATE tblpayment_type SET type_name = :type_name WHERE payment_type_id = :payment_type_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":type_name", $typeName, PDO::PARAM_STR);
        $stmt->bindParam(":payment_type_id", $paymentTypeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Payment type updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update payment type."];
    }

    public function deletePaymentType($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $paymentTypeId = $data["payment_type_id"] ?? null;

        if (!$paymentTypeId) {
            return ["success" => false, "message" => "Payment type ID is required."];
        }

        $sql = "UPDATE tblpayment_type SET is_active = 0 WHERE payment_type_id = :payment_type_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":payment_type_id", $paymentTypeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Payment type deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate payment type."];
    }

    public function restorePaymentType($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $paymentTypeId = $data["payment_type_id"] ?? null;

        if (!$paymentTypeId) {
            return ["success" => false, "message" => "Payment type ID is required."];
        }

        $sql = "UPDATE tblpayment_type SET is_active = 1 WHERE payment_type_id = :payment_type_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":payment_type_id", $paymentTypeId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Payment type restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore payment type."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new PaymentType();

switch ($operation) {
    case "getAllPaymentTypes":
        echo json_encode($obj->getAllPaymentTypes($status));
        break;
    case "searchPaymentTypes":
        echo json_encode($obj->searchPaymentTypes($keyword, $status));
        break;
    case "insertPaymentType":
        echo json_encode($obj->insertPaymentType($json));
        break;
    case "updatePaymentType":
        echo json_encode($obj->updatePaymentType($json));
        break;
    case "deletePaymentType":
        echo json_encode($obj->deletePaymentType($json));
        break;
    case "restorePaymentType":
        echo json_encode($obj->restorePaymentType($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}