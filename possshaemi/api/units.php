<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Units", "tblproduct_unit", "unit_id", "unit_name");
header("Content-Type: application/json");

class Unit {
    public function getAll($status) {
        include "connection-pdo.php";
        $sql = "SELECT unit_id, unit_name, unit_code, is_active FROM tblproduct_unit";
        if ($status === "active") $sql .= " WHERE is_active = 1";
        if ($status === "inactive") $sql .= " WHERE is_active = 0";
        $sql .= " ORDER BY unit_name ASC";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActive() {
        return $this->getAll("active");
    }

    public function search($keyword, $status) {
        include "connection-pdo.php";
        $sql = "SELECT unit_id, unit_name, unit_code, is_active FROM tblproduct_unit
                WHERE (unit_name LIKE :kw OR unit_code LIKE :kw)";
        if ($status === "active") $sql .= " AND is_active = 1";
        if ($status === "inactive") $sql .= " AND is_active = 0";
        $sql .= " ORDER BY unit_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":kw", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true) ?: [];
        $id = (int)($data["unit_id"] ?? 0);
        $name = trim($data["unit_name"] ?? "");
        $code = strtoupper(trim($data["unit_code"] ?? ""));
        if ($name === "" || $code === "") return ["success" => false, "message" => "Unit name and code are required."];
        if (strlen($code) > 10) return ["success" => false, "message" => "Unit code must be 10 characters or fewer."];
        $duplicate = $conn->prepare("SELECT COUNT(*) FROM tblproduct_unit WHERE (unit_name = :name OR unit_code = :code) AND unit_id != :id");
        $duplicate->execute([":name" => $name, ":code" => $code, ":id" => $id]);
        if ((int)$duplicate->fetchColumn() > 0) return ["success" => false, "message" => "Unit name or code already exists."];
        if ($id) {
            $stmt = $conn->prepare("UPDATE tblproduct_unit SET unit_name = :name, unit_code = :code WHERE unit_id = :id");
            $ok = $stmt->execute([":name" => $name, ":code" => $code, ":id" => $id]);
            return ["success" => $ok, "message" => $ok ? "Unit updated successfully." : "Failed to update unit."];
        }
        $stmt = $conn->prepare("INSERT INTO tblproduct_unit (unit_name, unit_code, is_active) VALUES (:name, :code, 1)");
        $ok = $stmt->execute([":name" => $name, ":code" => $code]);
        return ["success" => $ok, "message" => $ok ? "Unit added successfully." : "Failed to add unit."];
    }

    public function setStatus($json, $active) {
        include "connection-pdo.php";
        $id = (int)((json_decode($json, true) ?: [])["unit_id"] ?? 0);
        if (!$id) return ["success" => false, "message" => "Unit is required."];
        if (!$active) {
            $check = $conn->prepare("SELECT COUNT(*) FROM tblproduct WHERE unit_id = :id AND is_active = 1");
            $check->execute([":id" => $id]);
            if ((int)$check->fetchColumn() > 0) return ["success" => false, "message" => "Cannot deactivate a unit assigned to active products."];
        }
        $stmt = $conn->prepare("UPDATE tblproduct_unit SET is_active = :active WHERE unit_id = :id");
        $ok = $stmt->execute([":active" => $active, ":id" => $id]);
        return ["success" => $ok, "message" => $ok ? ($active ? "Unit restored successfully." : "Unit deactivated successfully.") : "Failed to update unit."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";
$obj = new Unit();
switch ($operation) {
    case "getAll": echo json_encode($obj->getAll($status)); break;
    case "search": echo json_encode($obj->search($keyword, $status)); break;
    case "getActive": echo json_encode($obj->getActive()); break;
    case "save": echo json_encode($obj->save($json)); break;
    case "deactivate": echo json_encode($obj->setStatus($json, 0)); break;
    case "restore": echo json_encode($obj->setStatus($json, 1)); break;
    default: echo json_encode(["success" => false, "message" => "Invalid operation."]);
}
