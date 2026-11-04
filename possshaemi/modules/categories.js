import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initCategories() {
    document.getElementById("categoryForm").addEventListener("submit", handleSubmit);
    document.getElementById("categoryStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayCategories();
    });
    document.getElementById("categorySearch").addEventListener("input", debounce(displayCategories, 300));
    document.getElementById("btnAddCategory").addEventListener("click", openAddModal);

    displayCategories();
}

export async function displayCategories() {
    const keyword = document.getElementById("categorySearch").value.trim();

    const params = keyword
        ? { operation: "searchCategories", keyword, status: currentStatus }
        : { operation: "getAllCategories", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/categories.php`, { params });
    renderTable(response.data);
}

export async function getActiveCategoryOptions() {
    const response = await axios.get(`${baseApiUrl}/categories.php`, { params: { operation: "getActiveCategoriesForDropdown" } });
    return response.data;
}

function renderTable(categories) {
    const tbody = document.getElementById("categoryTableBody");
    tbody.innerHTML = "";

    if (!categories || categories.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    categories.forEach((category) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(category.category_name)}</td>
            <td>${esc(category.description)}</td>
            <td>${statusBadge(category.is_active)}</td>
            <td class="text-end">
                ${category.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(category));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(category));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(category));

        tbody.appendChild(row);
    });

    refreshPager("categoryTableBody");
}

function openAddModal() {
    document.getElementById("categoryModalTitle").textContent = "Add Category";
    document.getElementById("categoryId").value = "";
    document.getElementById("categoryName").value = "";
    document.getElementById("categoryDescription").value = "";
}

function openEditModal(category) {
    document.getElementById("categoryModalTitle").textContent = "Edit Category";
    document.getElementById("categoryId").value = category.category_id;
    document.getElementById("categoryName").value = category.category_name;
    document.getElementById("categoryDescription").value = category.description ?? "";
    new bootstrap.Modal(document.getElementById("categoryModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const categoryId = document.getElementById("categoryId").value;
    const jsonData = {
        category_name: document.getElementById("categoryName").value.trim(),
        description: document.getElementById("categoryDescription").value.trim()
    };
    let operation = "insertCategory";

    if (categoryId) {
        jsonData.category_id = categoryId;
        operation = "updateCategory";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/categories.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("categoryModal"))?.hide();
        displayCategories();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(category) {
    const confirmed = await confirmDeactivate(category.category_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteCategory");
    formData.append("json", JSON.stringify({ category_id: category.category_id }));

    const response = await axios({ url: `${baseApiUrl}/categories.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCategories();
}

async function handleRestore(category) {
    const confirmed = await confirmRestore(category.category_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreCategory");
    formData.append("json", JSON.stringify({ category_id: category.category_id }));

    const response = await axios({ url: `${baseApiUrl}/categories.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCategories();
}
