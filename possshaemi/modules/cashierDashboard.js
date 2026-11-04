// cashierDashboard.js
// Cashier's "My Sales" tab: today's completed/voided transactions for THIS cashier only
// (api/pos.php -> getMySales already scopes by session employee_id), plus a running summary.
// If the Administrator voids one of these sales, the shared activity feed refreshes this list
// automatically so the cashier finds out without reloading the page.

import { baseApiUrl, esc, formatPeso, formatDateTime } from "./utils.js";

let currentDate;
let currentStatus = "all";

export async function initCashierDashboard() {
    currentDate = document.getElementById("mySalesDate").value || todayString();
    document.getElementById("mySalesDate").value = currentDate;

    document.getElementById("mySalesDate").addEventListener("change", (e) => {
        currentDate = e.target.value;
        loadMySales();
    });
    document.getElementById("mySalesStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        loadMySales();
    });

    await Promise.all([loadSummary(), loadMySales()]);
}

function todayString() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

export async function loadSummary() {
    const response = await axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getCashierSummary" } });
    const summary = response.data;

    document.getElementById("summaryCompletedCount").textContent = summary.completed_count;
    document.getElementById("summaryCompletedTotal").textContent = formatPeso(summary.completed_total);
    document.getElementById("summaryVoidedCount").textContent = summary.voided_count;
}

export async function loadMySales() {
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
        params: { operation: "getMySales", date: currentDate, status: currentStatus }
    });

    renderSales(response.data);
}

function renderSales(sales) {
    const tbody = document.getElementById("mySalesTableBody");
    tbody.innerHTML = "";

    if (!sales || sales.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No sales for this day.</td></tr>`;
        return;
    }

    sales.forEach((sale) => {
        const row = document.createElement("tr");
        const statusBadge = sale.sale_status === "Completed"
            ? `<span class="badge text-bg-success">Completed</span>`
            : `<span class="badge text-bg-danger" title="${esc(sale.void_reason ?? "")}">Voided</span>`;

        row.innerHTML = `
            <td class="text-nowrap">${formatDateTime(sale.created_at)}</td>
            <td>${esc(sale.receipt_number)}</td>
            <td>${esc(sale.customer_name ?? "Walk-in")}</td>
            <td>${sale.ar_account_id ? "Charged to AR" : esc(sale.type_name ?? "")}</td>
            <td class="text-end">${formatPeso(sale.total_amount)}</td>
            <td>${statusBadge}</td>
        `;
        tbody.appendChild(row);
    });
}
