import { arGet, arPost, esc, formatPeso, formatDateTime, tableHtml, badge, overdueBadge, openModal } from "./arApi.js";
import { showToast, debounce } from "./utils.js";

let root, mode = "overdue", rows = [];

export function creditColumns(withActions = true) {
    const cols = [
        { key: "customer_name", label: "Customer" },
        { key: "term_name", label: "Term" },
        { key: "credit_limit", label: "Credit Limit", type: "money" },
        { key: "current_balance", label: "Balance", type: "money" },
        { key: "available_credit", label: "Available Credit", type: "money", render: (r) => Number(r.available_credit) < 0 ? `<span class="text-danger">${formatPeso(r.available_credit)}</span>` : formatPeso(r.available_credit) },
        { key: "overdue_amount", label: "Overdue Amount", type: "money" },
        { key: "max_days_overdue", label: "Days Overdue", type: "html", render: (r) => overdueBadge(r.max_days_overdue), csv: (r) => r.max_days_overdue || 0 },
        { key: "earliest_due_date", label: "Earliest Due", type: "date" },
        { key: "credit_status", label: "Credit Status", type: "html", render: (r) => badge(r.credit_status) + (r.credit_hold_source === "AutoOverdue" ? ` <span class="badge text-bg-dark" title="Automatic hold since ${esc(r.credit_hold_at || "")} — an invoice reached 15 days overdue">Auto</span>` : ""), csv: (r) => r.credit_status + (r.credit_hold_source === "AutoOverdue" ? " (Auto)" : "") },
    ];
    if (withActions) cols.push({ key: "act", label: "", type: "html", noExport: true, render: (r) => `<div class="text-end text-nowrap no-print">
        <button class="btn btn-sm btn-outline-primary" data-statement="${r.ar_account_id}" title="Balance & payment history"><i class="bi bi-journal-text"></i></button>
        ${r.credit_status === "Hold" && r.credit_hold_source === "AutoOverdue" ? `<button class="btn btn-sm btn-outline-warning" data-release="${r.ar_account_id}" title="Release automatic hold (AR Manager, reason required)"><i class="bi bi-unlock"></i></button>` : ""}
        <button class="btn btn-sm btn-outline-secondary" data-credit="${r.ar_account_id}" title="Credit limit / restriction"><i class="bi bi-sliders"></i></button></div>` });
    return cols;
}

export function initArCredit() {
    root = document.getElementById("arCreditRoot");
    root.innerHTML = `<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap gap-2"><input class="form-control" id="creditSearch" placeholder="Search customer..." style="max-width:260px">
            <select class="form-select" id="creditMode" style="max-width:260px">
                <option value="overdue" selected>Overdue accounts (most overdue first)</option><option value="all">All active accounts</option>
                <option value="overlimit">Over credit limit</option><option value="hold">On Watch / Hold</option></select></div>
        </div>
        <div class="card p-2" id="creditTable"></div>`;
    root.querySelector("#creditSearch").addEventListener("input", debounce(refreshArCredit, 300));
    root.querySelector("#creditMode").addEventListener("change", (e) => { mode = e.target.value; refreshArCredit(); });
    return refreshArCredit();
}

export async function refreshArCredit() {
    if (!root) return;
    rows = await arGet("ar-reports.php", "getCreditMonitor", { keyword: root.querySelector("#creditSearch").value.trim(), mode });
    if (rows && rows.success === false) { showToast(esc(rows.message), "danger"); rows = []; }
    const box = root.querySelector("#creditTable");
    box.innerHTML = tableHtml(creditColumns(), rows, mode === "overdue" ? "No overdue accounts." : "No accounts found.");
    bindRowActions(box, rows);
}

export function bindRowActions(box, data) {
    box.querySelectorAll("[data-statement]").forEach((b) => b.addEventListener("click", () => openStatement(b.dataset.statement)));
    box.querySelectorAll("[data-credit]").forEach((b) => b.addEventListener("click", () => openCreditSettings(data.find((r) => String(r.ar_account_id) === b.dataset.credit))));
    box.querySelectorAll("[data-release]").forEach((b) => b.addEventListener("click", () => openReleaseHold(data.find((r) => String(r.ar_account_id) === b.dataset.release))));
}

function openReleaseHold(r) {
    if (!r) return;
    const { el, modal } = openModal({ title: `Release Automatic Hold - ${r.customer_name}`,
        body: `<div class="alert alert-warning py-2">This account is on <strong>automatic credit hold</strong> (placed ${esc(formatDateTime(r.credit_hold_at))}) because an invoice reached 15 days overdue. Releasing sets the account back to <strong>Good</strong>. The automatic hold will only return if a <strong>new</strong> invoice reaches 15 days overdue.</div>
            <p class="small text-muted mb-2">Balance ${formatPeso(r.current_balance)} &middot; Overdue ${formatPeso(r.overdue_amount)} &middot; Worst: ${esc(r.max_days_overdue || 0)} days overdue. Only the AR Manager can release an automatic hold, and the release is audit-logged.</p>
            <div class="mb-2"><label class="form-label">Reason for release (required)</label><textarea class="form-control" id="rhReason" rows="2" maxlength="255" placeholder="e.g. Customer paid the overdue invoice / payment plan agreed"></textarea></div>`,
        footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning" id="rhRelease">Release Hold</button>` });
    el.querySelector("#rhRelease").addEventListener("click", async (ev) => {
        const btn = ev.currentTarget; btn.disabled = true;
        try {
            const res = await arPost("ar-accounts.php", "releaseCreditHold", { ar_account_id: Number(r.ar_account_id), reason: el.querySelector("#rhReason").value.trim() });
            showToast(esc(res.message), res.success ? "success" : "danger");
            if (res.success) { modal.hide(); refreshArCredit(); }
        } finally { btn.disabled = false; }
    });
}

/* Running-balance ledger (extended getStatement). Rendered only when the
 * backend supplies ledger rows, so this modal still works against the
 * unextended shape. Rows are shown in the order the API returns them
 * (chronological, per the contract) — never re-sorted or "fixed" here.
 * Per-invoice adjustments have no field in the current statement contract;
 * they appear as ledger transactions (credit/debit memos, adjustments). */
function ledgerSection(d) {
    const ledger = Array.isArray(d.ledger) ? d.ledger : null;
    if (!ledger) return "";
    const a = d.account;
    const computed = d.computed_balance !== undefined && d.computed_balance !== null ? Number(d.computed_balance)
        : (ledger.length ? Number(ledger[ledger.length - 1].running_balance) : Number(a.current_balance));
    const stored = d.stored_balance !== undefined && d.stored_balance !== null ? Number(d.stored_balance) : Number(a.current_balance);
    const match = d.balances_match !== undefined && d.balances_match !== null ? !!d.balances_match : Math.abs(computed - stored) <= 0.005;
    const diff = d.balance_difference !== undefined && d.balance_difference !== null ? Number(d.balance_difference) : computed - stored;
    const cols = [
        { key: "transaction_date", label: "Date", type: "datetime" },
        { key: "reference_no", label: "Reference", render: (r) => esc(r.reference_no ?? r.reference ?? "—") },
        { key: "description", label: "Description" },
        { key: "debit", label: "Debit", type: "money" },
        { key: "credit", label: "Credit", type: "money" },
        { key: "running_balance", label: "Running Balance", type: "money" },
    ];
    return `<h6 class="mt-3">Running Balance Ledger</h6>
        <p class="small text-muted mb-2">A positive balance is the amount the customer still owes. Transactions are listed in chronological order.</p>
        <p class="small mb-2">Computed balance <strong>${formatPeso(computed)}</strong> &middot; Stored balance <strong>${formatPeso(stored)}</strong></p>
        ${match ? "" : `<div class="alert alert-warning py-2">Computed balance differs from the stored balance by ${formatPeso(Math.abs(diff))} — investigate before posting adjustments.</div>`}
        ${tableHtml(cols, ledger, "No ledger transactions.")}`;
}

export async function openStatement(id) {
    const d = await arGet("ar-reports.php", "getStatement", { ar_account_id: id });
    if (!d.success) { showToast(esc(d.message), "danger"); return; }
    const a = d.account;
    const invCols = [{ key: "receipt_number", label: "Receipt" }, { key: "sale_date", label: "Sale Date", type: "date" }, { key: "due_date", label: "Due", type: "date" }, { key: "amount", label: "Amount", type: "money" }, { key: "paid", label: "Paid", type: "money" }, { key: "outstanding", label: "Outstanding", type: "money" }, { key: "days_overdue", label: "Status", type: "html", render: (r) => Number(r.outstanding) > 0 ? overdueBadge(r.days_overdue) : `<span class="badge text-bg-secondary">Paid</span>`, csv: (r) => Number(r.outstanding) > 0 ? r.days_overdue : "Paid" }];
    const payCols = [{ key: "payment_date", label: "Date", type: "datetime" }, { key: "type_name", label: "Method" }, { key: "reference_no", label: "Reference" }, { key: "amount_paid", label: "Original", type: "money" }, { key: "net_amount", label: "Net", type: "money" }, { key: "payment_status", label: "Status", type: "html", render: (r) => badge(r.payment_status), csv: (r) => r.payment_status }];
    const { el } = openModal({ title: `Statement - ${a.customer_name}`, size: "modal-xl",
        body: `<div id="stmtBody"><p class="mb-2">Term <strong>${esc(a.term_name)}</strong> &middot; Limit ${formatPeso(a.credit_limit)} &middot; Balance <strong>${formatPeso(a.current_balance)}</strong> &middot; Available ${formatPeso(a.credit_limit - a.current_balance)} &middot; ${badge(a.credit_status)}</p>
            <h6>Credit Sales</h6>${tableHtml(invCols, d.invoices, "No credit sales.")}<h6 class="mt-3">Payment History</h6>${tableHtml(payCols, d.payments, "No payments yet.")}${ledgerSection(d)}</div>`,
        footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>` });
}

function openCreditSettings(r) {
    if (!r) return;
    const { el, modal } = openModal({ title: `Credit Settings - ${r.customer_name}`,
        body: `${r.credit_hold_source === "AutoOverdue" ? `<div class="alert alert-warning py-2">This account is on <strong>automatic hold</strong> — the credit status cannot be changed here. Use <strong>Release Hold</strong> (unlock button) instead. The credit limit can still be updated.</div>` : ""}
            <p class="small text-muted mb-2">Balance ${formatPeso(r.current_balance)}. Changes need <strong>Administrator approval</strong> and a reason, and are audit-logged.</p>
            <div class="row g-2 mb-2"><div class="col"><label class="form-label">Credit limit</label><input type="number" min="0" step="0.01" class="form-control" id="csLimit" value="${esc(r.credit_limit)}"></div>
            <div class="col"><label class="form-label">Credit status</label><select class="form-select" id="csStatus">${["Good", "Watch", "Hold"].map((s) => `<option ${s === r.credit_status ? "selected" : ""}>${s}</option>`).join("")}</select></div></div>
            <div class="small text-muted mb-2">Hold blocks new Charge-to-AR sales at the POS.</div>
            <div class="mb-2"><label class="form-label">Reason (required)</label><textarea class="form-control" id="csReason" rows="2" maxlength="255"></textarea></div>
            <div class="row g-2"><div class="col"><label class="form-label">Administrator username</label><input class="form-control" id="csUser" autocomplete="off"></div>
            <div class="col"><label class="form-label">Administrator password</label><input type="password" class="form-control" id="csPass" autocomplete="new-password"></div></div>`,
        footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="csSave">Save Changes</button>` });
    el.querySelector("#csSave").addEventListener("click", async (ev) => {
        const btn = ev.currentTarget; btn.disabled = true;
        try {
            const res = await arPost("ar-accounts.php", "updateCreditSettings", { ar_account_id: Number(r.ar_account_id), credit_limit: el.querySelector("#csLimit").value, credit_status: el.querySelector("#csStatus").value, reason: el.querySelector("#csReason").value.trim(), approver_username: el.querySelector("#csUser").value.trim(), approver_password: el.querySelector("#csPass").value });
            showToast(esc(res.message), res.success ? "success" : "danger");
            if (res.success) { modal.hide(); refreshArCredit(); }
        } finally { btn.disabled = false; }
    });
}
