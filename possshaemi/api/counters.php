<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Counters", "tblcounter", "counter_id", "counter_name");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Counter {

    public function getAllCounters($status) {
        include "connection-pdo.php";

        $sql = "SELECT counter_id, counter_name, counter_number, status, is_active FROM tblcounter";

        if ($status === "active") {
            $sql .= " WHERE is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE is_active = 0";
        }

        $sql .= " ORDER BY counter_number ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCounters($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT counter_id, counter_name, counter_number, status, is_active FROM tblcounter WHERE counter_name LIKE :keyword";

        if ($status === "active") {
            $sql .= " AND is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY counter_number ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertCounter($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterName = trim($data["counter_name"] ?? "");
        $counterNumber = $data["counter_number"] ?? null;
        $status = trim($data["status"] ?? "Closed");

        if ($counterName === "" || $counterNumber === null || !is_numeric($counterNumber)) {
            return ["success" => false, "message" => "Counter name and a valid counter number are required."];
        }

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblcounter WHERE counter_number = :counter_number");
        $dupCheck->bindParam(":counter_number", $counterNumber, PDO::PARAM_INT);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "Counter number already exists."];
        }

        $sql = "INSERT INTO tblcounter (counter_name, counter_number, status, is_active)
                VALUES (:counter_name, :counter_number, :status, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":counter_name", $counterName, PDO::PARAM_STR);
        $stmt->bindParam(":counter_number", $counterNumber, PDO::PARAM_INT);
        $stmt->bindParam(":status", $status, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Counter added successfully.", "counter_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add counter."];
    }

    public function updateCounter($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = $data["counter_id"] ?? null;
        $counterName = trim($data["counter_name"] ?? "");
        $counterNumber = $data["counter_number"] ?? null;
        $status = trim($data["status"] ?? "Closed");

        if (!$counterId || $counterName === "" || $counterNumber === null || !is_numeric($counterNumber)) {
            return ["success" => false, "message" => "Counter ID, name, and a valid counter number are required."];
        }

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblcounter WHERE counter_number = :counter_number AND counter_id != :counter_id");
        $dupCheck->bindParam(":counter_number", $counterNumber, PDO::PARAM_INT);
        $dupCheck->bindParam(":counter_id", $counterId, PDO::PARAM_INT);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "Counter number already exists."];
        }

        $sql = "UPDATE tblcounter SET counter_name = :counter_name, counter_number = :counter_number, status = :status
                WHERE counter_id = :counter_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":counter_name", $counterName, PDO::PARAM_STR);
        $stmt->bindParam(":counter_number", $counterNumber, PDO::PARAM_INT);
        $stmt->bindParam(":status", $status, PDO::PARAM_STR);
        $stmt->bindParam(":counter_id", $counterId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Counter updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update counter."];
    }

    public function deleteCounter($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = $data["counter_id"] ?? null;

        if (!$counterId) {
            return ["success" => false, "message" => "Counter ID is required."];
        }

        $sql = "UPDATE tblcounter SET is_active = 0 WHERE counter_id = :counter_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":counter_id", $counterId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Counter deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate counter."];
    }

    public function restoreCounter($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $counterId = $data["counter_id"] ?? null;

        if (!$counterId) {
            return ["success" => false, "message" => "Counter ID is required."];
        }

        $sql = "UPDATE tblcounter SET is_active = 1 WHERE counter_id = :counter_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":counter_id", $counterId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Counter restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore counter."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Counter();

switch ($operation) {
    case "getAllCounters":
        echo json_encode($obj->getAllCounters($status));
        break;
    case "searchCounters":
        echo json_encode($obj->searchCounters($keyword, $status));
        break;
    case "insertCounter":
        echo json_encode($obj->insertCounter($json));
        break;
    case "updateCounter":
        echo json_encode($obj->updateCounter($json));
        break;
    case "deleteCounter":
        echo json_encode($obj->deleteCounter($json));
        break;
    case "restoreCounter":
        echo json_encode($obj->restoreCounter($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}