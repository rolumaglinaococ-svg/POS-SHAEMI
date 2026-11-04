import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";
import { getActiveCategoryOptions } from "./categories.js";
import { getActivePriceTierOptions } from "./priceTiers.js";
import { getActiveUnitOptions } from "./units.js";

let currentStatus = "active";

export function initProducts() {
    document.getElementById("productForm").addEventListener("submit", handleSubmit);
    document.getElementById("productStatusFilter").addEventListener("change", (e) => {
        currentStatus = e.target.value;
        displayProducts();
    });
    document.getElementById("productSearch").addEventListener("input", debounce(displayProducts, 300));
    document.getElementById("btnAddProduct").addEventListener("click", openAddModal);

    displayProducts();
}

export async function displayProducts() {
    const keyword = document.getElementById("productSearch").value.trim();

    const params = keyword
        ? { operation: "searchProducts", keyword, status: currentStatus }
        : { operation: "getAllProducts", status: currentStatus };

    const response = await axios.get(`${baseApiUrl}/products.php`, { params });
    renderTable(response.data);
}

/**
 * Fetch active products for a <select> dropdown. Used by the Product Prices module.
 */
export async function getActiveProductOptions() {
    const response = await axios.get(`${baseApiUrl}/products.php`, { params: { operation: "getActiveProductsForDropdown" } });
    return response.data;
}

async function populateCategoryDropdown(selectedId = null) {
    const select = document.getElementById("productCategory");
    const categories = await getActiveCategoryOptions();

    select.innerHTML = categories
        .map((c) => `<option value="${c.category_id}">${esc(c.category_name)}</option>`)
        .join("");

    if (selectedId) select.value = selectedId;
}

async function populateUnitDropdown(selectedId = null) {
    const select = document.getElementById("productUnit");
    const units = await getActiveUnitOptions();
    select.innerHTML = `<option value="">-- Select unit --</option>` + units.map((unit) => `<option value="${unit.unit_id}">${esc(unit.unit_name)} (${esc(unit.unit_code)})</option>`).join("");
    if (selectedId) select.value = selectedId;
}

async function populateTierPriceInputs(existingPrices = {}) {
    const container = document.getElementById("productTierPrices");
    const tiers = await getActivePriceTierOptions();
    container.innerHTML = tiers.length
        ? tiers.map((tier) => `
            <div class="col-md-6">
                <label class="form-label small">${esc(tier.tier_name)} price${tier.tier_name === "Regular" ? " *" : ""}</label>
                <div class="input-group"><span class="input-group-text">₱</span>
                    <input type="number" min="0" step="0.01" class="form-control product-tier-price" data-tier-id="${tier.price_tier_id}" placeholder="Optional" value="${existingPrices[tier.price_tier_id] ?? ""}">
                </div>
            </div>`).join("")
        : `<div class="col-12 text-danger small">No active price tiers are available. Add and activate a price tier first.</div>`;
}

function renderTable(products) {
    const tbody = document.getElementById("productTableBody");
    tbody.innerHTML = "";

    if (!products || products.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }

    products.forEach((product) => {
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>${esc(product.barcode || "—")}</td>
            <td>${esc(product.product_name)}${product.is_wholesale_barcode == 1 ? ' <span class="badge bg-warning text-dark">Wholesale barcode</span>' : ""}</td>
            <td>${esc(product.category_name)}</td>
            <td>${esc(product.unit)}</td>
            <td>${product.is_vatable == 1 ? "Yes" : "No"}</td>
            <td>${statusBadge(product.is_active)}</td>
            <td class="text-end">
                ${product.is_active == 1 ? `
                    <button class="btn btn-sm btn-outline-secondary btn-action btn-edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action btn-delete"><i class="bi bi-slash-circle"></i></button>
                ` : `
                    <button class="btn btn-sm btn-outline-success btn-action btn-restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                `}
            </td>
        `;

        row.querySelector(".btn-edit")?.addEventListener("click", () => openEditModal(product));
        row.querySelector(".btn-delete")?.addEventListener("click", () => handleDelete(product));
        row.querySelector(".btn-restore")?.addEventListener("click", () => handleRestore(product));

        tbody.appendChild(row);
    });

    refreshPager("productTableBody");
}

async function openAddModal() {
    document.getElementById("productModalTitle").textContent = "Add Product";
    document.getElementById("productId").value = "";
    document.getElementById("productBarcode").value = "";
    document.getElementById("productName").value = "";
    document.getElementById("productUnit").value = "";
    document.getElementById("productVatable").value = "1";
    document.getElementById("productWholesaleBarcode").checked = false;
    document.getElementById("productTierPricesSection").classList.remove("d-none");
    await Promise.all([populateCategoryDropdown(), populateUnitDropdown(), populateTierPriceInputs()]);
}

async function openEditModal(product) {
    document.getElementById("productModalTitle").textContent = "Edit Product";
    document.getElementById("productId").value = product.product_id;
    document.getElementById("productBarcode").value = product.barcode || "";
    document.getElementById("productName").value = product.product_name;
    document.getElementById("productVatable").value = product.is_vatable;
    document.getElementById("productWholesaleBarcode").checked = product.is_wholesale_barcode == 1;
    document.getElementById("productTierPricesSection").classList.remove("d-none");
    // Prefill the product's current tier prices so they can be edited here
    // (previously this section was hidden in Edit and prices could only be
    // changed from the Product Prices section).
    const priceResponse = await axios.get(`${baseApiUrl}/product-prices.php`, {
        params: { operation: "getPricesForProduct", product_id: product.product_id }
    });
    const existingPrices = {};
    (Array.isArray(priceResponse.data) ? priceResponse.data : []).forEach((row) => {
        existingPrices[row.price_tier_id] = row.price;
    });
    await Promise.all([populateCategoryDropdown(product.category_id), populateUnitDropdown(product.unit_id), populateTierPriceInputs(existingPrices)]);
    new bootstrap.Modal(document.getElementById("productModal")).show();
}

async function handleSubmit(e) {
    e.preventDefault();

    const productId = document.getElementById("productId").value;
    const jsonData = {
        category_id: document.getElementById("productCategory").value,
        barcode: document.getElementById("productBarcode").value.trim(),
        product_name: document.getElementById("productName").value.trim(),
        unit_id: document.getElementById("productUnit").value,
        is_vatable: document.getElementById("productVatable").value,
        is_wholesale_barcode: document.getElementById("productWholesaleBarcode").checked ? 1 : 0
    };
    let operation = "insertProduct";

    // Tier prices are part of BOTH add and edit saves: a tier left blank
    // has no price (on edit, blank removes that tier's price); the default
    // (Regular) price is required by the server in both cases.
    jsonData.tier_prices = {};
    document.querySelectorAll(".product-tier-price").forEach((input) => {
        jsonData.tier_prices[input.dataset.tierId] = input.value;
    });

    if (productId) {
        jsonData.product_id = productId;
        operation = "updateProduct";
    }

    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(jsonData));

    const response = await axios({ url: `${baseApiUrl}/products.php`, method: "POST", data: formData });

    if (response.data.success) {
        showToast(response.data.message, "success");
        bootstrap.Modal.getInstance(document.getElementById("productModal"))?.hide();
        displayProducts();
    } else {
        showToast(response.data.message, "danger");
    }
}

async function handleDelete(product) {
    const confirmed = await confirmDeactivate(product.product_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "deleteProduct");
    formData.append("json", JSON.stringify({ product_id: product.product_id }));

    const response = await axios({ url: `${baseApiUrl}/products.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayProducts();
}

async function handleRestore(product) {
    const confirmed = await confirmRestore(product.product_name);
    if (!confirmed) return;

    const formData = new FormData();
    formData.append("operation", "restoreProduct");
    formData.append("json", JSON.stringify({ product_id: product.product_id }));

    const response = await axios({ url: `${baseApiUrl}/products.php`, method: "POST", data: formData });

    showToast(response.data.message, response.data.success ? "success" : "danger");
    if (response.data.success) displayProducts();
}
