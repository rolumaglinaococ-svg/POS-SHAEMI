import { baseApiUrl, esc, formatPeso, todayString } from "./utils.js";

export async function initPosReports() {
    const dateInput = document.getElementById("reportDateInput");
    dateInput.value = todayString();
    document.getElementById("btnRefreshReports").addEventListener("click", loadReports);
    dateInput.addEventListener("change", loadReports);
    await loadReports();
}

async function loadReports() {
    const date = document.getElementById("reportDateInput").value || todayString();

    try {
        const [paymentResponse, summaryResponse, mostPurchasedResponse, dateResponse] = await Promise.all([
            axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getPaymentReport", report_date: date } }),
            axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getCashierSummary" } }),
            axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getMostPurchasedProduct" } }),
            axios.get(`${baseApiUrl}/pos.php`, { params: { operation: "getSalesSummaryByDate", report_date: date } })
        ]);

        const payments = paymentResponse.data;
        document.getElementById("reportCashTotal").textContent = formatPeso(payments.cash || 0);
        document.getElementById("reportGcashTotal").textContent = formatPeso(payments.gcash || 0);
        document.getElementById("reportArTotal").textContent = formatPeso(payments.ar || 0);

        const summary = summaryResponse.data;
        document.getElementById("sumTransactions").textContent = summary.completed_count || 0;
        document.getElementById("sumItemsSold").textContent = summary.items_sold || 0;
        document.getElementById("sumTotalSales").textContent = formatPeso(summary.completed_total || 0);
        document.getElementById("sumTotalDiscounts").textContent = formatPeso(summary.total_discount || 0);

        const dateSummary = dateResponse.data?.summary;
        document.getElementById("dateReportResult").innerHTML = dateSummary
            ? `<div class="mb-1">Date: <strong>${esc(date)}</strong></div>
               <div>Total Transactions: ${dateSummary.transaction_count || 0}</div>
               <div>Items Sold: ${dateSummary.items_sold || 0}</div>
               <div>Total Discounts: ${formatPeso(dateSummary.total_discount || 0)}</div>
               <div class="fw-bold fs-5 mt-2">Total Sales: ${formatPeso(dateSummary.total_sales || 0)}</div>`
            : `<span class="text-muted">No sales recorded for this date.</span>`;

        const most = mostPurchasedResponse.data?.report;
        document.getElementById("mostPurchasedResult").innerHTML = most
            ? `<div class="fw-bold fs-5">${esc(most.product_name)}</div><div>Quantity Sold: ${most.quantity_sold}</div><div>Total Sales: ${formatPeso(most.total_sales)}</div>`
            : `<span class="text-muted">No sales recorded yet.</span>`;
    } catch (error) {
        document.getElementById("dateReportResult").innerHTML = `<span class="text-danger">Unable to load reports.</span>`;
    }
}
