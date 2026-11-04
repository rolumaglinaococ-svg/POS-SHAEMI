import {
  arGet,
  arPost,
  esc,
  formatPeso,
  formatDateTime,
  tableHtml,
  badge,
  overdueBadge,
  openModal,
  cents,
  periodControlsHtml,
  wirePeriod,
} from "./arApi.js";
import { showToast } from "./utils.js";

let root,
  state = {
    methods: [],
    accounts: [],
    invoices: [],
    account: null,
    accountId: "",
    token: "",
    busy: false,
  },
  getRange,
  historyRows = [];

const newToken = () =>
  crypto.randomUUID
    ? crypto.randomUUID()
    : String(Date.now()) +
      Math.random().toString(16).slice(2, 12).padEnd(10, "0");

/* ------------------------------------------------------------------ */
/* Searchable dropdown (combobox). items: [{value, label, sub}]        */
/* ------------------------------------------------------------------ */
function makeSearchable(input, listEl, items, onPick) {
  let open = false,
    highlight = -1,
    filtered = items;

  const render = () => {
    listEl.innerHTML = filtered.length
      ? filtered
          .map(
            (it, i) =>
              `<button type="button" class="combo-item ${i === highlight ? "active" : ""}" data-i="${i}">
                 <span class="combo-item-label">${esc(it.label)}</span>
                 ${it.sub ? `<span class="combo-item-sub">${esc(it.sub)}</span>` : ""}
               </button>`,
          )
          .join("")
      : `<div class="combo-empty">No matches found.</div>`;
    listEl.querySelectorAll(".combo-item").forEach((b) =>
      b.addEventListener("mousedown", (e) => {
        e.preventDefault();
        pick(filtered[Number(b.dataset.i)]);
      }),
    );
  };
  const show = () => {
    filtered = items;
    highlight = -1;
    render();
    listEl.classList.remove("d-none");
    open = true;
  };
  const hide = () => {
    listEl.classList.add("d-none");
    open = false;
  };
  const pick = (it) => {
    if (!it) return;
    input.value = it.label;
    input.dataset.value = it.value;
    hide();
    onPick(it);
  };

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
      highlight =
        e.key === "ArrowDown"
          ? Math.min(highlight + 1, filtered.length - 1)
          : Math.max(highlight - 1, 0);
      render();
    } else if (e.key === "Enter" && open && highlight >= 0) {
      e.preventDefault();
      pick(filtered[highlight]);
    } else if (e.key === "Escape") {
      hide();
    }
  });

  return {
    setItems(next) {
      items = next;
      if (open) render();
    },
    clear() {
      input.value = "";
      input.dataset.value = "";
    },
    value() {
      return input.dataset.value || "";
    },
    setValue(v, label) {
      input.dataset.value = v || "";
      input.value = label || "";
    },
  };
}

/* ------------------------------------------------------------------ */
/* FIFO allocation — oldest due first, automatically.                  */
/* ------------------------------------------------------------------ */
function computeFifo(amountCents) {
  let left = amountCents;
  const rows = state.invoices.map((inv) => {
    const out = cents(inv.outstanding);
    const applied = Math.max(0, Math.min(left, out));
    left -= applied;
    return { ...inv, applied, remaining: out - applied };
  });
  return { rows, unapplied: left };
}
const totalOutstanding = () =>
  state.invoices.reduce((s, i) => s + cents(i.outstanding), 0);

export async function initArPayments() {
  root = document.getElementById("arPaymentsRoot");
  state.token = newToken();
  root.innerHTML = `
    <div class="card p-3 mb-3">
      <div class="pay-step"><span class="pay-step-num">1</span><h6 class="mb-0">Select customer</h6></div>
      <div class="row g-2 mb-1">
        <div class="col-md-6">
          <div class="combo-wrap">
            <input class="form-control" id="payAccountSearch" placeholder="Type customer name to search..." autocomplete="off">
            <div class="combo-list d-none" id="payAccountList"></div>
          </div>
        </div>
        <div class="col-md-6 d-flex align-items-center"><div id="payAccountInfo" class="small text-muted"></div></div>
      </div>

      <div id="payTotalDebt" class="alert alert-light border mt-2 d-none">
        <div class="small text-muted">TOTAL DEBT</div>
        <div class="fs-2 fw-bold" id="payTotalDebtAmount">₱0.00</div>
        <div class="small text-muted" id="payTotalDebtSub"></div>
      </div>

      <div class="pay-step mt-3"><span class="pay-step-num">2</span><h6 class="mb-0">Payment details</h6></div>
      <div class="row g-2 mb-1">
        <div class="col-md-3"><label class="form-label small mb-1">Payment amount</label>
          <div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="payAmount" min="0.01" step="0.01" placeholder="0.00"></div>
        </div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm mb-1" id="payFull" disabled>Pay full balance</button></div>
        <div class="col-md-3"><label class="form-label small mb-1">Payment method</label><select class="form-select" id="payMethod"></select></div>
        <div class="col-md-3"><label class="form-label small mb-1">Reference no. <span id="payRefHint" class="text-muted"></span></label><input class="form-control" id="payRef" maxlength="50"></div>
      </div>
      <div class="small text-muted mb-2">The payment is applied automatically to the oldest due balance first.</div>

      <div class="pay-step mt-3"><span class="pay-step-num">3</span><h6 class="mb-0">Review breakdown</h6></div>
      <div id="payInvoices"></div>
      <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div id="paySummary" class="fw-semibold"></div>
        <button class="btn btn-success btn-lg" id="paySubmit" disabled><i class="bi bi-check-circle"></i> Record Payment</button>
      </div>
    </div>

    <div class="card p-3">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
        <h6 class="mb-0">Payment History</h6>
        <div class="d-flex flex-wrap gap-2 align-items-center">${periodControlsHtml()}
          <div class="combo-wrap" style="min-width:220px">
            <input class="form-control" id="histAccountSearch" placeholder="All customers" autocomplete="off">
            <div class="combo-list d-none" id="histAccountList"></div>
          </div>
        </div>
      </div>
      <div id="payHistory"></div>
    </div>`;

  getRange = wirePeriod(root, loadHistory);

  state.payCombo = makeSearchable(
    root.querySelector("#payAccountSearch"),
    root.querySelector("#payAccountList"),
    [],
    (it) => onAccountPick(it),
  );
  state.histCombo = makeSearchable(
    root.querySelector("#histAccountSearch"),
    root.querySelector("#histAccountList"),
    [{ value: "", label: "All customers", sub: "" }],
    () => loadHistory(),
  );

  root.querySelector("#payMethod").addEventListener("change", () => {
    updateMethodHint();
    recompute();
  });
  root.querySelector("#payRef").addEventListener("input", () => recompute());
  root.querySelector("#payAmount").addEventListener("input", () => recompute());
  root.querySelector("#payFull").addEventListener("click", () => {
    if (!state.account) return;
    root.querySelector("#payAmount").value = (totalOutstanding() / 100).toFixed(2);
    recompute();
  });
  root.querySelector("#paySubmit").addEventListener("click", openReview);

  const methods = await arGet("ar-payments.php", "getPaymentMethods");
  state.methods = methods;
  root.querySelector("#payMethod").innerHTML = methods
    .map(
      (m) =>
        `<option value="${m.payment_type_id}" data-name="${esc(m.type_name)}">${esc(m.type_name)}</option>`,
    )
    .join("");
  updateMethodHint();
  renderInvoices();
  await reloadAll();
}

export async function reloadAll() {
  await loadAccounts();
  await loadHistory();
}

async function loadAccounts() {
  const keepId = state.accountId;
  state.accounts = await arGet("ar-payments.php", "getPayableAccounts");
  const items = state.accounts.map((a) => ({
    value: String(a.ar_account_id),
    label: a.customer_name,
    sub: `owes ${formatPeso(a.outstanding)}`,
  }));
  state.payCombo.setItems(items);
  state.histCombo.setItems([{ value: "", label: "All customers", sub: "" }, ...items]);
  if (keepId && state.accounts.some((a) => String(a.ar_account_id) === keepId)) {
    const acc = state.accounts.find((a) => String(a.ar_account_id) === keepId);
    state.payCombo.setValue(keepId, acc.customer_name);
    await loadInvoices(keepId);
  } else {
    state.accountId = "";
    state.account = null;
    state.invoices = [];
    renderInvoices();
  }
}

function isCash() {
  const o = root.querySelector("#payMethod").selectedOptions[0];
  return o && o.dataset.name.toLowerCase() === "cash";
}
function updateMethodHint() {
  root.querySelector("#payRefHint").textContent = isCash() ? "(optional)" : "(required)";
}

async function onAccountPick(it) {
  if (!it || !it.value) {
    state.accountId = "";
    state.account = null;
    state.invoices = [];
    root.querySelector("#payAmount").value = "";
    renderInvoices();
    return;
  }
  state.accountId = it.value;
  root.querySelector("#payAmount").value = "";
  await loadInvoices(it.value);
}

async function loadInvoices(id) {
  const res = await arGet("ar-payments.php", "getOpenInvoices", { ar_account_id: id });
  state.invoices = res.invoices || [];
  state.account = res.account;
  renderInvoices();
}

function renderInvoices() {
  const info = root.querySelector("#payAccountInfo");
  const box = root.querySelector("#payInvoices");
  root.querySelector("#payFull").disabled = !state.account;
  if (!state.account) {
    info.textContent = "";
    root.querySelector("#payTotalDebt")?.classList.add("d-none");
    box.innerHTML = `<div class="text-muted small py-2">Select a customer to see their unpaid credit sales.</div>`;
    recompute();
    return;
  }
  const overdueCount = state.invoices.filter((i) => Number(i.days_overdue) > 0).length;
  info.innerHTML = `Balance <strong>${formatPeso(state.account.current_balance)}</strong> &middot; Credit limit ${formatPeso(state.account.credit_limit)} &middot; ${badge(state.account.credit_status)}${overdueCount ? ` &middot; <span class="text-danger fw-semibold">${overdueCount} overdue</span>` : ""}`;
  // Large Total Debt display for this customer, in the Payments
  // section. Before this, the debt was only the small Balance line
  // above. Total Debt here is the sum of unpaid invoices (what Pay
  // full balance actually pays), with the stored account balance,
  // limit, and available shown underneath so the two never get mixed
  // up if they ever differ.
  const debtBox = root.querySelector("#payTotalDebt");
  if (debtBox) {
    const totalDebt = totalOutstanding() / 100;
    const available =
      Number(state.account.credit_limit) - Number(state.account.current_balance);
    debtBox.classList.remove("d-none");
    root.querySelector("#payTotalDebtAmount").textContent =
      formatPeso(totalDebt);
    root.querySelector("#payTotalDebtSub").innerHTML =
      `Account balance ${formatPeso(state.account.current_balance)} &middot; Credit limit ${formatPeso(state.account.credit_limit)} &middot; Available ${formatPeso(available)}${overdueCount ? ` &middot; <span class="text-danger fw-semibold">${overdueCount} overdue</span>` : ""}`;
  }
  recompute();
}

function problems(amount) {
  const list = [];
  if (state.account && amount > totalOutstanding())
    list.push(`Payment exceeds the customer's balance of ${formatPeso(totalOutstanding() / 100)}. Overpayments are not accepted.`);
  if (state.account && amount > 0 && !isCash() && root.querySelector("#payRef").value.trim() === "")
    list.push("A reference number is required for non-cash payments.");
  return list;
}

function recompute() {
  if (!root || !state.account) {
    if (root) {
      const s = root.querySelector("#paySummary");
      if (s) s.textContent = "";
      const b = root.querySelector("#paySubmit");
      if (b) b.disabled = true;
    }
    return;
  }
  const amount = cents(root.querySelector("#payAmount").value);
  const box = root.querySelector("#payInvoices");
  const summary = root.querySelector("#paySummary");
  const list = problems(amount);
  const rows = computeFifo(amount).rows;

  box.innerHTML = `<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Receipt</th><th>Sale date</th><th>Due</th><th>Status</th><th class="text-end">Invoice</th><th class="text-end">Outstanding</th><th class="text-end">This payment</th><th class="text-end">Balance after</th></tr></thead>
    <tbody>${rows
      .map(
        (i) => `<tr class="${i.applied > 0 ? "" : "text-muted"}">
          <td>${esc(i.receipt_number)}</td><td>${esc(String(i.sale_date).slice(0, 10))}</td><td>${esc(i.due_date || "")}</td><td>${overdueBadge(i.days_overdue)}</td>
          <td class="text-end">${formatPeso(i.amount)}</td><td class="text-end">${formatPeso(i.outstanding)}</td>
          <td class="text-end fw-semibold">${i.applied > 0 ? formatPeso(i.applied / 100) : "—"}</td>
          <td class="text-end">${i.applied > 0 ? (i.remaining === 0 ? `<span class="badge text-bg-success">Paid in full</span>` : formatPeso(i.remaining / 100)) : formatPeso(i.outstanding)}</td></tr>`,
      )
      .join("")}</tbody></table></div>`;

  const newBalance = totalOutstanding() - amount;
  summary.innerHTML =
    amount > 0
      ? `Payment ${formatPeso(amount / 100)} &middot; New balance <span class="${newBalance === 0 ? "text-success" : ""}">${formatPeso(Math.max(newBalance, 0) / 100)}</span>${list.length ? `<div class="small text-danger fw-normal">${list.map(esc).join("<br>")}</div>` : ""}`
      : `<span class="text-muted fw-normal">Type the payment amount — it will be applied to the oldest due balance first.</span>`;
  root.querySelector("#paySubmit").disabled = state.busy || !(amount > 0 && list.length === 0);
}

/* ---------------- review + submit ---------------- */

function currentAllocations() {
  const amount = cents(root.querySelector("#payAmount").value);
  return computeFifo(amount)
    .rows.filter((r) => r.applied > 0)
    .map((r) => ({ invoice_id: Number(r.invoice_id), amount: r.applied / 100 }));
}

function openReview() {
  const amount = cents(root.querySelector("#payAmount").value);
  const rows = computeFifo(amount).rows.filter((r) => r.applied > 0);
  const method = root.querySelector("#payMethod").selectedOptions[0].dataset.name;
  const ref = root.querySelector("#payRef").value.trim();
  const custName = state.accounts.find((a) => String(a.ar_account_id) === state.accountId)?.customer_name || "";
  const { el, modal } = openModal({
    title: "Review Payment",
    size: "modal-lg",
    body: `<p class="mb-2"><strong>${esc(custName)}</strong> &middot; ${esc(method)}${ref ? " &middot; Ref " + esc(ref) : ""}</p>
      <div class="table-responsive"><table class="table table-sm align-middle mb-2">
        <thead><tr><th>Receipt</th><th>Due</th><th class="text-end">Applied</th><th class="text-end">Balance after</th></tr></thead>
        <tbody>${rows.map((r) => `<tr><td>${esc(r.receipt_number)}</td><td>${esc(r.due_date || "")}</td><td class="text-end">${formatPeso(r.applied / 100)}</td><td class="text-end">${r.remaining === 0 ? `<span class="badge text-bg-success">Paid in full</span>` : formatPeso(r.remaining / 100)}</td></tr>`).join("")}</tbody>
      </table></div>
      <div class="d-flex justify-content-between fs-5"><span>Amount to record</span><strong>${formatPeso(amount / 100)}</strong></div>
      <div class="d-flex justify-content-between text-muted"><span>New balance</span><span>${formatPeso((totalOutstanding() - amount) / 100)}</span></div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Back</button><button class="btn btn-success" id="reviewConfirm"><i class="bi bi-check-circle"></i> Confirm Payment</button>`,
  });
  el.querySelector("#reviewConfirm").addEventListener("click", async (ev) => {
    ev.currentTarget.disabled = true;
    const ok = await submitPayment();
    if (ok) modal.hide();
    else ev.currentTarget.disabled = false;
  });
}

async function submitPayment() {
  if (state.busy) return false;
  const amount = cents(root.querySelector("#payAmount").value);
  const allocations = currentAllocations();
  state.busy = true;
  recompute();
  try {
    const res = await arPost("ar-payments.php", "recordPayment", {
      ar_account_id: Number(state.accountId),
      payment_type_id: Number(root.querySelector("#payMethod").value),
      reference_no: root.querySelector("#payRef").value.trim(),
      amount: amount / 100,
      allocations,
      token: state.token,
    });
    showToast(esc(res.message), res.success ? "success" : "danger");
    if (res.success) {
      state.token = newToken(); // a new token only after success, so a retry of a lost response cannot double-post
      root.querySelector("#payAmount").value = "";
      root.querySelector("#payRef").value = "";
      const receipt = res.receipt;
      await reloadAll();
      if (receipt) showReceipt(receipt, false);
      return true;
    }
    if (/already recorded/i.test(res.message)) await reloadAll();
    return false;
  } finally {
    state.busy = false;
    recompute();
  }
}

/* ---------------- receipt (cashier .receipt style) ---------------- */

function receiptHtml(r, reprint) {
  const lineRows = r.lines
    .map(
      (l) => `<tr><td>${esc(l.receipt_number)}</td><td class="text-end">${formatPeso(l.applied)}</td><td class="text-end">${l.remaining_label ? esc(l.remaining_label) : formatPeso(l.remaining_after)}</td></tr>`,
    )
    .join("");
  const balanceBlock =
    r.balance_before !== undefined && r.balance_before !== null
      ? `<table class="w-100"><tr><td>Balance before</td><td class="text-end">${formatPeso(r.balance_before)}</td></tr><tr><td>Balance after</td><td class="text-end">${formatPeso(r.balance_after)}</td></tr></table>`
      : `<div class="d-flex justify-content-between"><span>Account balance today</span><span>${formatPeso(r.account_balance)}</span></div>`;
  return `<div class="receipt"><div class="text-center"><strong>GROCERY STORE</strong></div><div class="text-center">AR PAYMENT RECEIPT</div>${
    reprint ? `<div class="text-center"><strong>*** REPRINT ***</strong></div>` : ""
  }<hr>
    <div>Customer: ${esc(r.customer_name)}</div><div>Date: ${esc(formatDateTime(r.payment_date))}</div><div>Received by: ${esc(r.recorded_by || "")}</div><div>Method: ${esc(r.type_name)}${r.reference_no ? ` &middot; Ref ${esc(r.reference_no)}` : ""}</div><hr>
    <table class="w-100"><thead><tr><th>RECEIPT</th><th class="text-end">APPLIED</th><th class="text-end">BALANCE</th></tr></thead><tbody>${lineRows}</tbody></table><hr>
    <div class="d-flex justify-content-between"><strong>AMOUNT PAID</strong><strong>${formatPeso(r.amount)}</strong></div>
    ${balanceBlock}<hr><div class="text-center">THANK YOU</div></div>`;
}

function showReceipt(r, reprint) {
  const { el } = openModal({
    title: reprint ? "Payment Receipt (Reprint)" : "Payment Recorded",
    body: `<div id="arReceiptContent">${receiptHtml(r, reprint)}</div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" id="receiptPrint"><i class="bi bi-printer"></i> Print Receipt</button>`,
  });
  el.querySelector("#receiptPrint").addEventListener("click", () => window.print());
}

async function reprintReceipt(id) {
  const d = await arGet("ar-payments.php", "getPaymentDetail", { payment_id: id });
  if (!d.success) {
    showToast(esc(d.message), "danger");
    return;
  }
  const p = d.payment;
  const netTotal = d.allocations.reduce((s, a) => s + cents(a.net_amount), 0);
  showReceipt(
    {
      customer_name: p.customer_name,
      payment_date: p.payment_date,
      recorded_by: p.recorded_by,
      type_name: p.type_name,
      reference_no: p.reference_no,
      amount: netTotal / 100,
      account_balance: p.account_balance,
      lines: d.allocations.map((a) => ({
        receipt_number: a.receipt_number,
        applied: a.net_amount,
        remaining_label: "—",
      })),
    },
    true,
  );
}

/* ---------------- history ---------------- */

function historyColumns() {
  return [
    { key: "payment_date", label: "Date", type: "datetime" },
    { key: "customer_name", label: "Customer" },
    { key: "type_name", label: "Method" },
    { key: "reference_no", label: "Reference" },
    { key: "amount_paid", label: "Original", type: "money" },
    { key: "net_amount", label: "Net", type: "money" },
    { key: "payment_status", label: "Status", type: "html", render: (r) => badge(r.payment_status) },
    { key: "recorded_by", label: "Recorded by" },
    {
      key: "act",
      label: "",
      type: "html",
      render: (r) => `<div class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" data-view="${r.payment_id}" title="Details"><i class="bi bi-eye"></i></button>
            <button class="btn btn-sm btn-outline-secondary" data-reprint="${r.payment_id}" title="Print receipt"><i class="bi bi-printer"></i></button>
            ${
              r.payment_status !== "Reversed"
                ? `<button class="btn btn-sm btn-outline-warning" data-adjust="${r.payment_id}" title="Adjust"><i class="bi bi-pencil-square"></i></button>
            <button class="btn btn-sm btn-outline-danger" data-reverse="${r.payment_id}" title="Reverse"><i class="bi bi-arrow-counterclockwise"></i></button>`
                : ""
            }</div>`,
    },
  ];
}

async function loadHistory() {
  if (!getRange || !state.histCombo) return;
  const [from, to] = getRange();
  const res = await arGet("ar-payments.php", "getPayments", {
    date_from: from,
    date_to: to,
    ar_account_id: state.histCombo.value() || 0,
    limit: 500,
  });
  if (res && res.success === false) {
    showToast(esc(res.message), "danger");
    return;
  }
  historyRows = res;
  const box = root.querySelector("#payHistory");
  box.innerHTML = tableHtml(historyColumns(), historyRows, "No AR payments in this period.");
  box.querySelectorAll("[data-view]").forEach((b) => b.addEventListener("click", () => showDetail(b.dataset.view)));
  box.querySelectorAll("[data-reprint]").forEach((b) => b.addEventListener("click", () => reprintReceipt(b.dataset.reprint)));
  box.querySelectorAll("[data-reverse]").forEach((b) => b.addEventListener("click", () => openCorrection(b.dataset.reverse, "Reversal")));
  box.querySelectorAll("[data-adjust]").forEach((b) => b.addEventListener("click", () => openCorrection(b.dataset.adjust, "Adjustment")));
}

async function showDetail(id) {
  const d = await arGet("ar-payments.php", "getPaymentDetail", { payment_id: id });
  if (!d.success) {
    showToast(esc(d.message), "danger");
    return;
  }
  const p = d.payment;
  const { el } = openModal({
    title: `Payment Details`,
    size: "modal-lg",
    body: `
        <p class="mb-2"><strong>${esc(p.customer_name)}</strong> &middot; ${esc(p.type_name)}${p.reference_no ? " &middot; Ref " + esc(p.reference_no) : ""} &middot; ${formatPeso(p.amount_paid)} ${badge(p.payment_status)}<br>
        <span class="text-muted small">${esc(formatDateTime(p.payment_date))} &middot; recorded by ${esc(p.recorded_by || "")}</span></p>
        <h6>Allocation</h6>${tableHtml(
          [
            { key: "receipt_number", label: "Receipt" },
            { key: "due_date", label: "Due", type: "date" },
            { key: "applied_amount", label: "Applied", type: "money" },
            { key: "net_amount", label: "Net now", type: "money" },
          ],
          d.allocations,
        )}
        <h6 class="mt-3">Reversals / Adjustments</h6>${tableHtml(
          [
            { key: "created_at", label: "When", type: "datetime" },
            { key: "reversal_type", label: "Type" },
            { key: "amount", label: "Amount", type: "money" },
            { key: "reason", label: "Reason" },
            { key: "performed_by", label: "By" },
            { key: "authorized_by", label: "Approved by" },
          ],
          d.reversals,
          "None.",
        )}`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-primary" id="detailReprint"><i class="bi bi-printer"></i> Print Receipt</button>`,
  });
  el.querySelector("#detailReprint").addEventListener("click", () => reprintReceipt(id));
}

async function openCorrection(id, kind) {
  const d = await arGet("ar-payments.php", "getPaymentDetail", { payment_id: id });
  if (!d.success) {
    showToast(esc(d.message), "danger");
    return;
  }
  const open = d.allocations.filter((a) => cents(a.net_amount) > 0);
  if (open.length === 0) {
    showToast("Nothing remains to correct on this payment.", "danger");
    return;
  }
  const adjustRows =
    kind === "Adjustment"
      ? `<div class="table-responsive mb-2"><table class="table table-sm"><thead><tr><th>Receipt</th><th class="text-end">Applied now</th><th style="width:170px">Take back</th></tr></thead><tbody>
        ${open.map((a) => `<tr><td>${esc(a.receipt_number)}</td><td class="text-end">${formatPeso(a.net_amount)}</td><td><input type="number" min="0" step="0.01" max="${a.net_amount}" class="form-control form-control-sm adj-input" data-id="${a.invoice_id}"></td></tr>`).join("")}</tbody></table></div>`
      : "";
  const { el, modal } = openModal({
    title: `${kind === "Reversal" ? "Reverse" : "Adjust"} Payment`,
    body: `<p class="small text-muted">${kind === "Reversal" ? "The whole payment is undone and its amount returns to the customer's balance." : "Take back part of the payment; the amount returns to the customer's balance."} The original payment stays on record. This needs approval from an <strong>Administrator or Supervisor</strong> and is audit-logged.</p>
            ${adjustRows}
            <div class="mb-2"><label class="form-label">Reason (required)</label><textarea class="form-control" id="corrReason" rows="2" maxlength="255"></textarea></div>
            <div class="row g-2"><div class="col"><label class="form-label">Approver username</label><input class="form-control" id="corrUser" autocomplete="off"></div>
            <div class="col"><label class="form-label">Approver password</label><input type="password" class="form-control" id="corrPass" autocomplete="new-password"></div></div>`,
    footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" id="corrSubmit">${kind === "Reversal" ? "Reverse Payment" : "Save Adjustment"}</button>`,
  });
  el.querySelector("#corrSubmit").addEventListener("click", async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      const payload = {
        payment_id: Number(id),
        reason: el.querySelector("#corrReason").value.trim(),
        approver_username: el.querySelector("#corrUser").value.trim(),
        approver_password: el.querySelector("#corrPass").value,
      };
      if (kind === "Adjustment")
        payload.adjustments = [...el.querySelectorAll(".adj-input")]
          .map((i) => ({ invoice_id: Number(i.dataset.id), amount: cents(i.value) / 100 }))
          .filter((r) => r.amount > 0);
      const res = await arPost("ar-payments.php", kind === "Reversal" ? "reversePayment" : "adjustPayment", payload);
      showToast(esc(res.message), res.success ? "success" : "danger");
      if (res.success) {
        modal.hide();
        await reloadAll();
      }
    } finally {
      btn.disabled = false;
    }
  });
}
