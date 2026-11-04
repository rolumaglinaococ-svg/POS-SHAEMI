<?php
require_once "session-guard.php";
requireRole(["Administrator", "Manager"]);
require_once "activity-logger.php";
require_once "audit-logger.php";
require_once "ar-common.php";
startActivityLogging("AR Accounts", "tblar_account", "ar_account_id", "CONCAT('AR #', ar_account_id, ' - ', (SELECT CONCAT(c.first_name, ' ', c.last_name) FROM tblcustomer c WHERE c.customer_id = tblar_account.customer_id))");

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class ArAccount {

    public function getAllArAccounts($status) {
        include "connection-pdo.php";

        $holdCols = arHoldColumnsExist($conn) ? ", ar.credit_hold_source, ar.credit_hold_at, ar.credit_hold_invoice_id, ar.credit_hold_released_at, ar.credit_hold_released_by" : "";
        $sql = "SELECT ar.ar_account_id, ar.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       ar.approved_by, CONCAT(e.first_name, ' ', e.last_name) AS approved_by_name,
                       ar.credit_term_id, ct.term_name, ar.credit_limit, ar.current_balance,
                       ar.account_status, ar.date_opened, ar.credit_status" . $holdCols . "

                FROM tblar_account ar
                INNER JOIN tblcustomer c ON ar.customer_id = c.customer_id
                INNER JOIN tblemployee e ON ar.approved_by = e.employee_id
                INNER JOIN tblcredit_term ct ON ar.credit_term_id = ct.credit_term_id";

        if ($status === "active") {
            $sql .= " WHERE ar.account_status = 'Active'";
        } elseif ($status === "inactive") {
            $sql .= " WHERE ar.account_status = 'Inactive'";
        } elseif ($status === "pending") {
            $sql .= " WHERE ar.account_status = 'Pending'";
        } elseif ($status === "rejected") {
            $sql .= " WHERE ar.account_status = 'Rejected'";
        }

        $sql .= " ORDER BY ar.date_opened DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchArAccounts($keyword, $status) {
        include "connection-pdo.php";

        $holdCols = arHoldColumnsExist($conn) ? ", ar.credit_hold_source, ar.credit_hold_at, ar.credit_hold_invoice_id, ar.credit_hold_released_at, ar.credit_hold_released_by" : "";
        $sql = "SELECT ar.ar_account_id, ar.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       ar.approved_by, CONCAT(e.first_name, ' ', e.last_name) AS approved_by_name,
                       ar.credit_term_id, ct.term_name, ar.credit_limit, ar.current_balance,
                       ar.account_status, ar.date_opened, ar.credit_status" . $holdCols . "

                FROM tblar_account ar
                INNER JOIN tblcustomer c ON ar.customer_id = c.customer_id
                INNER JOIN tblemployee e ON ar.approved_by = e.employee_id
                INNER JOIN tblcredit_term ct ON ar.credit_term_id = ct.credit_term_id
                WHERE (c.first_name LIKE :keyword OR c.last_name LIKE :keyword)";

        if ($status === "active") {
            $sql .= " AND ar.account_status = 'Active'";
        } elseif ($status === "inactive") {
            $sql .= " AND ar.account_status = 'Inactive'";
        } elseif ($status === "pending") {
            $sql .= " AND ar.account_status = 'Pending'";
        } elseif ($status === "rejected") {
            $sql .= " AND ar.account_status = 'Rejected'";
        }

        $sql .= " ORDER BY ar.date_opened DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(":keyword", "%" . $keyword . "%", PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertArAccount($json) {
        include "connection-pdo.php";

        if ($_SESSION["role"] !== "Administrator") {
            return ["success" => false, "message" => "Only an Administrator can open an AR account."];
        }

        $data = json_decode($json, true);

        $customerId = $data["customer_id"] ?? null;
        $approvedBy = $data["approved_by"] ?? null;
        $creditTermId = $data["credit_term_id"] ?? null;
        $creditLimit = $data["credit_limit"] ?? null;
        $dateOpened = trim($data["date_opened"] ?? "");

        if (!$customerId || !$approvedBy || !$creditTermId || $creditLimit === null ||
            !is_numeric($creditLimit) || $creditLimit < 0 || $dateOpened === "") {
            return ["success" => false, "message" => "Customer, approving employee, credit term, a valid credit limit, and date opened are required."];
        }
        $date = DateTime::createFromFormat('Y-m-d', $dateOpened);
        if (!$date || $date->format('Y-m-d') !== $dateOpened || $dateOpened > date('Y-m-d')) {
            return ["success" => false, "message" => "Date opened must be a valid date that is not in the future."];
        }

        $approverCheck = $conn->prepare("SELECT e.employee_id FROM tblemployee e
            INNER JOIN tblrole r ON e.role_id = r.role_id
            WHERE e.employee_id = :id AND e.is_active = 1 AND r.is_active = 1
              AND r.role_name IN ('Administrator', 'Manager')");
        $approverCheck->bindValue(":id", $approvedBy, PDO::PARAM_INT);
        $approverCheck->execute();
        if (!$approverCheck->fetch(PDO::FETCH_ASSOC)) {
            return ["success" => false, "message" => "Select an active Manager or Administrator as the approver."];
        }

        $customerCheck = $conn->prepare("SELECT is_active FROM tblcustomer WHERE customer_id = :id");
        $customerCheck->bindParam(":id", $customerId, PDO::PARAM_INT);
        $customerCheck->execute();
        $customer = $customerCheck->fetch(PDO::FETCH_ASSOC);

        if (!$customer || (int)$customer["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected customer is not active."];
        }

        $termCheck = $conn->prepare("SELECT is_active FROM tblcredit_term WHERE credit_term_id = :id");
        $termCheck->bindParam(":id", $creditTermId, PDO::PARAM_INT);
        $termCheck->execute();
        $term = $termCheck->fetch(PDO::FETCH_ASSOC);

        if (!$term || (int)$term["is_active"] !== 1) {
            return ["success" => false, "message" => "Selected credit term is not active."];
        }

        $dupCheck = $conn->prepare("SELECT COUNT(*) AS total FROM tblar_account WHERE customer_id = :id");
        $dupCheck->bindParam(":id", $customerId, PDO::PARAM_INT);
        $dupCheck->execute();
        $dup = $dupCheck->fetch(PDO::FETCH_ASSOC);

        if ($dup["total"] > 0) {
            return ["success" => false, "message" => "This customer already has an AR account. A customer can only have one AR account."];
        }

        // New accounts start as Pending until a Manager approves them.
        $sql = "INSERT INTO tblar_account
                (customer_id, approved_by, credit_term_id, credit_limit, current_balance, account_status, date_opened, credit_status)
                VALUES (:customer_id, :approved_by, :credit_term_id, :credit_limit, 0, 'Pending', :date_opened, 'Good')";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customer_id", $customerId, PDO::PARAM_INT);
        $stmt->bindParam(":approved_by", $approvedBy, PDO::PARAM_INT);
        $stmt->bindParam(":credit_term_id", $creditTermId, PDO::PARAM_INT);
        $stmt->bindParam(":credit_limit", $creditLimit);
        $stmt->bindParam(":date_opened", $dateOpened, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "AR account submitted for approval.", "ar_account_id" => $conn->lastInsertId()];
        }

        return ["success" => false, "message" => "Failed to open AR account."];
    }

    public function updateArAccount($json) {
        include "connection-pdo.php";

        if ($_SESSION["role"] !== "Administrator") {
            return ["success" => false, "message" => "Only an Administrator can edit an AR account."];
        }

        $data = json_decode($json, true);

        $arAccountId = $data["ar_account_id"] ?? null;
        $creditTermId = $data["credit_term_id"] ?? null;
        $creditLimit = $data["credit_limit"] ?? null;
        $creditStatus = trim($data["credit_status"] ?? "Good");

        if (!$arAccountId || !$creditTermId || $creditLimit === null || !is_numeric($creditLimit) || $creditLimit < 0) {
            return ["success" => false, "message" => "AR account ID, credit term, and a valid credit limit are required."];
        }

        $sql = "UPDATE tblar_account
                SET credit_term_id = :credit_term_id, credit_limit = :credit_limit, credit_status = :credit_status
                WHERE ar_account_id = :ar_account_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":credit_term_id", $creditTermId, PDO::PARAM_INT);
        $stmt->bindParam(":credit_limit", $creditLimit);
        $stmt->bindParam(":credit_status", $creditStatus, PDO::PARAM_STR);
        $stmt->bindParam(":ar_account_id", $arAccountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "AR account updated successfully."];
        }

        return ["success" => false, "message" => "Failed to update AR account."];
    }

    public function deleteArAccount($json) {
        include "connection-pdo.php";

        if ($_SESSION["role"] !== "Administrator") {
            return ["success" => false, "message" => "Only an Administrator can deactivate an AR account."];
        }

        $data = json_decode($json, true);
        $arAccountId = $data["ar_account_id"] ?? null;

        if (!$arAccountId) {
            return ["success" => false, "message" => "AR account ID is required."];
        }

        $balanceCheck = $conn->prepare("SELECT current_balance, account_status FROM tblar_account WHERE ar_account_id = :id");
        $balanceCheck->bindParam(":id", $arAccountId, PDO::PARAM_INT);
        $balanceCheck->execute();
        $account = $balanceCheck->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            return ["success" => false, "message" => "AR account not found."];
        }

        if ($account["account_status"] !== "Active") {
            return ["success" => false, "message" => "Only an active AR account can be deactivated."];
        }

        if ((float)$account["current_balance"] > 0) {
            return ["success" => false, "message" => "Cannot deactivate: account still has an outstanding balance."];
        }

        $sql = "UPDATE tblar_account SET account_status = 'Inactive' WHERE ar_account_id = :ar_account_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":ar_account_id", $arAccountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => "AR account deactivated successfully."];
        }

        return ["success" => false, "message" => "Failed to deactivate AR account."];
    }

    public function restoreArAccount($json) {
        include "connection-pdo.php";

        if ($_SESSION["role"] !== "Administrator") {
            return ["success" => false, "message" => "Only an Administrator can restore an AR account."];
        }

        $data = json_decode($json, true);
        $arAccountId = $data["ar_account_id"] ?? null;

        if (!$arAccountId) {
            return ["success" => false, "message" => "AR account ID is required."];
        }

        $statusCheck = $conn->prepare("SELECT account_status FROM tblar_account WHERE ar_account_id = :id");
        $statusCheck->bindParam(":id", $arAccountId, PDO::PARAM_INT);
        $statusCheck->execute();
        $account = $statusCheck->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            return ["success" => false, "message" => "AR account not found."];
        }

        // A Rejected account must go back to the Manager - it can never skip approval.
        if ($account["account_status"] === "Inactive") {
            $newStatus = "Active";
            $message = "AR account restored successfully.";
        } elseif ($account["account_status"] === "Rejected") {
            $newStatus = "Pending";
            $message = "AR account resubmitted for Manager approval.";
        } else {
            return ["success" => false, "message" => "Only an inactive or rejected AR account can be restored."];
        }

        $sql = "UPDATE tblar_account SET account_status = :new_status WHERE ar_account_id = :ar_account_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":new_status", $newStatus, PDO::PARAM_STR);
        $stmt->bindParam(":ar_account_id", $arAccountId, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ["success" => true, "message" => $message];
        }

        return ["success" => false, "message" => "Failed to restore AR account."];
    }

    // Manager approves a Pending AR account, activating it.
    // approved_by is overwritten with the Manager who actually approved it.
    public function approveArAccount($json) {
        include "connection-pdo.php";

        if (!in_array($_SESSION["role"], ["Administrator", "Manager"], true)) {
            return ["success" => false, "message" => "Only an active Manager or Administrator can approve AR accounts."];
        }

        $data = json_decode($json, true);
        $arAccountId = $data["ar_account_id"] ?? null;

        if (!$arAccountId) {
            return ["success" => false, "message" => "AR account ID is required."];
        }

        try {
            $conn->beginTransaction();
            $beforeStmt = $conn->prepare("SELECT account_status, approved_by FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $beforeStmt->bindParam(":id", $arAccountId, PDO::PARAM_INT);
            $beforeStmt->execute();
            $beforeAcc = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $sql = "UPDATE tblar_account SET account_status = 'Active', approved_by = :approved_by
                    WHERE ar_account_id = :ar_account_id AND account_status = 'Pending'";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":approved_by", $_SESSION["employee_id"], PDO::PARAM_INT);
            $stmt->bindParam(":ar_account_id", $arAccountId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                return ["success" => false, "message" => "This AR account is no longer pending."];
            }

            auditEvent($conn, [
                "module" => "AR Accounts",
                "action" => "Approved",
                "record_id" => (int)$arAccountId,
                "record_label" => "AR #" . (int)$arAccountId,
                "details" => "AR account approved.",
                "before" => $beforeAcc,
                "after" => ["account_status" => "Active", "approved_by" => (int)$_SESSION["employee_id"]]
            ]);
            $conn->commit();
            return ["success" => true, "message" => "AR account approved."];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to approve AR account."];
        }
    }

    // Manager rejects a Pending AR account.
    public function rejectArAccount($json) {
        include "connection-pdo.php";

        if (!in_array($_SESSION["role"], ["Administrator", "Manager"], true)) {
            return ["success" => false, "message" => "Only an active Manager or Administrator can reject AR accounts."];
        }

        $data = json_decode($json, true);
        $arAccountId = $data["ar_account_id"] ?? null;

        if (!$arAccountId) {
            return ["success" => false, "message" => "AR account ID is required."];
        }

        try {
            $conn->beginTransaction();
            $beforeStmt = $conn->prepare("SELECT account_status, approved_by FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $beforeStmt->bindParam(":id", $arAccountId, PDO::PARAM_INT);
            $beforeStmt->execute();
            $beforeAcc = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $sql = "UPDATE tblar_account SET account_status = 'Rejected', approved_by = :approved_by
                    WHERE ar_account_id = :ar_account_id AND account_status = 'Pending'";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":approved_by", $_SESSION["employee_id"], PDO::PARAM_INT);
            $stmt->bindParam(":ar_account_id", $arAccountId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                return ["success" => false, "message" => "This AR account is no longer pending."];
            }

            auditEvent($conn, [
                "module" => "AR Accounts",
                "action" => "Rejected",
                "record_id" => (int)$arAccountId,
                "record_label" => "AR #" . (int)$arAccountId,
                "details" => "AR account rejected.",
                "before" => $beforeAcc,
                "after" => ["account_status" => "Rejected", "approved_by" => (int)$_SESSION["employee_id"]]
            ]);
            $conn->commit();
            return ["success" => true, "message" => "AR account rejected."];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to reject AR account."];
        }
    }

    /* ---- Add AR Account (AR Manager) -------------------------------------
     * Manager-created accounts are Active immediately (business decision,
     * 2026-10-20): the creating manager/admin is recorded as approved_by.
     * The Administrator-only insertArAccount flow (Pending -> review) is
     * unchanged. Duplicate AR accounts are prevented by an explicit check
     * AND the uq_ar_customer unique key. */

    public function getArFormOptions() {
        include "connection-pdo.php";
        $terms = $conn->query("SELECT credit_term_id, term_name, due_days FROM tblcredit_term WHERE is_active = 1 ORDER BY due_days ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tiers = $conn->query("SELECT price_tier_id, tier_name FROM tblprice_tier WHERE is_active = 1 ORDER BY price_tier_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        return ["success" => true, "credit_terms" => $terms, "price_tiers" => $tiers];
    }

    public function getEligibleCustomers() {
        include "connection-pdo.php";
        $sql = "SELECT c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       c.contact_number, c.email, c.customer_type,
                       CONCAT_WS(', ', NULLIF(c.street, ''), NULLIF(c.barangay, ''), NULLIF(c.city, ''), NULLIF(c.province, '')) AS address,
                       pt.tier_name AS price_tier
                FROM tblcustomer c
                LEFT JOIN tblprice_tier pt ON pt.price_tier_id = c.price_tier_id
                WHERE c.is_active = 1
                  AND NOT EXISTS (SELECT 1 FROM tblar_account a WHERE a.customer_id = c.customer_id)
                ORDER BY c.last_name ASC, c.first_name ASC";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function validateAccountInputs($conn, $customerId, $creditTermId, $creditLimit) {
        if ($customerId <= 0) throw new ArException("Select a customer.");
        if ($creditTermId <= 0) throw new ArException("Select a credit term.");
        if ($creditLimit === null || !is_numeric($creditLimit) || $creditLimit < 0 || $creditLimit > 9999999999.99) {
            throw new ArException("Enter a valid credit limit (zero or more).");
        }
        $cust = $conn->prepare("SELECT is_active FROM tblcustomer WHERE customer_id = :id");
        $cust->bindValue(":id", $customerId, PDO::PARAM_INT);
        $cust->execute();
        $c = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$c || (int)$c["is_active"] !== 1) throw new ArException("Selected customer is not active.");
        $term = $conn->prepare("SELECT is_active FROM tblcredit_term WHERE credit_term_id = :id");
        $term->bindValue(":id", $creditTermId, PDO::PARAM_INT);
        $term->execute();
        $t = $term->fetch(PDO::FETCH_ASSOC);
        if (!$t || (int)$t["is_active"] !== 1) throw new ArException("Selected credit term is not active.");
        $dup = $conn->prepare("SELECT COUNT(*) FROM tblar_account WHERE customer_id = :id");
        $dup->bindValue(":id", $customerId, PDO::PARAM_INT);
        $dup->execute();
        if ((int)$dup->fetchColumn() > 0) throw new ArException("This customer already has an AR account. A customer can only have one AR account.");
    }

    private function insertActiveAccount($conn, $customerId, $creditTermId, $creditLimit, $approvedBy) {
        $stmt = $conn->prepare("INSERT INTO tblar_account
                (customer_id, approved_by, credit_term_id, credit_limit, current_balance, account_status, date_opened, credit_status)
                VALUES (:customer_id, :approved_by, :credit_term_id, :credit_limit, 0, 'Active', CURDATE(), 'Good')");
        $stmt->bindValue(":customer_id", $customerId, PDO::PARAM_INT);
        $stmt->bindValue(":approved_by", $approvedBy, PDO::PARAM_INT);
        $stmt->bindValue(":credit_term_id", $creditTermId, PDO::PARAM_INT);
        $stmt->bindValue(":credit_limit", $creditLimit);
        $stmt->execute();
        return (int)$conn->lastInsertId();
    }

    private function insertPendingAccount($conn, $customerId, $approvedBy, $creditTermId, $creditLimit, $dateOpened) {
        $stmt = $conn->prepare("INSERT INTO tblar_account
                (customer_id, approved_by, credit_term_id, credit_limit, current_balance, account_status, date_opened, credit_status)
                VALUES (:customer_id, :approved_by, :credit_term_id, :credit_limit, 0, 'Pending', :date_opened, 'Good')");
        $stmt->bindValue(":customer_id", $customerId, PDO::PARAM_INT);
        $stmt->bindValue(":approved_by", $approvedBy, PDO::PARAM_INT);
        $stmt->bindValue(":credit_term_id", $creditTermId, PDO::PARAM_INT);
        $stmt->bindValue(":credit_limit", $creditLimit);
        $stmt->bindValue(":date_opened", $dateOpened, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$conn->lastInsertId();
    }

    /* Option A: open an AR account for an existing customer. */
    public function createArAccount($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $customerId = (int)($data["customer_id"] ?? 0);
            $creditTermId = (int)($data["credit_term_id"] ?? 0);
            $creditLimit = $data["credit_limit"] ?? null;
            $this->validateAccountInputs($conn, $customerId, $creditTermId, $creditLimit);
            $id = $this->insertActiveAccount($conn, $customerId, $creditTermId, $creditLimit, (int)$_SESSION["employee_id"]);
            return ["success" => true, "message" => "AR account created and Active. The customer can now charge purchases to it.", "ar_account_id" => $id];
        } catch (ArException $e) {
            return ["success" => false, "message" => $e->getMessage()];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return ["success" => false, "message" => "This customer already has an AR account. A customer can only have one AR account."];
            return ["success" => false, "message" => "Failed to open AR account. Nothing was created."];
        } catch (Throwable $e) {
            return ["success" => false, "message" => "Failed to open AR account. Nothing was created."];
        }
    }

    /* Option B: register a new customer and open the AR account in ONE transaction.
     * Customer validation mirrors api/customers.php insertCustomer exactly;
     * a duplicate guard (same full name + same contact number) is added here.
     * Any failure rolls back BOTH inserts. */
    public function createCustomerWithArAccount($json) {
        include "connection-pdo.php";
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $priceTierId = (int)($data["price_tier_id"] ?? 0);
            $firstName = trim((string)($data["first_name"] ?? ""));
            $lastName = trim((string)($data["last_name"] ?? ""));
            $contactNumber = trim((string)($data["contact_number"] ?? ""));
            $street = trim((string)($data["street"] ?? ""));
            $barangay = trim((string)($data["barangay"] ?? ""));
            $city = trim((string)($data["city"] ?? ""));
            $province = trim((string)($data["province"] ?? ""));
            $email = trim((string)($data["email"] ?? ""));
            $customerType = trim((string)($data["customer_type"] ?? "Regular"));
            if ($customerType === "") $customerType = "Regular";
            $creditTermId = (int)($data["credit_term_id"] ?? 0);
            $creditLimit = $data["credit_limit"] ?? null;

            if ($priceTierId <= 0 || $firstName === "" || $lastName === "" || $contactNumber === "") {
                throw new ArException("Price tier, first name, last name, and contact number are required.");
            }
            if (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
                throw new ArException("Contact number must be exactly 11 digits.");
            }
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ArException("Please supply a valid email address.");
            }
            if ($creditLimit === null || !is_numeric($creditLimit) || $creditLimit < 0 || $creditLimit > 9999999999.99) {
                throw new ArException("Enter a valid credit limit (zero or more).");
            }
            if ($creditTermId <= 0) throw new ArException("Select a credit term.");

            $conn->beginTransaction();

            $tier = $conn->prepare("SELECT is_active FROM tblprice_tier WHERE price_tier_id = :id");
            $tier->bindValue(":id", $priceTierId, PDO::PARAM_INT);
            $tier->execute();
            $t = $tier->fetch(PDO::FETCH_ASSOC);
            if (!$t || (int)$t["is_active"] !== 1) throw new ArException("Selected price tier is not active.");

            $term = $conn->prepare("SELECT is_active FROM tblcredit_term WHERE credit_term_id = :id");
            $term->bindValue(":id", $creditTermId, PDO::PARAM_INT);
            $term->execute();
            $tm = $term->fetch(PDO::FETCH_ASSOC);
            if (!$tm || (int)$tm["is_active"] !== 1) throw new ArException("Selected credit term is not active.");

            $dupCust = $conn->prepare("SELECT customer_id FROM tblcustomer
                                       WHERE LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln) AND contact_number = :cn LIMIT 1");
            $dupCust->bindValue(":fn", $firstName, PDO::PARAM_STR);
            $dupCust->bindValue(":ln", $lastName, PDO::PARAM_STR);
            $dupCust->bindValue(":cn", $contactNumber, PDO::PARAM_STR);
            $dupCust->execute();
            if ($dupCust->fetch(PDO::FETCH_ASSOC)) {
                throw new ArException("A customer with this name and contact number already exists. Use the Existing Customer option instead — no record was created.");
            }

            $ins = $conn->prepare("INSERT INTO tblcustomer
                    (price_tier_id, first_name, last_name, contact_number, street, barangay, city, province, email, customer_type, is_active)
                    VALUES (:pt, :fn, :ln, :cn, :st, :br, :ct, :pv, :em, :ctp, 1)");
            $ins->bindValue(":pt", $priceTierId, PDO::PARAM_INT);
            $ins->bindValue(":fn", $firstName, PDO::PARAM_STR);
            $ins->bindValue(":ln", $lastName, PDO::PARAM_STR);
            $ins->bindValue(":cn", $contactNumber, PDO::PARAM_STR);
            $ins->bindValue(":st", $street, PDO::PARAM_STR);
            $ins->bindValue(":br", $barangay, PDO::PARAM_STR);
            $ins->bindValue(":ct", $city, PDO::PARAM_STR);
            $ins->bindValue(":pv", $province, PDO::PARAM_STR);
            $ins->bindValue(":em", $email, PDO::PARAM_STR);
            $ins->bindValue(":ctp", $customerType, PDO::PARAM_STR);
            $ins->execute();
            $customerId = (int)$conn->lastInsertId();

            $arId = $this->insertActiveAccount($conn, $customerId, $creditTermId, $creditLimit, (int)$_SESSION["employee_id"]);

            $conn->commit();
            return ["success" => true, "message" => "Customer registered and AR account created (Active).", "customer_id" => $customerId, "ar_account_id" => $arId];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return ["success" => false, "message" => "This customer already has an AR account. Nothing was created."];
            return ["success" => false, "message" => "Failed to create the customer and AR account. Nothing was created."];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to create the customer and AR account. Nothing was created."];
        }
    }

    /* Admin "New Customer" option: register a new customer and open the AR
     * account in ONE transaction, with ADMIN semantics — the account starts
     * Pending for Manager approval (same as insertArAccount), assigned to
     * the chosen approver, with the given date opened. Customer validation
     * mirrors the Manager createCustomerWithArAccount (which stays Active-
     * immediately and unchanged). Any failure rolls back BOTH inserts. */
    public function createCustomerWithArAccountAdmin($json) {
        include "connection-pdo.php";

        if ($_SESSION["role"] !== "Administrator") {
            return ["success" => false, "message" => "Only an Administrator can open an AR account."];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $priceTierId = (int)($data["price_tier_id"] ?? 0);
            $firstName = trim((string)($data["first_name"] ?? ""));
            $lastName = trim((string)($data["last_name"] ?? ""));
            $contactNumber = trim((string)($data["contact_number"] ?? ""));
            $street = trim((string)($data["street"] ?? ""));
            $barangay = trim((string)($data["barangay"] ?? ""));
            $city = trim((string)($data["city"] ?? ""));
            $province = trim((string)($data["province"] ?? ""));
            $email = trim((string)($data["email"] ?? ""));
            $customerType = trim((string)($data["customer_type"] ?? "Regular"));
            if ($customerType === "") $customerType = "Regular";
            $approvedBy = (int)($data["approved_by"] ?? 0);
            $creditTermId = (int)($data["credit_term_id"] ?? 0);
            $creditLimit = $data["credit_limit"] ?? null;
            $dateOpened = trim((string)($data["date_opened"] ?? ""));

            if ($priceTierId <= 0 || $firstName === "" || $lastName === "" || $contactNumber === "") {
                throw new ArException("Price tier, first name, last name, and contact number are required.");
            }
            if (!preg_match('/^[0-9]{11}$/', $contactNumber)) {
                throw new ArException("Contact number must be exactly 11 digits.");
            }
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ArException("Please supply a valid email address.");
            }
            if ($approvedBy <= 0) throw new ArException("Select the approving Manager or Administrator.");
            if ($creditTermId <= 0) throw new ArException("Select a credit term.");
            if ($creditLimit === null || !is_numeric($creditLimit) || $creditLimit < 0 || $creditLimit > 9999999999.99) {
                throw new ArException("Enter a valid credit limit (zero or more).");
            }
            $date = DateTime::createFromFormat('Y-m-d', $dateOpened);
            if (!$date || $date->format('Y-m-d') !== $dateOpened || $dateOpened > date('Y-m-d')) {
                throw new ArException("Date opened must be a valid date that is not in the future.");
            }

            $conn->beginTransaction();

            $approverCheck = $conn->prepare("SELECT e.employee_id FROM tblemployee e
                INNER JOIN tblrole r ON e.role_id = r.role_id
                WHERE e.employee_id = :id AND e.is_active = 1 AND r.is_active = 1
                  AND r.role_name IN ('Administrator', 'Manager')");
            $approverCheck->bindValue(":id", $approvedBy, PDO::PARAM_INT);
            $approverCheck->execute();
            if (!$approverCheck->fetch(PDO::FETCH_ASSOC)) {
                throw new ArException("Select an active Manager or Administrator as the approver.");
            }

            $tier = $conn->prepare("SELECT is_active FROM tblprice_tier WHERE price_tier_id = :id");
            $tier->bindValue(":id", $priceTierId, PDO::PARAM_INT);
            $tier->execute();
            $t = $tier->fetch(PDO::FETCH_ASSOC);
            if (!$t || (int)$t["is_active"] !== 1) throw new ArException("Selected price tier is not active.");

            $term = $conn->prepare("SELECT is_active FROM tblcredit_term WHERE credit_term_id = :id");
            $term->bindValue(":id", $creditTermId, PDO::PARAM_INT);
            $term->execute();
            $tm = $term->fetch(PDO::FETCH_ASSOC);
            if (!$tm || (int)$tm["is_active"] !== 1) throw new ArException("Selected credit term is not active.");

            $dupCust = $conn->prepare("SELECT customer_id FROM tblcustomer
                                       WHERE LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln) AND contact_number = :cn LIMIT 1");
            $dupCust->bindValue(":fn", $firstName, PDO::PARAM_STR);
            $dupCust->bindValue(":ln", $lastName, PDO::PARAM_STR);
            $dupCust->bindValue(":cn", $contactNumber, PDO::PARAM_STR);
            $dupCust->execute();
            if ($dupCust->fetch(PDO::FETCH_ASSOC)) {
                throw new ArException("A customer with this name and contact number already exists. Use the Existing Customer option instead — no record was created.");
            }

            $ins = $conn->prepare("INSERT INTO tblcustomer
                    (price_tier_id, first_name, last_name, contact_number, street, barangay, city, province, email, customer_type, is_active)
                    VALUES (:pt, :fn, :ln, :cn, :st, :br, :ct, :pv, :em, :ctp, 1)");
            $ins->bindValue(":pt", $priceTierId, PDO::PARAM_INT);
            $ins->bindValue(":fn", $firstName, PDO::PARAM_STR);
            $ins->bindValue(":ln", $lastName, PDO::PARAM_STR);
            $ins->bindValue(":cn", $contactNumber, PDO::PARAM_STR);
            $ins->bindValue(":st", $street, PDO::PARAM_STR);
            $ins->bindValue(":br", $barangay, PDO::PARAM_STR);
            $ins->bindValue(":ct", $city, PDO::PARAM_STR);
            $ins->bindValue(":pv", $province, PDO::PARAM_STR);
            $ins->bindValue(":em", $email, PDO::PARAM_STR);
            $ins->bindValue(":ctp", $customerType, PDO::PARAM_STR);
            $ins->execute();
            $customerId = (int)$conn->lastInsertId();

            $arId = $this->insertPendingAccount($conn, $customerId, $approvedBy, $creditTermId, $creditLimit, $dateOpened);

            $conn->commit();
            return ["success" => true, "message" => "Customer registered and AR account submitted for approval.", "customer_id" => $customerId, "ar_account_id" => $arId];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to create the customer and AR account. Nothing was created."];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to create the customer and AR account. Nothing was created."];
        }
    }

    // Credit limit / credit status change available to the Manager, but only with Administrator approval.
    // An Administrator already signed in approves their own change. Reason is mandatory; before/after is audited
    // in the same transaction as the change.
    public function updateCreditSettings($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            $arAccountId = (int)($data["ar_account_id"] ?? 0);
            $creditLimit = $data["credit_limit"] ?? null;
            $creditStatus = trim((string)($data["credit_status"] ?? ""));

            if ($arAccountId <= 0 || $creditLimit === null || !is_numeric($creditLimit) || $creditLimit < 0 || $creditLimit > 9999999999.99) {
                throw new ArException("A valid credit limit is required.");
            }
            if (!in_array($creditStatus, ["Good", "Watch", "Hold"], true)) {
                throw new ArException("Credit status must be Good, Watch or Hold.");
            }
            $reason = arReason($data);
            $approver = arResolveApprover($conn, $data, ["Administrator"]);
            $employeeId = (int)$_SESSION["employee_id"];

            $hasHoldCols = arHoldColumnsExist($conn);
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT credit_limit, credit_status, account_status" . ($hasHoldCols ? ", credit_hold_source" : "") . " FROM tblar_account WHERE ar_account_id = :id FOR UPDATE");
            $stmt->bindValue(":id", $arAccountId, PDO::PARAM_INT);
            $stmt->execute();
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new ArException("AR account not found.");
            if ($before["account_status"] !== "Active") throw new ArException("Credit settings can only be changed on an Active account.");

            // Late fee policy (2026-10-22, D-D): an AUTOMATIC hold can only be
            // released by the AR Manager through releaseCreditHold (reason
            // required) — not by editing the status here.
            if ($hasHoldCols && ($before["credit_hold_source"] ?? null) === "AutoOverdue" && $creditStatus !== "Hold") {
                throw new ArException("This account is on AUTOMATIC credit hold (an invoice reached " . AR_AUTO_HOLD_DAYS . " days overdue). It can only be released by the AR Manager using Release Hold, with a reason.");
            }

            $newLimit = round((float)$creditLimit, 2);
            if (arCents($before["credit_limit"]) === arCents($newLimit) && $before["credit_status"] === $creditStatus) {
                throw new ArException("Nothing to change.");
            }

            // A manual status change makes the hold (if any) a manual one:
            // clear the automatic provenance so it never misdescribes it.
            $clearProv = ($hasHoldCols && $before["credit_status"] !== $creditStatus)
                ? ", credit_hold_source = NULL, credit_hold_at = NULL, credit_hold_invoice_id = NULL" : "";
            $upd = $conn->prepare("UPDATE tblar_account SET credit_limit = :l, credit_status = :s" . $clearProv . " WHERE ar_account_id = :id");
            $upd->bindValue(":l", $newLimit);
            $upd->bindValue(":s", $creditStatus, PDO::PARAM_STR);
            $upd->bindValue(":id", $arAccountId, PDO::PARAM_INT);
            $upd->execute();

            arAudit($conn, "credit_settings", $arAccountId, "Updated", $arAccountId,
                ["credit_limit" => (float)$before["credit_limit"], "credit_status" => $before["credit_status"]],
                ["credit_limit" => $newLimit, "credit_status" => $creditStatus],
                $reason, $employeeId, $approver["employee_id"]);
            $conn->commit();

            return ["success" => true, "message" => "Credit settings updated (limit ₱" . number_format($newLimit, 2) . ", status " . $creditStatus . "). Approved by " . $approver["name"] . ". Reason: " . $reason];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to update credit settings. Nothing was changed."];
        }
    }

    /*
     * Releases an AUTOMATIC credit hold (late fee policy, 2026-10-22, D-D).
     * Only an AR Manager session may do this; a reason is required and the
     * release is audit-logged together with the invoices that were already
     * 15+ days overdue — the automatic rule will not re-hold the account
     * for those same invoices (D-E); a NEW invoice reaching 15 days
     * overdue re-engages it. Manual holds are not touched here.
     */
    public function releaseCreditHold($json) {
        include "connection-pdo.php";

        $data = json_decode($json, true);
        if (!is_array($data)) return ["success" => false, "message" => "Invalid request."];

        try {
            if (($_SESSION["role"] ?? "") !== "Manager") {
                throw new ArException("Only the AR Manager can release an automatic credit hold.");
            }
            if (!arHoldColumnsExist($conn)) throw new ArException("The hold-provenance migration (20261022) has not been applied yet.");
            $arAccountId = (int)($data["ar_account_id"] ?? 0);
            if ($arAccountId <= 0) throw new ArException("Select an AR account.");
            $reason = arReason($data);
            $employeeId = (int)$_SESSION["employee_id"];

            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT ar.credit_status, ar.credit_hold_source, ar.credit_hold_at, ar.credit_hold_invoice_id,
                                           CONCAT(c.first_name, ' ', c.last_name) AS customer_name
                                    FROM tblar_account ar INNER JOIN tblcustomer c ON c.customer_id = ar.customer_id
                                    WHERE ar.ar_account_id = :id FOR UPDATE");
            $stmt->bindValue(":id", $arAccountId, PDO::PARAM_INT);
            $stmt->execute();
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new ArException("AR account not found.");
            if ($before["credit_status"] !== "Hold") throw new ArException("This account is not on credit hold.");
            if (($before["credit_hold_source"] ?? null) !== "AutoOverdue") {
                throw new ArException("This is a MANUAL credit hold. Release it through Credit Settings instead.");
            }

            // Invoices already 15+ days overdue at release time (D-E record).
            $od = $conn->prepare("SELECT oi.invoice_id FROM (" . arOpenInvoicesSql() . ") oi
                                  WHERE oi.ar_account_id = :ar AND oi.outstanding > 0 AND oi.days_overdue >= :d
                                  ORDER BY oi.invoice_id ASC");
            $od->bindValue(":ar", $arAccountId, PDO::PARAM_INT);
            $od->bindValue(":d", AR_AUTO_HOLD_DAYS, PDO::PARAM_INT);
            $od->execute();
            $overdueIds = array_map("intval", $od->fetchAll(PDO::FETCH_COLUMN));

            $upd = $conn->prepare("UPDATE tblar_account
                                   SET credit_status = 'Good', credit_hold_source = NULL, credit_hold_at = NULL,
                                       credit_hold_invoice_id = NULL, credit_hold_released_at = NOW(), credit_hold_released_by = :by
                                   WHERE ar_account_id = :id");
            $upd->bindValue(":by", $employeeId, PDO::PARAM_INT);
            $upd->bindValue(":id", $arAccountId, PDO::PARAM_INT);
            $upd->execute();

            arAudit($conn, "ar_account", $arAccountId, "HoldReleased", $arAccountId,
                ["credit_status" => "Hold", "credit_hold_source" => "AutoOverdue", "credit_hold_invoice_id" => $before["credit_hold_invoice_id"] !== null ? (int)$before["credit_hold_invoice_id"] : null, "credit_hold_at" => $before["credit_hold_at"]],
                ["credit_status" => "Good", "released_by" => $employeeId, "overdue_invoice_ids" => $overdueIds],
                $reason, $employeeId);
            $conn->commit();

            return ["success" => true, "message" => "Credit hold released for " . $before["customer_name"] . ". The account is now Good. The automatic hold will only return if a NEW invoice reaches " . AR_AUTO_HOLD_DAYS . " days overdue."];
        } catch (ArException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ["success" => false, "message" => "Failed to release the credit hold. Nothing was changed."];
        }
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";
$status = $_GET["status"] ?? "active";
$keyword = $_GET["keyword"] ?? "";

$obj = new ArAccount();

switch ($operation) {
    case "getAllArAccounts":
        echo json_encode($obj->getAllArAccounts($status));
        break;
    case "searchArAccounts":
        echo json_encode($obj->searchArAccounts($keyword, $status));
        break;
    case "insertArAccount":
        echo json_encode($obj->insertArAccount($json));
        break;
    case "updateArAccount":
        echo json_encode($obj->updateArAccount($json));
        break;
    case "deleteArAccount":
        echo json_encode($obj->deleteArAccount($json));
        break;
    case "releaseCreditHold":
        echo json_encode($obj->releaseCreditHold($json));
        break;
    case "updateCreditSettings":
        echo json_encode($obj->updateCreditSettings($json));
        break;
    case "restoreArAccount":
        echo json_encode($obj->restoreArAccount($json));
        break;
    case "approveArAccount":
        echo json_encode($obj->approveArAccount($json));
        break;
    case "rejectArAccount":
        echo json_encode($obj->rejectArAccount($json));
        break;
    case "getArFormOptions":
        echo json_encode($obj->getArFormOptions());
        break;
    case "getEligibleCustomers":
        echo json_encode($obj->getEligibleCustomers());
        break;
    case "createArAccount":
        echo json_encode($obj->createArAccount($json));
        break;
    case "createCustomerWithArAccount":
        echo json_encode($obj->createCustomerWithArAccount($json));
        break;
    case "createCustomerWithArAccountAdmin":
        echo json_encode($obj->createCustomerWithArAccountAdmin($json));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
