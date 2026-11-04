import { baseApiUrl, showToast, showAlert, esc, formatPeso, confirmDeactivate, debounce, refreshPager } from "./utils.js";


let currentStatus = "active";
let giftCertificates = [];

export function initGiftCertificates() {
    document.getElementById("giftCertificateStatusFilter").addEventListener("change", (e) => { currentStatus = e.target.value; displayGiftCertificates(); });
    document.getElementById("giftCertificateSearch").addEventListener("input", debounce(displayGiftCertificates, 300));
    document.getElementById("btnAddGiftCertificate").addEventListener("click", openCreateModal);
    document.getElementById("giftCertificateForm").addEventListener("submit", handleCreate);
    displayGiftCertificates();
}

export async function displayGiftCertificates() {
    const keyword = document.getElementById("giftCertificateSearch").value.trim();
    const response = await axios.get(`${baseApiUrl}/gift-certificates.php`, { params: { operation: "getAll", status: currentStatus, keyword } });
    giftCertificates = response.data;
    renderTable();
}

function renderTable() {
    const tbody = document.getElementById("giftCertificateTableBody");
    tbody.innerHTML = giftCertificates.length ? giftCertificates.map((gc) => `<tr><td>${esc(gc.customer_name)}</td><td>${esc(gc.card_number)}</td><td class="num">${formatPeso(gc.gc_value)}</td><td class="num">${formatPeso(gc.remaining_balance)}</td><td>${esc(gc.expiration_date || "—")}</td><td><span class="badge ${statusClass(gc.effective_status)}">${esc(gc.effective_status)}</span></td><td class="text-end">${gc.effective_status !== "Cancelled" && gc.effective_status !== "Fully Used" ? `<button class="btn btn-sm btn-outline-danger btn-cancel"><i class="bi bi-x-circle"></i> Cancel</button>` : ""}<button class="btn btn-sm btn-outline-secondary btn-history ms-1"><i class="bi bi-clock-history"></i></button></td></tr>`).join("") : `<tr><td colspan="7" class="text-center text-muted py-4">No Gift Certificates found.</td></tr>`;
    [...tbody.querySelectorAll("tr")].forEach((row, index) => {
        row.querySelector(".btn-cancel")?.addEventListener("click", () => cancelCertificate(giftCertificates[index]));
        row.querySelector(".btn-history")?.addEventListener("click", () => showUsage(giftCertificates[index]));
    });

    refreshPager("giftCertificateTableBody");
}

function statusClass(status) {
    if (status === "Active") return "text-bg-success";
    if (status === "Fully Used") return "text-bg-secondary";
    if (status === "Expired") return "text-bg-warning";
    return "text-bg-danger";
}

async function openCreateModal() {
    document.getElementById("giftCertificateError").textContent = "";
    document.getElementById("giftCertificateValue").value = "";
    document.getElementById("giftCertificateExpiration").value = "";
    document.getElementById("giftCertificateExpiration").min = new Date().toISOString().split("T")[0];
    const select = document.getElementById("giftCertificateSukiCard");
    const response = await axios.get(`${baseApiUrl}/suki-cards.php`, { params: { operation: "getAllSukiCards", status: "active" } });
    const cards = response.data || [];
    select.innerHTML = "<option value=\"\">-- Select Suki Card / Customer --</option>" + cards.map((c) => `<option value="${c.suki_card_id}">${esc(c.customer_name)} · ${esc(c.card_number)}</option>`).join("");
    bootstrap.Modal.getOrCreateInstance(document.getElementById("giftCertificateModal")).show();
}

async function handleCreate(e) {
    e.preventDefault();
    const error = document.getElementById("giftCertificateError"); error.textContent = "";
    const data = { suki_card_id: document.getElementById("giftCertificateSukiCard").value, gc_value: document.getElementById("giftCertificateValue").value, expiration_date: document.getElementById("giftCertificateExpiration").value };
    const formData = new FormData(); formData.append("operation", "insert"); formData.append("json", JSON.stringify(data));
    const response = await axios({ url: `${baseApiUrl}/gift-certificates.php`, method: "POST", data: formData });
    if (!response.data.success) { error.textContent = response.data.message; return; }
    showToast(`${response.data.message} ID: ${response.data.gc_id}`, "success");
    bootstrap.Modal.getInstance(document.getElementById("giftCertificateModal"))?.hide();
    displayGiftCertificates();
}

async function cancelCertificate(gc) {
    const confirmed = await confirmDeactivate(`Gift Certificate #${gc.gc_id}`);
    if (!confirmed) return;
    const formData = new FormData(); formData.append("operation", "cancel"); formData.append("json", JSON.stringify({ gc_id: gc.gc_id }));
    const response = await axios({ url: `${baseApiUrl}/gift-certificates.php`, method: "POST", data: formData });
    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayGiftCertificates();
}

async function showUsage(gc) {
    const response = await axios.get(`${baseApiUrl}/gift-certificates.php`, { params: { operation: "usage", gc_id: gc.gc_id } });
    if (!response.data.success) { showToast(response.data.message, "danger"); return; }
    const usage = response.data.usage;
    const lines = usage.length ? usage.map((u) => `${esc(u.payment_date)} · ${esc(u.receipt_number)} · ${formatPeso(u.amount_paid)} · ${esc(u.sale_status)}`).join("<br>") : "No usage yet.";
    await showAlert({
        icon: "info",
        title: `Gift Certificate #${gc.gc_id}`,
        html: `Customer: ${esc(gc.customer_name)}<br>Original Value: ${formatPeso(gc.gc_value)}<br>Remaining: ${formatPeso(gc.remaining_balance)}<br><br><strong>Usage History</strong><br>${lines}`,
    });
}
