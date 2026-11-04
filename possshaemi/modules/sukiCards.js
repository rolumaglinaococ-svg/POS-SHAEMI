import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";
import { getActiveCustomerOptions, getCustomersWithoutSukiCard } from "./customers.js";

let currentStatus = "active";

export function initSukiCards() {
    document.getElementById("sukiCardForm").addEventListener("submit", handleSubmit);
    document.getElementById("sukiCardStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displaySukiCards();
    });
    document.getElementById("sukiCardSearch").addEventListener("input", debounce(displaySukiCards, 300));
    document.getElementById("btnAddSukiCard").addEventListener("click", openAddModal);
    document.getElementById("pointAdjustmentForm").addEventListener("submit", handlePointAdjustmentSubmit);

    displaySukiCards();
}

export async function displaySukiCards() {
    const keyword = document.getElementById("sukiCardSearch").value.trim();

    const params = keyword
        ? { operation: "searchSukiCards", keyword, status: currentStatus }
        : { operation: "getAllSukiCards", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/suki-cards.php`, { params });
    renderTable(response.data);
}

async function populateCustomerDropdown(selectedId = null, onlyEligible = false) {
    const select = document.getElementById("sukiCardCustomer");
    const customers = onlyEligible ? await getCustomersWithoutSukiCard() : await getActiveCustomerOptions();

    select.innerHTML = customers.length
        ? customers
            .map((c) => `<option value="${c.customer_id}">${esc(c.full_name)}</option>`)
            .join("")
        : `<option value="" disabled selected>No eligible customers — everyone already has a Suki card</option>`;

    if (selectedId) select.value = selectedId;
}

let currentSukiCards = [];

function renderTable(sukiCards) {
    currentSukiCards = sukiCards || [];
    const tbody = document.getElementById("sukiCardTableBody");
    tbody.innerHTML = "";
    if (!currentSukiCards.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }
    currentSukiCards.forEach((card) => {
        // Points with cents (2026-10-29, revised): the table layout
        // stays exactly as before — one number in the Points cell,
        // no extra lines. The decimal part is the progress toward
        // the next point: progress pesos / ₱400.00. So ₱200 progress
        // shows as .50, ₱150 progress as .38 (150/400 = 0.375,
        // rounded to 2 decimals), and no progress as .00. The whole
        // points balance itself is unchanged; redemption still uses
        // whole points only. If the server has not been updated yet,
        // progress falls back to 0 and the display is just X.00.
        const progressPesos = Math.min(
            399.99,
            Math.max(0, Number(card.suki_progress_amount || 0)),
        );
        const pointsWithCents = (
            Number(card.points_balance || 0) +
            progressPesos / 400
        ).toFixed(2);
        const row = document.createElement("tr");
        row.innerHTML = `
            <td>${esc(card.card_number)}</td>
            <td>${esc(card.customer_name)}</td>
            <td class="num">${esc(pointsWithCents)}</td>
            <td>${esc(card.date_issued)}</td>
            <td>${statusBadge(card.is_active)}</td>
            <td class="text-end">
                ${card.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-primary btn-action btn-adjust" title="Adjust points"><i class="bi bi-plus-slash-minus"></i></button>
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-history" title="Point history"><i class="bi bi-clock-history"></i></button>
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit" title="Edit card"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete" title="Deactivate"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-history" title="Point history"><i class="bi bi-clock-history"></i></button>
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>`;
        row.querySelector(".btn-adjust")?.addEventListener("click", () => openPointAdjustment(card));
        row.querySelector(".btn-history")?.addEventListener("click", () => showPointHistory(card));
        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(card));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(card));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(card));
        tbody.appendChild(row);
    });

    refreshPager("sukiCardTableBody");
}

async function openAddModal() {
    document.getElementById("sukiCardModalTitle").textContent = "Add Suki Card";
    document.getElementById("sukiCardId").value = "";
    document.getElementById("sukiCardDateIssued").value = new Date().toISOString().split("T")[0];
    document.getElementById("sukiCardDateIssued").max = new Date().toISOString().split("T")[0];
    document.getElementById("sukiCardPointsWrapper").classList.add("d-none");
    document.getElementById("sukiCardDateWrapper").style.display = "block";
    document.getElementById("sukiCardCustomer").disabled = false;
    // Card numbers are auto-generated: fetch a fresh one and lock the field.
    document.getElementById("sukiCardNumber").value = "Generating…";
    await populateCustomerDropdown(null, true);
    try {
        const response = await axios.get(`${baseApiUrl}/suki-cards.php`, { params: { operation: "generateCardNumber" } });
        document.getElementById("sukiCardNumber").value = response.data.success ? response.data.card_number : "";
    } catch (error) {
        document.getElementById("sukiCardNumber").value = "";
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById("sukiCardModal")).show();
}

async function openEditModal(card) {
    document.getElementById("sukiCardModalTitle").textContent = "Edit Suki Card";
    document.getElementById("sukiCardId").value = card.suki_card_id;
    document.getElementById("sukiCardNumber").value = card.card_number;
    document.getElementById("sukiCardPointsWrapper").classList.remove("d-none");
    document.getElementById("sukiCardDateWrapper").style.display = "none";
    await populateCustomerDropdown(card.customer_id);
    bootstrap.Modal.getOrCreateInstance(document.getElementById("sukiCardModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const sukiCardId = document.getElementById("sukiCardId").value;
    let operation, jsonData;

    if (sukiCardId) {
        jsonData = {
            suki_card_id: sukiCardId,
            customer_id: document.getElementById("sukiCardCustomer").value,
            card_number: document.getElementById("sukiCardNumber").value.trim()
        };
        operation = "updateSukiCard";
    } else {
        jsonData = {
            customer_id: document.getElementById("sukiCardCustomer").value,
            card_number: document.getElementById("sukiCardNumber").value.trim(),
            date_issued: document.getElementById("sukiCardDateIssued").value
        };
        operation = "insertSukiCard";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/suki-cards.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("sukiCardModal"))?.hide();
        displaySukiCards();
    } else {
        showToast(response.data.message, "danger");
    }
}

function openPointAdjustment(card) {
    document.getElementById("pointAdjustmentCardId").value = card.suki_card_id;
    document.getElementById("pointAdjustmentCustomer").textContent = card.customer_name;
    document.getElementById("pointAdjustmentCardNumber").textContent = card.card_number;
    document.getElementById("pointAdjustmentCurrent").textContent = card.points_balance;
    document.getElementById("pointAdjustmentAmount").value = "";
    document.getElementById("pointAdjustmentReason").value = "";
    document.getElementById("pointAdjustmentError").textContent = "";
    bootstrap.Modal.getOrCreateInstance(document.getElementById("pointAdjustmentModal")).show();
}

async function handlePointAdjustmentSubmit(e) {
    e.preventDefault();
    const amount = Number(document.getElementById("pointAdjustmentAmount").value);
    const reason = document.getElementById("pointAdjustmentReason").value.trim();
    const error = document.getElementById("pointAdjustmentError");
    error.textContent = "";
    if (!Number.isInteger(amount) || amount === 0) { error.textContent = "Enter a non-zero whole-point adjustment."; return; }
    if (!reason) { error.textContent = "A reason is required."; return; }
    const formData = new FormData();
    formData.append("operation", "updateSukiPoints");
    formData.append("json", JSON.stringify({ suki_card_id: document.getElementById("pointAdjustmentCardId").value, adjustment_amount: amount, reason }));
    const response = await axios({ url: `${baseApiUrl}/suki-cards.php`, method: "POST", data: formData });
    if (!response.data.success) { error.textContent = response.data.message; return; }
    showToast(response.data.message, "success");
    bootstrap.Modal.getInstance(document.getElementById("pointAdjustmentModal"))?.hide();
    displaySukiCards();
}

async function showPointHistory(card) {
    const response = await axios.get(`${baseApiUrl}/suki-cards.php`, { params: { operation: "getPointHistory", suki_card_id: card.suki_card_id } });
    if (!response.data.success) { showToast(response.data.message, "danger"); return; }
    document.getElementById("pointHistoryLabel").textContent = `${card.customer_name} · ${card.card_number} · Current balance: ${card.points_balance}`;
    const tbody = document.getElementById("pointHistoryBody");
    tbody.innerHTML = response.data.history.length ? response.data.history.map((h) => `<tr><td>${esc(h.event_date || "")}</td><td>${esc(h.event_type || "")}</td><td>${esc(h.description || "")}</td><td class="text-end ${Number(h.point_change) >= 0 ? "text-success" : "text-danger"}">${Number(h.point_change) > 0 ? "+" : ""}${Number(h.point_change || 0)}</td><td>${esc(h.performed_by || "System")}</td></tr>`).join("") : `<tr><td colspan="5" class="text-center text-muted py-4">No point history.</td></tr>`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById("pointHistoryModal")).show();
}

async function handleDelete(card) {
    const confirmed = await confirmDeactivate(card.card_number);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteSukiCard");
    formData.append("json", JSON.stringify({ suki_card_id: card.suki_card_id }));

    const response = await axios({ url: `${baseApiUrl}/suki-cards.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displaySukiCards();
}

async function handleRestore(card) {
    const confirmed = await confirmRestore(card.card_number);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreSukiCard");
    formData.append("json", JSON.stringify({ suki_card_id: card.suki_card_id }));

    const response = await axios({ url: `${baseApiUrl}/suki-cards.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displaySukiCards();
}
