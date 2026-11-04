import { baseApiUrl, showToast, esc, debounce, formatPeso, formatDateTime } from "./utils.js";
import { openModal } from "./arApi.js";
import { initActivityFeed, initActivityPage, displayActivityLog, markActivitySeen } from "./activityFeed.js";

let currentStatus = "pending";

export function initManagerAr(session) {
    document.getElementById("arAccountStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayArAccounts();
    });
    document.getElementById("btnAddArAccount")?.addEventListener("click", openAddArAccount);
    document.getElementById("arAccountSearch").addEventListener("input", debounce(displayArAccounts, 300));

    displayArAccounts();

    // Manager also gets the shared Activity Log tab + live toast/badge feed,
    // so they see when the Administrator changes master data.
    initActivityFeed({
        employeeId: session.employee_id,
        onNew: () => {
            if (document.getElementById("section-arAccounts").classList.contains("d-none") === false) {
                displayArAccounts();
            }
            if (document.getElementById("section-activity")?.classList.contains("d-none") === false) {
                displayActivityLog();
            }
        }
    });
}

export async function displayArAccounts() {
    const keyword = document.getElementById("arAccountSearch").value.trim();

    const params = keyword
        ? { operation: "searchArAccounts", keyword, status: currentStatus }
        : { operation: "getAllArAccounts", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/ar-accounts.php`, { params, withCredentials: true });
    renderTable(response.data);
}

function statusBadge(status) {
    const map = { Pending: "text-bg-warning", Active: "text-bg-success", Inactive: "text-bg-secondary", Rejected: "text-bg-danger" };
    return `<span class="badge ${map[status] || "text-bg-secondary"}">${esc(status)}</span>`;
}

function renderTable(arAccounts) {
    const tbody = document.getElementById("arAccountTableBody");
    tbody.innerHTML = "";

    if (!arAccounts || arAccounts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    arAccounts.forEach((ar) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(ar.customer_name)}</td>
            <td>${esc(ar.term_name)}</td>
            <td>${formatPeso(ar.credit_limit)}</td>
            <td>${formatPeso(ar.current_balance)}</td>
            <td>${statusBadge(ar.account_status)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary btn-action btn-view"><i class="bi bi-eye"></i></button>
                ${ar.account_status === "Pending" ? `
                    <button class="btn btn-sm btn-outline-success btn-action btn-approve"><i class="bi bi-check-lg"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-reject"><i class="bi bi-x-lg"></i></button>
                ` : ``}
            </td>
        `;

        row.querySelector(".btn-view")?.addEventListener("click", () => openViewModal(ar));
        row.querySelector(".btn-approve")?.addEventListener("click", () => handleApprove(ar));
        row.querySelector(".btn-reject")?.addEventListener("click", () => handleReject(ar));

        tbody.appendChild(row);
    });
}

function openViewModal(ar) {
    document.getElementById("viewModalBody").innerHTML = `
        <p><strong>Customer:</strong> ${esc(ar.customer_name)}</p>
        <p><strong>Assigned Approver:</strong> ${esc(ar.approved_by_name)}</p>
        <p><strong>Credit Term:</strong> ${esc(ar.term_name)}</p>
        <p><strong>Credit Limit:</strong> ${formatPeso(ar.credit_limit)}</p>
        <p><strong>Current Balance:</strong> ${formatPeso(ar.current_balance)}</p>
        <p><strong>Date Opened:</strong> ${formatDateTime(ar.date_opened)}</p>
        <p class="mb-0"><strong>Status:</strong> ${statusBadge(ar.account_status)}</p>
    `;
    new bootstrap.Modal(document.getElementById("viewModal")).show();
}

function confirmAccountAction(ar, action) {
    const isApprove = action === "approveArAccount";
    const { el, modal } = openModal({
        title: `${isApprove ? "Approve" : "Reject"} AR Account`,
        body: `<p class="mb-1"><strong>${esc(ar.customer_name)}</strong></p>
               <p class="small text-muted mb-0">Credit limit ${formatPeso(ar.credit_limit)} &middot; Current balance ${formatPeso(ar.current_balance)} &middot; Term ${esc(ar.term_name)}</p>
               <p class="mt-2 mb-0">${isApprove ? "The customer can start charging purchases to this account once approved." : "The account request will be marked as rejected."}</p>`,
        footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn ${isApprove ? "btn-success" : "btn-danger"}" id="acctConfirm">${isApprove ? "Approve Account" : "Reject Account"}</button>`,
    });
    el.querySelector("#acctConfirm").addEventListener("click", async (ev) => {
        const btn = ev.currentTarget;
        btn.disabled = true;
        try {
            const formData = new FormData();
            formData.append("operation", action);
            formData.append("json", JSON.stringify({ ar_account_id: ar.ar_account_id }));
            const response = await axios({ url: `${baseApiUrl}/ar-accounts.php`, method: "POST", data: formData, withCredentials: true });
            showToast(response.data.message, response.data.success ? "success" : "danger");
            if (response.data.success) {
                modal.hide();
                displayArAccounts();
            }
        } finally {
            btn.disabled = false;
        }
    });
}

function handleApprove(ar) {
    confirmAccountAction(ar, "approveArAccount");
}

/* ---------------- Add AR Account ----------------
 * Option A: existing customer (searchable picker; only customers with no AR
 * account are listed). Option B: new customer + account in one transaction.
 * Accounts created here are Active immediately (business decision 2026-10-20). */

async function arApiCall(operation, payload) {
    if (payload === undefined) {
        const response = await axios.get(`${baseApiUrl}/ar-accounts.php`, { params: { operation }, withCredentials: true });
        return response.data;
    }
    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(payload));
    const response = await axios({ url: `${baseApiUrl}/ar-accounts.php`, method: "POST", data: formData, withCredentials: true });
    return response.data;
}

async function openAddArAccount() {
    const { el, modal } = openModal({
        title: "Add AR Account",
        size: "modal-lg",
        body: `
        <ul class="nav nav-pills mb-3">
          <li class="nav-item"><a href="#" class="nav-link active" data-atab="existing">Existing Customer</a></li>
          <li class="nav-item"><a href="#" class="nav-link" data-atab="new">New Customer</a></li>
        </ul>
        <div data-pane="existing">
          <div class="mb-2"><label class="form-label">Customer</label>
            <div class="combo-wrap"><input class="form-control" id="naCustomer" placeholder="Type customer name to search..." autocomplete="off"><div class="combo-list d-none" id="naCustomerList"></div></div>
            <div class="form-text">Only customers without an AR account are listed.</div></div>
          <div class="card p-3 mb-2 d-none" id="naDetails"></div>
        </div>
        <div data-pane="new" class="d-none">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">First name *</label><input class="form-control" id="ncFirst"></div>
            <div class="col-md-6"><label class="form-label">Last name *</label><input class="form-control" id="ncLast"></div>
            <div class="col-md-6"><label class="form-label">Contact number * (11 digits)</label><input class="form-control" id="ncContact" maxlength="11" inputmode="numeric"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" id="ncEmail" type="email"></div>
            <div class="col-md-6"><label class="form-label">Street</label><input class="form-control" id="ncStreet"></div>
            <div class="col-md-6"><label class="form-label">Barangay</label><input class="form-control" id="ncBarangay"></div>
            <div class="col-md-6"><label class="form-label">City</label><input class="form-control" id="ncCity"></div>
            <div class="col-md-6"><label class="form-label">Province</label><input class="form-control" id="ncProvince"></div>
            <div class="col-md-6"><label class="form-label">Customer type</label><select class="form-select" id="ncType"><option>Regular</option><option>Wholesale</option><option>VIP</option></select></div>
            <div class="col-md-6"><label class="form-label">Price tier *</label><select class="form-select" id="ncTier"></select></div>
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-md-6"><label class="form-label">Credit limit *</label><div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="naLimit" min="0" step="0.01" placeholder="0.00"></div></div>
          <div class="col-md-6"><label class="form-label">Credit term *</label><select class="form-select" id="naTerm"></select></div>
        </div>
        <p class="small text-muted mt-2 mb-0">The account is created <strong>Active</strong> with a ₱0.00 balance — the customer can charge to it right away.</p>`,
        footer: `<button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="naSubmit">Create AR Account</button>`,
    });

    let mode = "existing";
    let picked = null;

    el.querySelectorAll("[data-atab]").forEach((a) =>
        a.addEventListener("click", (e) => {
            e.preventDefault();
            mode = a.dataset.atab;
            el.querySelectorAll("[data-atab]").forEach((x) => x.classList.toggle("active", x === a));
            el.querySelector('[data-pane="existing"]').classList.toggle("d-none", mode !== "existing");
            el.querySelector('[data-pane="new"]').classList.toggle("d-none", mode !== "new");
        }),
    );

    const [options, customers] = await Promise.all([arApiCall("getArFormOptions"), arApiCall("getEligibleCustomers")]);
    if (options.success) {
        el.querySelector("#naTerm").innerHTML = options.credit_terms.map((t) => `<option value="${t.credit_term_id}">${esc(t.term_name)} (${t.due_days} days)</option>`).join("");
        el.querySelector("#ncTier").innerHTML = options.price_tiers.map((t) => `<option value="${t.price_tier_id}">${esc(t.tier_name)}</option>`).join("");
    }

    const input = el.querySelector("#naCustomer");
    const list = el.querySelector("#naCustomerList");
    const items = Array.isArray(customers) ? customers : [];
    const renderList = (rowsToShow) => {
        list.innerHTML = rowsToShow.length
            ? rowsToShow.map((c, i) => `<button type="button" class="combo-item" data-i="${i}"><span class="combo-item-label">${esc(c.customer_name)}</span><span class="combo-item-sub">${esc(c.contact_number || "")}</span></button>`).join("")
            : `<div class="combo-empty">No eligible customers found.</div>`;
        list.querySelectorAll(".combo-item").forEach((b) =>
            b.addEventListener("mousedown", (ev) => {
                ev.preventDefault();
                picked = rowsToShow[Number(b.dataset.i)];
                input.value = picked.customer_name;
                list.classList.add("d-none");
                const d = el.querySelector("#naDetails");
                d.classList.remove("d-none");
                d.innerHTML = `<div class="fw-semibold">${esc(picked.customer_name)}</div>
                    <div class="small text-muted">Contact ${esc(picked.contact_number || "—")}${picked.email ? ` &middot; ${esc(picked.email)}` : ""}<br>
                    ${esc(picked.address || "No address on file")} &middot; ${esc(picked.customer_type || "Regular")}${picked.price_tier ? ` &middot; ${esc(picked.price_tier)}` : ""}</div>`;
            }),
        );
    };
    input.addEventListener("focus", () => { renderList(items); list.classList.remove("d-none"); });
    input.addEventListener("click", () => { renderList(items.filter((c) => c.customer_name.toLowerCase().includes(input.value.trim().toLowerCase()))); list.classList.remove("d-none"); });
    input.addEventListener("blur", () => setTimeout(() => list.classList.add("d-none"), 120));
    input.addEventListener("input", () => {
        picked = null;
        el.querySelector("#naDetails").classList.add("d-none");
        renderList(items.filter((c) => c.customer_name.toLowerCase().includes(input.value.trim().toLowerCase())));
        list.classList.remove("d-none");
    });

    el.querySelector("#naSubmit").addEventListener("click", async (ev) => {
        const btn = ev.currentTarget;
        const credit_limit = parseFloat(el.querySelector("#naLimit").value);
        const credit_term_id = Number(el.querySelector("#naTerm").value);
        if (!(credit_limit >= 0) || !el.querySelector("#naLimit").value) return showToast("Enter a credit limit (zero or more).", "danger");
        if (!credit_term_id) return showToast("Select a credit term.", "danger");

        let operation, payload;
        if (mode === "existing") {
            if (!picked) return showToast("Search for and select a customer.", "danger");
            operation = "createArAccount";
            payload = { customer_id: Number(picked.customer_id), credit_term_id, credit_limit };
        } else {
            operation = "createCustomerWithArAccount";
            payload = {
                first_name: el.querySelector("#ncFirst").value.trim(),
                last_name: el.querySelector("#ncLast").value.trim(),
                contact_number: el.querySelector("#ncContact").value.trim(),
                email: el.querySelector("#ncEmail").value.trim(),
                street: el.querySelector("#ncStreet").value.trim(),
                barangay: el.querySelector("#ncBarangay").value.trim(),
                city: el.querySelector("#ncCity").value.trim(),
                province: el.querySelector("#ncProvince").value.trim(),
                customer_type: el.querySelector("#ncType").value,
                price_tier_id: Number(el.querySelector("#ncTier").value),
                credit_term_id,
                credit_limit,
            };
            if (!payload.first_name || !payload.last_name) return showToast("First name and last name are required.", "danger");
            if (!/^[0-9]{11}$/.test(payload.contact_number)) return showToast("Contact number must be exactly 11 digits.", "danger");
            if (!payload.price_tier_id) return showToast("Select a price tier.", "danger");
        }

        btn.disabled = true;
        try {
            const res = await arApiCall(operation, payload);
            showToast(res.message, res.success ? "success" : "danger");
            if (res.success) {
                modal.hide();
                currentStatus = "active";
                document.getElementById("arAccountStatusFilter").value = "active";
                displayArAccounts();
            }
        } finally {
            btn.disabled = false;
        }
    });
}

function handleReject(ar) {
    confirmAccountAction(ar, "rejectArAccount");
}

export { initActivityPage, markActivitySeen };
