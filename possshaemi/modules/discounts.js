import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initDiscounts() {
    document.getElementById("discountForm").addEventListener("submit", handleSubmit);
    document.getElementById("discountStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayDiscounts();
    });
    document.getElementById("discountSearch").addEventListener("input", debounce(displayDiscounts, 300));
    document.getElementById("btnAddDiscount").addEventListener("click", openAddModal);

    displayDiscounts();
}

export async function displayDiscounts() {
    const keyword = document.getElementById("discountSearch").value.trim();

    const params = keyword
        ? { operation: "searchDiscounts", keyword, status: currentStatus }
        : { operation: "getAllDiscounts", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/discounts.php`, { params });
    renderTable(response.data);
}

function formatValue(discount) {
    return discount.discount_type === "Percentage"
        ? `${parseFloat(discount.discount_value)}%`
        : `₱${parseFloat(discount.discount_value).toFixed(2)}`;
}

function renderTable(discounts) {
    const tbody = document.getElementById("discountTableBody");
    tbody.innerHTML = "";

    if (!discounts || discounts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    discounts.forEach((discount) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(discount.discount_name)}</td>
            <td>${esc(discount.discount_type)}</td>
            <td class="num">${formatValue(discount)}</td>
            <td>${statusBadge(discount.is_active)}</td>
            <td class="text-end">
                ${discount.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(discount));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(discount));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(discount));

        tbody.appendChild(row);
    });

    refreshPager("discountTableBody");
}

function openAddModal() {
    document.getElementById("discountModalTitle").textContent = "Add Discount";
    document.getElementById("discountId").value = "";
    document.getElementById("discountName").value = "";
    document.getElementById("discountType").value = "Percentage";
    document.getElementById("discountValue").value = "";
}

function openEditModal(discount) {
    document.getElementById("discountModalTitle").textContent = "Edit Discount";
    document.getElementById("discountId").value = discount.discount_id;
    document.getElementById("discountName").value = discount.discount_name;
    document.getElementById("discountType").value = discount.discount_type;
    document.getElementById("discountValue").value = discount.discount_value;
    new bootstrap.Modal(document.getElementById("discountModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const discountId = document.getElementById("discountId").value;
    const jsonData = {
        discount_name: document.getElementById("discountName").value.trim(),
        discount_type: document.getElementById("discountType").value,
        discount_value: document.getElementById("discountValue").value
    };
    let operation = "insertDiscount";

    if (discountId) {
        jsonData.discount_id = discountId;
        operation = "updateDiscount";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/discounts.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("discountModal"))?.hide();
        displayDiscounts();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(discount) {
    const confirmed = await confirmDeactivate(discount.discount_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteDiscount");
    formData.append("json", JSON.stringify({ discount_id: discount.discount_id }));

    const response = await axios({ url: `${baseApiUrl}/discounts.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayDiscounts();
}

async function handleRestore(discount) {
    const confirmed = await confirmRestore(discount.discount_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreDiscount");
    formData.append("json", JSON.stringify({ discount_id: discount.discount_id }));

    const response = await axios({ url: `${baseApiUrl}/discounts.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayDiscounts();
}
