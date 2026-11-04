<?php
require_once "session-guard.php";
requireRole(["Administrator", "Cashier"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
if (($_SESSION["role"] ?? "") === "Administrator") {
    startActivityLogging("GC Codes", "tblgift_certificate_code", "gcc_id", "code");
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class GcCode {
    const CODE_PREFIX = "GC-";
    const CODE_LENGTH = 8;
    const CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"; // no 0/O, 1/I/L
    const MAX_BATCH = 500;

    private function adminOnly() {
        if (($_SESSION["role"] ?? "") !== "Administrator") {
            http_response_code(403);
            return ["success" => false, "message" => "Administrator permission is required for GC Code management."];
        }
        return null;
    }

    private function effectiveStatus(array $row) {
        if ($row["status"] === "Voided") return "Voided";
        if ($row["status"] === "Redeemed") return "Redeemed";
        if (!empty($row["expiration_date"]) && $row["expiration_date"] < date("Y-m-d")) return "Expired";
        return "Available";
    }

    private function randomCode() {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = "";
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return self::CODE_PREFIX . $code;
    }

    public function getAll($status = "available", $keyword = "") {
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $stmt = $conn->prepare("SELECT g.gcc_id, g.code, g.face_value, g.expiration_date, g.status, g.date_issued, g.redeemed_at, s.receipt_number, CONCAT(e.first_name, ' ', e.last_name) AS issued_by_name FROM tblgift_certificate_code g LEFT JOIN tblsale s ON g.redeemed_sale_id = s.sale_id LEFT JOIN tblemployee e ON g.issued_by = e.employee_id ORDER BY g.gcc_id DESC");
        $stmt->execute();
        $rows = [];
        $keyword = trim($keyword);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row["effective_status"] = $this->effectiveStatus($row);
            if ($status !== "all" && strtolower($row["effective_status"]) !== strtolower($status)) continue;
            if ($keyword !== "" && stripos((string)$row["code"], $keyword) === false) continue;
            $rows[] = $row;
        }
        return $rows;
    }

    public function insertBatch($json) {
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request data."];

        $quantity = isset($data["quantity"]) ? (int)$data["quantity"] : 0;
        $denomination = trim((string)($data["denomination"] ?? ""));
        $customAmount = isset($data["custom_amount"]) ? round((float)$data["custom_amount"], 2) : 0;
        $expirationDate = trim((string)($data["expiration_date"] ?? ""));

        if ($quantity < 1 || $quantity > self::MAX_BATCH) {
            return ["success" => false, "message" => "Quantity must be between 1 and " . self::MAX_BATCH . "."];
        }
        $allowed = ["100", "200", "500", "1000"];
        if ($denomination === "custom") {
            if ($customAmount <= 0) return ["success" => false, "message" => "Enter a valid custom amount greater than zero."];
            $faceValue = $customAmount;
        } elseif (in_array($denomination, $allowed, true)) {
            $faceValue = (float)$denomination;
        } else {
            return ["success" => false, "message" => "Select a valid denomination."];
        }
        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $expirationDate) || $expirationDate < date("Y-m-d")) {
            return ["success" => false, "message" => "Expiration date must be today or a future date."];
        }

        $issuedBy = (int)($_SESSION["employee_id"] ?? 0);
        $today = date("Y-m-d");
        $insert = $conn->prepare("INSERT INTO tblgift_certificate_code (code, face_value, expiration_date, status, date_issued, issued_by) VALUES (:code, :face_value, :expiration_date, 'Available', :date_issued, :issued_by)");

        $conn->beginTransaction();
        try {
            $codes = [];
            for ($n = 0; $n < $quantity; $n++) {
                // UNIQUE(code) is the hard guarantee; the retry loop handles
                // the vanishingly rare collision without failing the batch.
                $attempts = 0;
                while (true) {
                    $attempts++;
                    if ($attempts > 10) {
                        throw new Exception("Could not generate a unique code after 10 attempts. Please retry the batch.");
                    }
                    $code = $this->randomCode();
                    $insert->bindValue(":code", $code, PDO::PARAM_STR);
                    $insert->bindValue(":face_value", $faceValue);
                    $insert->bindValue(":expiration_date", $expirationDate, PDO::PARAM_STR);
                    $insert->bindValue(":date_issued", $today, PDO::PARAM_STR);
                    $insert->bindValue(":issued_by", $issuedBy, PDO::PARAM_INT);
                    try {
                        $insert->execute();
                    } catch (PDOException $e) {
                        if ($e->getCode() === "23000") continue; // duplicate code, regenerate
                        throw $e;
                    }
                    $codes[] = ["code" => $code, "face_value" => $faceValue, "expiration_date" => $expirationDate];
                    break;
                }
            }
            // One audit row per batch (not per code).
            $batchRef = "GCB-" . date("Ymd") . "-" . substr($codes[0]["code"], 3, 4);
            auditEvent($conn, [
                "module" => "GC Codes",
                "action" => "Created",
                "record_label" => $batchRef,
                "details" => $quantity . " gift certificate code(s) generated.",
                "after" => ["quantity" => $quantity, "face_value" => $faceValue,
                            "total_face_value" => round($faceValue * $quantity, 2),
                            "expiration_date" => $expirationDate,
                            "first_code" => $codes[0]["code"], "last_code" => $codes[count($codes) - 1]["code"]],
                "reference_label" => $batchRef
            ]);
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }

        return ["success" => true, "message" => $quantity . " gift certificate code(s) generated.", "codes" => $codes];
    }

    public function void($json) {
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $data = json_decode($json, true);
        $gccId = !empty($data["gcc_id"]) ? (int)$data["gcc_id"] : 0;
        if ($gccId <= 0) return ["success" => false, "message" => "Gift certificate code ID is required."];
        $stmt = $conn->prepare("SELECT gcc_id, code, status FROM tblgift_certificate_code WHERE gcc_id = :id");
        $stmt->bindValue(":id", $gccId, PDO::PARAM_INT); $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ["success" => false, "message" => "Gift certificate code not found."];
        if ($row["status"] === "Voided") return ["success" => false, "message" => "This code is already voided."];
        if ($row["status"] === "Redeemed") return ["success" => false, "message" => "A redeemed certificate cannot be voided. It stays redeemed."];
        try {
            $conn->beginTransaction();
            $update = $conn->prepare("UPDATE tblgift_certificate_code SET status = 'Voided' WHERE gcc_id = :id AND status = 'Available'");
            $update->bindValue(":id", $gccId, PDO::PARAM_INT); $update->execute();
            if ($update->rowCount() === 0) {
                $conn->rollBack();
                return ["success" => false, "message" => "This code can no longer be voided."];
            }
            auditEvent($conn, [
                "module" => "GC Codes",
                "action" => "Voided",
                "record_id" => $gccId,
                "record_label" => $row["code"],
                "details" => "Gift certificate code voided.",
                "before" => ["status" => $row["status"]],
                "after" => ["status" => "Voided"],
                "reason" => isset($data["reason"]) ? trim((string)$data["reason"]) : null,
                "reference_label" => $row["code"]
            ]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to void the gift certificate code. Nothing was changed."];
        }
        return ["success" => true, "message" => "Gift certificate code " . $row["code"] . " voided."];
    }

    public function validateCode($code) {
        include "connection-pdo.php";
        $code = strtoupper(trim((string)$code));
        if ($code === "") return ["success" => false, "message" => "Gift certificate code is required."];
        $stmt = $conn->prepare("SELECT gcc_id, code, face_value, expiration_date, status FROM tblgift_certificate_code WHERE code = :code LIMIT 1");
        $stmt->bindValue(":code", $code, PDO::PARAM_STR); $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ["success" => false, "message" => "Gift certificate code not found."];
        if ($row["status"] === "Voided") return ["success" => false, "message" => "This gift certificate has been voided and cannot be used."];
        if ($row["status"] === "Redeemed") return ["success" => false, "message" => "This gift certificate has already been redeemed."];
        if (!empty($row["expiration_date"]) && $row["expiration_date"] < date("Y-m-d")) {
            return ["success" => false, "message" => "This gift certificate expired on " . $row["expiration_date"] . "."];
        }
        return ["success" => true, "certificate" => ["code" => $row["code"], "face_value" => (float)$row["face_value"], "expiration_date" => $row["expiration_date"]]];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "available";
$keyword = $_GET["keyword"] ?? "";
$code = $_GET["code"] ?? $_POST["code"] ?? "";
$obj = new GcCode();

switch ($operation) {
    case "getAll": echo json_encode($obj->getAll($status, $keyword)); break;
    case "insertBatch": echo json_encode($obj->insertBatch($json)); break;
    case "void": echo json_encode($obj->void($json)); break;
    case "validateCode": echo json_encode($obj->validateCode($code)); break;
    default: echo json_encode(["success" => false, "message" => "Invalid operation."]); break;
}
