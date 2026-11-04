import {
  arGet,
  arPost,
  esc,
  formatPeso,
  formatDateTime,
  openModal,
  cents,
} from "./arApi.js";
import { showToast, debounce } from "./utils.js";

/* Credit / Debit Memos.
 * A memo affects balances only after an Administrator/Supervisor approves it.
 * Lists are paginated client-side (25 per page by default). */

const CATEGORIES = {
  credit: ["Returned goods", "Damaged goods", "Price correction", "Overcharge", "Other"],
  debit: ["Omitted charge", "Late fee", "Other"],
};
const STATUS_BADGE = {
  Pending: "text-bg-warning",
  Approved: "text-bg-success",
  Rejected: "text-bg-secondary",
  Reversed: "text-bg-dark",
};
const statusBadge = (s) =>
  `<span class="badge ${STATUS_BADGE[s] || "text-bg-secondary"}">${esc(s)}</span>`;

let root,
  tab = "credit",
  rows = [],
  page = 1,
  pageSize = 25,
  keyword = "",
  statusFilter = "";

export function refreshArMemos() {
  if (root) return load();
}

export async function initArMemos() {
  root = document.getElementById("arMemosRoot");
  root.innerHTML = `
    <ul class="nav nav-pills mb-3" id="memoTabs">
      <li class="nav-item"><a href="#" class="nav-link active" data-mtab="credit">Credit Memos</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-mtab="debit">Debit Memos</a></li>
    </ul>
    <div class="card p-3">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap gap-2">
          <input class="form-control" id="memoSearch" placeholder="Search customer, reference, receipt..." style="max-width:280px">
          <select class="form-select" id="memoStatus" style="max-width:180px">
            <option value="">All statuses</option><option>Pending</option><option>Approved</option><option>Rejected</option><option>Reversed</option>
          </select>
        </div>
        <button class="btn btn-primary" id="memoNew"><i class="bi bi-plus-lg"></i> New <span id="memoNewKind">Credit</span> Memo</button>
      </div>
      <div class="small text-muted mb-2">Memos change a customer's balance <strong>only after approval</strong> by an Administrator or Supervisor. They are adjustments, not payments, and never count as collections.</div>
      <div id="memoTable"></div>
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-3">
        <div class="d-flex align-items-center gap-2">
          <label class="small text-muted mb-0" for="memoPageSize">Rows per page</label>
          <select class="form-select form-select-sm" id="memoPageSize" style="width:auto">
            <option>10</option><option selected>25</option><option>50</option><option>100</option>
          </select>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted" id="memoPageInfo"></span>
          <button class="btn btn-sm btn-outline-secondary" id="memoPrev">Prev</button>
          <button class="btn btn-sm btn-outline-secondary" id="memoNext">Next</button>
        </div>
      </div>
    </div>`;

  root.querySelectorAll("[data-mtab]").forEach((a) =>
    a.addEventListener("click", (e) => {
      e.preventDefault();
      tab = a.dataset.mtab;
      page = 1;
      root.querySelectorAll("[data-mtab]").forEach((x) => x.classList.toggle("active", x === a));
      root.querySelector("#memoNewKind").textContent = tab === "credit" ? "Credit" : "Debit";
      load();
    }),
  );
  root.querySelector("#memoSearch").addEventListener(
    "input",
    debounce((e) => {
      keyword = e.target.value.trim().toLowerCase();
      page = 1;
      render();
    }, 250),
  );
  root.querySelector("#memoStatus").addEventListener("change", (e) => {
    statusFilter = e.target.value;
    page = 1;
    render();
  });
  root.querySelector("#memoPageSize").addEventListener("change", (e) => {
    pageSize = Number(e.target.value);
    page = 1;
    render();
  });
  root.querySelector("#memoPrev").addEventListener("click", () => {
    if (page > 1) {
      page--;
      render();
    }
  });
  root.querySelector("#memoNext").addEventListener("click", () => {
    if (page < pageCount()) {
      page++;
      render();
    }
  });
  root.querySelector("#memoNew").addEventListener("click", openCreate);
  return load();
}

async function load() {
  const res = await arGet("ar-memos.php", "getMemos", { type: tab });
  if (res && res.success === false) {
    showToast(esc(res.message), "danger");
    rows = [];
  } else rows = res;
  page = 1;
  render();
}

const filtered = () =>
  rows.filter((r) => {
    if (statusFilter && r.status !== statusFilter) return false;
    if (!keyword) return true;
    const hay = `${r.customer_name} ${r.reference_no || ""} ${r.receipt_number} ${r.category || ""} ${r.reason || ""}`.toLowerCase();
    return hay.includes(keyword);
  });
const pageCount = () => Math.max(1, Math.ceil(filtered().length / pageSize));

function render() {
  const list = filtered();
  const totalPages = pageCount();
  if (page > totalPages) page = totalPages;
  const slice = list.slice((page - 1) * pageSize, page * pageSize);
  const box = root.querySelector("#memoTable");
  if (!list.length) {
    box.innerHTML = `<div class="text-center text-muted py-4">No ${tab} memos found.</div>`;
  } else {
    box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
      <thead><tr><th>Reference</th><th>Date</th><th>Customer</th><th>Receipt</th><th>Category</th><th class="text-end">Amount</th><th>Status</th><th>Created by</th><th>Approved by</th><th></th></tr></thead>
      <tbody>${slice
        .map(
          (r) => `<tr>
          <td class="fw-semibold">${esc(r.reference_no || "—")}</td>
          <td>${esc(formatDateTime(r.memo_date))}</td>
          <td>${esc(r.customer_name)}</td>
          <td>${esc(r.receipt_number)}</td>
          <td>${esc(r.category || "")}</td>
          <td class="text-end">${formatPeso(r.amount)}</td>
          <td>${statusBadge(r.status)}</td>
          <td>${r.created_by ? esc(r.created_by_name || "") : `<span class="badge text-bg-dark">System</span>`}</td>
          <td>${esc(r.approved_by_name || "—")}</td>
          <td><div class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" data-view="${r.memo_id}" title="View / audit trail"><i class="bi bi-eye"></i></button>
            ${r.status === "Pending" ? `<button class="btn btn-sm btn-outline-success" data-approve="${r.memo_id}" title="Approve (Administrator/Supervisor)"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-sm btn-outline-secondary" data-reject="${r.memo_id}" title="Reject"><i class="bi bi-x-lg"></i></button>` : ""}
            ${r.status === "Approved" ? `<button class="btn btn-sm btn-outline-danger" data-reverse="${r.memo_id}" title="Reverse (Administrator/Supervisor)"><i class="bi bi-arrow-counterclockwise"></i></button>` : ""}
          </div></td></tr>`,
        )
        .join("")}</tbody></table></div>`;
  }
  root.querySelector("#memoPageInfo").textContent = list.length
    ? `Page ${page} of ${totalPages} · ${list.length} record${list.length === 1 ? "" : "s"}`
    : "";
  root.querySelector("#memoPrev").disabled = page <= 1;
  root.querySelector("#memoNext").disabled = page >= totalPages;

  box.querySelectorAll("[data-view]").forEach((b) => b.addEventListener("click", () => openView(b.dataset.view)));
  box.querySelectorAll("[data-approve]").forEach((b) => b.addEventListener("click", () => openApprove(b.dataset.approve)));
  box.querySelectorAll("[data-reject]").forEach((b) => b.addEventListener("click", () => openReject(b.dataset.reject)));
  box.querySelectorAll("[data-reverse]").forEach((b) => b.addEventListener("click", () => openReverse(b.dataset.reverse)));
}

const findRow = (id) => rows.find((r) => String(r.memo_id) === String(id));
const memoSummary = (r) =>
  `<p class="mb-1"><strong>${esc(r.reference_no || "")}</strong> &middot; ${esc(r.customer_name)} &middot; Receipt ${esc(r.receipt_number)}</p>
   <p class="small text-muted mb-0">${esc(r.category || "")} &middot; ${formatPeso(r.amount)} &middot; ${esc(r.reason || "")}</p>`;

/* ---------------- searchable picker (same pattern as Payments) ---------------- */
function makeSearchable(input, listEl, items, onPick) {
  let open = false, highlight = -1, filtered = items;
  const render = () => {
    listEl.innerHTML = filtered.length
      ? filtered.map((it, i) => `<button type="button" class="combo-item ${i === highlight ? "active" : ""}" data-i="${i}"><span class="combo-item-label">${esc(it.label)}</span>${it.sub ? `<span class="combo-item-sub">${esc(it.sub)}</span>` : ""}</button>`).join("")
      : `<div class="combo-empty">No matches found.</div>`;
    listEl.querySelectorAll(".combo-item").forEach((b) =>
      b.addEventListener("mousedown", (e) => {
        e.preventDefault();
        pick(filtered[Number(b.dataset.i)]);
      }),
    );
  };
  const show = () => { filtered = items; highlight = -1; render(); listEl.classList.remove("d-none"); open = true; };
  const hide = () => { listEl.classList.add("d-none"); open = false; };
  const pick = (it) => { if (!it) return; input.value = it.label; input.dataset.value = it.value; hide(); onPick(it); };
  input.addEventListener("focus", show);
  input.addEventListener("click", show);
  input.addEventListener("blur", () => setTimeout(hide, 120));
  input.addEventListener("input", () => {
    const q = input.value.trim().toLowerCase();
    input.dataset.value = "";
    filtered = items.filter((it) => it.label.toLowerCase().includes(q));
    highlight = filtered.length ? 0 : -1;
    render();
    listEl.classList.remove("d-none");
    open = true;
    onPick(null);
  });
  input.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      if (!open) return show();
      highlight = e.key === "ArrowDown" ? Math.min(highlight + 1, filtered.length - 1) : Math.max(highlight - 1, 0);
      render();
    } else if (e.key === "Enter" && open && highlight >= 0) {
      e.preventDefault();
      pick(filtered[highlight]);
    } else if (e.key === "Escape") hide();
  });
  return {
    setItems(next) { items = next; if (open) render(); },
    value() { return input.dataset.value || ""; },
  };
}

/* ---------------- create ---------------- */
async function openCreate() {
  const kind = tab;
  const { el, modal } = openModal({
    title: `New ${kind === "credit" ? "Credit" : "Debit"} Memo`,
    size: "modal-lg",
    body: `
      <p class="small text-muted">A ${kind} memo ${kind === "credit" ? "reduces" : "increases"} what the customer owes on one invoice. It takes effect <strong>only after approval</strong> by an Administrator or Supervisor.</p>
      <div class="mb-2"><label class="form-label">Customer</label>
        <div class="combo-wrap"><input class="form-control" id="mcCustomer" placeholder="Type customer name to search..." autocomplete="off"><div class="combo-list d-none" id="mcCustomerList"></div></div></div>
      <div class="mb-2"><label class="form-label">Invoice (credit sale)</label>
        <div class="combo-wrap"><input class="form-control" id="mcInvoice" placeholder="Select a customer first..." autocomplete="off" disabled><div class="combo-list d-none" id="mcInvoiceList"></div></div>
        <div class="small text-muted mt-1" id="mcInvoiceInfo"></div></div>
      <div class="row g-2 mb-2">
        <div class="col-md-6"><label class="form-label">Category</label><select class="form-select" id="mcCategory">${CATEGORIES[kind].map((c) => `<option>${esc(c)}</option>`).join("")}</select></div>
        <div class="col-md-6"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="mcAmount" min="0.01" step="0.01" placeholder="0.00"></div></div>
      </div>
      <div class="mb-1"><label class="form-label">Reason (required)</label><textarea class="form-control" id="mcReason" rows="2" maxlength="255" placeholder="Why is this adjustment being made?"></textarea></div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="mcSubmit">Create Memo (Pending)</button>`,
  });

  const custCombo = makeSearchable(el.querySelector("#mcCustomer"), el.querySelector("#mcCustomerList"), [], async (it) => {
    const invInput = el.querySelector("#mcInvoice");
    invCombo.setItems([]);
    invInput.value = "";
    invInput.dataset.value = "";
    el.querySelector("#mcInvoiceInfo").textContent = "";
    if (!it) {
      invInput.disabled = true;
      invInput.placeholder = "Select a customer first...";
      return;
    }
    const res = await arGet("ar-memos.php", "getMemoInvoices", { ar_account_id: it.value });
    const invoices = (res.invoices || []).filter((i) => kind === "debit" || cents(i.outstanding) > 0);
    invCombo.setItems(
      invoices.map((i) => ({
        value: String(i.invoice_id),
        label: i.receipt_number,
        sub: cents(i.outstanding) > 0 ? `due ${esc(i.due_date || "")} · owes ${formatPeso(i.outstanding)}` : "PAID IN FULL",
        inv: i,
      })),
    );
    invInput.disabled = false;
    invInput.placeholder = invoices.length ? "Type receipt number to search..." : "No credit invoices for this customer";
  });
  const invCombo = makeSearchable(el.querySelector("#mcInvoice"), el.querySelector("#mcInvoiceList"), [], (it) => {
    const info = el.querySelector("#mcInvoiceInfo");
    if (!it) {
      info.textContent = "";
      return;
    }
    const i = it.inv;
    info.innerHTML = `Invoice ${formatPeso(i.amount)} &middot; Due ${esc(i.due_date || "")} &middot; Outstanding <strong>${formatPeso(i.outstanding)}</strong>${cents(i.approved_credit_memos) ? ` &middot; Approved credit memos ${formatPeso(i.approved_credit_memos)}` : ""}${cents(i.approved_debit_memos) ? ` &middot; Approved debit memos ${formatPeso(i.approved_debit_memos)}` : ""}`;
  });

  const customers = await arGet("ar-memos.php", "getMemoCustomers");
  custCombo.setItems(
    (customers || []).map((c) => ({
      value: String(c.ar_account_id),
      label: c.customer_name,
      sub: `balance ${formatPeso(c.current_balance)}`,
    })),
  );

  el.querySelector("#mcSubmit").addEventListener("click", async (ev) => {
    const btn = ev.currentTarget;
    const payload = {
      type: kind,
      invoice_id: Number(invCombo.value() || 0),
      amount: parseFloat(el.querySelector("#mcAmount").value) || 0,
      category: el.querySelector("#mcCategory").value,
      reason: el.querySelector("#mcReason").value.trim(),
    };
    if (!payload.invoice_id) return showToast("Select a customer and an invoice.", "danger");
    if (!(payload.amount > 0)) return showToast("Enter an amount greater than zero.", "danger");
    if (payload.reason.length < 5) return showToast("A reason of at least 5 characters is required.", "danger");
    btn.disabled = true;
    try {
      const res = await arPost("ar-memos.php", "createMemo", payload);
      showToast(esc(res.message), res.success ? "success" : "danger");
      if (res.success) {
        modal.hide();
        await load();
      }
    } finally {
      btn.disabled = false;
    }
  });
}

/* ---------------- view / approve / reject / reverse ---------------- */
async function openView(id) {
  const r = findRow(id);
  if (!r) return;
  const audit = await arGet("ar-memos.php", "getMemoAudit", { type: tab, memo_id: id });
  const auditRows = (audit.rows || [])
    .map((a) => `<tr><td>${esc(formatDateTime(a.created_at))}</td><td>${esc(a.action)}</td><td>${esc(a.performed_by || "")}</td><td>${esc(a.authorized_by || "—")}</td><td>${esc(a.reason || "")}</td></tr>`)
    .join("");
  openModal({
    title: `${tab === "credit" ? "Credit" : "Debit"} Memo ${esc(r.reference_no || "")}`,
    size: "modal-lg",
    body: `<p class="mb-1"><strong>${esc(r.customer_name)}</strong> &middot; Receipt ${esc(r.receipt_number)} &middot; Due ${esc(r.due_date || "")}</p>
      <p class="mb-1">Amount <strong>${formatPeso(r.amount)}</strong> &middot; ${statusBadge(r.status)}</p>
      <p class="mb-1"><span class="text-muted">Category:</span> ${esc(r.category || "")}</p>
      <p class="mb-1"><span class="text-muted">Reason:</span> ${esc(r.reason || "")}</p>
      <p class="mb-1"><span class="text-muted">Created:</span> ${esc(formatDateTime(r.memo_date))} by ${r.created_by ? esc(r.created_by_name || "") : "System (automatic late fee policy)"}</p>
      <p class="mb-3"><span class="text-muted">Approved:</span> ${r.approved_at ? `${esc(formatDateTime(r.approved_at))} by ${esc(r.approved_by_name || "")}` : "—"}</p>
      <h6>Audit Trail</h6>
      ${auditRows ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Approved by</th><th>Reason</th></tr></thead><tbody>${auditRows}</tbody></table></div>` : `<div class="text-muted small">No audit entries.</div>`}`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`,
  });
}

function approverFieldsHtml(note) {
  return `<p class="small text-muted">${note} This needs an <strong>Administrator or Supervisor</strong> — enter their username and password. (An Administrator who is signed in may leave these blank.)</p>
    <div class="row g-2"><div class="col"><label class="form-label">Approver username</label><input class="form-control" id="mmUser" autocomplete="off"></div>
    <div class="col"><label class="form-label">Approver password</label><input type="password" class="form-control" id="mmPass" autocomplete="new-password"></div></div>`;
}

async function openApprove(id) {
  const r = findRow(id);
  if (!r) return;
  const { el, modal } = openModal({
    title: `Approve ${tab === "credit" ? "Credit" : "Debit"} Memo`,
    body: `${memoSummary(r)}
      <p class="mt-2">Approving applies this memo to the customer's balance immediately${tab === "credit" ? ` (balance goes <strong>down</strong> by ${formatPeso(r.amount)})` : ` (balance goes <strong>up</strong> by ${formatPeso(r.amount)})`}.</p>
      ${approverFieldsHtml("Approval is the moment the adjustment takes financial effect.")}`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" id="mmApprove">Approve & Apply</button>`,
  });
  el.querySelector("#mmApprove").addEventListener("click", async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const res = await arPost("ar-memos.php", "approveMemo", {
        type: tab,
        memo_id: Number(id),
        approver_username: el.querySelector("#mmUser").value.trim(),
        approver_password: el.querySelector("#mmPass").value,
      });
      showToast(esc(res.message), res.success ? "success" : "danger");
      if (res.success) {
        modal.hide();
        await load();
      }
    } finally {
      btn.disabled = false;
    }
  });
}

async function openReject(id) {
  const r = findRow(id);
  if (!r) return;
  const { el, modal } = openModal({
    title: `Reject ${tab === "credit" ? "Credit" : "Debit"} Memo`,
    body: `${memoSummary(r)}
      <p class="small text-muted mt-2">A rejected memo never affects any balance.</p>
      <div class="mb-1"><label class="form-label">Reason (required)</label><textarea class="form-control" id="mmReason" rows="2" maxlength="255"></textarea></div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" id="mmReject">Reject Memo</button>`,
  });
  el.querySelector("#mmReject").addEventListener("click", async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const res = await arPost("ar-memos.php", "rejectMemo", {
        type: tab,
        memo_id: Number(id),
        reason: el.querySelector("#mmReason").value.trim(),
      });
      showToast(esc(res.message), res.success ? "success" : "danger");
      if (res.success) {
        modal.hide();
        await load();
      }
    } finally {
      btn.disabled = false;
    }
  });
}

async function openReverse(id) {
  const r = findRow(id);
  if (!r) return;
  const { el, modal } = openModal({
    title: `Reverse ${tab === "credit" ? "Credit" : "Debit"} Memo`,
    body: `${memoSummary(r)}
      <p class="small text-muted mt-2">Reversing undoes this memo's effect on the customer's balance. The memo stays on record as Reversed.</p>
      ${approverFieldsHtml("Reversal changes a posted balance, so it is approval-gated like the original approval.")}
      <div class="mb-1 mt-2"><label class="form-label">Reason (required)</label><textarea class="form-control" id="mmReason" rows="2" maxlength="255"></textarea></div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" id="mmReverse">Reverse Memo</button>`,
  });
  el.querySelector("#mmReverse").addEventListener("click", async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const res = await arPost("ar-memos.php", "reverseMemo", {
        type: tab,
        memo_id: Number(id),
        reason: el.querySelector("#mmReason").value.trim(),
        approver_username: el.querySelector("#mmUser").value.trim(),
        approver_password: el.querySelector("#mmPass").value,
      });
      showToast(esc(res.message), res.success ? "success" : "danger");
      if (res.success) {
        modal.hide();
        await load();
      }
    } finally {
      btn.disabled = false;
    }
  });
}
