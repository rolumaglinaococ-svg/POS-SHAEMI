<?php

require_once "session-guard.php";
require_once "audit-logger.php";

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle browser preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

class Auth
{
    private $allowedRoles = [
        "Administrator",
        "Manager",
        "Cashier"
    ];

    /* Records a failed login attempt (never the password). */
    private function logLoginFailure($username, $category, $employee = null) {
        auditAttempt([
            "module" => "Security",
            "action" => "LoginFailed",
            "outcome" => "Failed",
            "employee_id" => $employee ? (int)$employee["employee_id"] : null,
            "actor_name" => $employee ? ($employee["first_name"] . " " . $employee["last_name"]) : ($username !== "" ? $username : "Unknown"),
            "actor_role" => $employee ? $employee["role_name"] : "Unknown",
            "record_label" => $username !== "" ? $username : null,
            "details" => "Failed login attempt.",
            "reason" => $category
        ]);
    }

    /**
     * Login employee
     */
    public function login($json)
    {
        include "connection-pdo.php";

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [
                "success" => false,
                "message" => "Invalid login data."
            ];
        }

        $username = trim($data["username"] ?? "");
        $password = $data["password"] ?? "";

        // Validate required fields
        if ($username === "" || $password === "") {
            return [
                "success" => false,
                "message" => "Username and password are required."
            ];
        }

        // Find employee by username
        $sql = "
            SELECT
                e.employee_id,
                e.first_name,
                e.last_name,
                e.username,
                e.password,
                e.is_active,
                r.role_id,
                r.role_name,
                r.is_active AS role_is_active
            FROM tblemployee e
            INNER JOIN tblrole r
                ON e.role_id = r.role_id
            WHERE e.username = :username
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":username", $username, PDO::PARAM_STR);
        $stmt->execute();

        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        // Username does not exist
        if (!$employee) {
            $this->logLoginFailure($username, "Unknown username");
            return [
                "success" => false,
                "message" => "Invalid username or password."
            ];
        }

        // Verify password
        if (!password_verify($password, $employee["password"])) {
            $this->logLoginFailure($username, "Wrong password", $employee);
            return [
                "success" => false,
                "message" => "Invalid username or password."
            ];
        }

        // Check employee status
        if ((int)$employee["is_active"] !== 1) {
            $this->logLoginFailure($username, "Account inactive", $employee);
            return [
                "success" => false,
                "message" => "This account is inactive. Contact an administrator."
            ];
        }

        // Check role status
        if ((int)$employee["role_is_active"] !== 1) {
            $this->logLoginFailure($username, "Role inactive", $employee);
            return [
                "success" => false,
                "message" => "This employee role is inactive."
            ];
        }

        // Check if role is allowed in this application
        if (!in_array($employee["role_name"], $this->allowedRoles, true)) {
            $this->logLoginFailure($username, "Role has no application access", $employee);
            return [
                "success" => false,
                "message" => "This account has no application role assigned."
            ];
        }

        // Shared login: no role is selected at sign in. The role stored on
        // the employee account is authoritative and is returned to the
        // client, which redirects by it.

        // Regenerate session ID after successful authentication
        session_regenerate_id(true);

        $_SESSION["employee_id"] = (int)$employee["employee_id"];
        $_SESSION["username"] = $employee["username"];
        $_SESSION["role"] = $employee["role_name"];
        $_SESSION["display_name"] =
            $employee["first_name"] . " " . $employee["last_name"];

        auditStandalone([
            "module" => "Security",
            "action" => "Login",
            "outcome" => "Success",
            "employee_id" => (int)$employee["employee_id"],
            "actor_name" => $_SESSION["display_name"],
            "actor_role" => $employee["role_name"],
            "details" => "Login successful."
        ]);

        return [
            "success" => true,
            "message" => "Login successful.",
            "role" => $employee["role_name"],
            "employee_id" => (int)$employee["employee_id"],
            "display_name" => $_SESSION["display_name"]
        ];
    }

    /**
     * Logout employee
     */
    public function logout()
    {
        if (!empty($_SESSION["employee_id"])) {
            auditStandalone([
                "module" => "Security",
                "action" => "Logout",
                "outcome" => "Success",
                "employee_id" => (int)$_SESSION["employee_id"],
                "actor_name" => $_SESSION["display_name"] ?? "Unknown",
                "actor_role" => $_SESSION["role"] ?? "Unknown",
                "details" => "Logged out."
            ]);
        }

        $_SESSION = [];

        // Remove session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();

        return [
            "success" => true,
            "message" => "Logged out."
        ];
    }

    /**
     * Check current authenticated session
     */
    public function checkSession()
    {
        if (!validateSession()) {
            http_response_code(401);

            return [
                "success" => false,
                "message" => "Not authenticated."
            ];
        }

        return [
            "success" => true,
            "employee_id" => (int)$_SESSION["employee_id"],
            "role" => $_SESSION["role"],
            "username" => $_SESSION["username"],
            "display_name" => $_SESSION["display_name"]
        ];
    }
}


// --------------------------------------------------
// GET / POST PARAMETERS
// --------------------------------------------------

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$json = $_GET["json"] ?? $_POST["json"] ?? "";

$obj = new Auth();


// --------------------------------------------------
// OPERATION ROUTER
// --------------------------------------------------

switch ($operation) {

    case "login":
        echo json_encode($obj->login($json));
        break;

    case "logout":
        echo json_encode($obj->logout());
        break;

    case "checkSession":
        echo json_encode($obj->checkSession());
        break;

    default:
        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid operation."
        ]);
        break;
}

