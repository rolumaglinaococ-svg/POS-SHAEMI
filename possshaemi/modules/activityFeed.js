// activityFeed.js
// Shared by the Administrator, Manager and Cashier panels.
// - initActivityFeed(): polls activity-log.php every few seconds, shows a toast for what OTHER
//   people just did, and keeps the red badge on the "Activity Log" menu item up to date.
// - initActivityPage(): the full Activity Log table with search + module filter.
// - loadRecentActivity(): the small "recent activity" list used on the Administrator dashboard.

import { baseApiUrl, showToast, esc, debounce, formatDateTime, refreshPager } from "./utils.js";

const pollIntervalMs = 10000;

let storageKey = "pos_last_seen_activity";
let lastSeenId = 0;
let lastToastedId = 0;
let latestKnownId = 0;
let onNewCallback = null;
let pollTimer = null;
let activityPageReady = false;

export async function initActivityFeed({ employeeId, onNew = null }) {
    storageKey = `pos_last_seen_activity_${employeeId}`;
    onNewCallback = onNew;

    // First request only asks "what is the newest id?" so old rows never pop up as new.
    const response = await axios.get(`${baseApiUrl}/activity-log.php`, { params: { operation: "getNewActivity", since_id: -1 } });
    latestKnownId = response.data.latest_id;
    lastToastedId = latestKnownId;

    let stored = null;
    try {
        stored = localStorage.getItem(storageKey);
    } catch (error) {
        stored = null;
    }
    lastSeenId = stored === null ? latestKnownId : parseInt(stored, 10);

    await pollActivity();

    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollActivity, pollIntervalMs);
}

async function pollActivity() {
    let data;

    try {
        const response = await axios.get(`${baseApiUrl}/activity-log.php`, {
            params: { operation: "getNewActivity", since_id: lastSeenId },
            silent: true
        });
        data = response.data;
    } catch (error) {
        if (error.response?.status === 401) window.location.href = "login.html";
        return;
    }

    latestKnownId = data.latest_id;
    updateBadges(data.unread_count);

    const fresh = data.items.filter((item) => item.activity_id > lastToastedId);
    if (fresh.length === 0) return;

    lastToastedId = fresh[fresh.length - 1].activity_id;

    fresh.slice(-3).forEach((item) => showToast(activityToastMessage(item), toastTypeFor(item.action)));

    if (auditPageReady) displayAuditLogs();
    else if (activityPageReady) displayActivityLog();
    if (onNewCallback) onNewCallback(fresh);
}

function activityToastMessage(item) {
    return `<strong>${esc(item.actor_name)}</strong> (${esc(item.actor_role)}) ${esc(item.action.toLowerCase())} ${esc(item.module)}: ${esc(item.record_label ?? "")}`;
}

function toastTypeFor(action) {
    if (action === "Deactivated" || action === "Voided" || action === "Rejected" || action === "Reversed") return "danger";
    if (action === "Updated") return "info";
    return "success";
}

function updateBadges(count) {
    document.querySelectorAll("[data-activity-badge]").forEach((badge) => {
        badge.textContent = count > 99 ? "99+" : count;
        badge.classList.toggle("d-none", count === 0);
    });
}

/**
 * Call when the person opens the Activity Log page - everything up to now counts as read.
 */
export function markActivitySeen() {
    lastSeenId = latestKnownId;
    updateBadges(0);

    try {
        localStorage.setItem(storageKey, String(lastSeenId));
    } catch (error) {
        // localStorage can be blocked; the badge simply resets on next visit.
    }
}

function actionBadge(action) {
    const map = {
        Created: "text-bg-primary",
        Updated: "text-bg-info",
        Deactivated: "text-bg-danger",
        Voided: "text-bg-danger",
        Rejected: "text-bg-danger",
        Restored: "text-bg-success",
        Approved: "text-bg-success",
        Recorded: "text-bg-success",
        Reversed: "text-bg-danger",
        Adjusted: "text-bg-warning"
    };
    return `<span class="badge ${map[action] || "text-bg-secondary"}">${esc(action)}</span>`;
}

function roleBadge(role) {
    const map = { Administrator: "text-bg-dark", Manager: "text-bg-warning", Cashier: "text-bg-secondary" };
    return `<span class="badge ${map[role] || "text-bg-secondary"}">${esc(role)}</span>`;
}

export function initActivityPage() {
    document.getElementById("activitySearch").addEventListener("input", debounce(displayActivityLog, 300));
    document.getElementById("activityModuleFilter").addEventListener("change", displayActivityLog);
    document.getElementById("btnRefreshActivity").addEventListener("click", displayActivityLog);

    activityPageReady = true;
    populateModuleFilter();
    displayActivityLog();
}

async function populateModuleFilter() {
    const select = document.getElementById("activityModuleFilter");
    const selected = select.value;

    const response = await axios.get(`${baseApiUrl}/activity-log.php`, { params: { operation: "getActivityModules" } });

    select.innerHTML = `<option value="">All modules</option>` +
        response.data.map((module) => `<option value="${esc(module)}">${esc(module)}</option>`).join("");
    select.value = selected;
}

export async function displayActivityLog() {
    const keyword = document.getElementById("activitySearch").value.trim();
    const module = document.getElementById("activityModuleFilter").value;

    const response = await axios.get(`${baseApiUrl}/activity-log.php`, {
        params: { operation: "getActivityLog", keyword, module, limit: 150 }
    });

    renderActivityTable(response.data);
}

function renderActivityTable(entries) {
    const tbody = document.getElementById("activityTableBody");
    tbody.innerHTML = "";

    if (!entries || entries.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No activity yet.</td></tr>`;
        return;
    }

    entries.forEach((entry) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td class="text-nowrap">${formatDateTime(entry.created_at)}</td>
            <td>${esc(entry.actor_name)}</td>
            <td>${roleBadge(entry.actor_role)}</td>
            <td>${esc(entry.module)}</td>
            <td>${actionBadge(entry.action)}</td>
            <td>${esc(entry.record_label ?? "")}</td>
            <td class="text-muted small">${esc(entry.details ?? "")}</td>
        `;

        tbody.appendChild(row);
    });

    refreshPager("activityTableBody");
}

/**
 * Small "recent activity" list (Administrator dashboard).
 */
export async function loadRecentActivity(containerId, limit = 8) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const response = await axios.get(`${baseApiUrl}/activity-log.php`, { params: { operation: "getActivityLog", limit } });
    const entries = response.data;

    if (!entries || entries.length === 0) {
        container.innerHTML = `<div class="text-muted small p-3">No activity yet.</div>`;
        return;
    }

    container.innerHTML = "";

    entries.forEach((entry) => {
        const item = document.createElement("div");
        item.className = "list-group-item d-flex justify-content-between align-items-start gap-2";
        item.innerHTML = `
            <div>
                <div>${actionBadge(entry.action)} <strong>${esc(entry.module)}</strong> — ${esc(entry.record_label ?? "")}</div>
                <div class="text-muted small">${esc(entry.actor_name)} ${roleBadge(entry.actor_role)}</div>
            </div>
            <div class="text-muted small text-nowrap">${formatDateTime(entry.created_at)}</div>
        `;
        container.appendChild(item);
    });
}

/* =====================================================================
 * Audit Logs page (Administrator) — server-side filtered + paginated
 * investigation view over the same tblactivity_log. The legacy
 * Activity page above stays untouched for the Cashier panel (D1).
 * ===================================================================== */

let auditPageReady = false;
let auditPage = 1;
let auditTotalPages = 1;

function outcomeBadge(outcome) {
    const map = { Success: "text-bg-success", Failed: "text-bg-warning", Denied: "text-bg-danger" };
    return `<span class="badge ${map[outcome] || "text-bg-secondary"}">${esc(outcome || "Success")}</span>`;
}

export function initAuditPage() {
    const rerun = () => { auditPage = 1; displayAuditLogs(); };
    document.getElementById("auditSearch").addEventListener("input", debounce(rerun, 300));
    ["auditDateFrom", "auditDateTo", "auditUserFilter", "auditRoleFilter", "auditModuleFilter", "auditActionFilter", "auditOutcomeFilter"]
        .forEach((id) => document.getElementById(id).addEventListener("change", rerun));
    document.getElementById("btnRefreshAudit").addEventListener("click", rerun);
    document.getElementById("btnExportAudit").addEventListener("click", exportAuditCsv);
    document.getElementById("auditPageSize").addEventListener("change", rerun);
    document.getElementById("auditPagePrev").addEventListener("click", () => { if (auditPage > 1) { auditPage--; displayAuditLogs(); } });
    document.getElementById("auditPageNext").addEventListener("click", () => { if (auditPage < auditTotalPages) { auditPage++; displayAuditLogs(); } });
    document.getElementById("auditTableBody").addEventListener("click", (e) => {
        const btn = e.target.closest("[data-audit-view]");
        if (btn) openAuditDetail(parseInt(btn.dataset.auditView, 10));
    });

    auditPageReady = true;
    populateAuditFilters();
    displayAuditLogs();
}

async function populateAuditFilters() {
    try {
        const response = await axios.get(`${baseApiUrl}/activity-log.php`, { params: { operation: "getAuditFilterOptions" } });
        const data = response.data;
        if (!data.success) return;
        const fill = (id, values, label) => {
            const select = document.getElementById(id);
            const current = select.value;
            select.innerHTML = `<option value="">${label}</option>` + values.map((v) => `<option value="${esc(v)}">${esc(v)}</option>`).join("");
            select.value = current;
        };
        fill("auditModuleFilter", data.modules, "All modules");
        fill("auditActionFilter", data.actions, "All actions");
        fill("auditRoleFilter", data.roles, "All roles");
        const userSelect = document.getElementById("auditUserFilter");
        const currentUser = userSelect.value;
        userSelect.innerHTML = `<option value="">All users</option>` +
            data.users.map((u) => `<option value="${esc(u.employee_id)}">${esc(u.name)}</option>`).join("");
        userSelect.value = currentUser;
    } catch (error) {
        // Filters stay on their defaults; the table itself will surface errors.
    }
}

function auditFilterParams() {
    return {
        keyword: document.getElementById("auditSearch").value.trim(),
        date_from: document.getElementById("auditDateFrom").value,
        date_to: document.getElementById("auditDateTo").value,
        employee_id: document.getElementById("auditUserFilter").value,
        role: document.getElementById("auditRoleFilter").value,
        module: document.getElementById("auditModuleFilter").value,
        action: document.getElementById("auditActionFilter").value,
        outcome: document.getElementById("auditOutcomeFilter").value
    };
}

export async function displayAuditLogs() {
    const params = {
        operation: "getAuditLogs",
        ...auditFilterParams(),
        page: auditPage,
        page_size: document.getElementById("auditPageSize").value
    };
    const response = await axios.get(`${baseApiUrl}/activity-log.php`, { params });
    const data = response.data;
    const tbody = document.getElementById("auditTableBody");
    tbody.innerHTML = "";

    if (!data.success || !data.items || data.items.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-4">No audit records match these filters.</td></tr>`;
    } else {
        data.items.forEach((entry) => {
            const row = document.createElement("tr");
            row.innerHTML = `
                <td class="text-nowrap">${formatDateTime(entry.created_at)}</td>
                <td>${esc(entry.actor_name)}</td>
                <td>${roleBadge(entry.actor_role)}</td>
                <td>${esc(entry.module)}</td>
                <td>${actionBadge(entry.action)}</td>
                <td>${outcomeBadge(entry.outcome)}</td>
                <td>${esc(entry.record_label ?? "")}</td>
                <td>${esc(entry.reference_label ?? "")}</td>
                <td>${entry.authorized_by ? esc("#" + entry.authorized_by) : ""}</td>
                <td><button type="button" class="btn btn-sm btn-outline-primary" data-audit-view="${entry.activity_id}">View</button></td>`;
            tbody.appendChild(row);
        });
    }

    auditTotalPages = Math.max(1, data.total_pages || 1);
    if (auditPage > auditTotalPages) auditPage = auditTotalPages;
    document.getElementById("auditCount").textContent = `${data.total ?? 0} record(s)`;
    document.getElementById("auditPageInfo").textContent = `Page ${auditPage} of ${auditTotalPages}`;
    document.getElementById("auditPagePrev").disabled = auditPage <= 1;
    document.getElementById("auditPageNext").disabled = auditPage >= auditTotalPages;
}

function exportAuditCsv() {
    const query = new URLSearchParams({ operation: "exportAuditCsv", ...auditFilterParams() });
    const link = document.createElement("a");
    link.href = `${baseApiUrl}/activity-log.php?${query.toString()}`;
    link.download = "audit-logs.csv";
    document.body.appendChild(link);
    link.click();
    link.remove();
}

// Tier id -> name map for legacy audit snapshots that stored tier_prices
// keyed by numeric tier ID ({"1":55,...}). Fetched once, lazily.
let auditTierNames = null;
async function loadAuditTierNames() {
    if (auditTierNames) return auditTierNames;
    auditTierNames = {};
    try {
        const response = await axios.get(`${baseApiUrl}/price-tiers.php`, { params: { operation: "getAllPriceTiers", status: "all" } });
        const rows = Array.isArray(response.data) ? response.data : [];
        rows.forEach((t) => { auditTierNames[String(t.price_tier_id)] = t.tier_name; });
    } catch (error) {
        // Names unavailable: numeric keys fall back to "Tier #<id>".
    }
    return auditTierNames;
}

function auditTierLabel(key) {
    if (/^\d+$/.test(String(key))) return (auditTierNames && auditTierNames[String(key)]) || `Tier #${key}`;
    return String(key);
}

// ---- Audit detail formatting (v4 cleanup) ----
// Money keys render as pesos; Suki point keys never do (adjustment_amount
// & friends are POINTS there, despite the "_amount" suffix).
const AUDIT_MONEY_KEYS = new Set(["balance", "amount", "total_amount", "total", "price", "face_value", "cash_value", "applied", "credit_limit", "deposit_amount", "deposit_verified_amount", "expected_cash", "actual_cash", "cash_difference", "deposit_difference", "difference"]);
const AUDIT_POINT_KEYS = new Set(["previous_balance", "new_balance", "adjustment_amount"]);
const AUDIT_LABEL_MAP = { gc_codes: "GC codes", ar_account_id: "AR account", suki_points_earned: "Suki points earned", suki_points_redeemed: "Suki points redeemed", tier_prices: "Tier prices" };
const AUDIT_EMPTY_HTML = "<span class=\"text-muted\">—</span>";

function isAuditMoneyKey(key) {
    if (AUDIT_POINT_KEYS.has(key)) return false;
    return AUDIT_MONEY_KEYS.has(key) || String(key).endsWith("_amount");
}

function auditMoneyText(v) {
    return "₱" + Number(v).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Plain (unescaped) display text for a scalar; callers escape once.
function auditScalarText(key, v) {
    if (v === "" || v === null || v === undefined) return "—";
    if (isAuditMoneyKey(key) && (typeof v === "number" || (typeof v === "string" && v.trim() !== "" && Number.isFinite(Number(v))))) return auditMoneyText(v);
    return String(v);
}

function humanizeAuditLabel(key) {
    if (AUDIT_LABEL_MAP[key]) return AUDIT_LABEL_MAP[key];
    const s = String(key).replace(/_/g, " ");
    return s.charAt(0).toUpperCase() + s.slice(1);
}

// "" and a missing value are the same "empty" for change detection, so a
// null -> "" pair does not falsely highlight.
function auditNorm(v) {
    return JSON.stringify(v === "" || v === undefined ? null : v);
}

// Renders one audit before/after cell. Objects become readable lines (one
// "key: value" per line) instead of a raw JSON string; arrays render one
// line per item (allocations read "Invoice #7 — ₱116.00"); tier_prices
// gets "TierName: ₱55.00" lines. A line whose counterpart on the other
// side differs is bolded. `other` is the opposite side's field value.
function formatAuditValue(value, fieldKey, other) {
    if (value === null || value === undefined || value === "") return AUDIT_EMPTY_HTML;
    if (Array.isArray(value)) {
        if (!value.length) return AUDIT_EMPTY_HTML;
        return value.map((item, i) => {
            const otherVal = Array.isArray(other) ? other[i] : undefined;
            const changed = auditNorm(item) !== auditNorm(otherVal);
            let text;
            if (item && typeof item === "object") {
                if ("invoice_id" in item && "amount" in item) {
                    text = `Invoice #${item.invoice_id} — ${auditMoneyText(item.amount)}`;
                } else {
                    text = Object.entries(item).map(([k, v]) => `${humanizeAuditLabel(k)}: ${auditScalarText(k, v && typeof v === "object" ? JSON.stringify(v) : v)}`).join(" · ");
                }
            } else {
                text = auditScalarText(fieldKey, item);
            }
            return `<div class="${changed ? "fw-bold" : ""}">${esc(text)}</div>`;
        }).join("");
    }
    if (typeof value === "object") {
        const entries = Object.entries(value);
        if (!entries.length) return AUDIT_EMPTY_HTML;
        return entries.map(([k, v]) => {
            const otherVal = other && typeof other === "object" && !Array.isArray(other) ? other[k] : undefined;
            const changed = auditNorm(v) !== auditNorm(otherVal);
            if (fieldKey !== "tier_prices" && v && typeof v === "object") {
                // Nested value: label line, then its own readable lines.
                return `<div class="${changed ? "fw-bold" : ""}">${esc(humanizeAuditLabel(k))}:</div>` + formatAuditValue(v, k, otherVal);
            }
            const line = fieldKey === "tier_prices"
                ? `${auditTierLabel(k)}: ${v !== "" && Number.isFinite(Number(v)) ? auditMoneyText(v) : auditScalarText(k, v)}`
                : `${humanizeAuditLabel(k)}: ${auditScalarText(k, v)}`;
            return `<div class="${changed ? "fw-bold" : ""}">${esc(line)}</div>`;
        }).join("");
    }
    return esc(auditScalarText(fieldKey, value));
}

async function openAuditDetail(activityId) {
    const response = await axios.get(`${baseApiUrl}/activity-log.php`, {
        params: { operation: "getAuditDetail", activity_id: activityId }
    });
    const data = response.data;
    const body = document.getElementById("auditDetailBody");
    if (!data.success) {
        body.innerHTML = `<div class="alert alert-danger mb-0">${esc(data.message || "Could not load this audit record.")}</div>`;
    } else {
        const item = data.item;
        const before = item.before_json ? JSON.parse(item.before_json) : null;
        const after = item.after_json ? JSON.parse(item.after_json) : null;
        const keys = [...new Set([...Object.keys(before || {}), ...Object.keys(after || {})])];
        if (keys.includes("tier_prices")) await loadAuditTierNames();
        const diffRows = keys.map((key) => {
            const b = before ? before[key] : undefined;
            const a = after ? after[key] : undefined;
            const changed = auditNorm(b) !== auditNorm(a);
            return `<tr class="${changed ? "table-warning" : ""}"><td class="fw-semibold">${esc(humanizeAuditLabel(key))}</td><td>${formatAuditValue(b, key, a)}</td><td>${formatAuditValue(a, key, b)}</td></tr>`;
        }).join("");
        let arBlock = "";
        if (data.ar_audit) {
            arBlock = `<p class="text-muted small mt-3 mb-0">Linked AR audit #${esc(data.ar_audit.audit_id)} · ${esc(data.ar_audit.entity_type)} #${esc(data.ar_audit.entity_id)}</p>`;
        }
        body.innerHTML = `
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td class="fw-semibold" style="width:170px">Date/Time</td><td>${formatDateTime(item.created_at)}</td></tr>
                    <tr><td class="fw-semibold">Actor</td><td>${esc(item.actor_name)} (${esc(item.actor_role)})</td></tr>
                    <tr><td class="fw-semibold">Module / Action</td><td>${esc(item.module)} · ${esc(item.action)}</td></tr>
                    <tr><td class="fw-semibold">Outcome</td><td>${outcomeBadge(item.outcome)}</td></tr>
                    <tr><td class="fw-semibold">Record</td><td>${esc(item.record_label ?? "")}${item.record_id ? ` <span class="text-muted">(#${esc(item.record_id)})</span>` : ""}</td></tr>
                    <tr><td class="fw-semibold">Reference</td><td>${esc(item.reference_label ?? "—")}</td></tr>
                    <tr><td class="fw-semibold">Authorized by</td><td>${item.authorized_by ? `#${esc(item.authorized_by)}${item.authorized_role ? " (" + esc(item.authorized_role) + ")" : ""}` : "—"}</td></tr>
                    <tr><td class="fw-semibold">Reason</td><td>${esc(item.reason ?? "—")}</td></tr>
                    <tr><td class="fw-semibold">IP address</td><td>${esc(item.ip_address ?? "—")}</td></tr>
                    <tr><td class="fw-semibold">Details</td><td>${esc(item.details ?? "—")}</td></tr>
                </tbody>
            </table>
            ${keys.length ? `<h6 class="mt-4">Before → After</h6>
            <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                <tbody>${diffRows}</tbody>
            </table></div>` : ""}
            ${arBlock}`;
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById("auditDetailModal")).show();
}
