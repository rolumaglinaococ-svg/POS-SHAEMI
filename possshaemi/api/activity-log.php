<?php
require_once "session-guard.php";
requireRole(["Administrator", "Manager", "Cashier"]);
require_once "audit-logger.php";

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

class ActivityLog {

    // Administrator and Manager see everything.
    // A Cashier sees every master-file / AR change made by staff (so they know
    // when a product, price, discount or customer was deactivated) plus their
    // own sales - but never Employees / Roles changes or other cashiers' sales.
    private function scopeSql(&$params) {
        if ($_SESSION["role"] === "Cashier") {
            $params[":me"] = (int)$_SESSION["employee_id"];
            return "(module NOT IN ('Employees', 'Roles') AND (actor_role <> 'Cashier' OR employee_id = :me))";
        }

        return "1 = 1";
    }

    private function bindAll($stmt, $params) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    public function getActivityLog($keyword, $module, $limit) {
        include "connection-pdo.php";

        $params = [];
        $sql = "SELECT activity_id, employee_id, actor_name, actor_role, module, action, record_id, record_label, details, created_at
                FROM tblactivity_log
                WHERE " . $this->scopeSql($params);

        if ($module !== "") {
            $sql .= " AND module = :module";
            $params[":module"] = $module;
        }

        if ($keyword !== "") {
            $sql .= " AND (actor_name LIKE :keyword OR record_label LIKE :keyword OR details LIKE :keyword)";
            $params[":keyword"] = "%" . $keyword . "%";
        }

        $limit = max(1, min((int)$limit, 200));
        $sql .= " ORDER BY activity_id DESC LIMIT " . $limit;

        $stmt = $conn->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActivityModules() {
        include "connection-pdo.php";

        $params = [];
        $sql = "SELECT DISTINCT module FROM tblactivity_log WHERE " . $this->scopeSql($params) . " ORDER BY module ASC";
        $stmt = $conn->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Polled every few seconds by activityFeed.js.
    // since_id < 0  -> only report the newest id (used on first load so old rows do not pop up as "new").
    public function getNewActivity($sinceId) {
        include "connection-pdo.php";

        $params = [];
        $scope = $this->scopeSql($params);

        $latestStmt = $conn->prepare("SELECT COALESCE(MAX(activity_id), 0) AS latest_id FROM tblactivity_log WHERE " . $scope);
        $this->bindAll($latestStmt, $params);
        $latestStmt->execute();
        $latestId = (int)$latestStmt->fetch(PDO::FETCH_ASSOC)["latest_id"];

        if ($sinceId < 0) {
            return ["latest_id" => $latestId, "unread_count" => 0, "items" => []];
        }

        // A person is never notified about their own actions.
        $countParams = $params;
        $countParams[":since_id"] = (int)$sinceId;
        $countParams[":actor_me"] = (int)$_SESSION["employee_id"];
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tblactivity_log
                                     WHERE " . $scope . " AND activity_id > :since_id AND (employee_id IS NULL OR employee_id <> :actor_me)");
        $this->bindAll($countStmt, $countParams);
        $countStmt->execute();
        $unread = (int)$countStmt->fetch(PDO::FETCH_ASSOC)["total"];

        $itemParams = $params;
        $itemParams[":since_id"] = (int)$sinceId;
        $itemParams[":actor_me"] = (int)$_SESSION["employee_id"];
        $itemStmt = $conn->prepare("SELECT activity_id, employee_id, actor_name, actor_role, module, action, record_id, record_label, details, created_at
                                    FROM tblactivity_log
                                    WHERE " . $scope . " AND activity_id > :since_id AND (employee_id IS NULL OR employee_id <> :actor_me)
                                    ORDER BY activity_id ASC LIMIT 20");
        $this->bindAll($itemStmt, $itemParams);
        $itemStmt->execute();

        return ["latest_id" => $latestId, "unread_count" => $unread, "items" => $itemStmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    /* ================= Audit Logs (Audit Logs build) =================
     * Read-only investigation endpoints. Administrator: full scope.
     * Manager: server-forced to AR modules — request parameters can
     * narrow the scope but never widen it. Cashier never reaches these
     * methods (denied in the router below, and the denial is logged).
     * There is deliberately NO update/delete operation for audit rows.
     */

    private function auditModulesScope() {
        if (($_SESSION["role"] ?? "") === "Manager") {
            return ["AR Payments", "AR Accounts", "AR Memos", "AR"];
        }
        return null; // Administrator: unrestricted
    }

    private function auditWhere($filters, &$params) {
        $where = "1 = 1";
        $scope = $this->auditModulesScope();
        if ($scope !== null) {
            $in = [];
            foreach ($scope as $i => $m) {
                $key = ":scope" . $i;
                $in[] = $key;
                $params[$key] = $m;
            }
            $where .= " AND module IN (" . implode(",", $in) . ")";
        }
        if (!empty($filters["date_from"])) {
            $where .= " AND created_at >= :date_from";
            $params[":date_from"] = $filters["date_from"] . " 00:00:00";
        }
        if (!empty($filters["date_to"])) {
            $where .= " AND created_at <= :date_to";
            $params[":date_to"] = $filters["date_to"] . " 23:59:59";
        }
        if (!empty($filters["employee_id"])) {
            $where .= " AND employee_id = :employee_id";
            $params[":employee_id"] = (int)$filters["employee_id"];
        }
        if (!empty($filters["role"])) {
            $where .= " AND actor_role = :role";
            $params[":role"] = $filters["role"];
        }
        if (!empty($filters["module"])) {
            if ($scope !== null && !in_array($filters["module"], $scope, true)) {
                $where .= " AND 1 = 0"; // Manager asked outside AR scope
            } else {
                $where .= " AND module = :module";
                $params[":module"] = $filters["module"];
            }
        }
        if (!empty($filters["action"])) {
            $where .= " AND action = :action";
            $params[":action"] = $filters["action"];
        }
        if (!empty($filters["outcome"]) && in_array($filters["outcome"], ["Success", "Failed", "Denied"], true)) {
            $where .= " AND outcome = :outcome";
            $params[":outcome"] = $filters["outcome"];
        }
        if (!empty($filters["keyword"])) {
            $where .= " AND (actor_name LIKE :kw OR record_label LIKE :kw OR reference_label LIKE :kw OR details LIKE :kw OR after_json LIKE :kw OR CAST(record_id AS CHAR) = :kw_exact)";
            $params[":kw"] = "%" . $filters["keyword"] . "%";
            $params[":kw_exact"] = $filters["keyword"];
        }
        return $where;
    }

    private function auditColumns() {
        return "activity_id, employee_id, actor_name, actor_role, module, action, record_id, record_label, details,
                outcome, before_json, after_json, reason, authorized_by, authorized_role, reference_label, ip_address, created_at";
    }

    public function getAuditLogs($filters) {
        include "connection-pdo.php";
        $params = [];
        $where = $this->auditWhere($filters, $params);

        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tblactivity_log WHERE " . $where);
        $this->bindAll($countStmt, $params);
        $countStmt->execute();
        $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)["total"];

        $page = max(1, (int)($filters["page"] ?? 1));
        $pageSize = max(1, min(100, (int)($filters["page_size"] ?? 15)));
        $offset = ($page - 1) * $pageSize;

        $stmt = $conn->prepare("SELECT " . $this->auditColumns() . " FROM tblactivity_log WHERE " . $where .
                               " ORDER BY activity_id DESC LIMIT :lim OFFSET :off");
        $this->bindAll($stmt, $params);
        $stmt->bindValue(":lim", $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(":off", $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            "success" => true,
            "items" => $stmt->fetchAll(PDO::FETCH_ASSOC),
            "total" => $total,
            "page" => $page,
            "page_size" => $pageSize,
            "total_pages" => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0
        ];
    }

    public function getAuditLogsForExport($filters) {
        include "connection-pdo.php";
        $params = [];
        $where = $this->auditWhere($filters, $params);
        $stmt = $conn->prepare("SELECT " . $this->auditColumns() . " FROM tblactivity_log WHERE " . $where .
                               " ORDER BY activity_id DESC LIMIT 5000");
        $this->bindAll($stmt, $params);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAuditDetail($activityId) {
        include "connection-pdo.php";
        $stmt = $conn->prepare("SELECT " . $this->auditColumns() . " FROM tblactivity_log WHERE activity_id = :id");
        $stmt->bindValue(":id", (int)$activityId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ["success" => false, "message" => "Audit record not found."];
        }
        $scope = $this->auditModulesScope();
        if ($scope !== null && !in_array($row["module"], $scope, true)) {
            return ["success" => false, "forbidden" => true, "message" => "This audit record is outside your scope."];
        }
        $arAudit = null;
        if (!empty($row["reference_label"]) && preg_match("/^AR Audit #(\d+)$/", $row["reference_label"], $m)) {
            $aStmt = $conn->prepare("SELECT * FROM tblar_audit WHERE audit_id = :id");
            $aStmt->bindValue(":id", (int)$m[1], PDO::PARAM_INT);
            $aStmt->execute();
            $arAudit = $aStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return ["success" => true, "item" => $row, "ar_audit" => $arAudit];
    }

    public function getAuditFilterOptions() {
        include "connection-pdo.php";
        $params = [];
        $scopeWhere = "1 = 1";
        $scope = $this->auditModulesScope();
        if ($scope !== null) {
            $in = [];
            foreach ($scope as $i => $m) {
                $key = ":scope" . $i;
                $in[] = $key;
                $params[$key] = $m;
            }
            $scopeWhere .= " AND module IN (" . implode(",", $in) . ")";
        }
        $collect = function ($sql) use ($conn, $params) {
            $stmt = $conn->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };
        $usersStmt = $conn->prepare("SELECT employee_id, MAX(actor_name) AS name FROM tblactivity_log WHERE " . $scopeWhere . " AND employee_id IS NOT NULL GROUP BY employee_id ORDER BY name ASC");
        $this->bindAll($usersStmt, $params);
        $usersStmt->execute();
        return [
            "success" => true,
            "modules" => $collect("SELECT DISTINCT module FROM tblactivity_log WHERE " . $scopeWhere . " ORDER BY module ASC"),
            "actions" => $collect("SELECT DISTINCT action FROM tblactivity_log WHERE " . $scopeWhere . " ORDER BY action ASC"),
            "roles" => $collect("SELECT DISTINCT actor_role FROM tblactivity_log WHERE " . $scopeWhere . " ORDER BY actor_role ASC"),
            "outcomes" => ["Success", "Failed", "Denied"],
            "users" => $usersStmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
}

$operation = $_GET["operation"] ?? $_POST["operation"] ?? "";
$keyword = trim($_GET["keyword"] ?? "");
$module = trim($_GET["module"] ?? "");
$limit = $_GET["limit"] ?? 100;
$sinceId = $_GET["since_id"] ?? -1;

$obj = new ActivityLog();

// Audit investigation endpoints: Administrator (full) and Manager
// (AR-scoped) only. A Cashier reaching one is denied AND the denial
// itself is written to the Audit Log.
$auditOps = ["getAuditLogs", "getAuditDetail", "getAuditFilterOptions", "exportAuditCsv"];
if (in_array($operation, $auditOps, true) && !in_array($_SESSION["role"] ?? "", ["Administrator", "Manager"], true)) {
    auditAttempt([
        "module" => "Audit Logs",
        "action" => $operation,
        "outcome" => "Denied",
        "record_label" => "activity-log.php",
        "reference_label" => "activity-log.php",
        "details" => "Cashier attempted to access the Audit Logs endpoint " . $operation . ".",
        "reason" => "Endpoint: activity-log.php"
    ]);
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "You do not have permission to access this resource."]);
    exit;
}

$auditFilters = [
    "date_from" => trim($_GET["date_from"] ?? ""),
    "date_to" => trim($_GET["date_to"] ?? ""),
    "employee_id" => $_GET["employee_id"] ?? "",
    "role" => trim($_GET["role"] ?? ""),
    "module" => trim($_GET["module"] ?? ""),
    "action" => trim($_GET["action"] ?? ""),
    "outcome" => trim($_GET["outcome"] ?? ""),
    "keyword" => trim($_GET["keyword"] ?? ""),
    "page" => $_GET["page"] ?? 1,
    "page_size" => $_GET["page_size"] ?? 15
];
foreach (["date_from", "date_to"] as $dk) {
    if ($auditFilters[$dk] !== "" && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $auditFilters[$dk])) {
        $auditFilters[$dk] = "";
    }
}

switch ($operation) {
    case "getAuditLogs":
        echo json_encode($obj->getAuditLogs($auditFilters));
        break;
    case "getAuditFilterOptions":
        echo json_encode($obj->getAuditFilterOptions());
        break;
    case "getAuditDetail":
        $detail = $obj->getAuditDetail((int)($_GET["activity_id"] ?? 0));
        if (!empty($detail["forbidden"])) {
            auditAttempt([
                "module" => "Audit Logs",
                "action" => "getAuditDetail",
                "outcome" => "Denied",
                "record_label" => "activity-log.php",
                "details" => "Audit detail outside the requester's scope was requested.",
                "reason" => "Endpoint: activity-log.php"
            ]);
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "You do not have permission to access this resource."]);
            break;
        }
        echo json_encode($detail);
        break;
    case "exportAuditCsv":
        $rows = $obj->getAuditLogsForExport($auditFilters);
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"audit-logs.csv\"");
        $out = fopen("php://output", "w");
        fputcsv($out, ["Date/Time", "Actor", "Role", "Module", "Action", "Outcome", "Record ID", "Record", "Reference", "Authorized By", "Reason", "Details"]);
        foreach ($rows as $r) {
            fputcsv($out, [$r["created_at"], $r["actor_name"], $r["actor_role"], $r["module"], $r["action"], $r["outcome"],
                           $r["record_id"], $r["record_label"], $r["reference_label"], $r["authorized_by"], $r["reason"], $r["details"]]);
        }
        fclose($out);
        exit;
    case "getActivityLog":
        echo json_encode($obj->getActivityLog($keyword, $module, $limit));
        break;
    case "getActivityModules":
        echo json_encode($obj->getActivityModules());
        break;
    case "getNewActivity":
        echo json_encode($obj->getNewActivity((int)$sinceId));
        break;
    default:
        echo json_encode(["success" => false, "message" => "Invalid operation."]);
        break;
}
