<?php

/*
 * session-guard.php
 * Shared server-side authorization check. Every protected API file
 * includes this and calls requireRole() before its switch statement.
 * This is the real security boundary - the frontend redirect logic
 * in modules/auth.js is UX convenience only.
 *
 * v2: the session is re-checked against the database on every request,
 * so if an Administrator deactivates an employee (or changes the role),
 * that employee is signed out on their very next action.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function validateSession() {
    include "connection-pdo.php";

    if (!isset($_SESSION["employee_id"])) {
        return false;
    }

    $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.username, e.is_active,
                   r.role_name, r.is_active AS role_active
            FROM tblemployee e
            INNER JOIN tblrole r ON e.role_id = r.role_id
            WHERE e.employee_id = :employee_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(":employee_id", $_SESSION["employee_id"], PDO::PARAM_INT);
    $stmt->execute();
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee || (int)$employee["is_active"] !== 1 || (int)$employee["role_active"] !== 1) {
        $_SESSION = [];
        session_destroy();
        return false;
    }

    // Keep the session in sync with what the Administrator currently has on file.
    $_SESSION["username"] = $employee["username"];
    $_SESSION["role"] = $employee["role_name"];
    $_SESSION["display_name"] = $employee["first_name"] . " " . $employee["last_name"];

    return true;
}

/*
 * Records a 401/403 denial in the central Audit Log (own connection,
 * survives the exit below). Best-effort by design: a logging failure
 * must never change the denial response itself.
 */
function logGuardDenial($statusCode, $snapshot) {
    try {
        require_once __DIR__ . "/audit-logger.php";
        $endpoint = basename((string)($_SERVER["SCRIPT_NAME"] ?? "unknown"));
        auditAttempt([
            "module" => "Security",
            "action" => $statusCode === 401 ? "Unauthenticated" : "AccessDenied",
            "outcome" => "Denied",
            "employee_id" => $snapshot["employee_id"],
            "actor_name" => $snapshot["name"],
            "actor_role" => $snapshot["role"],
            "record_label" => $endpoint,
            "reference_label" => $endpoint,
            "details" => ($statusCode === 401
                ? "Unauthenticated request denied (401)."
                : "Role '" . $snapshot["role"] . "' denied access (403)."),
            "reason" => "Endpoint: " . $endpoint
        ]);
    } catch (Throwable $e) {
        error_log("POSAR guard denial logging failed: " . $e->getMessage());
    }
}

function requireRole(array $allowedRoles) {
    header("Content-Type: application/json");

    // Snapshot the session identity first: validateSession() may clear
    // the session, and the denial row should still name who tried.
    $snapshot = [
        "employee_id" => isset($_SESSION["employee_id"]) ? (int)$_SESSION["employee_id"] : null,
        "name" => $_SESSION["display_name"] ?? "Unknown",
        "role" => $_SESSION["role"] ?? "Unknown"
    ];

    if (!validateSession()) {
        logGuardDenial(401, $snapshot);
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Not authenticated."]);
        exit;
    }

    if (!in_array($_SESSION["role"], $allowedRoles, true)) {
        logGuardDenial(403, [
            "employee_id" => (int)$_SESSION["employee_id"],
            "name" => $_SESSION["display_name"] ?? "Unknown",
            "role" => $_SESSION["role"]
        ]);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You do not have permission to access this resource."]);
        exit;
    }
}
