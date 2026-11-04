import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initPriceTiers() {
    document.getElementById("priceTierForm").addEventListener("submit", handleSubmit);
    document.getElementById("priceTierStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayPriceTiers();
    });
    document.getElementById("priceTierSearch").addEventListener("input", debounce(displayPriceTiers, 300));
    document.getElementById("btnAddPriceTier").addEventListener("click", openAddModal);

    displayPriceTiers();
}

export async function displayPriceTiers() {
    const keyword = document.getElementById("priceTierSearch").value.trim();

    const params = keyword
        ? { operation: "searchPriceTiers", keyword, status: currentStatus }
        : { operation: "getAllPriceTiers", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/price-tiers.php`, { params });
    renderTable(response.data);
}

export async function getActivePriceTierOptions() {
    const response = await axios.get(`${baseApiUrl}/price-tiers.php`, { params: { operation: "getActivePriceTiersForDropdown" } });
    return response.data;
}

function renderTable(tiers) {
    const tbody = document.getElementById("priceTierTableBody");
    tbody.innerHTML = "";

    if (!tiers || tiers.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    tiers.forEach((tier) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(tier.tier_name)}</td>
            <td>${statusBadge(tier.is_active)}</td>
            <td class="text-end">
                ${tier.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(tier));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(tier));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(tier));

        tbody.appendChild(row);
    });

    refreshPager("priceTierTableBody");
}

function openAddModal() {
    document.getElementById("priceTierModalTitle").textContent = "Add Price Tier";
    document.getElementById("priceTierId").value = "";
    document.getElementById("tierName").value = "";
}

function openEditModal(tier) {
    document.getElementById("priceTierModalTitle").textContent = "Edit Price Tier";
    document.getElementById("priceTierId").value = tier.price_tier_id;
    document.getElementById("tierName").value = tier.tier_name;
    new bootstrap.Modal(document.getElementById("priceTierModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const priceTierId = document.getElementById("priceTierId").value;
    const jsonData = { tier_name: document.getElementById("tierName").value.trim() };
    let operation = "insertPriceTier";

    if (priceTierId) {
        jsonData.price_tier_id = priceTierId;
        operation = "updatePriceTier";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/price-tiers.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("priceTierModal"))?.hide();
        displayPriceTiers();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(tier) {
    const confirmed = await confirmDeactivate(tier.tier_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deletePriceTier");
    formData.append("json", JSON.stringify({ price_tier_id: tier.price_tier_id }));

    const response = await axios({ url: `${baseApiUrl}/price-tiers.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayPriceTiers();
}

async function handleRestore(tier) {
    const confirmed = await confirmRestore(tier.tier_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restorePriceTier");
    formData.append("json", JSON.stringify({ price_tier_id: tier.price_tier_id }));

    const response = await axios({ url: `${baseApiUrl}/price-tiers.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayPriceTiers();
}
