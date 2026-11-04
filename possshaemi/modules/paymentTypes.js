import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initPaymentTypes() {
    document.getElementById("paymentTypeForm").addEventListener("submit", handleSubmit);
    document.getElementById("paymentTypeStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayPaymentTypes();
    });
    document.getElementById("paymentTypeSearch").addEventListener("input", debounce(displayPaymentTypes, 300));
    document.getElementById("btnAddPaymentType").addEventListener("click", openAddModal);

    displayPaymentTypes();
}

export async function displayPaymentTypes() {
    const keyword = document.getElementById("paymentTypeSearch").value.trim();

    const params = keyword
        ? { operation: "searchPaymentTypes", keyword, status: currentStatus }
        : { operation: "getAllPaymentTypes", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/payment-types.php`, { params });
    renderTable(response.data);
}

function renderTable(paymentTypes) {
    const tbody = document.getElementById("paymentTypeTableBody");
    tbody.innerHTML = "";

    if (!paymentTypes || paymentTypes.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    paymentTypes.forEach((paymentType) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(paymentType.type_name)}</td>
            <td>${statusBadge(paymentType.is_active)}</td>
            <td class="text-end">
                ${paymentType.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(paymentType));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(paymentType));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(paymentType));

        tbody.appendChild(row);
    });

    refreshPager("paymentTypeTableBody");
}

function openAddModal() {
    document.getElementById("paymentTypeModalTitle").textContent = "Add Payment Type";
    document.getElementById("paymentTypeId").value = "";
    document.getElementById("typeName").value = "";
}

function openEditModal(paymentType) {
    document.getElementById("paymentTypeModalTitle").textContent = "Edit Payment Type";
    document.getElementById("paymentTypeId").value = paymentType.payment_type_id;
    document.getElementById("typeName").value = paymentType.type_name;
    new bootstrap.Modal(document.getElementById("paymentTypeModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const paymentTypeId = document.getElementById("paymentTypeId").value;
    const jsonData = { type_name: document.getElementById("typeName").value.trim() };
    let operation = "insertPaymentType";

    if (paymentTypeId) {
        jsonData.payment_type_id = paymentTypeId;
        operation = "updatePaymentType";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/payment-types.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("paymentTypeModal"))?.hide();
        displayPaymentTypes();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(paymentType) {
    const confirmed = await confirmDeactivate(paymentType.type_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deletePaymentType");
    formData.append("json", JSON.stringify({ payment_type_id: paymentType.payment_type_id }));

    const response = await axios({ url: `${baseApiUrl}/payment-types.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayPaymentTypes();
}

async function handleRestore(paymentType) {
    const confirmed = await confirmRestore(paymentType.type_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restorePaymentType");
    formData.append("json", JSON.stringify({ payment_type_id: paymentType.payment_type_id }));

    const response = await axios({ url: `${baseApiUrl}/payment-types.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayPaymentTypes();
}
