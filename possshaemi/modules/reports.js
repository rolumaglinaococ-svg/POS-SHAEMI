import { baseApiUrl, esc, formatPeso, todayString } from "./utils.js";

const instances = new Map();

export function initReports(containerId, { administrator = false } = {}) {
  if (instances.has(containerId)) return refreshReports(containerId);
  const el = document.getElementById(containerId);
  el.innerHTML = reportMarkup(administrator);
  const state = {
    administrator,
    topLimit: 5,
    salesChart: null,
    productsChart: null,
  };
  instances.set(containerId, state);
  el.querySelector("[data-period]").addEventListener("change", () =>
    setPeriod(el),
  );
  el.querySelector("[data-from]").addEventListener("change", () => {
    el.querySelector("[data-period]").value = "custom";
    refreshReports(containerId);
  });
  el.querySelector("[data-to]").addEventListener("change", () => {
    el.querySelector("[data-period]").value = "custom";
    refreshReports(containerId);
  });
  el.querySelector("[data-refresh]").addEventListener("click", () =>
    refreshReports(containerId),
  );
  el.querySelectorAll("[data-top]").forEach((button) =>
    button.addEventListener("click", () => {
      state.topLimit = Number(button.dataset.top);
      refreshReports(containerId);
    }),
  );
  el.querySelectorAll("[data-product-from],[data-product-to]").forEach(
    (input) =>
      input.addEventListener("change", () => refreshReports(containerId)),
  );
  if (administrator)
    el.querySelector("[data-audit]").addEventListener("change", () =>
      refreshReports(containerId),
    );
  setPeriod(el, false);
  return refreshReports(containerId);
}

function reportMarkup(administrator) {
  return `
  <div class="admin-toolbar">
    <div class="report-title">${administrator ? "Store" : "My"} sales reports</div>
    <div class="admin-toolbar-filters">
      <select class="form-select" data-period>
        <option value="today">Today</option>
        <option value="month">This Month</option>
        <option value="year">This Year</option>
        <option value="specific">Specific Date</option>
        <option value="custom">Custom Range</option>
      </select>
      <input type="date" class="form-control" data-from>
      <input type="date" class="form-control" data-to>
      <button class="btn btn-outline-secondary" data-refresh title="Refresh">
        <i class="bi bi-arrow-clockwise"></i>
      </button>
    </div>
  </div>
  ${
    administrator
      ? `<div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" data-audit id="reportAudit">
          <label class="form-check-label" for="reportAudit">Show void/cancel audit</label>
        </div>`
      : ""
  }
  <div class="kpi-strip" data-summary></div>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-header"><span>Sales trend</span></div>
        <div class="p-3"><canvas data-sales-chart></canvas></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-header"><span>Top-selling products</span>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary active" data-top="5">Top 5</button>
            <button class="btn btn-outline-primary" data-top="10">Top 10</button>
          </div>
        </div>
        <div class="p-3">
          <div class="d-flex gap-2 mb-2">
            <input type="date" class="form-control form-control-sm" data-product-from>
            <input type="date" class="form-control form-control-sm" data-product-to>
          </div>
          <canvas data-products-chart></canvas>
          <div class="table-responsive mt-2">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Product</th>
                  <th class="num">Qty</th>
                  <th class="num">Sales</th>
                </tr>
              </thead>
              <tbody data-products></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="panel">
    <div class="panel-header"><span>Sales by payment method</span></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Method</th><th class="num">Amount</th></tr></thead>
        <tbody data-payments></tbody>
        <tfoot data-payments-total></tfoot>
      </table>
    </div>
  </div>
  <div data-audit-results class="mt-3"></div>
`;
}

function setPeriod(el, refresh = true) {
  const period = el.querySelector("[data-period]").value;
  const today = todayString();
  let from = el.querySelector("[data-from]").value || today,
    to = el.querySelector("[data-to]").value || today;
  if (period === "today") from = to = today;
  if (period === "month") {
    from = `${today.slice(0, 7)}-01`;
    to = today;
  }
  if (period === "year") {
    from = `${today.slice(0, 4)}-01-01`;
    to = today;
  }
  if (period === "specific") to = from;
  el.querySelector("[data-from]").value = from;
  el.querySelector("[data-to]").value = to;
  if (!el.querySelector("[data-product-from]").value) {
    el.querySelector("[data-product-from]").value = from;
    el.querySelector("[data-product-to]").value = to;
  }
  if (refresh) refreshReports(el.id);
}

async function refreshReports(containerId) {
  const el = document.getElementById(containerId),
    state = instances.get(containerId);
  const from = el.querySelector("[data-from]").value,
    to = el.querySelector("[data-to]").value;
  const params = {
    date_from: from,
    date_to: to,
    product_date_from: el.querySelector("[data-product-from]").value || from,
    product_date_to: el.querySelector("[data-product-to]").value || to,
    include_audit:
      state.administrator && el.querySelector("[data-audit]").checked ? 1 : 0,
  };
  const { data } = await axios.get(`${baseApiUrl}/reports.php`, { params });
  if (!data.success) return;
  render(el, state, data);
}
function render(el, state, data) {
  const s = data.summary;
  el.querySelector("[data-summary]").innerHTML = [
    ["Total Sales", formatPeso(s.total_sales)],
    ["Transactions", s.transaction_count],
    ["Items Sold", s.items_sold],
    ["Average Sale", formatPeso(s.average_transaction)],
    ["Discounts", formatPeso(s.total_discount)],
  ]
    .map(
      ([l, v]) =>
        `<div class="kpi"><div class="kpi-label">${l}</div><div class="kpi-value">${v}</div></div>`,
    )
    .join("");
  const paymentMethods = [
    ...data.payment_methods,
    { type_name: "AR Charges", amount: data.ar_sales },
  ];
  const paymentsTotal = paymentMethods.reduce(
    (sum, p) => sum + Number(p.amount || 0),
    0,
  );
  el.querySelector("[data-payments]").innerHTML = paymentMethods.length
    ? paymentMethods
        .map(
          (p) =>
            `<tr><td>${esc(p.type_name)}</td><td class="num">${formatPeso(p.amount)}</td></tr>`,
        )
        .join("")
    : `<tr><td colspan="2" class="text-muted text-center">No payment records in this period.</td></tr>`;
  el.querySelector("[data-payments-total]").innerHTML = paymentMethods.length
    ? `<tr><td class="fw-semibold">Total</td><td class="num fw-semibold">${formatPeso(paymentsTotal)}</td></tr>`
    : "";
  // el.querySelector("[data-ar-collections]").textContent = formatPeso(
  //   data.ar_collections,
  // );
  const products = data.top_products.slice(0, state.topLimit);
  el.querySelector("[data-products]").innerHTML = products.length
    ? products
        .map(
          (p) =>
            `<tr><td>${esc(p.product_name)}</td><td class="text-end">${p.quantity_sold}</td><td class="text-end">${formatPeso(p.sales_amount)}</td></tr>`,
        )
        .join("")
    : `<tr><td colspan="3" class="text-muted text-center">No product sales.</td></tr>`;
  el.querySelectorAll("[data-top]").forEach((b) =>
    b.classList.toggle("active", Number(b.dataset.top) === state.topLimit),
  );
  drawCharts(el, state, data.trend, products);
  const audit = el.querySelector("[data-audit-results]");
  audit.innerHTML = data.audit
    ? `<div class="panel"><div class="panel-header"><span>Voided / cancelled transactions</span></div>${data.audit.length ? `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Receipt</th><th>Date</th><th>Status</th><th class="num">Amount</th><th>Reason</th></tr></thead><tbody>${data.audit.map((x) => `<tr><td>${esc(x.receipt_number)}</td><td>${esc(x.sale_date)}</td><td>${esc(x.sale_status)}</td><td class="num">${formatPeso(x.total_amount)}</td><td>${esc(x.void_reason || "")}</td></tr>`).join("")}</tbody></table></div>` : `<div class="p-3 text-muted small">No voided or cancelled transactions.</div>`}</div>`
    : "";
}
function drawCharts(el, state, trend, products) {
  if (!window.Chart) return;
  state.salesChart?.destroy();
  state.productsChart?.destroy();
  state.salesChart = new Chart(el.querySelector("[data-sales-chart]"), {
    type: "line",
    data: {
      labels: trend.map((x) => x.sale_day),
      datasets: [
        {
          label: "Sales",
          data: trend.map((x) => x.total_sales),
          borderColor: "#0d6efd",
          backgroundColor: "rgba(13,110,253,.12)",
          fill: true,
          tension: 0.25,
        },
      ],
    },
    options: { responsive: true },
  });
  state.productsChart = new Chart(el.querySelector("[data-products-chart]"), {
    type: "bar",
    data: {
      labels: products.map((x) => x.product_name),
      datasets: [
        {
          label: "Quantity sold",
          data: products.map((x) => x.quantity_sold),
          backgroundColor: "#198754",
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      plugins: { legend: { display: false } },
    },
  });
}
