import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let currentStatus = "active";

export function initCounters() {
    document.getElementById("counterForm").addEventListener("submit", handleSubmit);
    document.getElementById("counterStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayCounters();
    });
    document.getElementById("counterSearch").addEventListener("input", debounce(displayCounters, 300));
    document.getElementById("btnAddCounter").addEventListener("click", openAddModal);

    displayCounters();
}

export async function displayCounters() {
    const keyword = document.getElementById("counterSearch").value.trim();

    const params = keyword
        ? { operation: "searchCounters", keyword, status: currentStatus }
        : { operation: "getAllCounters", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/counters.php`, { params });
    renderTable(response.data);
}

function renderTable(counters) {
    const tbody = document.getElementById("counterTableBody");
    tbody.innerHTML = "";

    if (!counters || counters.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    counters.forEach((counter) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(counter.counter_name)}</td>
            <td>${esc(counter.counter_number)}</td>
            <td><span class="badge ${counter.status === "Open" ? "text-bg-success" : "text-bg-secondary"}">${esc(counter.status)}</span></td>
            <td>${statusBadge(counter.is_active)}</td>
            <td class="text-end">
                ${counter.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(counter));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(counter));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(counter));

        tbody.appendChild(row);
    });

    refreshPager("counterTableBody");
}

function openAddModal() {
    document.getElementById("counterModalTitle").textContent = "Add Counter";
    document.getElementById("counterId").value = "";
    document.getElementById("counterName").value = "";
    document.getElementById("counterNumber").value = "";
    document.getElementById("counterOpenStatus").value = "Closed";
}

function openEditModal(counter) {
    document.getElementById("counterModalTitle").textContent = "Edit Counter";
    document.getElementById("counterId").value = counter.counter_id;
    document.getElementById("counterName").value = counter.counter_name;
    document.getElementById("counterNumber").value = counter.counter_number;
    document.getElementById("counterOpenStatus").value = counter.status;
    new bootstrap.Modal(document.getElementById("counterModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const counterId = document.getElementById("counterId").value;
    const jsonData = {
        counter_name: document.getElementById("counterName").value.trim(),
        counter_number: document.getElementById("counterNumber").value,
        status: document.getElementById("counterOpenStatus").value
    };
    let operation = "insertCounter";

    if (counterId) {
        jsonData.counter_id = counterId;
        operation = "updateCounter";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/counters.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("counterModal"))?.hide();
        displayCounters();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(counter) {
    const confirmed = await confirmDeactivate(counter.counter_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteCounter");
    formData.append("json", JSON.stringify({ counter_id: counter.counter_id }));

    const response = await axios({ url: `${baseApiUrl}/counters.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCounters();
}

async function handleRestore(counter) {
    const confirmed = await confirmRestore(counter.counter_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreCounter");
    formData.append("json", JSON.stringify({ counter_id: counter.counter_id }));

    const response = await axios({ url: `${baseApiUrl}/counters.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayCounters();
}
