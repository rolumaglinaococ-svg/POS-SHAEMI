import {
  arGet,
  esc,
  formatPeso,
  formatDateTime,
  tableHtml,
  periodControlsHtml,
  wirePeriod,
  badge,
  overdueBadge,
} from "./arApi.js";
import { showToast } from "./utils.js";
import { openReconciliation } from "./managerArReports.js";

/* AR Dashboard — Option A "Balanced Grid".
 * KPIs: Total Outstanding (computed from open invoices, memos included),
 * Overdue, Collections Today (net allocation movements recorded today),
 * Expected Collections for the selected period (follows the filter).
 * Recent Payments is paged on the server (5 per page). */

let root, getRange, chart = null, view = "daily", lastData = null, payPage = 1;

const PERIOD_LABELS = { today: "Today", week: "This Week", month: "This Month", custom: "Custom Range" };

function periodLabel() {
  const sel = root.querySelector("[data-period]");
  return PERIOD_LABELS[sel ? sel.value : "month"] || "This Month";
}

function gotoSection(name) {
  const link = document.querySelector(`.sidebar-nav [data-section="${name}"]`);
  if (link) link.click();
}

export function initArDashboard() {
  root = document.getElementById("arDashboardRoot");
  root.innerHTML = `
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
      <div class="d-flex flex-wrap gap-2 align-items-center">${periodControlsHtml()}
        <button class="btn btn-outline-secondary" data-refresh title="Refresh"><i class="bi bi-arrow-clockwise"></i></button></div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary" data-qa="record"><i class="bi bi-cash-coin"></i> Record Payment</button>
        <button class="btn btn-outline-primary" data-qa="overdue">Overdue Accounts</button>
        <button class="btn btn-outline-primary" data-qa="collections">Collection Report</button>
      </div>
    </div>
    <div id="arDashContent"><div class="text-muted py-4">Loading...</div></div>`;
  getRange = wirePeriod(root, () => refreshArDashboard());
  root.querySelector("[data-refresh]").addEventListener("click", refreshArDashboard);
  root.querySelector('[data-qa="record"]').addEventListener("click", () => {
    gotoSection("arPayments");
    setTimeout(() => {
      const el = document.getElementById("payStep1");
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 200);
  });
  root.querySelector('[data-qa="overdue"]').addEventListener("click", () => gotoSection("arCredit"));
  root.querySelector('[data-qa="collections"]').addEventListener("click", () => gotoSection("arReports"));
  return refreshArDashboard();
}

export async function refreshArDashboard() {
  if (!root) return;
  payPage = 1;
  const [from, to] = getRange();
  try {
    const data = await arGet("ar-reports.php", "getDashboard", { date_from: from, date_to: to });
    if (!data.success) {
      showToast(esc(data.message || "Unable to load dashboard."), "danger");
      return;
    }
    lastData = data;
    render(data);
    loadRecent();
  } catch (e) {
    /* global interceptor shows the error */
  }
}

function kpiCard(label, value, sub, valueClass = "") {
  return `<div class="col-md-6 col-xl-3"><div class="card p-3 h-100">
    <div class="text-muted small text-uppercase fw-semibold" style="letter-spacing:.04em">${label}</div>
    <div class="fs-4 fw-bold ${valueClass}">${value}</div>
    <div class="small text-muted">${sub}</div></div></div>`;
}

function render(d) {
  const t = d.totals, td = d.collected_today, x = d.expected;
  const overduePct = Number(t.total_outstanding) > 0 ? ((Number(t.total_overdue) / Number(t.total_outstanding)) * 100).toFixed(1) : "0.0";
  const label = periodLabel();

  const alert = d.mismatch_count > 0
    ? `<div class="alert alert-warning d-flex flex-wrap gap-2 justify-content-between align-items-center py-2">
         <span><i class="bi bi-exclamation-triangle"></i> <strong>${d.mismatch_count} AR account(s)</strong> have a stored balance that does not match their open invoices. Nothing was changed automatically.</span>
         <button class="btn btn-sm btn-outline-dark" data-recon>View Reconciliation <i class="bi bi-arrow-right"></i></button></div>`
    : "";

  const methodRows = [...d.actual_by_method];
  const methodTotal = methodRows.reduce((s, m) => s + (parseFloat(m.amount) || 0), 0);
  if (methodRows.length) methodRows.push({ type_name: "Total (period)", amount: methodTotal, _rowClass: "fw-bold table-light" });

  const overdueRows = (d.overdue_accounts_list || []).map((r) => ({
    ...r,
    days: overdueBadge(r.max_days_overdue),
  }));
  const upcomingRows = d.upcoming_due || [];

  document.getElementById("arDashContent").innerHTML = `${alert}
    <div class="row g-3 mb-3">
      ${kpiCard("Total Outstanding Receivables", formatPeso(t.total_outstanding), `${t.accounts_with_balance} account(s) with a balance`, "text-primary")}
      ${kpiCard("Overdue Receivables", formatPeso(t.total_overdue), `${t.overdue_accounts} account(s) · ${overduePct}% of outstanding`, "text-danger")}
      ${kpiCard("Collections Today", formatPeso(td.net_collected), `${td.payment_count} payment(s) today · net of reversals`, "text-success")}
      ${kpiCard(`Expected Collections · ${label}`, formatPeso(x.due_in_range), `Due dates in period · ${formatPeso(x.overdue_before_range)} already overdue before it`)}
    </div>
    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="card p-3 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Collections Trend</h6>
          <div class="btn-group btn-group-sm"><button class="btn btn-outline-primary ${view === "daily" ? "active" : ""}" data-view="daily">Daily</button><button class="btn btn-outline-primary ${view === "monthly" ? "active" : ""}" data-view="monthly">Monthly</button></div></div>
        <div style="height:260px"><canvas id="arTrendChart"></canvas></div>
        <div class="small text-muted mt-1">Solid bars = actual collections (received). Dashed line = expected by due date — not money received.</div></div></div>
      <div class="col-lg-4"><div class="card p-3 h-100"><h6>Collections by Method · ${esc(label)}</h6>
        ${tableHtml([{ key: "type_name", label: "Method" }, { key: "amount", label: "Net", type: "money" }], methodRows, "No collections in this period.")}</div></div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-lg-6"><div class="card p-3 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Overdue Accounts</h6><a href="#" class="small fw-semibold text-decoration-none" data-goto="arCredit">View all <i class="bi bi-arrow-right"></i></a></div>
        ${tableHtml(
          [
            { key: "customer_name", label: "Customer" },
            { key: "overdue_amount", label: "Overdue", type: "money" },
            { key: "oldest_due", label: "Oldest Due", type: "date" },
            { key: "days", label: "Days", render: (r) => r.days },
          ],
          overdueRows, "No overdue accounts.")}
        </div></div>
      <div class="col-lg-6"><div class="card p-3 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Upcoming Due Dates · Next 7 Days</h6><a href="#" class="small fw-semibold text-decoration-none" data-goto="arCredit">View all <i class="bi bi-arrow-right"></i></a></div>
        ${tableHtml(
          [
            { key: "customer_name", label: "Customer" },
            { key: "receipt_number", label: "Receipt" },
            { key: "due_date", label: "Due", type: "date" },
            { key: "outstanding", label: "Amount", type: "money" },
          ],
          upcomingRows, "Nothing falls due in the next 7 days.")}
        </div></div>
    </div>
    <div class="card p-3"><h6>Recent Payments</h6><div id="dashRecent"><div class="text-muted py-3">Loading...</div></div></div>`;

  const recon = document.querySelector("#arDashContent [data-recon]");
  if (recon) recon.addEventListener("click", () => {
    gotoSection("arReports");
    setTimeout(() => openReconciliation(), 80);
  });
  document.querySelectorAll("#arDashContent [data-goto]").forEach((a) =>
    a.addEventListener("click", (e) => {
      e.preventDefault();
      gotoSection(a.dataset.goto);
    }),
  );
  document.querySelectorAll("#arDashContent [data-view]").forEach((b) =>
    b.addEventListener("click", () => {
      view = b.dataset.view;
      render(lastData);
      loadRecent();
    }),
  );
  drawChart(d);
}

function drawChart(d) {
  const rows = view === "daily" ? d.daily : d.monthly;
  const labels = rows.map((r) => r.date || r.month);
  if (chart) {
    chart.destroy();
    chart = null;
  }
  const canvas = document.getElementById("arTrendChart");
  if (window.Chart && canvas) {
    chart = new Chart(canvas, {
      data: {
        labels,
        datasets: [
          { type: "bar", label: "Actual collections", data: rows.map((r) => r.actual), backgroundColor: "rgba(25,135,84,.75)" },
          { type: "line", label: "Expected (by due date)", data: rows.map((r) => r.expected), borderColor: "#6c757d", borderDash: [6, 4], backgroundColor: "transparent", pointRadius: 2 },
        ],
      },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } },
    });
  }
}

/* ---------------- Recent Payments (server-paged) ---------------- */
const payStatus = (r) =>
  badge(r.payment_status === "Reversed" ? "Reversed" : Number(r.has_reversals) === 1 ? "Adjusted" : r.payment_status || "Posted");

async function loadRecent() {
  const box = document.getElementById("dashRecent");
  if (!box) return;
  const res = await arGet("ar-payments.php", "getPaymentsPage", { page: payPage, page_size: 5 });
  if (!res.success) {
    box.innerHTML = `<div class="text-muted py-3">${esc(res.message || "Unable to load payments.")}</div>`;
    return;
  }
  payPage = res.page;
  const rows = res.rows.map((r) => ({
    ...r,
    when: formatDateTime(r.payment_date),
    statusHtml: payStatus(r),
  }));
  const from = res.total === 0 ? 0 : (res.page - 1) * res.page_size + 1;
  const to = Math.min(res.total, res.page * res.page_size);
  box.innerHTML = `${tableHtml(
    [
      { key: "when", label: "Date" },
      { key: "customer_name", label: "Customer" },
      { key: "type_name", label: "Method" },
      { key: "reference_no", label: "Reference" },
      { key: "net_amount", label: "Amount", type: "money" },
      { key: "statusHtml", label: "Status", render: (r) => r.statusHtml },
    ],
    rows, "No payments recorded yet.",
  )}
    <div class="d-flex justify-content-between align-items-center mt-2">
      <span class="small text-muted">Showing ${from}–${to} of ${res.total} payment(s)</span>
      <span><button class="btn btn-sm btn-outline-secondary" data-pg="prev" ${res.page <= 1 ? "disabled" : ""}>← Prev</button>
      <button class="btn btn-sm btn-outline-secondary" data-pg="next" ${res.page >= res.total_pages ? "disabled" : ""}>Next →</button></span>
    </div>`;
  box.querySelector('[data-pg="prev"]').addEventListener("click", () => {
    if (payPage > 1) {
      payPage--;
      loadRecent();
    }
  });
  box.querySelector('[data-pg="next"]').addEventListener("click", () => {
    payPage++;
    loadRecent();
  });
}
