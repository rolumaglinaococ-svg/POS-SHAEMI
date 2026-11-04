import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";
import { getActivePriceTierOptions } from "./priceTiers.js";
import { phAddressData } from "./Phaddressdata.js";

let currentStatus = "active";

export function initCustomers() {
  document
    .getElementById("customerForm")
    .addEventListener("submit", handleSubmit);
  document
    .getElementById("customerStatusFilter")
    .addEventListener("change", (e) => {
      currentStatus = e.target.value;
      displayCustomers();
    });
  document
    .getElementById("customerSearch")
    .addEventListener("input", debounce(displayCustomers, 300));
  document
    .getElementById("btnAddCustomer")
    .addEventListener("click", openAddModal);

  // Contact number: digits only, max 11 characters (PH mobile format, e.g. 09171234567)
  document.getElementById("customerContact").addEventListener("input", (e) => {
    e.target.value = e.target.value.replace(/\D/g, "").slice(0, 11);
  });

  // Province -> City -> Barangay cascade
  document
    .getElementById("customerProvince")
    .addEventListener("change", (e) => {
      populateCities(e.target.value);
      populateBarangays(null, null);
    });
  document.getElementById("customerCity").addEventListener("change", (e) => {
    const province = document.getElementById("customerProvince").value;
    populateBarangays(province, e.target.value);
  });

  displayCustomers();
}

export async function displayCustomers() {
  const keyword = document.getElementById("customerSearch").value.trim();

  const params = keyword
    ? { operation: "searchCustomers", keyword, status: currentStatus }
    : { operation: "getAllCustomers", status: currentStatus };

  const response = await axios.get(`${baseApiUrl}/customers.php`, { params });
  renderTable(response.data);
}

/**
 * Fetch active customers for a <select> dropdown. Used by Suki Cards and AR Accounts.
 */
export async function getActiveCustomerOptions() {
  const response = await axios.get(`${baseApiUrl}/customers.php`, {
    params: { operation: "getActiveCustomersForDropdown" },
  });
  return response.data;
}

/**
 * Active customers with no AR account yet (for the "Open AR Account" modal).
 */
export async function getCustomersWithoutArAccount() {
  const response = await axios.get(`${baseApiUrl}/customers.php`, {
    params: { operation: "getCustomersWithoutArAccount" },
  });
  return response.data;
}

/**
 * Active customers with no Suki card yet (for the "Add Suki Card" modal).
 */
export async function getCustomersWithoutSukiCard() {
  const response = await axios.get(`${baseApiUrl}/customers.php`, {
    params: { operation: "getCustomersWithoutSukiCard" },
  });
  return response.data;
}

async function populatePriceTierDropdown(selectedId = null) {
  const select = document.getElementById("customerPriceTier");
  const tiers = await getActivePriceTierOptions();

  select.innerHTML = tiers
    .map(
      (t) => `<option value="${t.price_tier_id}">${esc(t.tier_name)}</option>`,
    )
    .join("");

  if (selectedId) select.value = selectedId;
}

function populateProvinces(selectedProvince = null) {
  const select = document.getElementById("customerProvince");
  const provinces = Object.keys(phAddressData).sort();

  select.innerHTML =
    `<option value="" selected>Select Province</option>` +
    provinces
      .map((p) => `<option value="${esc(p)}">${esc(p)}</option>`)
      .join("");

  if (selectedProvince) select.value = selectedProvince;
}

function populateCities(province, selectedCity = null) {
  const select = document.getElementById("customerCity");

  if (!province || !phAddressData[province]) {
    select.innerHTML = `<option value="" selected>Select City/Municipality</option>`;
    select.disabled = true;
    return;
  }

  const cities = Object.keys(phAddressData[province]).sort();
  select.innerHTML =
    `<option value="" selected>Select City/Municipality</option>` +
    cities.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join("");
  select.disabled = false;

  if (selectedCity) select.value = selectedCity;
}

function populateBarangays(province, city, selectedBarangay = null) {
  const select = document.getElementById("customerBarangay");

  if (
    !province ||
    !city ||
    !phAddressData[province] ||
    !phAddressData[province][city]
  ) {
    select.innerHTML = `<option value="" selected>Select Barangay</option>`;
    select.disabled = true;
    return;
  }

  const barangays = [...phAddressData[province][city]].sort();
  select.innerHTML =
    `<option value="" selected>Select Barangay</option>` +
    barangays
      .map((b) => `<option value="${esc(b)}">${esc(b)}</option>`)
      .join("");
  select.disabled = false;

  if (selectedBarangay) select.value = selectedBarangay;
}

function renderTable(customers) {
  const tbody = document.getElementById("customerTableBody");
  tbody.innerHTML = "";

  if (!customers || customers.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>`;
    return;
  }

  customers.forEach((customer) => {
    const row = document.createElement("tr");

    row.innerHTML = `
            <td class="text-start">${esc(customer.first_name)} ${esc(customer.last_name)}</td>
            <td>${esc(customer.contact_number)}</td>
            <td>${esc(customer.tier_name)}</td>
            <td>${esc(customer.customer_type)}</td>
            <td>${statusBadge(customer.is_active)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary btn-action btn-view"><i class="bi bi-eye"></i></button>
                ${
                  customer.is_active == 1
                    ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                `
                    : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `
                }
            </td>
        `;

    row
      .querySelector(".btn-view")
      ?.addEventListener("click", () => openViewModal(customer));
    row
      .querySelector(".btn-edit")
      ?.addEventListener("click", () => openEditModal(customer));
    row
      .querySelector(".btn-delete")
      ?.addEventListener("click", () => handleDelete(customer));
    row
      .querySelector(".btn-restore")
      ?.addEventListener("click", () => handleRestore(customer));

    tbody.appendChild(row);
  });

    refreshPager("customerTableBody");
}

function openViewModal(customer) {
  document.getElementById("viewModalBody").innerHTML = `
        <p><strong>Name:</strong> ${esc(customer.first_name)} ${esc(customer.last_name)}</p>
        <p><strong>Contact Number:</strong> ${esc(customer.contact_number)}</p>
        <p><strong>Email:</strong> ${esc(customer.email) || "—"}</p>
        <p><strong>Address:</strong> ${esc(customer.street)}, ${esc(customer.barangay)}, ${esc(customer.city)}, ${esc(customer.province)}</p>
        <p><strong>Price Tier:</strong> ${esc(customer.tier_name)}</p>
        <p><strong>Customer Type:</strong> ${esc(customer.customer_type)}</p>
        <p class="mb-0"><strong>Status:</strong> ${statusBadge(customer.is_active)}</p>
    `;
  new bootstrap.Modal(document.getElementById("viewModal")).show();
}

async function openAddModal() {
  document.getElementById("customerModalTitle").textContent = "Add Customer";
  document.getElementById("customerId").value = "";
  document.getElementById("customerFirstName").value = "";
  document.getElementById("customerLastName").value = "";
  document.getElementById("customerContact").value = "";
  document.getElementById("customerEmail").value = "";
  document.getElementById("customerType").value = "Regular";
  document.getElementById("customerStreet").value = "";

  populateProvinces();
  populateCities(null);
  populateBarangays(null, null);

  await populatePriceTierDropdown();
}

async function openEditModal(customer) {
  document.getElementById("customerModalTitle").textContent = "Edit Customer";
  document.getElementById("customerId").value = customer.customer_id;
  document.getElementById("customerFirstName").value = customer.first_name;
  document.getElementById("customerLastName").value = customer.last_name;
  document.getElementById("customerContact").value = customer.contact_number;
  document.getElementById("customerEmail").value = customer.email ?? "";
  document.getElementById("customerType").value = customer.customer_type;
  document.getElementById("customerStreet").value = customer.street ?? "";

  populateProvinces(customer.province);
  populateCities(customer.province, customer.city);
  populateBarangays(customer.province, customer.city, customer.barangay);

  await populatePriceTierDropdown(customer.price_tier_id);
  new bootstrap.Modal(document.getElementById("customerModal")).show();
}

async function handleSubmit(e) {
  e.preventDefault();

  const contactNumber = document.getElementById("customerContact").value.trim();

  if (contactNumber.length !== 11) {
    showToast("Contact number must be exactly 11 digits.", "danger");
    return;
  }

  const customerId = document.getElementById("customerId").value;
  const jsonData = {
    price_tier_id: document.getElementById("customerPriceTier").value,
    first_name: document.getElementById("customerFirstName").value.trim(),
    last_name: document.getElementById("customerLastName").value.trim(),
    contact_number: contactNumber,
    email: document.getElementById("customerEmail").value.trim(),
    customer_type: document.getElementById("customerType").value,
    street: document.getElementById("customerStreet").value.trim(),
    barangay: document.getElementById("customerBarangay").value,
    city: document.getElementById("customerCity").value,
    province: document.getElementById("customerProvince").value,
  };
  let operation = "insertCustomer";

  if (customerId) {
    jsonData.customer_id = customerId;
    operation = "updateCustomer";
  }

  const formData = new FormData();
  formData.append("operation", operation);
  formData.append("json", JSON.stringify(jsonData));

  const response = await axios({
    url: `${baseApiUrl}/customers.php`,
    method: "POST",
    data: formData,
  });

  if (response.data.success) {
    showToast(response.data.message, "success");
    bootstrap.Modal.getInstance(
      document.getElementById("customerModal"),
    )?.hide();
    displayCustomers();
  } else {
    showToast(response.data.message, "danger");
  }
}

async function handleDelete(customer) {
  const confirmed = await confirmDeactivate(
    `${customer.first_name} ${customer.last_name}`,
  );
  if (!confirmed) return;

  const formData = new FormData();
  formData.append("operation", "deleteCustomer");
  formData.append(
    "json",
    JSON.stringify({ customer_id: customer.customer_id }),
  );

  const response = await axios({
    url: `${baseApiUrl}/customers.php`,
    method: "POST",
    data: formData,
  });

  showToast(
    response.data.message,
    response.data.success ? "success" : "danger",
  );
  if (response.data.success) displayCustomers();
}

async function handleRestore(customer) {
  const confirmed = await confirmRestore(
    `${customer.first_name} ${customer.last_name}`,
  );
  if (!confirmed) return;

  const formData = new FormData();
  formData.append("operation", "restoreCustomer");
  formData.append(
    "json",
    JSON.stringify({ customer_id: customer.customer_id }),
  );

  const response = await axios({
    url: `${baseApiUrl}/customers.php`,
    method: "POST",
    data: formData,
  });

  showToast(
    response.data.message,
    response.data.success ? "success" : "danger",
  );
  if (response.data.success) displayCustomers();
}
