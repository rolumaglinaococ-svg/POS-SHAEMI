<?php

/*
 * activity-logger.php
 * Shared activity trail for NON-CRITICAL master-file CRUD. An API file
 * calls startActivityLogging() once, right after requireRole(). When the
 * request is a successful POST whose operation starts with insert /
 * update / delete / restore / approve / reject / void / record /
 * reverse / adjust / hold / save, one row is written to tblactivity_log
 * with WHO did WHAT to WHICH record, plus a before/after snapshot of
 * the row (redacted — password hashes and similar never stored).
 *
 * Critical operations do NOT rely on this logger: they write through
 * auditEvent() inside their own transaction (see audit-logger.php) and
 * set the suppression flag $GLOBALS["posar_audit_written"], which makes
 * this logger skip the request — one designated writer per operation.
 *
 * $table, $idColumn and $labelExpression are fixed strings written in
 * code (never user input); the record id itself is always bound as a
 * parameter.
 */

require_once __DIR__ . "/audit-logger.php";

function logTruncate($text, $length) {
    return function_exists("mb_substr") ? mb_substr($text, 0, $length) : substr($text, 0, $length);
}

function startActivityLogging($module, $table, $idColumn, $labelExpression) {
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        return;
    }

    $operation = $_POST["operation"] ?? "";

    $actionMap = [
        "insert"  => "Created",
        "create"  => "Created",
        "update"  => "Updated",
        "delete"  => "Deactivated",
        "deactiv" => "Deactivated",
        "restore" => "Restored",
        "approve" => "Approved",
        "reject"  => "Rejected",
        "void"    => "Voided",
        "record"  => "Recorded",
        "reverse" => "Reversed",
        "adjust"  => "Adjusted",
        "hold"    => "Held"
    ];

    $action = null;
    foreach ($actionMap as $prefix => $actionLabel) {
        if (strpos($operation, $prefix) === 0) {
            $action = $actionLabel;
            break;
        }
    }

    // units.php uses a single "save" operation for insert and update.
    if ($action === null && strpos($operation, "save") === 0) {
        $action = "Saved";
    }

    if ($action === null) {
        return;
    }

    $requestData = json_decode($_POST["json"] ?? "", true);
    if (!is_array($requestData)) {
        $requestData = [];
    }
    $actor = [
        "employee_id" => $_SESSION["employee_id"] ?? null,
        "name" => $_SESSION["display_name"] ?? "Unknown",
        "role" => $_SESSION["role"] ?? "Unknown"
    ];

    // units "save": refine the label once we know whether an id was sent.
    if ($operation === "save") {
        $action = !empty($requestData[$idColumn]) ? "Updated" : "Created";
    }

    // Before-snapshot: read the current row NOW (this runs before the
    // operation), so updates/deactivations keep what the record was.
    $beforeRow = null;
    $requestId = isset($requestData[$idColumn]) && is_numeric($requestData[$idColumn]) ? (int)$requestData[$idColumn] : null;
    if ($requestId !== null) {
        try {
            include "connection-pdo.php";
            $beforeStmt = $conn->prepare("SELECT * FROM $table WHERE $idColumn = :id");
            $beforeStmt->bindValue(":id", $requestId, PDO::PARAM_INT);
            $beforeStmt->execute();
            $row = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            $beforeRow = $row ?: null;
        } catch (Throwable $e) {
            $beforeRow = null;
        }
    }

    ob_start();

    register_shutdown_function(function () use ($module, $table, $idColumn, $labelExpression, $action, $requestData, $actor, $beforeRow) {
        // A critical-operation writer already recorded this request.
        if (!empty($GLOBALS["posar_audit_written"])) {
            return;
        }

        $output = ob_get_contents();
        $result = json_decode($output === false ? "" : $output, true);

        if (!is_array($result) || empty($result["success"])) {
            return;
        }

        try {
            include "connection-pdo.php";

            $recordId = $requestData[$idColumn] ?? ($result[$idColumn] ?? null);
            $recordId = is_numeric($recordId) ? (int)$recordId : null;

            $recordLabel = null;
            $afterRow = null;
            if ($recordId !== null) {
                $labelSql = "SELECT $labelExpression AS label FROM $table WHERE $idColumn = :id";
                $labelStmt = $conn->prepare($labelSql);
                $labelStmt->bindParam(":id", $recordId, PDO::PARAM_INT);
                $labelStmt->execute();
                $row = $labelStmt->fetch(PDO::FETCH_ASSOC);
                $recordLabel = $row ? $row["label"] : null;

                $afterStmt = $conn->prepare("SELECT * FROM $table WHERE $idColumn = :id");
                $afterStmt->bindValue(":id", $recordId, PDO::PARAM_INT);
                $afterStmt->execute();
                $afterRow = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $details = $result["message"] ?? null;

            $sql = "INSERT INTO tblactivity_log (employee_id, actor_name, actor_role, module, action, record_id, record_label, details, outcome, before_json, after_json)
                    VALUES (:employee_id, :actor_name, :actor_role, :module, :action, :record_id, :record_label, :details, 'Success', :before_json, :after_json)";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(":employee_id", $actor["employee_id"], $actor["employee_id"] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(":actor_name", logTruncate($actor["name"], 100), PDO::PARAM_STR);
            $stmt->bindValue(":actor_role", logTruncate($actor["role"], 50), PDO::PARAM_STR);
            $stmt->bindValue(":module", $module, PDO::PARAM_STR);
            $stmt->bindValue(":action", $action, PDO::PARAM_STR);
            $stmt->bindValue(":record_id", $recordId, $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(":record_label", $recordLabel === null ? null : logTruncate($recordLabel, 150), $recordLabel === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(":details", $details === null ? null : logTruncate($details, 255), $details === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $beforeJson = $beforeRow === null ? null : auditJson($beforeRow);
            $afterJson = $afterRow === null ? null : auditJson($afterRow);
            $stmt->bindValue(":before_json", $beforeJson, $beforeJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(":after_json", $afterJson, $afterJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
        } catch (Throwable $e) {
            // Non-critical trail: never break the user's response, but no
            // longer silent — the failure lands in the PHP error log.
            error_log("POSAR activity log failed ($module/$action): " . $e->getMessage());
        }
    });
}
