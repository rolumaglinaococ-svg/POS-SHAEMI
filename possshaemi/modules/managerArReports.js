import { arGet, esc, formatPeso, tableHtml, periodControlsHtml, wirePeriod, badge, overdueBadge, periodRange } from "./arApi.js";
import { showToast } from "./utils.js";
import { creditColumns, bindRowActions, openStatement } from "./managerArCredit.js";

let root, current = "summary", getRange, report = null;

const TABS = { summary: "AR Summary", customers: "Customer Summary", due: "Due Invoices", aging: "AR Aging", collections: "Collections", balances: "Customer Balances & History", reconciliation: "Reconciliation" };

export function refreshArReports() { if (root) return load(); }

/* Deep link used by the Dashboard's reconciliation alert. If the Reports
 * section has not been opened yet, `current` stays set and initArReports
 * lands on the Reconciliation tab. */
export function openReconciliation() {
    current = "reconciliation";
    if (!root) return;
    root.querySelectorAll("[data-tab]").forEach((x) => x.classList.toggle("active", x.dataset.tab === "reconciliation"));
    return setup();
}

export function initArReports() {
    root = document.getElementById("arReportsRoot");
    root.innerHTML = `<ul class="nav nav-pills mb-3 no-print" id="repTabs">${Object.entries(TABS).map(([k, v]) => `<li class="nav-item"><a href="#" class="nav-link ${k === current ? "active" : ""}" data-tab="${k}">${v}</a></li>`).join("")}</ul>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2"><div id="repFilters" class="d-flex flex-wrap gap-2"></div></div>
        <div class="card p-3" id="repBody"></div>`;
    root.querySelectorAll("[data-tab]").forEach((a) => a.addEventListener("click", (e) => {
        e.preventDefault(); current = a.dataset.tab;
        root.querySelectorAll("[data-tab]").forEach((x) => x.classList.toggle("active", x === a));
        setup();
    }));
    return setup();
}

let balanceKeyword = "";

/* ---------------- shared pager (no pager pattern existed in the manager modules) ---------------- */

function pagerHtml(page, total, pageSize) {
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    const start = total === 0 ? 0 : (page - 1) * pageSize + 1;
    const end = Math.min(page * pageSize, total);
    return `<div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">Showing ${start}–${end} of ${total}</div>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" data-page-prev ${page <= 1 ? "disabled" : ""}>Previous</button>
            <span class="btn btn-outline-secondary disabled">Page ${page} of ${totalPages}</span>
            <button class="btn btn-outline-secondary" data-page-next ${page >= totalPages ? "disabled" : ""}>Next</button>
        </div></div>`;
}

function bindPager(body, onPage) {
    body.querySelector("[data-page-prev]")?.addEventListener("click", () => onPage(-1));
    body.querySelector("[data-page-next]")?.addEventListener("click", () => onPage(1));
}

/* ---------------- Customer Summary (per-customer, from extended getCreditMonitor) ---------------- */
/* The API returns the full row set (existing getCreditMonitor pattern), so the
 * status filter, sorting and pagination below run client-side over those rows.
 * The status options are built from the statuses the API actually returned, so
 * no status is ever offered that the data does not contain. */

const CUST_PAGE_SIZE = 20;
let custKeyword = "", custStatus = "all", custSort = "balance_desc", custPage = 1, custRows = [];

const CUST_SORTS = {
    balance_desc: "Outstanding: high to low",
    balance_asc: "Outstanding: low to high",
    oldest_asc: "Oldest invoice: oldest first",
    oldest_desc: "Oldest invoice: newest first",
    name_asc: "Customer: A to Z",
};

const CUST_SUBTITLE = "Per-customer outstanding balances and credit, from actual account records.";

function custOldestVal(r) {
    const v = r.oldest_outstanding_invoice_date ?? r.oldest_invoice_date ?? null;
    return v ? Date.parse(String(v).slice(0, 10)) : NaN;
}

function custFilteredSorted() {
    let rows = [...custRows];
    if (custStatus !== "all") rows = rows.filter((r) => (r.account_status ?? r.credit_status ?? "") === custStatus);
    const byDate = (a, b, dir) => {
        const ta = custOldestVal(a), tb = custOldestVal(b);
        if (isNaN(ta) && isNaN(tb)) return 0;
        if (isNaN(ta)) return 1; // rows with no outstanding invoice date always sort last
        if (isNaN(tb)) return -1;
        return dir * (ta - tb);
    };
    if (custSort === "balance_desc") rows.sort((a, b) => Number(b.current_balance || 0) - Number(a.current_balance || 0));
    else if (custSort === "balance_asc") rows.sort((a, b) => Number(a.current_balance || 0) - Number(b.current_balance || 0));
    else if (custSort === "oldest_asc") rows.sort((a, b) => byDate(a, b, 1));
    else if (custSort === "oldest_desc") rows.sort((a, b) => byDate(a, b, -1));
    else rows.sort((a, b) => String(a.customer_name || "").localeCompare(String(b.customer_name || "")));
    return rows;
}

function custStatusOptions() {
    const values = [...new Set(custRows.map((r) => r.account_status ?? r.credit_status).filter(Boolean))].sort();
    if (custStatus !== "all" && !values.includes(custStatus)) custStatus = "all";
    return `<option value="all">All statuses</option>` + values.map((v) => `<option value="${esc(v)}" ${v === custStatus ? "selected" : ""}>${esc(v)}</option>`).join("");
}

function customerSummaryColumns() {
    const count = (v) => (v === null || v === undefined || v === "") ? "—" : esc(v);
    const dateOrBlank = (v) => v ? esc(String(v).slice(0, 10)) : "";
    return [
        { key: "customer_name", label: "Customer" },
        { key: "account_status", label: "Account status", render: (r) => badge(r.account_status ?? r.credit_status ?? "—") },
        { key: "current_balance", label: "Outstanding balance", type: "money" },
        { key: "unpaid_invoice_count", label: "Unpaid invoices", render: (r) => count(r.unpaid_invoice_count) },
        { key: "overdue_invoice_count", label: "Overdue invoices", render: (r) => count(r.overdue_invoice_count) },
        { key: "oldest_outstanding_invoice_date", label: "Oldest outstanding invoice", render: (r) => dateOrBlank(r.oldest_outstanding_invoice_date ?? r.oldest_invoice_date) },
        { key: "last_payment_date", label: "Last payment", render: (r) => dateOrBlank(r.last_payment_date ?? r.most_recent_payment_date) },
        { key: "credit_limit", label: "Credit limit", type: "money" },
        { key: "available_credit", label: "Available credit", type: "money", render: (r) => Number(r.available_credit) < 0 ? `<span class="text-danger">${formatPeso(r.available_credit)}</span>` : formatPeso(r.available_credit) },
        { key: "act", label: "", type: "html", render: (r) => `<div class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" data-details="${r.ar_account_id}">View details</button></div>` },
    ];
}

function customersTableHtml() {
    const rows = custFilteredSorted();
    const totalPages = Math.max(1, Math.ceil(rows.length / CUST_PAGE_SIZE));
    if (custPage > totalPages) custPage = totalPages;
    const pageRows = rows.slice((custPage - 1) * CUST_PAGE_SIZE, custPage * CUST_PAGE_SIZE);
    return tableHtml(customerSummaryColumns(), pageRows, "No AR customers match the current filter.") + pagerHtml(custPage, rows.length, CUST_PAGE_SIZE);
}

function bindCustomersAfter(body) {
    bindPager(body, (dir) => { custPage += dir; refreshCustomersView(); });
    body.querySelectorAll("[data-details]").forEach((b) => b.addEventListener("click", () => openStatement(b.dataset.details)));
}

/* Re-renders the summary table without refetching (status / sort / page changes). */
function refreshCustomersView() {
    const body = root.querySelector("#repBody");
    body.innerHTML = `<h5 class="mb-1">Customer Summary</h5><div class="small text-muted mb-3">${esc(CUST_SUBTITLE)}</div>` + customersTableHtml();
    bindCustomersAfter(body);
}

/* ---------------- Due invoices (getDueInvoices, server-side filtering + paging) ---------------- */

const DUE_PAGE_SIZE = 20;
let dueStatus = "all_outstanding", dueKeyword = "", dueFrom = "", dueTo = "", duePage = 1;

const DUE_STATUSES = [
    ["all_outstanding", "All outstanding"],
    ["not_yet_due", "Not yet due"],
    ["due_today", "Due today"],
    ["overdue", "Overdue"],
    ["this_week", "This week"],
    ["this_month", "This month"],
    ["custom", "Custom range"],
];

/* The contract describes invoice fields; the existing code names them
 * receipt_number / sale_date / amount / outstanding, so both are accepted
 * here with the existing convention preferred. */
function normalizeDueRow(r) {
    const original = Number(r.original_amount ?? r.amount ?? 0);
    const remaining = Number(r.remaining_balance ?? r.outstanding ?? 0);
    const paid = r.amount_paid !== null && r.amount_paid !== undefined ? Number(r.amount_paid) : Math.max(0, original - remaining);
    const days = Number(r.days_overdue ?? 0);
    const status = r.payment_status ?? (remaining <= 0 ? "Paid" : days > 0 ? "Overdue" : paid > 0 ? "Partial" : "Unpaid");
    return { ...r, _invoice_no: r.invoice_number ?? r.receipt_number ?? "", _invoice_date: r.invoice_date ?? r.sale_date ?? "", _due_date: r.due_date ?? "", _original: original, _paid: paid, _remaining: remaining, _days: days, _status: status };
}

function dueColumns() {
    return [
        { key: "_invoice_no", label: "Invoice number" },
        { key: "customer_name", label: "Customer" },
        { key: "_invoice_date", label: "Invoice date", render: (r) => r._invoice_date ? esc(String(r._invoice_date).slice(0, 10)) : "" },
        { key: "_due_date", label: "Due date", render: (r) => r._due_date ? esc(String(r._due_date).slice(0, 10)) : "" },
        { key: "_original", label: "Original amount", type: "money" },
        { key: "_paid", label: "Amount paid", type: "money" },
        { key: "_remaining", label: "Remaining balance", type: "money" },
        { key: "_days", label: "Days overdue", render: (r) => r._days > 0 ? `<span class="text-danger fw-semibold">${r._days}</span>` : "—" },
        { key: "_status", label: "Payment status", render: (r) => r._days > 0 ? overdueBadge(r._days) : badge(r._status) },
    ];
}

function setup() {
    const filters = root.querySelector("#repFilters");
    if (current === "collections") {
        filters.innerHTML = periodControlsHtml();
        getRange = wirePeriod(filters, load);
    } else if (current === "customers") {
        filters.innerHTML = `<input class="form-control" id="custSearch" placeholder="Search customer..." style="max-width:280px" value="${esc(custKeyword)}">
            <select class="form-select" id="custStatus" style="max-width:200px">${custStatusOptions()}</select>
            <select class="form-select" id="custSort" style="max-width:260px">${Object.entries(CUST_SORTS).map(([k, v]) => `<option value="${k}" ${k === custSort ? "selected" : ""}>${v}</option>`).join("")}</select>`;
        getRange = null;
        let t;
        filters.querySelector("#custSearch").addEventListener("input", (e) => {
            clearTimeout(t);
            t = setTimeout(() => { custKeyword = e.target.value.trim(); load(); }, 300);
        });
        filters.querySelector("#custStatus").addEventListener("change", (e) => { custStatus = e.target.value; custPage = 1; refreshCustomersView(); });
        filters.querySelector("#custSort").addEventListener("change", (e) => { custSort = e.target.value; custPage = 1; refreshCustomersView(); });
    } else if (current === "due") {
        filters.innerHTML = `<select class="form-select" id="dueStatus" style="max-width:200px">${DUE_STATUSES.map(([v, l]) => `<option value="${v}" ${v === dueStatus ? "selected" : ""}>${l}</option>`).join("")}</select>
            <span id="dueCustomRange" class="${dueStatus === "custom" ? "" : "d-none"}"><input type="date" class="form-control d-inline-block" id="dueFrom" style="max-width:170px" value="${esc(dueFrom)}"><input type="date" class="form-control d-inline-block" id="dueTo" style="max-width:170px" value="${esc(dueTo)}"></span>
            <input class="form-control" id="dueSearch" placeholder="Search customer or invoice..." style="max-width:280px" value="${esc(dueKeyword)}">`;
        getRange = null;
        const reload = () => { duePage = 1; load(); };
        filters.querySelector("#dueStatus").addEventListener("change", (e) => {
            dueStatus = e.target.value;
            filters.querySelector("#dueCustomRange").classList.toggle("d-none", dueStatus !== "custom");
            reload();
        });
        filters.querySelector("#dueFrom").addEventListener("change", (e) => { dueFrom = e.target.value; reload(); });
        filters.querySelector("#dueTo").addEventListener("change", (e) => { dueTo = e.target.value; reload(); });
        let t;
        filters.querySelector("#dueSearch").addEventListener("input", (e) => {
            clearTimeout(t);
            t = setTimeout(() => { dueKeyword = e.target.value.trim(); reload(); }, 300);
        });
    } else if (current === "balances") {
        filters.innerHTML = `<input class="form-control" id="repSearch" placeholder="Search customer..." style="max-width:280px" value="${esc(balanceKeyword)}">`;
        getRange = null;
        let t;
        filters.querySelector("#repSearch").addEventListener("input", (e) => {
            clearTimeout(t);
            t = setTimeout(() => { balanceKeyword = e.target.value.trim(); load(); }, 300);
        });
    } else { filters.innerHTML = ""; getRange = null; }
    return load();
}

async function load() {
    const body = root.querySelector("#repBody");
    body.innerHTML = `<div class="text-muted">Loading...</div>`;
    try {
        report = await LOADERS[current]();
    } catch (e) {
        body.innerHTML = `<div class="text-muted">Could not load this report. Check your connection and try again.</div>`;
        showToast("Could not load the report. Check your connection and try again.", "danger");
        return;
    }
    if (!report) { body.innerHTML = `<div class="text-muted">Could not load this report.</div>`; return; }
    const head = `<h5 class="mb-1">${esc(report.title)}</h5>${report.subtitle ? `<div class="small text-muted mb-3">${esc(report.subtitle)}</div>` : `<div class="mb-3"></div>`}`;
    body.innerHTML = report.bodyHtml
        ? head + report.bodyHtml
        : head + (report.headerHtml || "") + tableHtml(report.columns, report.rows, report.empty);
    if (report.after) report.after(body);
}

const money = { type: "money" };

const kpiTile = (label, value, note, cls = "") =>
    `<div class="col-md-6 col-xl-3"><div class="card p-3 h-100">
       <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em">${label}</div>
       <div class="fs-4 fw-bold ${cls}">${value}</div>
       ${note ? `<div class="small text-muted">${note}</div>` : ""}</div></div>`;

const LOADERS = {
    async summary() {
        const d = await arGet("ar-reports.php", "getSummary");
        if (!d.success) { showToast(esc(d.message), "danger"); return null; }
        const a = d.accounts, r = d.receivables;
        const def = (label, value) => `<div class="d-flex justify-content-between py-2 border-bottom"><span>${label}</span><strong>${value}</strong></div>`;
        const bodyHtml = `
        <div class="row g-3 mb-3">
            ${kpiTile("Total Outstanding", formatPeso(r.total_outstanding), `${r.open_invoices} open invoice(s)`, "text-primary")}
            ${kpiTile("Total Overdue", formatPeso(r.total_overdue), `${r.overdue_accounts} account(s)`, "text-danger")}
            ${kpiTile("Collected This Month", formatPeso(d.collected_this_month), "Net of reversals", "text-success")}
            ${kpiTile("Balance Mismatches", String(d.mismatch_count), "")}
        </div>
        <h6 class="mb-1">Accounts Overview</h6>
        <div class="row g-3"><div class="col-lg-6">
            ${def("Active AR accounts", a.active_accounts)}${def("Pending approval", a.pending_accounts)}${def("Accounts on Watch", a.watch_accounts)}${def("Accounts on Hold", a.hold_accounts)}
        </div><div class="col-lg-6">
            ${def("Total credit limits (active)", formatPeso(a.total_credit_limit))}${def("Total outstanding receivables", formatPeso(r.total_outstanding))}${def("Open credit invoices", r.open_invoices)}${def("Accounts with overdue balance", r.overdue_accounts)}
        </div></div>`;
        return { title: "AR Summary", subtitle: `As of ${d.as_of}`, bodyHtml };
    },
    async aging() {
        const d = await arGet("ar-reports.php", "getAging");
        if (!d.success) { showToast(esc(d.message), "danger"); return null; }
        const t = d.totals;
        const rows = [...d.rows, { customer_name: "TOTAL", ...t, _rowClass: "fw-bold table-light" }];
        const total = Number(t.total) || 0;
        const pct = (v) => (total > 0 ? Math.max(0, (Number(v) / total) * 100).toFixed(1) : 0);
        const seg = [["current_amt", "#198754", "Current"], ["d1_30", "#7fb069", "1–30"], ["d31_60", "#e0a458", "31–60"], ["d61_90", "#d97b4f", "61–90"], ["d90_plus", "#dc3545", "90+"]];
        const bar = total > 0
            ? `<div class="d-flex mb-1" style="height:10px;border-radius:6px;overflow:hidden">${seg.map(([k, c]) => `<div style="width:${pct(t[k])}%;background:${c}"></div>`).join("")}</div>
               <div class="small text-muted mb-3">${seg.map(([, c, n]) => `<span class="me-3"><span style="color:${c}">●</span> ${n}</span>`).join("")}</div>`
            : "";
        return { title: "AR Aging", subtitle: `As of ${d.as_of}`,
            headerHtml: bar,
            columns: [{ key: "customer_name", label: "Customer" }, { key: "current_amt", label: "Current", ...money }, { key: "d1_30", label: "1-30", ...money }, { key: "d31_60", label: "31-60", ...money }, { key: "d61_90", label: "61-90", ...money }, { key: "d90_plus", label: "Over 90", ...money }, { key: "total", label: "Total", ...money }],
            rows, empty: "No outstanding receivables." };
    },
    async collections() {
        const [from, to] = getRange();
        const d = await arGet("ar-reports.php", "getCollectionReport", { date_from: from, date_to: to });
        if (!d.success) { showToast(esc(d.message), "danger"); return null; }
        const a = d.actual;
        const methods = d.by_method.map((m) => `${esc(m.type_name)} ${formatPeso(m.amount)}`).join(" &middot; ") || "none";
        return { title: "Collection Report", subtitle: `${from} to ${to}`,
            headerHtml: `<div class="alert alert-success py-2">Net collected <strong>${formatPeso(a.net_collected)}</strong> &middot; Received ${formatPeso(a.gross_collected)} &middot; Reversed/adjusted ${formatPeso(a.reversed)} &middot; ${a.payment_count} payment(s)<br><span class="small">By method: ${methods}</span></div>`,
            columns: [{ key: "payment_date", label: "Date", type: "datetime" }, { key: "customer_name", label: "Customer" }, { key: "type_name", label: "Method" }, { key: "reference_no", label: "Reference" }, { key: "amount_paid", label: "Original", ...money }, { key: "net_amount", label: "Net", ...money }, { key: "payment_status", label: "Status", render: (r) => badge(r.payment_status) }, { key: "recorded_by", label: "Recorded by" }],
            rows: d.payments, empty: "No payments in this period." };
    },
    async customers() {
        const rows = await arGet("ar-reports.php", "getCreditMonitor", { keyword: custKeyword, mode: "all" });
        if (rows && rows.success === false) { showToast(esc(rows.message), "danger"); return null; }
        custRows = Array.isArray(rows) ? rows : [];
        custPage = 1;
        // Rebuild the status options from the statuses the API actually returned.
        const sel = root.querySelector("#custStatus");
        if (sel) sel.innerHTML = custStatusOptions();
        return { title: "Customer Summary", subtitle: CUST_SUBTITLE,
            bodyHtml: customersTableHtml(), after: (body) => bindCustomersAfter(body) };
    },
    async due() {
        if (dueStatus === "custom" && (!dueFrom || !dueTo || dueFrom > dueTo)) {
            return { title: "Due Invoices", subtitle: "Outstanding and overdue invoices, from actual invoice records.",
                bodyHtml: `<div class="text-muted">Choose a from and to date to list invoices.</div>` };
        }
        const params = { status: dueStatus, keyword: dueKeyword, page: duePage, limit: DUE_PAGE_SIZE };
        if (dueStatus === "due_today") { const [f, t] = periodRange("today"); params.from = f; params.to = t; }
        else if (dueStatus === "this_week") { const [f, t] = periodRange("week"); params.from = f; params.to = t; }
        else if (dueStatus === "this_month") { const [f, t] = periodRange("month"); params.from = f; params.to = t; }
        else if (dueStatus === "custom") { params.from = dueFrom; params.to = dueTo; }
        const d = await arGet("ar-reports.php", "getDueInvoices", params);
        if (d && d.success === false) { showToast(esc(d.message), "danger"); return null; }
        const rows = (Array.isArray(d) ? d : d.rows || []).map(normalizeDueRow);
        const total = Array.isArray(d) ? rows.length : Number(d.total ?? rows.length);
        let page = Array.isArray(d) ? duePage : Number(d.page ?? duePage);
        const totalPages = Math.max(1, Math.ceil(total / DUE_PAGE_SIZE));
        if (page > totalPages) { duePage = totalPages; return due(); }
        return { title: "Due Invoices", subtitle: "Outstanding and overdue invoices, from actual invoice records.",
            bodyHtml: tableHtml(dueColumns(), rows, "No invoices match the current filter.") + pagerHtml(page, total, DUE_PAGE_SIZE),
            after: (body) => bindPager(body, (dir) => { duePage = page + dir; load(); }) };
    },
    async balances() {
        const rows = await arGet("ar-reports.php", "getCreditMonitor", { keyword: balanceKeyword, mode: "all" });
        if (rows && rows.success === false) { showToast(esc(rows.message), "danger"); return null; }
        return { title: "Customer Balances & Payment History", subtitle: "",
            columns: creditColumns(true), rows, after: (body) => bindRowActions(body, rows) };
    },
    async reconciliation() {
        const d = await arGet("ar-reports.php", "getReconciliation");
        if (!d.success) { showToast(esc(d.message), "danger"); return null; }
        if (!d.rows.length) {
            return { title: "Balance Reconciliation", subtitle: "",
                bodyHtml: `<div class="alert alert-success d-flex align-items-center gap-2 mb-0"><i class="bi bi-check-circle-fill fs-5"></i><strong>All AR balances agree with their open credit invoices.</strong></div>` };
        }
        return { title: "Balance Reconciliation", subtitle: "",
            headerHtml: `<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <strong>${d.rows.length} account(s)</strong> with a balance mismatch. Nothing was changed automatically.</div>`,
            columns: [{ key: "customer_name", label: "Customer" }, { key: "account_status", label: "Account" }, { key: "current_balance", label: "Stored Balance", ...money }, { key: "invoice_outstanding", label: "Open Invoices", ...money }, { key: "difference", label: "Difference", ...money }],
            rows: d.rows, empty: "" };
    },
};
