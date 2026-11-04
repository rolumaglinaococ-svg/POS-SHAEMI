// managerAudit.js
// AR-scoped Audit Logs view for the AR Manager panel. The server forces
// the AR-module scope (api/activity-log.php); this module only renders
// what the API returns and can narrow — never widen — that scope.
// Markup, badges and the detail modal mirror the Admin Audit Logs page
// (index.html #section-activity + modules/activityFeed.js) so both roles
// see the same design; the matching component styles live in style.css
// scoped to body.manager-page. Deliberate difference from Admin: no CSV
// export button (export is Administrator-only).

import { baseApiUrl, esc, debounce, formatDateTime } from "./utils.js";

let page = 1;
let totalPages = 1;
let initialized = false;

function outcomeBadge(outcome) {
    const map = { Success: "text-bg-success", Failed: "text-bg-warning", Denied: "text-bg-danger" };
    return `<span class="badge ${map[outcome] || "text-bg-secondary"}">${esc(outcome || "Success")}</span>`;
}

// Same badge helpers as the Admin Audit Logs page (modules/activityFeed.js),
// so Manager rows read identically to Admin rows.
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

export function initManagerAudit() {
    const root = document.getElementById("managerAuditRoot");
    root.innerHTML = `
      <div class="admin-toolbar">
        <input type="text" class="form-control admin-search" id="mgrAuditSearch" placeholder="Search actor, record, reference...">
        <div class="admin-toolbar-filters">
          <button class="btn btn-outline-secondary" id="mgrBtnRefreshAudit" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
      </div>
      <div class="admin-toolbar">
        <div class="admin-toolbar-filters">
          <label class="small text-muted">From <input type="date" class="form-control" id="mgrAuditDateFrom"></label>
          <label class="small text-muted">To <input type="date" class="form-control" id="mgrAuditDateTo"></label>
          <select class="form-select" id="mgrAuditUserFilter"><option value="">All users</option></select>
          <select class="form-select" id="mgrAuditModuleFilter"><option value="">All AR modules</option></select>
          <select class="form-select" id="mgrAuditActionFilter"><option value="">All actions</option></select>
          <select class="form-select" id="mgrAuditOutcomeFilter">
            <option value="">All outcomes</option>
            <option value="Success">Success</option>
            <option value="Failed">Failed</option>
            <option value="Denied">Denied</option>
          </select>
        </div>
      </div>
      <div class="table-responsive data-card">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date/Time</th>
              <th>Actor</th>
              <th>Role</th>
              <th>Module</th>
              <th>Action</th>
              <th>Outcome</th>
              <th>Record</th>
              <th>Reference</th>
              <th>Authorized By</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="mgrAuditTableBody"></tbody>
        </table>
        <div class="admin-table-footer">
          <span class="admin-table-count" id="mgrAuditCount"></span>
          <div class="admin-pager">
            <label class="admin-pager-label">Rows
              <select class="form-select form-select-sm" id="mgrAuditPageSize">
                <option value="10">10</option>
                <option value="15" selected>15</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </label>
            <button type="button" class="btn btn-outline-secondary admin-page-btn" id="mgrAuditPagePrev"><i class="bi bi-chevron-left"></i></button>
            <span class="admin-page-info" id="mgrAuditPageInfo"></span>
            <button type="button" class="btn btn-outline-secondary admin-page-btn" id="mgrAuditPageNext"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>`;

    const rerun = () => { page = 1; displayManagerAudit(); };
    document.getElementById("mgrAuditSearch").addEventListener("input", debounce(rerun, 300));
    ["mgrAuditDateFrom", "mgrAuditDateTo", "mgrAuditUserFilter", "mgrAuditModuleFilter", "mgrAuditActionFilter", "mgrAuditOutcomeFilter"]
        .forEach((id) => document.getElementById(id).addEventListener("change", rerun));
    document.getElementById("mgrBtnRefreshAudit").addEventListener("click", rerun);
    document.getElementById("mgrAuditPageSize").addEventListener("change", rerun);
    document.getElementById("mgrAuditPagePrev").addEventListener("click", () => { if (page > 1) { page--; displayManagerAudit(); } });
    document.getElementById("mgrAuditPageNext").addEventListener("click", () => { if (page < totalPages) { page++; displayManagerAudit(); } });
    document.getElementById("mgrAuditTableBody").addEventListener("click", (e) => {
        const btn = e.target.closest("[data-mgr-audit-view]");
        if (btn) openManagerAuditDetail(parseInt(btn.dataset.mgrAuditView, 10));
    });

    initialized = true;
    populateFilters();
    displayManagerAudit();
}

export function refreshManagerAudit() {
    if (initialized) displayManagerAudit();
}

async function populateFilters() {
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
        fill("mgrAuditModuleFilter", data.modules, "All AR modules");
        fill("mgrAuditActionFilter", data.actions, "All actions");
        const userSelect = document.getElementById("mgrAuditUserFilter");
        userSelect.innerHTML = `<option value="">All users</option>` +
            data.users.map((u) => `<option value="${esc(u.employee_id)}">${esc(u.name)}</option>`).join("");
    } catch (error) {
        // Defaults remain usable.
    }
}

function filterParams() {
    return {
        keyword: document.getElementById("mgrAuditSearch").value.trim(),
        date_from: document.getElementById("mgrAuditDateFrom").value,
        date_to: document.getElementById("mgrAuditDateTo").value,
        employee_id: document.getElementById("mgrAuditUserFilter").value,
        module: document.getElementById("mgrAuditModuleFilter").value,
        action: document.getElementById("mgrAuditActionFilter").value,
        outcome: document.getElementById("mgrAuditOutcomeFilter").value
    };
}

async function displayManagerAudit() {
    const response = await axios.get(`${baseApiUrl}/activity-log.php`, {
        params: { operation: "getAuditLogs", ...filterParams(), page, page_size: document.getElementById("mgrAuditPageSize").value }
    });
    const data = response.data;
    const tbody = document.getElementById("mgrAuditTableBody");
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
                <td><button type="button" class="btn btn-sm btn-outline-primary" data-mgr-audit-view="${entry.activity_id}">View</button></td>`;
            tbody.appendChild(row);
        });
    }
    totalPages = Math.max(1, data.total_pages || 1);
    if (page > totalPages) page = totalPages;
    document.getElementById("mgrAuditCount").textContent = `${data.total ?? 0} record(s)`;
    document.getElementById("mgrAuditPageInfo").textContent = `Page ${page} of ${totalPages}`;
    document.getElementById("mgrAuditPagePrev").disabled = page <= 1;
    document.getElementById("mgrAuditPageNext").disabled = page >= totalPages;
}

// Tier id -> name map for legacy audit snapshots keyed by numeric tier ID.
// price-tiers.php is Administrator-only, so for a Manager session the fetch
// fails and numeric keys fall back to "Tier #<id>".
let mgrTierNames = null;
async function loadMgrTierNames() {
    if (mgrTierNames) return mgrTierNames;
    mgrTierNames = {};
    try {
        const response = await axios.get(`${baseApiUrl}/price-tiers.php`, { params: { operation: "getAllPriceTiers", status: "all" } });
        const rows = Array.isArray(response.data) ? response.data : [];
        rows.forEach((t) => { mgrTierNames[String(t.price_tier_id)] = t.tier_name; });
    } catch (error) {
        // Names unavailable to this role: fall back to "Tier #<id>".
    }
    return mgrTierNames;
}

function mgrTierLabel(key) {
    if (/^\d+$/.test(String(key))) return (mgrTierNames && mgrTierNames[String(key)]) || `Tier #${key}`;
    return String(key);
}

// ---- Audit detail formatting (v4 cleanup; mirrors activityFeed.js) ----
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

// One audit before/after cell as HTML: objects become readable lines (one
// "key: value" per line); arrays render one line per item (allocations
// read "Invoice #7 — ₱116.00"); tier_prices lines read "TierName: ₱55.00"
// and a line that differs from the other side is bolded. Values are
// escaped here.
function formatValue(value, fieldKey, other) {
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
                return `<div class="${changed ? "fw-bold" : ""}">${esc(humanizeAuditLabel(k))}:</div>` + formatValue(v, k, otherVal);
            }
            const line = fieldKey === "tier_prices"
                ? `${mgrTierLabel(k)}: ${v !== "" && Number.isFinite(Number(v)) ? auditMoneyText(v) : auditScalarText(k, v)}`
                : `${humanizeAuditLabel(k)}: ${auditScalarText(k, v)}`;
            return `<div class="${changed ? "fw-bold" : ""}">${esc(line)}</div>`;
        }).join("");
    }
    return esc(auditScalarText(fieldKey, value));
}

async function openManagerAuditDetail(activityId) {
    let modal = document.getElementById("managerAuditDetailModal");
    if (!modal) {
        modal = document.createElement("div");
        modal.className = "modal fade";
        modal.id = "managerAuditDetailModal";
        modal.setAttribute("tabindex", "-1");
        modal.innerHTML = `
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Audit Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" id="managerAuditDetailBody"></div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>`;
        document.body.appendChild(modal);
    }
    const body = document.getElementById("managerAuditDetailBody");
    const response = await axios.get(`${baseApiUrl}/activity-log.php`, {
        params: { operation: "getAuditDetail", activity_id: activityId }
    });
    const data = response.data;
    if (!data.success) {
        body.innerHTML = `<div class="alert alert-danger mb-0">${esc(data.message || "Could not load this audit record.")}</div>`;
    } else {
        const item = data.item;
        const before = item.before_json ? JSON.parse(item.before_json) : null;
        const after = item.after_json ? JSON.parse(item.after_json) : null;
        const keys = [...new Set([...Object.keys(before || {}), ...Object.keys(after || {})])];
        if (keys.includes("tier_prices")) await loadMgrTierNames();
        const diffRows = keys.map((key) => {
            const b = before ? before[key] : undefined;
            const a = after ? after[key] : undefined;
            const changed = auditNorm(b) !== auditNorm(a);
            return `<tr class="${changed ? "table-warning" : ""}"><td class="fw-semibold">${esc(humanizeAuditLabel(key))}</td><td>${formatValue(b, key, a)}</td><td>${formatValue(a, key, b)}</td></tr>`;
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
    bootstrap.Modal.getOrCreateInstance(modal).show();
}
