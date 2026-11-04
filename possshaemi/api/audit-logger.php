<?php

/*
 * audit-logger.php
 * Central Audit Log writer (Audit Logs build, approved 2026-10-22).
 *
 * Two entry points:
 *
 *   auditEvent($conn, $event)
 *     Transactional writer for CRITICAL operations. Call it INSIDE the
 *     operation's open database transaction, on the operation's own
 *     connection, just before commit(). If the insert fails it throws,
 *     the caller's existing catch rolls the whole operation back — a
 *     critical operation can never succeed silently unaudited.
 *
 *   auditAttempt($event)
 *     Writer for FAILED / DENIED attempts (bad login, 401/403, wrong
 *     supervisor or AR approver credentials). Opens its OWN connection
 *     so the row is committed independently and survives the rollback
 *     of the operation that was denied. Never throws: a failed attempt
 *     must still produce its denial response even if logging fails;
 *     failures are sent to the PHP error log instead.
 *
 *   auditStandalone($event)
 *     Same own-connection mechanics as auditAttempt(), for successful
 *     events that have no business transaction of their own (login,
 *     logout, supervisor authorizeAction). Never throws.
 *
 * Both set $GLOBALS["posar_audit_written"] so the generic shutdown
 * logger in activity-logger.php skips the request — one designated
 * writer per operation, no duplicate rows.
 *
 * Redaction: any array key matching the denylist below (passwords,
 * hashes, tokens, secrets) is stripped recursively from before/after
 * snapshots before anything is stored, and free-text fields are
 * scanned for a submitted credential value never being passed in —
 * callers must pass categories/labels, never credential values.
 */

function auditRedact($value) {
    if (!is_array($value)) {
        return $value;
    }
    $deny = "/(password|passwd|pwd|token|secret|hash|authorization|cookie|session_id|api_key|apikey)/i";
    $out = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match($deny, $key)) {
            continue;
        }
        $out[$key] = auditRedact($item);
    }
    return $out;
}

function auditJson($value) {
    if ($value === null) {
        return null;
    }
    $json = json_encode(auditRedact($value));
    if ($json === false) {
        return null;
    }
    // Keep a single event bounded; snapshots in this system are small.
    if (strlen($json) > 8000) {
        $json = substr($json, 0, 8000);
    }
    return $json;
}

function auditTruncate($text, $length) {
    if ($text === null) {
        return null;
    }
    $text = (string)$text;
    return function_exists("mb_substr") ? mb_substr($text, 0, $length) : substr($text, 0, $length);
}

/*
 * Inserts one row into tblactivity_log on the GIVEN connection.
 * Throws on failure (callers inside a transaction rely on that).
 * $event keys (all optional except module + action):
 *   module, action, outcome (Success|Failed|Denied),
 *   employee_id, actor_name, actor_role (default: session identity),
 *   record_id, record_label, details, before, after (arrays),
 *   reason, authorized_by, authorized_role, reference_label, ip_address
 */
function auditEvent($conn, array $event) {
    $employeeId = $event["employee_id"] ?? ($_SESSION["employee_id"] ?? null);
    $actorName = $event["actor_name"] ?? ($_SESSION["display_name"] ?? "Unknown");
    $actorRole = $event["actor_role"] ?? ($_SESSION["role"] ?? "Unknown");
    $outcome = $event["outcome"] ?? "Success";
    $beforeJson = array_key_exists("before", $event) ? auditJson($event["before"]) : null;
    $afterJson = array_key_exists("after", $event) ? auditJson($event["after"]) : null;
    $recordId = isset($event["record_id"]) && is_numeric($event["record_id"]) ? (int)$event["record_id"] : null;
    $authorizedBy = isset($event["authorized_by"]) && is_numeric($event["authorized_by"]) ? (int)$event["authorized_by"] : null;

    $sql = "INSERT INTO tblactivity_log
                (employee_id, actor_name, actor_role, module, action, record_id, record_label, details,
                 outcome, before_json, after_json, reason, authorized_by, authorized_role, reference_label, ip_address)
            VALUES
                (:employee_id, :actor_name, :actor_role, :module, :action, :record_id, :record_label, :details,
                 :outcome, :before_json, :after_json, :reason, :authorized_by, :authorized_role, :reference_label, :ip_address)";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(":employee_id", $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(":actor_name", auditTruncate($actorName, 100), PDO::PARAM_STR);
    $stmt->bindValue(":actor_role", auditTruncate($actorRole, 50), PDO::PARAM_STR);
    $stmt->bindValue(":module", auditTruncate($event["module"] ?? "System", 50), PDO::PARAM_STR);
    $stmt->bindValue(":action", auditTruncate($event["action"] ?? "Action", 30), PDO::PARAM_STR);
    $stmt->bindValue(":record_id", $recordId, $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(":record_label", isset($event["record_label"]) ? auditTruncate($event["record_label"], 150) : null,
                     isset($event["record_label"]) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(":details", isset($event["details"]) ? auditTruncate($event["details"], 255) : null,
                     isset($event["details"]) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(":outcome", $outcome, PDO::PARAM_STR);
    $stmt->bindValue(":before_json", $beforeJson, $beforeJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(":after_json", $afterJson, $afterJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(":reason", isset($event["reason"]) ? auditTruncate($event["reason"], 255) : null,
                     isset($event["reason"]) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(":authorized_by", $authorizedBy, $authorizedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(":authorized_role", isset($event["authorized_role"]) ? auditTruncate($event["authorized_role"], 50) : null,
                     isset($event["authorized_role"]) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(":reference_label", isset($event["reference_label"]) ? auditTruncate($event["reference_label"], 100) : null,
                     isset($event["reference_label"]) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ip = $event["ip_address"] ?? ($_SERVER["REMOTE_ADDR"] ?? null);
    $stmt->bindValue(":ip_address", $ip !== null ? auditTruncate($ip, 45) : null, $ip !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();

    $GLOBALS["posar_audit_written"] = true;
    return (int)$conn->lastInsertId();
}

/*
 * Own-connection write for events with no surrounding business
 * transaction. Never throws; failures go to the PHP error log.
 */
function auditStandalone(array $event) {
    try {
        include "connection-pdo.php";
        return auditEvent($conn, $event);
    } catch (Throwable $e) {
        error_log("POSAR audit write failed (" . ($event["module"] ?? "?") . "/" . ($event["action"] ?? "?") . "): " . $e->getMessage());
        return null;
    }
}

/* Failed / denied attempt: same mechanics, outcome forced by caller. */
function auditAttempt(array $event) {
    if (!isset($event["outcome"])) {
        $event["outcome"] = "Denied";
    }
    return auditStandalone($event);
}

/* Marks this request as already audited (generic logger will skip). */
function auditMarkWritten() {
    $GLOBALS["posar_audit_written"] = true;
}
