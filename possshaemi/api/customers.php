<?php
require_once "session-guard.php";
requireRole(["Administrator"]);
require_once "activity-logger.php";
startActivityLogging("Customers", "tblcustomer", "customer_id", "CONCAT(first_name, ' ', last_name)");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class Customer {

    public function getAllCustomers($status) {
        include "connection-pdo.php";

        $sql = "SELECT c.customer_id, c.price_tier_id, pt.tier_name, c.first_name, c.last_name,
                       c.contact_number, c.street, c.barangay, c.city, c.province, c.email,
                       c.customer_type, c.is_active
                FROM tblcustomer c
                INNER JOIN tblprice_tier pt ON c.price_tier_id = pt.price_tier_id";

        if ($status === "active") {
            $sql .= " WHERE c.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " WHERE c.is_active = 0";
        }

        $sql .= " ORDER BY c.last_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCustomers($keyword, $status) {
        include "connection-pdo.php";

        $sql = "SELECT c.customer_id, c.price_tier_id, pt.tier_name, c.first_name, c.last_name,
                       c.contact_number, c.street, c.barangay, c.city, c.province, c.email,
                       c.customer_type, c.is_active
                FROM tblcustomer c
                INNER JOIN tblprice_tier pt ON c.price_tier_id = pt.price_tier_id
                WHERE (c.first_name LIKE :keyword OR c.last_name LIKE :keyword OR c.email LIKE :keyword)";

        if ($status === "active") {
            $sql .= " AND c.is_active = 1";
        } elseif ($status === "inactive") {
            $sql .= " AND c.is_active = 0";
        }

        $sql .= " ORDER BY c.last_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveCustomersForDropdown() {
        include "connection-pdo.php";

        $sql = "SELECT customer_id, CONCAT(first_name, ' ', last_name) AS full_name
                FROM tblcustomer WHERE is_active = 1 ORDER BY last_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Active customers that do not have any AR account yet.
     * Used by the "Open AR Account" modal so each customer gets at most one account.
     */
    public function getCustomersWithoutArAccount() {
        include "connection-pdo.php";

        $sql = "SELECT c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS full_name
                FROM tblcustomer c
                WHERE c.is_active = 1
                  AND NOT EXISTS (SELECT 1 FROM tblar_account a WHERE a.customer_id = c.customer_id)
                ORDER BY c.last_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Active customers that do not have a Suki card yet.
     * Used by the "Add Suki Card" modal so each customer gets at most one card.
     */
    public function getCustomersWithoutSukiCard() {
        include "connection-pdo.php";

        $sql = "SELECT c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS full_name
                FROM tblcustomer c
                WHERE c.is_active = 1
                  AND NOT EXISTS (SELECT 1 FROM tblsuki_card sc WHERE sc.customer_id = c.customer_id)
                ORDER BY c.last_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertCustomer($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $priceTierId = $data["price_tier_id"] ?? null;
        $firstName = trim($data["first_name"] ?? "");
        $lastName = trim($data["last_name"] ?? "");
        $contactNumber = trim($data["contact_number"] ?? "");
        $street = trim($data["street"] ?? "");
        $barangay = trim($data["barangay"] ?? "");
        $city = trim($data["city"] ?? "");
        $province = trim($data["province"] ?? "");
        $email = trim($data["email"] ?? "");
        $customerType = trim($data["customer_type"] ?? "Regular");

      if (!$priceTierId || $firstName === "" || $lastName === "" || $contactNumber === "") {
    return ["success" => false, "message" => "Price tier, first name, last name, and contact number are required."];
}

if (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
    return ["success" => false, "message" => "Contact number must be exactly 11 digits."];
}

if (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
    return ["success" => false, "message" => "Contact number must be exactly 11 digits."];
}

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ["success" => false, "message" => "Please supply a valid email address."];
        }

        $tierCheck = $conn->prepare("SELECT is_active FROM tblprice_tier WHERE price_tier_id = :id");
        $tierCheck->bindParam(":id", $priceTierId, PDO::PARAM_INT);
        $tierCheck->execute();
        $tier = $tierCheck->fetch(PDO::FETCH_ASSOC);

        if (!$tier || (int)$tier["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected price tier is not active."];
        }

        $sql = "INSERT INTO tblcustomer
                (price_tier_id, first_name, last_name, contact_number, street, barangay, city, province, email, customer_type, is_active)
                VALUES (:price_tier_id, :first_name, :last_name, :contact_number, :street, :barangay, :city, :province, :email, :customer_type, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);
        $stmt->bindParam(":first_name", $firstName, PDO::PARAM_STR);
        $stmt->bindParam(":last_name", $lastName, PDO::PARAM_STR);
        $stmt->bindParam(":contact_number", $contactNumber, PDO::PARAM_STR);
        $stmt->bindParam(":street", $street, PDO::PARAM_STR);
        $stmt->bindParam(":barangay", $barangay, PDO::PARAM_STR);
        $stmt->bindParam(":city", $city, PDO::PARAM_STR);
        $stmt->bindParam(":province", $province, PDO::PARAM_STR);
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->bindParam(":customer_type", $customerType, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Customer added successfully.", "customer_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to add customer."];
    }

    public function updateCustomer($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        $customerId = $data["customer_id"] ?? null;
        $priceTierId = $data["price_tier_id"] ?? null;
        $firstName = trim($data["first_name"] ?? "");
        $lastName = trim($data["last_name"] ?? "");
        $contactNumber = trim($data["contact_number"] ?? "");
        $street = trim($data["street"] ?? "");
        $barangay = trim($data["barangay"] ?? "");
        $city = trim($data["city"] ?? "");
        $province = trim($data["province"] ?? "");
        $email = trim($data["email"] ?? "");
        $customerType = trim($data["customer_type"] ?? "Regular");

        if (!$customerId || !$priceTierId || $firstName === "" || $lastName === "" || $contactNumber === "") {
            return ["success" => false, "message" => "Customer ID, price tier, first name, last name, and contact number are required."];
        }

        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ["success" => false, "message" => "Please supply a valid email address."];
        }

        $sql = "UPDATE tblcustomer SET
                    price_tier_id = :price_tier_id, first_name = :first_name, last_name = :last_name,
                    contact_number = :contact_number, street = :street, barangay = :barangay,
                    city = :city, province = :province, email = :email, customer_type = :customer_type
                WHERE customer_id = :customer_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":price_tier_id", $priceTierId, PDO::PARAM_INT);
        $stmt->bindParam(":first_name", $firstName, PDO::PARAM_STR);
        $stmt->bindParam(":last_name", $lastName, PDO::PARAM_STR);
        $stmt->bindParam(":contact_number", $contactNumber, PDO::PARAM_STR);
        $stmt->bindParam(":street", $street, PDO::PARAM_STR);
        $stmt->bindParam(":barangay", $barangay, PDO::PARAM_STR);
        $stmt->bindParam(":city", $city, PDO::PARAM_STR);
        $stmt->bindParam(":province", $province, PDO::PARAM_STR);
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->bindParam(":customer_type", $customerType, PDO::PARAM_STR);
        $stmt->bindParam(":customer_id", $customerId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Customer updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update customer."];
    }

    public function deleteCustomer($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $customerId = $data["customer_id"] ?? null;

        if (!$customerId) {
            return ["success" => false, "message" => "Customer ID is required."];
        }

        $sql = "UPDATE tblcustomer SET is_active = 0 WHERE customer_id = :customer_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customer_id", $customerId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Customer deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate customer."];
    }

    public function restoreCustomer($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        $customerId = $data["customer_id"] ?? null;

        if (!$customerId) {
            return ["success" => false, "message" => "Customer ID is required."];
        }

        $sql = "UPDATE tblcustomer SET is_active = 1 WHERE customer_id = :customer_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customer_id", $customerId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "Customer restored successfully."];
        }

        return ["success" => false, "message" => "Failed to restore customer."];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new Customer();

switch ($operation) {
    case "getAllCustomers":
        echo json_encode($obj->getAllCustomers($status));
        break;
    case "searchCustomers":
        echo json_encode($obj->searchCustomers($keyword, $status));
        break;
    case "getActiveCustomersForDropdown":
        echo json_encode($obj->getActiveCustomersForDropdown());
        break;
    case "getCustomersWithoutArAccount":
        echo json_encode($obj->getCustomersWithoutArAccount());
        break;
    case "getCustomersWithoutSukiCard":
        echo json_encode($obj->getCustomersWithoutSukiCard());
        break;
    case "insertCustomer":
        echo json_encode($obj->insertCustomer($json));
        break;
    case "updateCustomer":
        echo json_encode($obj->updateCustomer($json));
        break;
    case "deleteCustomer":
        echo json_encode($obj->deleteCustomer($json));
        break;
    case "restoreCustomer":
        echo json_encode($obj->restoreCustomer($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}