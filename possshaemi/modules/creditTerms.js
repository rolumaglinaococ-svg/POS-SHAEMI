import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initCreditTerms() {
    document.getElementById("creditTermForm").addEventListener("submit", handleSubmit);
    document.getElementById("creditTermStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayCreditTerms();
    });
    document.getElementById("creditTermSearch").addEventListener("input", debounce(displayCreditTerms, 300));
    document.getElementById("btnAddCreditTerm").addEventListener("click", openAddModal);

    displayCreditTerms();
}

export async function displayCreditTerms() {
    const keyword = document.getElementById("creditTermSearch").value.trim();

    const params = keyword
        ? { operation: "searchCreditTerms", keyword, status: currentStatus }
        : { operation: "getAllCreditTerms", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/credit-terms.php`, { params });
    renderTable(response.data);
}

export async function getActiveCreditTermOptions() {
    const response = await axios.get(`${baseApiUrl}/credit-terms.php`, { params: { operation: "getActiveCreditTermsForDropdown" } });
    return response.data;
}

function renderTable(terms) {
    const tbody = document.getElementById("creditTermTableBody");
    tbody.innerHTML = "";

    if (!terms || terms.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    terms.forEach((term) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(term.term_name)}</td>
            <td class="num">${esc(term.due_days)} day(s)</td>
            <td>${statusBadge(term.is_active)}</td>
            <td class="text-end">
                ${term.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(term));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(term));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(term));

        tbody.appendChild(row);
    });

    refreshPager("creditTermTableBody");
}

function openAddModal() {
    document.getElementById("creditTermModalTitle").textContent = "Add Credit Term";
    document.getElementById("creditTermId").value = "";
    document.getElementById("termName").value = "";
    document.getElementById("dueDays").value = "";
}

function openEditModal(term) {
    document.getElementById("creditTermModalTitle").textContent = "Edit Credit Term";
    document.getElementById("creditTermId").value = term.credit_term_id;
    document.getElementById("termName").value = term.term_name;
    document.getElementById("dueDays").value = term.due_days;
    new bootstrap.Modal(document.getElementById("creditTermModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const creditTermId = document.getElementById("creditTermId").value;
    const jsonData = {
        term_name: document.getElementById("termName").value.trim(),
        due_days: document.getElementById("dueDays").value
    };
    let operation = "insertCreditTerm";

    if (creditTermId) {
        jsonData.credit_term_id = creditTermId;
        operation = "updateCreditTerm";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/credit-terms.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("creditTermModal"))?.hide();
        displayCreditTerms();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(term) {
    const confirmed = await confirmDeactivate(term.term_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteCreditTerm");
    formData.append("json", JSON.stringify({ credit_term_id: term.credit_term_id }));

    const response = await axios({ url: `${baseApiUrl}/credit-terms.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCreditTerms();
}

async function handleRestore(term) {
    const confirmed = await confirmRestore(term.term_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreCreditTerm");
    formData.append("json", JSON.stringify({ credit_term_id: term.credit_term_id }));

    const response = await axios({ url: `${baseApiUrl}/credit-terms.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCreditTerms();
}
