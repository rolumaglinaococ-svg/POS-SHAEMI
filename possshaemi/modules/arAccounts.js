import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, esc, debounce, refreshPager, formatPeso } from "./utils.js";
import { getActiveCustomerOptions, getCustomersWithoutArAccount } from "./customers.js";
import { getActiveApproverOptions } from "./employees.js";
import { getActiveCreditTermOptions } from "./creditTerms.js";
import { getActivePriceTierOptions } from "./priceTiers.js";

let currentStatus = "all";
let arFormMode = "existing"; // "existing" | "new" — Add mode only

export function initArAccounts() {
    document.getElementById("arAccountForm").addEventListener("submit", handleSubmit);
    document.getElementById("arAccountStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayArAccounts();
    });
    document.getElementById("arAccountSearch").addEventListener("input", debounce(displayArAccounts, 300));
    document.getElementById("btnAddArAccount").addEventListener("click", openAddModal);
    document.getElementById("arModeExistingBtn").addEventListener("click", () => setArFormMode("existing"));
    document.getElementById("arModeNewBtn").addEventListener("click", () => setArFormMode("new"));

    displayArAccounts();
}

export async function displayArAccounts() {
    const keyword = document.getElementById("arAccountSearch").value.trim();

    const params = keyword
        ? { operation: "searchArAccounts", keyword, status: currentStatus }
        : { operation: "getAllArAccounts", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/ar-accounts.php`, { params });
    renderTable(response.data);
}

async function populateDropdowns(selectedCustomerId = null, selectedEmployeeId = null, selectedTermId = null, onlyEligibleCustomers = false) {
    const customerSelect = document.getElementById("arAccountCustomer");
    const employeeSelect = document.getElementById("arAccountEmployee");
    const termSelect = document.getElementById("arAccountCreditTerm");

    const [customers, employees, terms] = await Promise.all([
        onlyEligibleCustomers ? getCustomersWithoutArAccount() : getActiveCustomerOptions(),
        getActiveApproverOptions(),
        getActiveCreditTermOptions()
    ]);

    customerSelect.innerHTML = customers.length
        ? customers.map((c) => `<option value="${c.customer_id}">${esc(c.full_name)}</option>`).join("")
        : `<option value="" disabled selected>No eligible customers — everyone already has an AR account</option>`;
    employeeSelect.innerHTML = employees.map((e) => `<option value="${e.employee_id}">${esc(e.full_name)} (${esc(e.role_name)})</option>`).join("");
    termSelect.innerHTML = terms.map((t) => `<option value="${t.credit_term_id}">${esc(t.term_name)} (${t.due_days}d)</option>`).join("");

    if (selectedCustomerId) customerSelect.value = selectedCustomerId;
    if (selectedEmployeeId) employeeSelect.value = selectedEmployeeId;
    if (selectedTermId) termSelect.value = selectedTermId;
}

function creditStatusBadge(status, ar) {
    const map = { Good: "text-bg-success", Watch: "text-bg-warning", Hold: "text-bg-danger" };
    const auto = ar && ar.credit_hold_source === "AutoOverdue" ? ` <span class="badge text-bg-dark" title="Automatic hold — only the AR Manager can release it">Auto</span>` : "";
    return `<span class="badge ${map[status] || "text-bg-secondary"}">${esc(status)}</span>${auto}`;
}

function accountStatusBadge(status) {
    const map = { Pending: "text-bg-warning", Active: "text-bg-success", Inactive: "text-bg-secondary", Rejected: "text-bg-danger" };
    return `<span class="badge ${map[status] || "text-bg-secondary"}">${esc(status)}</span>`;
}

function renderTable(arAccounts) {
    const tbody = document.getElementById("arAccountTableBody");
    tbody.innerHTML = "";

    if (!arAccounts || arAccounts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    arAccounts.forEach((ar) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(ar.customer_name)}</td>
            <td>${esc(ar.term_name)}</td>
            <td class="num">${formatPeso(ar.credit_limit)}</td>
            <td class="num">${formatPeso(ar.current_balance)}</td>
            <td>${creditStatusBadge(ar.credit_status, ar)}</td>
            <td>${accountStatusBadge(ar.account_status)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary btn-action btn-view"><i class="bi bi-eye"></i></button>
                ${ar.account_status === "Active" ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : ""}
                ${ar.account_status === "Pending" ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                ` : ""}
                ${ar.account_status === "Inactive" ? `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                ` : ""}
                ${ar.account_status === "Rejected" ? `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore" title="Resubmit for Manager approval"><i class="bi bi-send"></i></button>
                ` : ""}
            </td>
        `;

        row.querySelector(".btn-view")?.addEventListener("click", () => openViewModal(ar));
        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(ar));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(ar));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(ar));

        tbody.appendChild(row);
    });

    refreshPager("arAccountTableBody");
}

function openViewModal(ar) {
    document.getElementById("viewModalBody").innerHTML = `
        <p><strong>Customer:</strong> ${esc(ar.customer_name)}</p>
        <p><strong>${ar.account_status === "Pending" ? "Assigned Approver" : "Approved / Reviewed By"}:</strong> ${esc(ar.approved_by_name)}</p>
        <p><strong>Credit Term:</strong> ${esc(ar.term_name)}</p>
        <p><strong>Credit Limit:</strong> ₱${parseFloat(ar.credit_limit).toFixed(2)}</p>
        <p><strong>Current Balance:</strong> ₱${parseFloat(ar.current_balance).toFixed(2)}</p>
        <p><strong>Date Opened:</strong> ${esc(ar.date_opened)}</p>
        <p><strong>Credit Status:</strong> ${creditStatusBadge(ar.credit_status, ar)}</p>
        <p class="mb-0"><strong>Account Status:</strong> ${accountStatusBadge(ar.account_status)}</p>
    `;
    new bootstrap.Modal(document.getElementById("viewModal")).show();
}

const NEW_CUSTOMER_FIELDS = [
    "arNewFirstName", "arNewLastName", "arNewContact", "arNewEmail",
    "arNewStreet", "arNewBarangay", "arNewCity", "arNewProvince",
    "arNewType", "arNewPriceTier"
];

// Existing vs New customer (Add mode only). Inputs in the inactive pane
// are disabled so browser validation only applies to the visible option.
function setArFormMode(mode) {
    arFormMode = mode;
    const isNew = mode === "new";
    document.getElementById("arModeExistingBtn").classList.toggle("active", !isNew);
    document.getElementById("arModeNewBtn").classList.toggle("active", isNew);
    document.getElementById("arAccountCustomerWrapper").classList.toggle("d-none", isNew);
    document.getElementById("arNewCustomerPane").classList.toggle("d-none", !isNew);
    document.getElementById("arAccountCustomer").disabled = isNew;
    NEW_CUSTOMER_FIELDS.forEach((id) => {
        document.getElementById(id).disabled = !isNew;
    });
}

function resetNewCustomerFields() {
    NEW_CUSTOMER_FIELDS.forEach((id) => {
        const el = document.getElementById(id);
        if (el.tagName === "SELECT") el.selectedIndex = 0;
        else el.value = "";
    });
}

async function populateNewPriceTiers() {
    const tiers = await getActivePriceTierOptions();
    document.getElementById("arNewPriceTier").innerHTML = tiers.length
        ? tiers.map((t) => `<option value="${t.price_tier_id}">${esc(t.tier_name)}</option>`).join("")
        : `<option value="" disabled selected>No active price tiers</option>`;
}

async function openAddModal() {
    document.getElementById("arAccountModalTitle").textContent = "Open AR Account";
    document.getElementById("arAccountId").value = "";
    document.getElementById("arAccountCreditLimit").value = "";
    document.getElementById("arAccountDateOpened").value = new Date().toISOString().split("T")[0];
    document.getElementById("arAccountDateOpened").max = new Date().toISOString().split("T")[0];

    document.getElementById("arAccountCustomerWrapper").style.display = "block";
    document.getElementById("arAccountEmployeeWrapper").style.display = "block";
    document.getElementById("arAccountDateWrapper").style.display = "block";
    document.getElementById("arAccountCreditStatusWrapper").style.display = "none";
    document.getElementById("arAccountModeWrapper").style.display = "block";

    resetNewCustomerFields();
    setArFormMode("existing");
    await Promise.all([populateDropdowns(null, null, null, true), populateNewPriceTiers()]);
}

async function openEditModal(ar) {
    document.getElementById("arAccountModalTitle").textContent = "Edit AR Account";
    document.getElementById("arAccountId").value = ar.ar_account_id;
    document.getElementById("arAccountCreditLimit").value = ar.credit_limit;
    document.getElementById("arAccountCreditStatus").value = ar.credit_status;

    // Customer, approver, and date opened are fixed once the account exists;
    // only the credit term, limit, and standing can be revised.
    document.getElementById("arAccountCustomerWrapper").style.display = "none";
    document.getElementById("arAccountEmployeeWrapper").style.display = "none";
    document.getElementById("arAccountDateWrapper").style.display = "none";
    document.getElementById("arAccountCreditStatusWrapper").style.display = "block";
    document.getElementById("arAccountModeWrapper").style.display = "none";
    document.getElementById("arNewCustomerPane").classList.add("d-none");
    NEW_CUSTOMER_FIELDS.forEach((id) => {
        document.getElementById(id).disabled = true;
    });
    document.getElementById("arAccountCustomer").disabled = false;

    await populateDropdowns(ar.customer_id, ar.approved_by, ar.credit_term_id);
    new bootstrap.Modal(document.getElementById("arAccountModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const arAccountId = document.getElementById("arAccountId").value;
    let operation, jsonData;

    if (arAccountId) {
        jsonData = {
            ar_account_id: arAccountId,
            credit_term_id: document.getElementById("arAccountCreditTerm").value,
            credit_limit: document.getElementById("arAccountCreditLimit").value,
            credit_status: document.getElementById("arAccountCreditStatus").value
        };
        operation = "updateArAccount";
    } else if (arFormMode === "new") {
        jsonData = {
            first_name: document.getElementById("arNewFirstName").value.trim(),
            last_name: document.getElementById("arNewLastName").value.trim(),
            contact_number: document.getElementById("arNewContact").value.trim(),
            email: document.getElementById("arNewEmail").value.trim(),
            street: document.getElementById("arNewStreet").value.trim(),
            barangay: document.getElementById("arNewBarangay").value.trim(),
            city: document.getElementById("arNewCity").value.trim(),
            province: document.getElementById("arNewProvince").value.trim(),
            customer_type: document.getElementById("arNewType").value,
            price_tier_id: document.getElementById("arNewPriceTier").value,
            approved_by: document.getElementById("arAccountEmployee").value,
            credit_term_id: document.getElementById("arAccountCreditTerm").value,
            credit_limit: document.getElementById("arAccountCreditLimit").value,
            date_opened: document.getElementById("arAccountDateOpened").value
        };
        operation = "createCustomerWithArAccountAdmin";
    } else {
        jsonData = {
            customer_id: document.getElementById("arAccountCustomer").value,
            approved_by: document.getElementById("arAccountEmployee").value,
            credit_term_id: document.getElementById("arAccountCreditTerm").value,
            credit_limit: document.getElementById("arAccountCreditLimit").value,
            date_opened: document.getElementById("arAccountDateOpened").value
        };
        operation = "insertArAccount";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/ar-accounts.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("arAccountModal"))?.hide();
        displayArAccounts();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(ar) {
    const confirmed = await confirmDeactivate(ar.customer_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteArAccount");
    formData.append("json", JSON.stringify({ ar_account_id: ar.ar_account_id }));

    const response = await axios({ url: `${baseApiUrl}/ar-accounts.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayArAccounts();
}

async function handleRestore(ar) {
    const confirmed = await confirmRestore(ar.customer_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreArAccount");
    formData.append("json", JSON.stringify({ ar_account_id: ar.ar_account_id }));

    const response = await axios({ url: `${baseApiUrl}/ar-accounts.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayArAccounts();
}
