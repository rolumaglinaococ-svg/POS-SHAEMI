<?php
require_once "session-guard.php";
requireRole(["Administrator", "Cashier"]);
require_once "activity-logger.php";
if (($_SESSION["role"] ?? "") === "Administrator") {
    startActivityLogging("Gift Certificates", "tblgift_certificate", "gc_id", "gc_id");
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class GiftCertificate {
    private function adminOnly() {
        if (($_SESSION["role"] ?? "") !== "Administrator") {
            http_response_code(403);
            return ["success" => false, "message" => "Administrator permission is required for Gift Certificate management."];
        }
        return null;
    }

    private function effectiveStatus(array $gc, float $remaining) {
        if (strcasecmp((string)$gc["status"], "Cancelled") === 0) return "Cancelled";
        if ($remaining <= 0.001) return "Fully Used";
        if (!empty($gc["expiration_date"]) && $gc["expiration_date"] < date("Y-m-d")) return "Expired";
        return "Active";
    }

    private function getRemaining(PDO $conn, int $gcId) {
        $stmt = $conn->prepare("SELECT gc_value FROM tblgift_certificate WHERE gc_id = :id");
        $stmt->bindValue(":id", $gcId, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value === false) return null;
        $used = $conn->prepare("SELECT COALESCE(SUM(pay.amount_paid),0) FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id INNER JOIN tblsale s ON inv.sale_id = s.sale_id WHERE pay.gc_id = :id AND s.sale_status = 'Completed'");
        $used->bindValue(":id", $gcId, PDO::PARAM_INT);
        $used->execute();
        return round((float)$value - (float)$used->fetchColumn(), 2);
    }

    public function getAll($status = "active", $keyword = "") {
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $stmt = $conn->prepare("SELECT gc.gc_id, gc.suki_card_id, gc.gc_value, gc.expiration_date, gc.date_issued, gc.date_redeemed, gc.status, gc.card_number, CONCAT(c.first_name, ' ', c.last_name) AS customer_name FROM (SELECT g.*, sc.card_number, sc.customer_id FROM tblgift_certificate g INNER JOIN tblsuki_card sc ON g.suki_card_id = sc.suki_card_id) gc INNER JOIN tblcustomer c ON gc.customer_id = c.customer_id ORDER BY gc.gc_id DESC");
        $stmt->execute();
        $rows = [];
        $keyword = trim($keyword);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $gc) {
            $remaining = $this->getRemaining($conn, (int)$gc["gc_id"]);
            $gc["remaining_balance"] = $remaining;
            $gc["effective_status"] = $this->effectiveStatus($gc, (float)$remaining);
            if ($status !== "all" && strtolower($gc["effective_status"]) !== strtolower($status)) continue;
            if ($keyword !== "" && stripos($gc["customer_name"] . " " . $gc["card_number"], $keyword) === false) continue;
            $rows[] = $gc;
        }
        return $rows;
    }

    public function getForPayment($gcId) {
        include "connection-pdo.php";
        $gcId = (int)$gcId;
        if ($gcId <= 0) return ["success" => false, "message" => "Gift Certificate ID is required."];
        $stmt = $conn->prepare("SELECT g.gc_id, g.suki_card_id, g.gc_value, g.expiration_date, g.date_issued, g.status, sc.card_number, CONCAT(c.first_name, ' ', c.last_name) AS customer_name FROM tblgift_certificate g INNER JOIN tblsuki_card sc ON g.suki_card_id = sc.suki_card_id INNER JOIN tblcustomer c ON sc.customer_id = c.customer_id WHERE g.gc_id = :id LIMIT 1");
        $stmt->bindValue(":id", $gcId, PDO::PARAM_INT); $stmt->execute();
        $gc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$gc) return ["success" => false, "message" => "Gift Certificate not found."];
        $remaining = $this->getRemaining($conn, $gcId);
        $status = $this->effectiveStatus($gc, (float)$remaining);
        if ($status === "Cancelled") return ["success" => false, "message" => "Gift Certificate Cancelled. This certificate can no longer be used."];
        if ($status === "Expired") return ["success" => false, "message" => "Gift Certificate Expired. This certificate can no longer be used."];
        if ($status === "Fully Used") return ["success" => false, "message" => "Gift Certificate Already Used. This certificate has no remaining balance."];
        return ["success" => true, "gift_certificate" => array_merge($gc, ["remaining_balance" => $remaining, "effective_status" => $status])];
    }

    public function insert($json) {
        // Legacy Suki-bound certificates are redeem-only since 2026-10-22:
        // GC Codes (api/gc-codes.php) is the only way to issue new ones.
        // Redemption stays available via getForPayment + api/pos.php.
        return ["success" => false, "message" => "Creating legacy Gift Certificates is disabled. Issue GC Codes from the Admin panel instead."];
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $data = json_decode($json, true);
        $sukiCardId = !empty($data["suki_card_id"]) ? (int)$data["suki_card_id"] : 0;
        $value = isset($data["gc_value"]) ? round((float)$data["gc_value"], 2) : 0;
        $expirationDate = trim((string)($data["expiration_date"] ?? ""));
        if ($sukiCardId <= 0 || $value <= 0 || $expirationDate === "") return ["success" => false, "message" => "Suki Card, Gift Certificate value, and expiration date are required."];
        if ($expirationDate < date("Y-m-d")) return ["success" => false, "message" => "Expiration date cannot be in the past."];
        $check = $conn->prepare("SELECT is_active FROM tblsuki_card WHERE suki_card_id = :id");
        $check->bindValue(":id", $sukiCardId, PDO::PARAM_INT); $check->execute();
        if ((int)$check->fetchColumn() !== 1) return ["success" => false, "message" => "Selected Suki Card is not active."];
        $stmt = $conn->prepare("INSERT INTO tblgift_certificate (suki_card_id, gc_value, points_used, date_issued, expiration_date, status) VALUES (:suki_card_id, :value, 0, CURDATE(), :expiration, 'Active')");
        $stmt->bindValue(":suki_card_id", $sukiCardId, PDO::PARAM_INT); $stmt->bindValue(":value", $value); $stmt->bindValue(":expiration", $expirationDate, PDO::PARAM_STR); $stmt->execute();
        return ["success" => true, "message" => "Gift Certificate created successfully.", "gc_id" => (int)$conn->lastInsertId()];
    }

    public function cancel($json) {
        // Disabled together with insert() — legacy certificates are
        // redeem-only now; nothing new is created or cancelled here.
        return ["success" => false, "message" => "Cancelling legacy Gift Certificates is disabled. Legacy certificates remain redeemable at the register until they expire or are fully used."];
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $data = json_decode($json, true); $gcId = !empty($data["gc_id"]) ? (int)$data["gc_id"] : 0;
        if ($gcId <= 0) return ["success" => false, "message" => "Gift Certificate ID is required."];
        $stmt = $conn->prepare("SELECT status, gc_value FROM tblgift_certificate WHERE gc_id = :id"); $stmt->bindValue(":id", $gcId, PDO::PARAM_INT); $stmt->execute(); $gc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$gc) return ["success" => false, "message" => "Gift Certificate not found."];
        if (strcasecmp((string)$gc["status"], "Cancelled") === 0) return ["success" => false, "message" => "Gift Certificate is already cancelled."];
        $remaining = $this->getRemaining($conn, $gcId);
        if ($remaining !== null && $remaining <= 0.001) return ["success" => false, "message" => "A fully used Gift Certificate cannot be cancelled again."];
        $update = $conn->prepare("UPDATE tblgift_certificate SET status = 'Cancelled' WHERE gc_id = :id"); $update->bindValue(":id", $gcId, PDO::PARAM_INT); $update->execute();
        return ["success" => true, "message" => "Gift Certificate cancelled."];
    }

    public function usage($gcId) {
        $guard = $this->adminOnly(); if ($guard) return $guard;
        include "connection-pdo.php";
        $gcId = (int)$gcId; if ($gcId <= 0) return ["success" => false, "message" => "Gift Certificate ID is required."];
        $stmt = $conn->prepare("SELECT pay.payment_id, pay.amount_paid, pay.payment_date, pay.reference_no, inv.sale_id, s.receipt_number, s.sale_status, CONCAT(e.first_name, ' ', e.last_name) AS cashier_name FROM tblpayment pay INNER JOIN tblinvoice inv ON pay.invoice_id = inv.invoice_id INNER JOIN tblsale s ON inv.sale_id = s.sale_id INNER JOIN tblemployee e ON s.employee_id = e.employee_id WHERE pay.gc_id = :id ORDER BY pay.payment_id DESC");
        $stmt->bindValue(":id", $gcId, PDO::PARAM_INT); $stmt->execute();
        return ["success" => true, "usage" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$gcId = $_GET["gc_id"] ?? $_POST["gc_id"] ?? 0;
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";
$obj = new GiftCertificate();

switch ($operation) {
    case "getAll": echo json_encode($obj->getAll($status, $keyword)); break;
    case "getForPayment": echo json_encode($obj->getForPayment($gcId)); break;
    case "insert": echo json_encode($obj->insert($json)); break;
    case "cancel": echo json_encode($obj->cancel($json)); break;
    case "usage": echo json_encode($obj->usage($gcId)); break;
    default: echo json_encode(["success" => false, "message" => "Invalid operation."]); break;
}
