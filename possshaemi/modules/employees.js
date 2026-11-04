import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";
import { getActiveRolesForDropdown } from "./roles.js";

let currentStatus = "active";

/* Strong password policy (mirrors api/employees.php): 8+ chars, 3+ digits, 1+ symbol.
   Returns the first failing rule's message, or null when the password passes. */
function passwordPolicyError(password) {
    if (password.length < 8) return "Password must be at least 8 characters.";
    if ((password.match(/[0-9]/g) || []).length < 3) return "Password must contain at least 3 digits.";
    if (!/[^A-Za-z0-9]/.test(password)) return "Password must contain at least 1 symbol (e.g. ! @ # $ %).";
    return null;
}

function refreshPasswordChecklist() {
    const password = document.getElementById("employeePassword").value;
    const set = (id, ok) => document.getElementById(id)?.classList.toggle("ok", ok);
    set("ppLen", password.length >= 8);
    set("ppDigit", (password.match(/[0-9]/g) || []).length >= 3);
    set("ppSym", /[^A-Za-z0-9]/.test(password));
}

export function initEmployees() {
    document.getElementById("employeeForm").addEventListener("submit", handleSubmit);
    document.getElementById("employeePassword").addEventListener("input", refreshPasswordChecklist);
    document.getElementById("employeeStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayEmployees();
    });
    document.getElementById("employeeSearch").addEventListener("input", debounce(displayEmployees, 300));
    document.getElementById("btnAddEmployee").addEventListener("click", openAddModal);

    displayEmployees();
}

export async function displayEmployees() {
    const keyword = document.getElementById("employeeSearch").value.trim();

    const params = keyword
        ? { operation: "searchEmployees", keyword, status: currentStatus }
        : { operation: "getAllEmployees", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/employees.php`, { params });
    renderTable(response.data);
}

/**
 * Fetch active employees for a <select> dropdown. Used by AR Accounts (Approved By).
 */
export async function getActiveEmployeeOptions() {
    const response = await axios.get(`${baseApiUrl}/employees.php`, { params: { operation: "getActiveEmployeesForDropdown" } });
    return response.data;
}

export async function getActiveApproverOptions() {
    const response = await axios.get(`${baseApiUrl}/employees.php`, { params: { operation: "getActiveApproversForDropdown" } });
    return response.data;
}

async function populateRoleDropdown(selectedId = null) {
    const select = document.getElementById("employeeRole");
    const roles = await getActiveRolesForDropdown();

    select.innerHTML = roles
        .map((r) => `<option value="${r.role_id}">${esc(r.role_name)}</option>`)
        .join("");

    if (selectedId) select.value = selectedId;
}

function renderTable(employees) {
    const tbody = document.getElementById("employeeTableBody");
    tbody.innerHTML = "";

    if (!employees || employees.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    employees.forEach((employee) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(employee.first_name)} ${esc(employee.last_name)}</td>
            <td>${esc(employee.role_name)}</td>
            <td>${esc(employee.contact_number)}</td>
            <td>${esc(employee.username)}</td>
            <td>${statusBadge(employee.is_active)}</td>
            <td class="text-end">
                ${employee.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(employee));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(employee));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(employee));

        tbody.appendChild(row);
    });

    refreshPager("employeeTableBody");
}

async function openAddModal() {
    document.getElementById("employeeModalTitle").textContent = "Add Employee";
    document.getElementById("employeeId").value = "";
    document.getElementById("employeeFirstName").value = "";
    document.getElementById("employeeLastName").value = "";
    document.getElementById("employeeContact").value = "";
    document.getElementById("employeeUsername").value = "";
    document.getElementById("employeePassword").value = "";
    document.getElementById("employeePassword").required = true;
    document.getElementById("employeePasswordLabel").textContent = "Password *";
    document.getElementById("employeePasswordHint").style.display = "none";
    refreshPasswordChecklist();
    await populateRoleDropdown();
}

async function openEditModal(employee) {
    document.getElementById("employeeModalTitle").textContent = "Edit Employee";
    document.getElementById("employeeId").value = employee.employee_id;
    document.getElementById("employeeFirstName").value = employee.first_name;
    document.getElementById("employeeLastName").value = employee.last_name;
    document.getElementById("employeeContact").value = employee.contact_number ?? "";
    document.getElementById("employeeUsername").value = employee.username;
    document.getElementById("employeePassword").value = "";
    document.getElementById("employeePassword").required = false;
    document.getElementById("employeePasswordLabel").textContent = "Password";
    document.getElementById("employeePasswordHint").style.display = "block";
    refreshPasswordChecklist();
    await populateRoleDropdown(employee.role_id);
    new bootstrap.Modal(document.getElementById("employeeModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const employeeId = document.getElementById("employeeId").value;

    const typedPassword = document.getElementById("employeePassword").value;
    if (!employeeId || typedPassword !== "") {
        const policyError = passwordPolicyError(typedPassword);
        if (policyError) {
            showToast(policyError, "danger");
            return;
        }
    }

    const jsonData = {
        role_id: document.getElementById("employeeRole").value,
        first_name: document.getElementById("employeeFirstName").value.trim(),
        last_name: document.getElementById("employeeLastName").value.trim(),
        contact_number: document.getElementById("employeeContact").value.trim(),
        username: document.getElementById("employeeUsername").value.trim(),
        password: document.getElementById("employeePassword").value
    };
    let operation = "insertEmployee";

    if (employeeId) {
        jsonData.employee_id = employeeId;
        operation = "updateEmployee";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/employees.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("employeeModal"))?.hide();
        displayEmployees();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(employee) {
    const confirmed = await confirmDeactivate(`${employee.first_name} ${employee.last_name}`);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteEmployee");
    formData.append("json", JSON.stringify({ employee_id: employee.employee_id }));

    const response = await axios({ url: `${baseApiUrl}/employees.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayEmployees();
}

async function handleRestore(employee) {
    const confirmed = await confirmRestore(`${employee.first_name} ${employee.last_name}`);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreEmployee");
    formData.append("json", JSON.stringify({ employee_id: employee.employee_id }));

    const response = await axios({ url: `${baseApiUrl}/employees.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayEmployees();
}
