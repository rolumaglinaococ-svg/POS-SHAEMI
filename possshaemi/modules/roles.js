import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initRoles() {
    document.getElementById("roleForm").addEventListener("submit", handleSubmit);
    document.getElementById("roleStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayRoles();
    });
    document.getElementById("roleSearch").addEventListener("input", debounce(displayRoles, 300));
    document.getElementById("btnAddRole").addEventListener("click", openAddModal);

    displayRoles();
}

export async function displayRoles() {
    const keyword = document.getElementById("roleSearch").value.trim();

    const params = keyword
        ? { operation: "searchRoles", keyword, status: currentStatus }
        : { operation: "getAllRoles", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/roles.php`, { params });
    renderTable(response.data);
}

export async function getActiveRolesForDropdown() {
    const response = await axios.get(`${baseApiUrl}/roles.php`, { params: { operation: "getActiveRolesForDropdown" } });
    return response.data;
}

function renderTable(roles) {
    const tbody = document.getElementById("roleTableBody");
    tbody.innerHTML = "";

    if (!roles || roles.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    roles.forEach((role) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(role.role_name)}</td>
            <td>${statusBadge(role.is_active)}</td>
            <td class="text-end">
                ${role.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete" title="Deactivate"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(role));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(role));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(role));

        tbody.appendChild(row);
    });

    refreshPager("roleTableBody");
}

function openAddModal() {
    document.getElementById("roleModalTitle").textContent = "Add Role";
    document.getElementById("roleId").value = "";
    document.getElementById("roleName").value = "";
}

function openEditModal(role) {
    document.getElementById("roleModalTitle").textContent = "Edit Role";
    document.getElementById("roleId").value = role.role_id;
    document.getElementById("roleName").value = role.role_name;
    new bootstrap.Modal(document.getElementById("roleModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const roleId = document.getElementById("roleId").value;
    const roleName = document.getElementById("roleName").value.trim();

    const jsonData = { role_name: roleName };
    let operation = "insertRole";

    if (roleId) {
        jsonData.role_id = roleId;
        operation = "updateRole";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/roles.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("roleModal"))?.hide();
        displayRoles();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(role) {
    const confirmed = await confirmDeactivate(role.role_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteRole");
    formData.append("json", JSON.stringify({ role_id: role.role_id }));

    const response = await axios({ url: `${baseApiUrl}/roles.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayRoles();
}

async function handleRestore(role) {
    const confirmed = await confirmRestore(role.role_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreRole");
    formData.append("json", JSON.stringify({ role_id: role.role_id }));

    const response = await axios({ url: `${baseApiUrl}/roles.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayRoles();
}
