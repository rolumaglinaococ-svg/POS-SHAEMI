import { baseApiUrl, showToast, esc, formatPeso, debounce, refreshPager } from "./utils.js";

/* GC Codes — standalone code-based gift certificates (no Suki association).
   The legacy Suki-bound certificates keep their own module untouched. */

let currentStatus = "available";
let gcCodes = [];
let lastBatch = [];
let voidTarget = null;

export function initGcCodes() {
    document.getElementById("gcCodeStatusFilter").addEventListener("change", (e) => { currentStatus = e.target.value; displayGcCodes(); });
    document.getElementById("gcCodeSearch").addEventListener("input", debounce(displayGcCodes, 300));
    document.getElementById("btnGenerateGcCodes").addEventListener("click", openBatchModal);
    document.getElementById("gcDenomination").addEventListener("change", toggleCustomAmount);
    document.getElementById("gcBatchForm").addEventListener("submit", handleBatch);
    document.getElementById("btnPrintGcBatch").addEventListener("click", () => window.print());
    document.getElementById("btnConfirmGcVoid").addEventListener("click", handleVoid);
    displayGcCodes();
}

export async function displayGcCodes() {
    const keyword = document.getElementById("gcCodeSearch").value.trim();
    const response = await axios.get(`${baseApiUrl}/gc-codes.php`, { params: { operation: "getAll", status: currentStatus, keyword } });
    gcCodes = response.data;
    renderTable();
}

function statusClass(status) {
    if (status === "Available") return "text-bg-success";
    if (status === "Redeemed") return "text-bg-secondary";
    if (status === "Expired") return "text-bg-warning";
    return "text-bg-danger";
}

function renderTable() {
    const tbody = document.getElementById("gcCodeTableBody");
    tbody.innerHTML = gcCodes.length ? gcCodes.map((g) => `<tr><td><strong>${esc(g.code)}</strong></td><td class="num">${formatPeso(g.face_value)}</td><td>${esc(g.expiration_date)}</td><td><span class="badge ${statusClass(g.effective_status)}">${esc(g.effective_status)}</span></td><td>${esc(g.date_issued)}${g.issued_by_name ? ` <span class="text-muted">· ${esc(g.issued_by_name)}</span>` : ""}</td><td>${g.receipt_number ? `${esc(g.receipt_number)} <span class="text-muted">· ${esc(g.redeemed_at || "")}</span>` : "—"}</td><td class="text-end">${g.effective_status === "Available" ? `<button class="btn btn-sm btn-outline-danger btn-void"><i class="bi bi-x-circle"></i> Void</button>` : ""}</td></tr>`).join("") : `<tr><td colspan="7" class="text-center text-muted py-4">No GC codes found.</td></tr>`;
    [...tbody.querySelectorAll("tr")].forEach((row, index) => {
        row.querySelector(".btn-void")?.addEventListener("click", () => openVoidModal(gcCodes[index]));
    });
    refreshPager("gcCodeTableBody");
}

function openBatchModal() {
    document.getElementById("gcBatchError").textContent = "";
    document.getElementById("gcQuantity").value = "10";
    document.getElementById("gcDenomination").value = "500";
    document.getElementById("gcCustomAmount").value = "";
    document.getElementById("gcCustomWrap").classList.add("d-none");
    const expiration = document.getElementById("gcExpiration");
    expiration.min = new Date().toISOString().split("T")[0];
    expiration.value = "";
    bootstrap.Modal.getOrCreateInstance(document.getElementById("gcBatchModal")).show();
}

function toggleCustomAmount() {
    document.getElementById("gcCustomWrap").classList.toggle("d-none", document.getElementById("gcDenomination").value !== "custom");
}

async function handleBatch(e) {
    e.preventDefault();
    const error = document.getElementById("gcBatchError"); error.textContent = "";
    const data = {
        quantity: document.getElementById("gcQuantity").value,
        denomination: document.getElementById("gcDenomination").value,
        custom_amount: document.getElementById("gcCustomAmount").value,
        expiration_date: document.getElementById("gcExpiration").value
    };
    const formData = new FormData();
    formData.append("operation", "insertBatch");
    formData.append("json", JSON.stringify(data));
    let response;
    try {
        response = await axios({ url: `${baseApiUrl}/gc-codes.php`, method: "POST", data: formData });
    } catch (err) {
        error.textContent = err.response?.data?.message || "Batch generation failed.";
        return;
    }
    if (!response.data.success) { error.textContent = response.data.message; return; }
    lastBatch = response.data.codes || [];
    showToast(response.data.message, "success");
    bootstrap.Modal.getInstance(document.getElementById("gcBatchModal"))?.hide();
    renderPrintSheet();
    bootstrap.Modal.getOrCreateInstance(document.getElementById("gcPrintModal")).show();
    displayGcCodes();
}

function renderPrintSheet() {
    document.getElementById("gcPrintSheet").innerHTML = `<div class="text-center mb-3"><h5 class="mb-1">Gift Certificate Codes</h5><div class="text-muted small">Generated ${esc(new Date().toLocaleString())} · ${lastBatch.length} code(s)</div></div><table class="table table-bordered table-sm"><thead><tr><th>Code</th><th class="text-end">Value</th><th>Expires</th></tr></thead><tbody>${lastBatch.map((c) => `<tr><td><strong>${esc(c.code)}</strong></td><td class="text-end">${formatPeso(c.face_value)}</td><td>${esc(c.expiration_date)}</td></tr>`).join("")}</tbody></table><p class="small text-muted">Each code is redeemable once. Expired or voided codes are rejected at the register. If the code value exceeds the purchase total, the excess is forfeited.</p>`;
}

function openVoidModal(g) {
    voidTarget = g;
    document.getElementById("gcVoidLabel").textContent = `${g.code} · ${formatPeso(g.face_value)}`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("gcVoidModal")).show();
}

async function handleVoid() {
    if (!voidTarget) return;
    const formData = new FormData();
    formData.append("operation", "void");
    formData.append("json", JSON.stringify({ gcc_id: voidTarget.gcc_id }));
    const response = await axios({ url: `${baseApiUrl}/gc-codes.php`, method: "POST", data: formData });
    showToast(response.data.message, response.data.success ? "success" : "danger");
    bootstrap.Modal.getInstance(document.getElementById("gcVoidModal"))?.hide();
    if (response.data.success) displayGcCodes();
}
