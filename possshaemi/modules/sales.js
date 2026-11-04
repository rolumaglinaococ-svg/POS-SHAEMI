import { baseApiUrl, showToast, confirmRestore, esc, debounce, formatPeso, formatDateTime, refreshPager } from "./utils.js";

let currentStatus = "completed";

export function initSales() {
    document.getElementById("saleStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displaySales();
    });
    document.getElementById("saleSearch").addEventListener("input", debounce(displaySales, 300));
    document.getElementById("saleDateFilter").addEventListener("change", displaySales);
    document.getElementById("btnClearSaleDate").addEventListener("click", () => {
        document.getElementById("saleDateFilter").value = "";
        displaySales();
    });
    document.getElementById("voidSaleForm").addEventListener("submit", handleVoidSubmit);

    displaySales();
}

export async function displaySales() {
    const keyword = document.getElementById("saleSearch").value.trim();
    const date = document.getElementById("saleDateFilter").value;

    const params = keyword
        ? { operation: "searchSales", keyword, status: currentStatus, date }
        : { operation: "getAllSales", status: currentStatus, date };

    const response = await axios.get(`${baseApiUrl}/sales.php`, { params });
    renderTable(response.data);
}

function renderSummary(sales) {
    const el = document.getElementById("saleSummary");
    if (!el) return;
    if (!sales.length) {
        el.innerHTML = "";
        return;
    }
    const total = sales.reduce((sum, s) => sum + Number(s.total_amount || 0), 0);
    const voided = sales.filter((s) => s.sale_status === "Voided").length;
    el.innerHTML =
        `<span><strong>${sales.length}</strong> transaction${sales.length === 1 ? "" : "s"}</span>` +
        `<span class="table-meta-sep">·</span><span>Total <strong>${formatPeso(total)}</strong></span>` +
        (voided ? `<span class="table-meta-sep">·</span><span><strong>${voided}</strong> voided</span>` : "");
}

function saleStatusBadge(status) {
    return status === "Completed"
        ? `<span class="badge text-bg-success">Completed</span>`
        : `<span class="badge text-bg-danger">Voided</span>`;
}

function paymentLabel(sale) {
    if (sale.ar_account_id) return `<span class="badge text-bg-warning">Charged to AR</span>`;
    return esc(sale.payment_types || sale.type_name || "");
}

function renderTable(sales) {
    const tbody = document.getElementById("saleTableBody");
    tbody.innerHTML = "";

    renderSummary(sales || []);

    if (!sales || sales.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    sales.forEach((sale) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(sale.receipt_number)}</td>
            <td class="text-nowrap">${formatDateTime(sale.created_at)}</td>
            <td>${esc(sale.counter_name)}</td>
            <td>${esc(sale.cashier_name)}</td>
            <td>${esc(sale.customer_name ?? "Walk-in")}</td>
            <td>${paymentLabel(sale)}</td>
            <td class="num">${formatPeso(sale.total_amount)}</td>
            <td>${saleStatusBadge(sale.sale_status)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary btn-action btn-view"><i class="bi bi-eye"></i></button>
                ${sale.sale_status === "Completed" ? `
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete" title="Void sale"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore" title="Restore sale"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-view").addEventListener("click", () => openViewModal(sale));
        row.querySelector(".btn-delete")?.addEventListener("click", () => openVoidModal(sale));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(sale));

        tbody.appendChild(row);
    });

    refreshPager("saleTableBody");
}

async function openViewModal(saleRow) {
    const response = await axios.get(`${baseApiUrl}/sales.php`, { params: { operation: "getSaleDetail", sale_id: saleRow.sale_id } });

    if (!response.data.success) {
        showToast(response.data.message, "danger");
        return;
    }

    const sale = response.data.sale;

    const itemRows = sale.items.map((item) => `
        <tr>
            <td>${esc(item.product_name)} <span class="text-muted small">(${esc(item.unit)})</span></td>
            <td class="text-end">${item.quantity}</td>
            <td class="text-end">${formatPeso(item.unit_price)}</td>
            <td class="text-end">${formatPeso(item.line_total)}</td>
        </tr>
    `).join("");

    const voidInfo = sale.sale_status === "Voided" ? `
        <div class="alert alert-danger py-2 mt-3 mb-0">
            <strong>Voided</strong> by ${esc(sale.voided_by_name ?? "")} on ${formatDateTime(sale.voided_at)}<br>
            Reason: ${esc(sale.void_reason ?? "")}
        </div>
    ` : "";

    document.getElementById("viewModalBody").innerHTML = `
        <p class="mb-1"><strong>Receipt:</strong> ${esc(sale.receipt_number)} ${saleStatusBadge(sale.sale_status)}</p>
        <p class="mb-1"><strong>Date:</strong> ${formatDateTime(sale.created_at)}</p>
        <p class="mb-1"><strong>Counter:</strong> ${esc(sale.counter_name)} &nbsp; <strong>Cashier:</strong> ${esc(sale.cashier_name)}</p>
        <p class="mb-1"><strong>Customer:</strong> ${esc(sale.customer_name ?? "Walk-in")}</p>
        <p class="mb-3"><strong>Payment Methods:</strong> ${sale.ar_account_id ? "Charged to AR" : esc((sale.payments || []).map((p) => `${p.type_name}${p.gcc_code ? ` · ${p.gcc_code}` : p.gc_id ? ` #${p.gc_id}` : ""} — ${formatPeso(p.amount_paid)}`).join(" · ") || sale.type_name || "")}</p>

        <table class="table table-sm">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
            <tbody>${itemRows}</tbody>
        </table>

        <div class="d-flex justify-content-between"><span>Subtotal</span><span>${formatPeso(sale.subtotal)}</span></div>
        <div class="d-flex justify-content-between"><span>Discount ${sale.discount_name ? `(${esc(sale.discount_name)})` : ""}</span><span>- ${formatPeso(sale.discount_amount)}</span></div>
        <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span>${formatPeso(sale.total_amount)}</span></div>
        <div class="d-flex justify-content-between text-muted small"><span>VAT included</span><span>${formatPeso(sale.vat_amount)}</span></div>
        <div class="d-flex justify-content-between text-muted small"><span>Tendered / Change</span><span>${formatPeso(sale.amount_tendered)} / ${formatPeso(sale.change_amount)}</span></div>
        <div class="d-flex justify-content-between text-muted small"><span>Suki points earned</span><span>${sale.points_earned}</span></div>
        <div class="d-flex justify-content-between text-muted small"><span>Suki points used</span><span>${sale.points_redeemed || 0}</span></div>
        ${voidInfo}
    `;

    new bootstrap.Modal(document.getElementById("viewModal")).show();
}

function openVoidModal(sale) {
    document.getElementById("voidSaleId").value = sale.sale_id;
    document.getElementById("voidSaleLabel").textContent = `${sale.receipt_number} (${formatPeso(sale.total_amount)})`;
    document.getElementById("voidSaleReason").value = "";

    new bootstrap.Modal(document.getElementById("voidSaleModal")).show();
}

async function handleVoidSubmit(e) {
    e.preventDefault();

    const jsonData = {
        sale_id: document.getElementById("voidSaleId").value,
        reason: document.getElementById("voidSaleReason").value.trim()
    };

    const formData = new FormData();
    formData.append("operation", "voidSale");
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/sales.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");

    if (response.data.success) {
        bootstrap.Modal.getInstance(document.getElementById("voidSaleModal"))?.hide();
        displaySales();
    }
}

async function handleRestore(sale) {
    const confirmed = await confirmRestore(sale.receipt_number);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreSale");
    formData.append("json", JSON.stringify({ sale_id: sale.sale_id }));

    const response = await axios({ url: `${baseApiUrl}/sales.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displaySales();
}
