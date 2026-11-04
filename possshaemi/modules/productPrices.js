import { baseApiUrl, showToast, confirmAlert, statusBadge, esc, debounce, refreshPager, formatPeso } from "./utils.js";
import { getActiveCategoryOptions } from "./categories.js";
import { getActiveProductOptions } from "./products.js";
import { getActivePriceTierOptions } from "./priceTiers.js";

/* ==========================================================
   Product Prices
   - Table: one row per product + price tier (search / category / status filters)
   - "Manage Price" modal: pick a product, all its active price tiers are
     shown automatically (no tier dropdown). Only the tiers that were
     actually changed are sent to the API when saving.
   All HTML for this page lives in index.html. This file only reads data
   into it and reacts to events - it does not build the page's own markup.
   ========================================================== */

let currentStatus = "active";
let currentCategoryId = "";

export function initProductPrices() {
  if (!requiredElementsExist()) return;

  // The ERD's PRODUCT_PRICE has no is_active, so there are no "inactive" prices to filter by.
  const statusFilter = document.getElementById("productPriceStatusFilter");
  statusFilter.value = "active";
  statusFilter.style.display = "none";

  document
    .getElementById("productPriceCategoryFilter")
    .addEventListener("change", (e) => {
      currentCategoryId = e.target.value;
      displayProductPrices();
    });

  // Refresh category options when opened, so new categories appear without a page reload
  document
    .getElementById("productPriceCategoryFilter")
    .addEventListener("focus", populateCategoryFilter);

  document
    .getElementById("productPriceSearch")
    .addEventListener("input", debounce(displayProductPrices, 300));

  document
    .getElementById("btnManageProductPrice")
    .addEventListener("click", () => openModalForProduct());

  document
    .getElementById("productPriceProductSelect")
    .addEventListener("change", (e) => {
      loadTiersForProduct(e.target.value);
    });

  document
    .getElementById("productPriceForm")
    .addEventListener("submit", handleSubmit);

  populateCategoryFilter();
  displayProductPrices();
}

/** Fail loudly (but not by crashing) if index.html is missing a required element. */
function requiredElementsExist() {
  const ids = [
    "productPriceSearch",
    "productPriceCategoryFilter",
    "productPriceStatusFilter",
    "productPriceTableBody",
    "btnManageProductPrice",
    "productPriceModal",
    "productPriceForm",
    "productPriceProductSelect",
    "productPriceTiersWrapper",
    "productPriceTiersContainer",
    "productPriceNoTiersMsg",
    "productPriceSaveBtn",
  ];

  const missing = ids.filter((id) => !document.getElementById(id));
  if (missing.length > 0) {
    console.error(
      "Product Prices: missing element(s) in index.html:",
      missing.join(", "),
    );
    return false;
  }
  return true;
}

/* ==========================================================
   Table: list of product + tier price rows
   ========================================================== */

export async function displayProductPrices() {
  const keyword = document.getElementById("productPriceSearch").value.trim();

  const params = keyword
    ? { operation: "searchProductPrices", keyword, status: currentStatus }
    : { operation: "getAllProductPrices", status: currentStatus };

  if (currentCategoryId) params.category_id = currentCategoryId;

  const response = await axios.get(`${baseApiUrl}/product-prices.php`, {
    params,
  });
  renderTable(response.data);
}

async function populateCategoryFilter() {
  const select = document.getElementById("productPriceCategoryFilter");
  const selected = select.value;

  const categories = await getActiveCategoryOptions();

  select.innerHTML =
    `<option value="">All Categories</option>` +
    categories
      .map(
        (c) =>
          `<option value="${c.category_id}">${esc(c.category_name)}</option>`,
      )
      .join("");

  select.value = selected;
  currentCategoryId = select.value;
}

function renderTable(productPrices) {
  const tbody = document.getElementById("productPriceTableBody");
  tbody.innerHTML = "";

  if (!Array.isArray(productPrices) || productPrices.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>`;
    return;
  }

  productPrices.forEach((pp) => {
    const row = document.createElement("tr");

    row.innerHTML = `
            <td>${esc(pp.product_name)}</td>
            <td>${esc(pp.category_name)}</td>
            <td>${esc(pp.tier_name)}</td>
            <td class="num">${formatPeso(pp.price)}</td>
            <td>${statusBadge(pp.is_active)}</td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-edit">
                    <i class="bi bi-pencil me-1"></i>Edit
                </button>
            </td>
        `;

    // Any row for a product opens the same "Manage Price" modal for that whole product
    row
      .querySelector(".btn-edit")
      .addEventListener("click", () =>
        openModalForProduct(pp.product_id, pp.product_name),
      );

    tbody.appendChild(row);
  });

    refreshPager("productPriceTableBody");
}

/* ==========================================================
   Manage Price modal
   ========================================================== */

async function populateProductDropdown(
  selectedId = null,
  selectedLabel = null,
) {
  const select = document.getElementById("productPriceProductSelect");
  const products = await getActiveProductOptions();

  select.innerHTML =
    `<option value="">-- Select a product --</option>` +
    products
      .map(
        (p) =>
          `<option value="${p.product_id}">${esc(p.product_name)}</option>`,
      )
      .join("");

  // The product being edited might be inactive (not in the active list) - keep it selectable anyway
  if (
    selectedId &&
    !products.some((p) => Number(p.product_id) === Number(selectedId))
  ) {
    const opt = document.createElement("option");
    opt.value = selectedId;
    opt.textContent = selectedLabel
      ? `${selectedLabel} (inactive)`
      : `Product #${selectedId} (inactive)`;
    select.appendChild(opt);
  }

  select.value = selectedId ?? "";
}

async function openModalForProduct(productId = null, productName = null) {
  await populateProductDropdown(productId, productName);

  if (productId) {
    await loadTiersForProduct(productId);
  } else {
    resetTiersArea();
  }

  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("productPriceModal"),
  ).show();
}

function resetTiersArea() {
  document.getElementById("productPriceTiersWrapper").classList.add("d-none");
  document.getElementById("productPriceNoTiersMsg").classList.add("d-none");
  document.getElementById("productPriceTiersContainer").innerHTML = "";
  document.getElementById("productPriceSaveBtn").disabled = true;
}

/** Load every active tier for the chosen product and render one price field per tier. */
async function loadTiersForProduct(productId) {
  if (!productId) {
    resetTiersArea();
    return;
  }

  const [priceRowsResponse, tierList] = await Promise.all([
    axios.get(`${baseApiUrl}/product-prices.php`, {
      params: { operation: "getPricesForProduct", product_id: productId },
    }),
    getActivePriceTierOptions(),
  ]);

  const priceRows = Array.isArray(priceRowsResponse.data)
    ? priceRowsResponse.data
    : [];
  const tiers = (Array.isArray(tierList) ? tierList : []).sort(
    (a, b) => Number(a.price_tier_id) - Number(b.price_tier_id),
  );

  const container = document.getElementById("productPriceTiersContainer");
  container.innerHTML = "";

  if (tiers.length === 0) {
    document.getElementById("productPriceTiersWrapper").classList.add("d-none");
    document
      .getElementById("productPriceNoTiersMsg")
      .classList.remove("d-none");
    document.getElementById("productPriceSaveBtn").disabled = true;
    return;
  }

  tiers.forEach((tier) => {
    const priceRow = priceRows.find(
      (r) => Number(r.price_tier_id) === Number(tier.price_tier_id),
    );
    container.appendChild(buildTierRow(tier, priceRow, productId));
  });

  document
    .getElementById("productPriceTiersWrapper")
    .classList.remove("d-none");
  document.getElementById("productPriceNoTiersMsg").classList.add("d-none");
  document.getElementById("productPriceSaveBtn").disabled = false;

  focusFirstPriceField();
}

/** Build one "Regular / Wholesale / VIP" price row for the modal. */
function buildTierRow(tier, priceRow, productId) {
  const exists = !!priceRow;
  const isActive = exists && Number(priceRow.is_active) === 1;
  const isNotSet = !exists || Number(priceRow.price) <= 0;

  const line = document.createElement("div");
  line.className = "row g-2 align-items-start py-2 border-bottom";

  let hint = "";
  if (!exists)
    hint = `<div class="form-text text-warning-emphasis">No price set for this tier yet.</div>`;
  else if (!isActive)
    hint = `<div class="form-text">This tier price is deactivated.</div>`;
  else if (isNotSet)
    hint = `<div class="form-text text-warning-emphasis">Price is not set.</div>`;

  let actionBtn = "";
  if (exists) {
    actionBtn = `<button type="button" class="btn btn-sm btn-outline-danger" data-action="remove">Remove</button>`;
  }

  line.innerHTML = `
        <div class="col-3 fw-semibold pt-2">${esc(tier.tier_name)}</div>
        <div class="col-6">
            <label class="form-label small mb-1">Price</label>
            <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" min="0" step="0.01" class="form-control price-input"
                       data-tier-id="${tier.price_tier_id}"
                       data-original="${exists ? esc(priceRow.price) : ""}"
                       data-price-id="${exists ? priceRow.product_price_id : ""}"
                       value="${exists ? Number(priceRow.price).toFixed(2) : ""}"
                       placeholder="0.00"
                       ${exists && !isActive ? "disabled" : ""}>
            </div>
            ${hint}
        </div>
        <div class="col-3 text-end pt-4">${actionBtn}</div>
    `;

  line.querySelector("[data-action]")?.addEventListener("click", (e) => {
    const select = document.getElementById("productPriceProductSelect");
    const productName =
      select.options[select.selectedIndex]?.textContent ?? "this product";
    handleTierRemove(productId, priceRow, productName);
  });

  return line;
}

function focusFirstPriceField() {
  const inputs = [
    ...document.querySelectorAll(
      "#productPriceTiersContainer .price-input:not(:disabled)",
    ),
  ];
  const target =
    inputs.find((i) => i.value === "" || Number(i.value) <= 0) || inputs[0];
  target?.focus();
  target?.select();
}

/* ==========================================================
   Save: only tiers that actually changed are sent to the API
   ========================================================== */

async function post(operation, jsonData) {
  const formData = new FormData();
  formData.append("operation", operation);
  formData.append("json", JSON.stringify(jsonData));

  const response = await axios({
    url: `${baseApiUrl}/product-prices.php`,
    method: "POST",
    data: formData,
  });
  return response.data;
}

async function handleSubmit(e) {
  e.preventDefault();

  const productId = document.getElementById("productPriceProductSelect").value;
  if (!productId) {
    showToast("Select a product first.", "danger");
    return;
  }

  const inputs = [
    ...document.querySelectorAll(
      "#productPriceTiersContainer .price-input:not(:disabled)",
    ),
  ];
  const jobs = [];

  for (const input of inputs) {
    const raw = input.value.trim();
    const priceId = input.dataset.priceId;

    if (raw !== "" && (isNaN(Number(raw)) || Number(raw) < 0)) {
      showToast("Enter a price of zero or more.", "danger");
      input.focus();
      return;
    }

    if (priceId) {
      // Tier already has a price row: only touch it if the value changed
      if (raw === "") {
        showToast(
          "Price can't be empty. Use Remove instead if this tier should stop being sold.",
          "danger",
        );
        input.focus();
        return;
      }
      if (Number(raw) !== Number(input.dataset.original)) {
        jobs.push(() =>
          post("updateProductPrice", { product_price_id: priceId, price: raw }),
        );
      }
    } else if (raw !== "") {
      // Tier never had a price row: create one only if the user actually entered a value
      jobs.push(() =>
        post("insertProductPrice", {
          product_id: productId,
          price_tier_id: input.dataset.tierId,
          price: raw,
        }),
      );
    }
  }

  if (jobs.length === 0) {
    showToast("No changes to save.", "success");
    bootstrap.Modal.getInstance(
      document.getElementById("productPriceModal"),
    )?.hide();
    return;
  }

  let failedMessage = null;
  for (const job of jobs) {
    const result = await job();
    if (!result.success) {
      failedMessage = result.message;
      break;
    }
  }

  await displayProductPrices();

  if (failedMessage) {
    showToast(failedMessage, "danger");
    return; // keep the modal open so the user can fix the failed field
  }

  showToast(jobs.length === 1 ? "Price saved." : "Prices saved.", "success");
  bootstrap.Modal.getInstance(
    document.getElementById("productPriceModal"),
  )?.hide();
}

/* ==========================================================
   Remove a single tier price, from inside the modal
   (PRODUCT_PRICE has no is_active in the ERD, so this deletes the row)
   ========================================================== */

async function handleTierRemove(productId, priceRow, productName) {
  // Close the modal first so the confirm dialog isn't stacked on top of it
  await hideModal();

  const label = `${productName} - ${priceRow.tier_name}`;
  const confirmed = await confirmAlert({
    icon: "warning",
    title: "Remove tier price?",
    text: `Remove the price for ${label}? This cannot be undone.`,
    confirmText: "Remove",
    confirmColor: "#dc3545",
  });

  if (confirmed) {
    const result = await post("deleteProductPrice", {
      product_price_id: priceRow.product_price_id,
    });

    showToast(result.message, result.success ? "success" : "danger");
    if (result.success) await displayProductPrices();
  }

  // Reopen so the user can keep editing this product's other tiers
  await openModalForProduct(productId);
}

function hideModal() {
  return new Promise((resolve) => {
    const el = document.getElementById("productPriceModal");
    const instance = bootstrap.Modal.getInstance(el);

    if (!instance || !el.classList.contains("show")) {
      resolve();
      return;
    }

    el.addEventListener("hidden.bs.modal", () => resolve(), { once: true });
    instance.hide();
  });
}
