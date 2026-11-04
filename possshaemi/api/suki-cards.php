<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
startActivityLogging("Suki Cards", "tblsuki_card", "suki_card_id", "card_number");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class SukiCard {

    private function sukiProgressColumnExists($conn) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblsuki_card' AND COLUMN_NAME = 'suki_progress_amount'");
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }


    public function getAllSukiCards($status) {
        include "connection-pdo.php";

        $progressSelect = $this->sukiProgressColumnExists($conn) ? ", sc.suki_progress_amount" : ", 0 AS suki_progress_amount";
        $sql = "SELECT sc.suki_card_id, sc.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       sc.card_number, sc.points_balance" . $progressSelect . ", sc.date_issued, sc.is_active
                FROM tblsuki_card sc
                INNER JOIN tblcustomer c ON sc.customer_id = c.customer_id";

        if ($status === "active") {
            $sql .= " WHERE sc.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE sc.is_active = 0";
        }

        $sql .= " ORDER BY sc.date_issued DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchSukiCards($keyword, $status) {
        include "connection-pdo.php";

        $progressSelect = $this->sukiProgressColumnExists($conn) ? ", sc.suki_progress_amount" : ", 0 AS suki_progress_amount";
        $sql = "SELECT sc.suki_card_id, sc.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       sc.card_number, sc.points_balance" . $progressSelect . ", sc.date_issued, sc.is_active
                FROM tblsuki_card sc
                INNER JOIN tblcustomer c ON sc.customer_id = c.customer_id
                WHERE (sc.card_number LIKE :keyword OR c.first_name LIKE :keyword OR c.last_name LIKE :keyword)";

        if ($status === "active") {
            $sql .= " AND sc.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND sc.is_active = 0";
        }

        $sql .= " ORDER BY sc.date_issued DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertSukiCard($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $customerId = $data["customer_id"] ?? null;
        $cardNumber = trim($data["card_number"] ?? "");
        $dateIssued = trim($data["date_issued"] ?? "");

        if (!$customerId || $dateIssued === "") {
            return ["success" => false, "message" => "Customer and date issued are required."];
        }

        // Card numbers are auto-generated; accept a supplied one but fall back
        // to a fresh unique number when the client sends a blank.
        if ($cardNumber === "") {
            $cardNumber = $this->buildUniqueCardNumber($conn);
            if (!$cardNumber) {
                return ["success" => false, "message" => "Could not generate a unique card number. Please try again."];
            }
        }

        $customerCheck = $conn->prepare("SELECT is_active FROM tblcustomer WHERE customer_id = :id");
        $customerCheck->bindParam(":id", $customerId, PDO::PARAM_INT);
        $customerCheck->execute();
        $customer = $customerCheck->fetch(PDO::FETCH_ASSOC);

        if (!$customer || (int)$customer["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected customer is not active."];
        }

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblsuki_card WHERE card_number = :card_number");
        $dupCheck->bindParam(":card_number", $cardNumber, PDO::PARAM_STR);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "Card number already exists."];
        }

        $custCardCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblsuki_card WHERE customer_id = :customer_id");
        $custCardCheck->bindParam(":customer_id", $customerId, PDO::PARAM_INT);
        $custCardCheck->execute();
        $custCard = $custCardCheck->fetch(PDO::FETCH_ASSOC);

        if ($custCard["total"] > 0) {
            return ["success" => false, "message" => "This customer already has a Suki card. A customer can only have one."];
        }

        $sql = "INSERT INTO tblsuki_card (customer_id, card_number, points_balance, date_issued, is_active)
                VALUES (:customer_id, :card_number, 0, :date_issued, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customer_id", $customerId, PDO::PARAM_INT);
        $stmt->bindParam(":card_number", $cardNumber, PDO::PARAM_STR);
        $stmt->bindParam(":date_issued", $dateIssued, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Suki card added successfully.", "suki_card_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add suki card."];
    }

    /**
     * Build a unique card number like SK-261018-4821. Retries on collision;
     * returns null when a unique number cannot be found quickly.
     */
    private function buildUniqueCardNumber($conn) {
        for ($i = 0; $i < 20; $i++) {
            $candidate = "SK-" . date("ymd") . "-" . str_pad((string)random_int(0, 9999), 4, "0", STR_PAD_LEFT);
            $check = $conn->prepare("SELECT COUNT(*) FROM tblsuki_card WHERE card_number = :c");
            $check->bindValue(":c", $candidate, PDO::PARAM_STR);
            $check->execute();
            if ((int)$check->fetchColumn() === 0) return $candidate;
        }
        return null;
    }

    public function generateCardNumber() {
        include "connection-pdo.php";
        $cardNumber = $this->buildUniqueCardNumber($conn);
        if (!$cardNumber) {
            return ["success" => false, "message" => "Could not generate a unique card number. Please try again."];
        }
        return ["success" => true, "card_number" => $cardNumber];
    }

    public function updateSukiCard($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        $sukiCardId = $data["suki_card_id"] ?? null;
        $customerId = $data["customer_id"] ?? null;
        $cardNumber = trim($data["card_number"] ?? "");
        if (!$sukiCardId || !$customerId || $cardNumber === "") {
            return ["success" => false, "message" => "Suki card ID, customer, and card number are required."];
        }
        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblsuki_card WHERE card_number = :card_number AND suki_card_id != :id");
        $dupCheck->bindValue(":card_number", $cardNumber, PDO::PARAM_STR);
        $dupCheck->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
        $dupCheck->execute();
        if ((int)$dupCheck->fetchColumn() > 0) return ["success" => false, "message" => "Card number already exists."];
        $custCardCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblsuki_card WHERE customer_id = :customer_id AND suki_card_id != :id");
        $custCardCheck->bindValue(":customer_id", $customerId, PDO::PARAM_INT);
        $custCardCheck->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
        $custCardCheck->execute();
        if ((int)$custCardCheck->fetchColumn() > 0) return ["success" => false, "message" => "This customer already has another Suki card. A customer can only have one."];
        $sql = "UPDATE tblsuki_card SET customer_id = :customer_id, card_number = :card_number WHERE suki_card_id = :suki_card_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":customer_id", $customerId, PDO::PARAM_INT);
        $stmt->bindValue(":card_number", $cardNumber, PDO::PARAM_STR);
        $stmt->bindValue(":suki_card_id", $sukiCardId, PDO::PARAM_INT);
        return $stmt->execute()
            ? ["success" => true, "message" => "Suki card updated successfully."]
            : ["success" => false, "message" => "Failed to update suki card."];
    }

    public function updateSukiPoints($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        $sukiCardId = !empty($data["suki_card_id"]) ? (int)$data["suki_card_id"] : 0;
        $adjustment = isset($data["adjustment_amount"]) && is_numeric($data["adjustment_amount"]) ? (int)$data["adjustment_amount"] : 0;
        $reason = trim((string)($data["reason"] ?? ""));
        $adminId = (int)$_SESSION["employee_id"];
        if ($sukiCardId <= 0 || $adjustment === 0 || $reason === "") {
            return ["success" => false, "message" => "Suki card, a non-zero adjustment, and a reason are required."];
        }
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT points_balance, is_active FROM tblsuki_card WHERE suki_card_id = :id FOR UPDATE");
            $stmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
            $stmt->execute();
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card || (int)$card["is_active"] !== 1) throw new Exception("Selected Suki Card is not active.");
            $previous = (int)$card["points_balance"];
            $newBalance = $previous + $adjustment;
            if ($newBalance < 0) throw new Exception("The adjustment would make the Suki Point balance negative.");
            $log = $conn->prepare("INSERT INTO tblsuki_point_adjustment (suki_card_id, previous_balance, adjustment_amount, new_balance, reason, adjusted_by) VALUES (:card, :previous, :adjustment, :new_balance, :reason, :admin)");
            $log->bindValue(":card", $sukiCardId, PDO::PARAM_INT);
            $log->bindValue(":previous", $previous, PDO::PARAM_INT);
            $log->bindValue(":adjustment", $adjustment, PDO::PARAM_INT);
            $log->bindValue(":new_balance", $newBalance, PDO::PARAM_INT);
            $log->bindValue(":reason", $reason, PDO::PARAM_STR);
            $log->bindValue(":admin", $adminId, PDO::PARAM_INT);
            $log->execute();
            $update = $conn->prepare("UPDATE tblsuki_card SET points_balance = :points WHERE suki_card_id = :id");
            $update->bindValue(":points", $newBalance, PDO::PARAM_INT);
            $update->bindValue(":id", $sukiCardId, PDO::PARAM_INT);
            $update->execute();
            auditEvent($conn, [
                "module" => "Suki Cards",
                "action" => "Adjusted",
                "record_id" => $sukiCardId,
                "record_label" => "Suki card #" . $sukiCardId,
                "details" => "Suki Points updated from " . $previous . " to " . $newBalance . ".",
                "before" => ["points_balance" => $previous],
                "after" => ["points_balance" => $newBalance, "adjustment_amount" => $adjustment],
                "reason" => $reason
            ]);
            $conn->commit();
            return ["success" => true, "message" => "Suki Points updated from " . $previous . " to " . $newBalance . ".", "new_balance" => $newBalance];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    public function getPointHistory($sukiCardId) {
        include "connection-pdo.php";
        $sukiCardId = (int)$sukiCardId;
        if ($sukiCardId <= 0) return ["success" => false, "message" => "Suki card ID is required."];
        $rows = [];
        $stmt = $conn->prepare("SELECT a.adjusted_at AS event_date, 'Manual Adjustment' AS event_type, a.reason AS description, a.adjustment_amount AS point_change, a.new_balance AS balance_after, CONCAT(e.first_name, ' ', e.last_name) AS performed_by FROM tblsuki_point_adjustment a INNER JOIN tblemployee e ON a.adjusted_by = e.employee_id WHERE a.suki_card_id = :id");
        $stmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT); $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;

        $stmt = $conn->prepare("SELECT pr.redemption_date AS event_date, 'Redemption' AS event_type, CONCAT('Used ', pr.points_used, ' point(s) in payment #', pr.payment_id) AS description, -pr.points_used AS point_change, NULL AS balance_after, NULL AS performed_by FROM tblpoints_redemption pr WHERE pr.suki_card_id = :id");
        $stmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT); $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;

        $stmt = $conn->prepare("SELECT s.sale_date AS event_date, CASE WHEN s.sale_status = 'Voided' THEN 'Sale Points Reversed' ELSE 'Sale Points Earned' END AS event_type, CONCAT(s.receipt_number, ' · ', s.sale_status) AS description, CASE WHEN s.sale_status = 'Voided' THEN -FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0), 0) / 400) ELSE FLOOR(GREATEST(s.total_amount - COALESCE((SELECT SUM(pr.cash_value) FROM tblpoints_redemption pr INNER JOIN tblpayment rp ON pr.payment_id = rp.payment_id INNER JOIN tblinvoice ri ON rp.invoice_id = ri.invoice_id WHERE ri.sale_id = s.sale_id), 0), 0) / 400) END AS point_change, NULL AS balance_after, NULL AS performed_by FROM tblsale s WHERE s.suki_card_id = :id");
        $stmt->bindValue(":id", $sukiCardId, PDO::PARAM_INT); $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;

        usort($rows, fn($a,$b) => strcmp((string)$b["event_date"], (string)$a["event_date"]));
        return ["success" => true, "history" => $rows];
    }

    public function deleteSukiCard($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $sukiCardId = $data["suki_card_id"] ?? null;

        if (!$sukiCardId) {
            return ["success" => false, "message" => "Suki card ID is required."];
        }

        $sql = "UPDATE tblsuki_card SET is_active = 0 WHERE suki_card_id = :suki_card_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":suki_card_id", $sukiCardId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Suki card deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate suki card."];
    }

    public function restoreSukiCard($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $sukiCardId = $data["suki_card_id"] ?? null;

        if (!$sukiCardId) {
            return ["success" => false, "message" => "Suki card ID is required."];
        }

        $sql = "UPDATE tblsuki_card SET is_active = 1 WHERE suki_card_id = :suki_card_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":suki_card_id", $sukiCardId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Suki card restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore suki card."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new SukiCard();

switch ($operation) {
    case "getAllSukiCards":
        echo json_encode($obj->getAllSukiCards($status));
        break;
    case "searchSukiCards":
        echo json_encode($obj->searchSukiCards($keyword, $status));
        break;
    case "insertSukiCard":
        echo json_encode($obj->insertSukiCard($json));
        break;
    case "generateCardNumber":
        echo json_encode($obj->generateCardNumber());
        break;
    case "updateSukiCard":
        echo json_encode($obj->updateSukiCard($json));
        break;
    case "updateSukiPoints":
        echo json_encode($obj->updateSukiPoints($json));
        break;
    case "getPointHistory":
        echo json_encode($obj->getPointHistory($_GET["suki_card_id"] ?? 0));
        break;
    case "deleteSukiCard":
        echo json_encode($obj->deleteSukiCard($json));
        break;
    case "restoreSukiCard":
        echo json_encode($obj->restoreSukiCard($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}