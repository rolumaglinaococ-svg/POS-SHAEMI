// posHistory.js
// "Transaction History" tab: every sale THIS cashier rang up, pulled from the database
// (api/pos.php -> getMySales, already scoped server-side to the logged-in cashier).
// Clicking VIEW reuses the same printable receipt layout as pos.js so a past
// transaction looks identical to the one shown right after checkout.

import { baseApiUrl, esc, debounce, formatPeso, formatDateTime, todayString } from "./utils.js";
import { buildReceiptHtml } from "./pos.js";

let currentDate = "";
let currentStatus = "all";

export function initPosHistory() {
    currentDate = todayString();
    document.getElementById("historyDate").value = currentDate;

    document.getElementById("historyDate").addEventListener("change", (e) => {
        currentDate = e.target.value;
        displayHistory();
    });
    document.getElementById("historyStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayHistory();
    });
    document.getElementById("btnCloseHistoryDetail").addEventListener("click", () => {
        bootstrap.Modal.getInstance(document.getElementById("historyDetailModal"))?.hide();
    });

    displayHistory();
}

export async function displayHistory() {
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
        params: { operation: "getMySales", date: currentDate, status: currentStatus }
    });

    renderHistoryTable(response.data);
}

function renderHistoryTable(sales) {
    const tbody = document.getElementById("historyTableBody");
    tbody.innerHTML = "";

    if (!sales || sales.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No transactions for this day.</td></tr>`;
        return;
    }

    sales.forEach((sale) => {
        const row = document.createElement("tr");
        const statusBadge = sale.sale_status === "Completed"
            ? `<span class="badge text-bg-success">Completed</span>`
            : `<span class="badge text-bg-danger" title="${esc(sale.void_reason ?? "")}">Voided</span>`;

        row.innerHTML = `
            <td>${esc(sale.receipt_number)}</td>
            <td class="text-nowrap">${formatDateTime(sale.created_at)}</td>
            <td>${esc(sale.customer_name ?? "Walk-in")}</td>
            <td>${sale.ar_account_id ? "Charged to AR" : esc(sale.payment_types || sale.type_name || "")}</td>
            <td class="text-end">${formatPeso(sale.total_amount)}</td>
            <td class="text-end">${statusBadge} <button type="button" class="btn btn-sm btn-outline-primary btn-view-history ms-1">VIEW</button></td>
        `;
        row.querySelector(".btn-view-history").addEventListener("click", () => viewTransaction(sale.sale_id));
        tbody.appendChild(row);
    });
}

async function viewTransaction(saleId) {
    const response = await axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getSaleDetail", sale_id: saleId } });

    if (!response.data.success) return;

    document.getElementById("historyDetailContent").innerHTML = buildReceiptHtml(response.data.sale);
    new bootstrap.Modal(document.getElementById("historyDetailModal")).show();
}
