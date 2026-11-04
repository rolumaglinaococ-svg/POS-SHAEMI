import {
  baseApiUrl,
  showToast,
  showAlert,
  confirmAlert,
  setBusy,
  esc,
  debounce,
  formatPeso,
  formatDateTime,
} from "./utils.js";
import { logout } from "./auth.js";

let lookups = {
  counters: [],
  discounts: [],
  payment_types: [],
  categories: [],
  default_price_tier_id: 0,
};
let allProducts = [];
let cart = [];
let selectedCustomer = null;
let useAr = false;
let pendingSupervisorAction = null;
let pendingCustomer = null;
// Filter the cashier chose in the Customer modal when they picked
// pendingCustomer: "ar", "suki", or "all". It is the cashier's intent:
// "ar" means charge to AR, "suki" means pay in cash / with Suki
// points, not AR. Null means the customer did not come from the
// modal (e.g. a resumed held transaction), where the old auto-tick
// behaviour is kept and the held sale's own use_ar wins afterwards.
let pendingCustomerFilter = null;
let salePayments = [];
let selectedPaymentIndex = -1;
let pendingGcCode = null; // { code, face_value } from the last successful code lookup (preview only)
// Held transaction currently loaded in the cart, or null for a fresh
// cart. A held transaction stays in the held list after it is resumed
// and only disappears when it is paid, or when the cashier explicitly
// clears it (New Sale / Void) or re-holds it. That is what stops a
// resumed transaction from vanishing when the cashier switches to
// another held transaction.
let activeHeldId = null;

// Barcode Variant Pricing (approved 2026-10-22): a product marked as a
// wholesale barcode (is_wholesale_barcode) may only be bought by a customer
// whose Customer Type is Wholesale. Price always comes from the customer's
// Price Tier — the server prices marked products from the Wholesale tier.
function hasWholesaleAccess(customer) {
  const t = String(customer?.customer_type || "")
    .trim()
    .toLowerCase();
  return t === "wholesale" || t === "wholesaler";
}
function isWholesaleBarcode(item) {
  return Number(item?.is_wholesale_barcode || 0) === 1;
}
function lineIsBlocked(line) {
  return isWholesaleBarcode(line) && !hasWholesaleAccess(selectedCustomer);
}
function cartHasBlockedLine() {
  return cart.some((line) => line.blocked);
}
const WHOLESALE_BLOCK_MESSAGE =
  "This is a wholesale-only product. Only a Wholesale customer may buy it.";

// Reprice EVERY cart line at the currently selected customer's tier and
// re-evaluate blocked lines. Called whenever the customer is selected,
// changed, or cleared, so the screen total always matches what the
// server will charge at checkout.
async function repriceCart() {
  const tierId = selectedCustomer
    ? selectedCustomer.price_tier_id
    : lookups.default_price_tier_id;
  for (const line of cart) {
    try {
      const response = await axios.get(`${baseApiUrl}/pos.php`, {
        params: {
          operation: "getPosProducts",
          keyword: String(line.product_id),
          price_tier_id: tierId,
          category_id: 0,
          customer_type: selectedCustomer?.customer_type || "",
        },
      });
      const product = response.data.find(
        (p) => String(p.product_id) === String(line.product_id),
      );
      if (product) {
        line.price = parseFloat(product.price);
        line.is_wholesale_barcode = Number(product.is_wholesale_barcode || 0);
        line.is_suki_eligible = Number(product.is_suki_eligible ?? 1);
        if (!line.barcode) line.barcode = product.barcode || "";
      }
    } catch (error) {
      // Keep the line's current price; the server re-prices at checkout.
    }
    line.blocked = lineIsBlocked(line);
  }
  renderCart();
  renderTotals();
}
// Phase 1 redesign state
let selectedLineIndex = -1;
let lastSaleId = null;
let pendingGateResolve = null;
let gateEventsBound = false;
let gateEvalSeq = 0;

// Live header clock: "09:42 PM · Oct 17, 2026" (tabular numerals via CSS).
export function tickHeaderClock() {
  const el = document.getElementById("headerClock");
  if (!el) return;
  const now = new Date();
  const time = now.toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: true,
  });
  const date = now.toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
  el.textContent = `${time} · ${date}`;
}

export async function initPos() {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getPosLookups" },
  });
  lookups = response.data;

  populateCounters();
  populateCategoryFilter();
  populateDiscounts();
  populatePaymentTypes();
  updateCounterDisplay();

  // Opening-cash denomination grid: counts into the Beginning Cash total.
  bindDenominationGrid(
    document.getElementById("gateDenominationGrid"),
    document.getElementById("openingFloatInput"),
  );

  // Opening Cash gate: block the sales UI until the drawer is opened.
  const gatePassed = await runOpeningCashGate();
  if (!gatePassed) return;

  bindPosEvents();
  bindShiftEvents();

  await searchProducts();
  renderCart();
  updateCounterDisplay();
  await refreshHeldTransactions();
  await refreshCashDrawer();
  focusScanner();
}

function bindPosEvents() {
  // Register the global shortcuts FIRST, not last. The old code
  // registered them at the very end, so if any button binding above
  // threw (for example, a new element missing from an older
  // cashier.html), every shortcut key and every button after that
  // point silently died, while scanning still worked.
  document.addEventListener("keydown", globalKeyboardShortcuts);
  document
    .getElementById("posBarcodeInput")
    .addEventListener("keydown", handleBarcodeKeydown);
  // Barcode fields take digits only: letters are blocked on keypress,
  // and anything non-digit that still gets in (paste, autofill) is
  // stripped on input. This covers both the main scan box and the
  // F2 barcode search, which is now barcode-only.
  document
    .getElementById("posBarcodeInput")
    .addEventListener("keydown", blockNonDigitKeys);
  document
    .getElementById("posBarcodeInput")
    .addEventListener("input", stripNonDigits);
  document
    .getElementById("posProductSearch")
    .addEventListener("keydown", blockNonDigitKeys);
  document
    .getElementById("posProductSearch")
    .addEventListener("input", stripNonDigits);
  // Quantity-first scanning: Enter in Qty moves to the barcode box; the
  // scan itself only runs from the barcode box.
  document.getElementById("posQtyInput").addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      document.getElementById("posBarcodeInput").focus();
    }
  });
  document
    .getElementById("btnOpenProductList")
    .addEventListener("click", openProductList);
  document
    .getElementById("posProductSearch")
    .addEventListener("input", debounce(searchProducts, 250));
  document
    .getElementById("posCategoryFilter")
    .addEventListener("change", searchProducts);
  document
    .getElementById("btnCustomer")
    .addEventListener("click", openCustomerModal);
  document
    .getElementById("posCustomerSearch")
    .addEventListener("input", debounce(searchCustomers, 250));
  // Optional: this filter only exists in the newer cashier.html.
  // Without ?. here, an older live cashier.html made this line throw
  // and killed every binding after it, including the shortcuts.
  document
    .getElementById("posCustomerArFilter")
    ?.addEventListener("change", searchCustomers);
  // Explicit Clear Customer click gets its own feedback. clearCustomer()
  // itself must stay silent, because it is also called by resetSale,
  // resume, and payment completion, where a "customer cleared" toast
  // would be noise on top of the real action's feedback.
  document
    .getElementById("btnClearCustomer")
    .addEventListener("click", async () => {
      await clearCustomer();
      showToast("Customer cleared. Now Walk-in.", "success");
    });
  document
    .getElementById("posDiscount")
    .addEventListener("change", renderTotals);
  document
    .getElementById("btnDiscount")
    .addEventListener("click", openDiscountModal);
  document
    .getElementById("btnDiscountPanel")
    ?.addEventListener("click", openDiscountModal);
  document
    .getElementById("btnDrawer")
    ?.addEventListener("click", () =>
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("drawerModal"),
      ).show(),
    );
  document
    .getElementById("discountForm")
    .addEventListener("submit", applyDiscountFromModal);
  document.getElementById("btnHelp").addEventListener("click", openHelpModal);
  document.getElementById("btnNewSale").addEventListener("click", newSale);
  document
    .getElementById("btnReprint")
    .addEventListener("click", reprintLastReceipt);
  document
    .getElementById("btnRemoveLine")
    .addEventListener("click", removeSelectedLine);
  document
    .getElementById("btnOpenPayment")
    .addEventListener("click", openPaymentModal);
  document
    .getElementById("btnAddPayment")
    .addEventListener("click", addPaymentEntry);
  document
    .getElementById("paymentMethod")
    .addEventListener("change", updatePaymentEntryFields);
  document
    .getElementById("paymentModal")
    .addEventListener("keydown", handleTenderKeydown);
  document
    .getElementById("paymentAmount")
    .addEventListener("input", updatePaymentSummary);
  document
    .getElementById("paymentGiftCertificate")
    .addEventListener("blur", lookupPaymentGiftCertificate);
  document
    .getElementById("paymentCompleteBtn")
    .addEventListener("click", completeSaleFromPaymentModal);
  document
    .getElementById("paymentModal")
    .addEventListener("hidden.bs.modal", () => {
      salePayments = [];
      renderTotals();
    });
  // After any dialog closes, return focus to the quantity input.
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("hidden.bs.modal", () => focusScanner());
  });
  document.getElementById("posUseAr").addEventListener("change", (e) => {
    useAr = e.target.checked;
    if (useAr) salePayments = [];
    renderTotals();
  });
  document.getElementById("posCounter").addEventListener("change", (e) => {
    setCounterSelection(e.target.value);
    refreshCashDrawer();
  });
  document
    .getElementById("btnHold")
    .addEventListener("click", holdCurrentTransaction);
  document
    .getElementById("btnResumeTransaction")
    .addEventListener("click", openHeldModal);
  document
    .getElementById("btnVoidCart")
    .addEventListener("click", voidCurrentSale);
  document
    .getElementById("btnOpenCashDrawer")
    .addEventListener("click", openCashDrawer);
  document
    .getElementById("btnCashLift")
    .addEventListener("click", openCashLiftModal);
  // Cash Lift button inside the Cash Float modal no longer opens the
  // separate Cash Lift modal on top of it. It records the lift directly
  // from the Cash Float modal's own amount + supervisor fields, so
  // there is no redundant second modal. The separate Cash Lift modal
  // now appears only from F8 / the main Cash Lift button.
  document
    .getElementById("btnCashLiftDrawer")
    ?.addEventListener("click", submitDrawerCashLift);
  document
    .getElementById("drawerLiftAmount")
    ?.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        submitDrawerCashLift();
      }
    });
  // Cash Float replenish form (inside the Cash Float modal).
  bindDenominationGrid(
    document.getElementById("replenishDenominationGrid"),
    document.getElementById("replenishFloatInput"),
  );
  document
    .getElementById("btnSubmitReplenishFloat")
    ?.addEventListener("click", submitReplenishFloat);
  document
    .getElementById("replenishFloatInput")
    ?.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        submitReplenishFloat();
      }
    });
  document
    .getElementById("supervisorForm")
    .addEventListener("submit", handleSupervisorSubmit);
  const supervisorModal = document.getElementById("supervisorModal");
  supervisorModal.addEventListener("hide.bs.modal", () => {
    // Bootstrap applies aria-hidden while hiding a modal. Remove focus first so
    // assistive technologies are never left focused on hidden content.
    if (supervisorModal.contains(document.activeElement)) {
      document.activeElement.blur();
    }
  });
  document
    .getElementById("cashLiftForm")
    .addEventListener("submit", handleCashLiftSubmit);
  document
    .getElementById("checkoutForm")
    .addEventListener("submit", handleCheckout);
  document
    .getElementById("btnPrintReceipt")
    .addEventListener("click", () => window.print());
  // Note: the global shortcut listener is registered at the top of
  // this function, not here, so it survives any later binding failure.
}

// ---------- Opening Cash gate ----------

// Per-device counter memory: each terminal reopens on the counter it last
// used, so shared-counter collisions (Bonita's device defaulting to Dela
// Cruz's counter) stop happening after the first correct selection.
const COUNTER_MEMORY_KEY = "posar_counter_id";

function readRememberedCounterId() {
  try {
    return window.localStorage.getItem(COUNTER_MEMORY_KEY);
  } catch (error) {
    return null;
  }
}

function saveRememberedCounterId(counterId) {
  try {
    if (counterId) {
      window.localStorage.setItem(COUNTER_MEMORY_KEY, String(counterId));
    }
  } catch (error) {
    // Storage unavailable (private mode): the counter just won't persist.
  }
}

// Keeps #posCounter and the gate select showing the same counter, refreshes
// the labels that display it, and remembers the choice on this device.
function setCounterSelection(counterId) {
  const select = document.getElementById("posCounter");
  const gateSelect = document.getElementById("gateCounterSelect");
  if (select) select.value = String(counterId);
  if (gateSelect) gateSelect.value = String(counterId);
  saveRememberedCounterId(counterId);
  updateCounterDisplay();
  const label = document.getElementById("gateCounterLabel");
  if (label && select) {
    label.textContent =
      select.options[select.selectedIndex]?.textContent || "—";
  }
}

function populateGateCounterSelect() {
  const gateSelect = document.getElementById("gateCounterSelect");
  const select = document.getElementById("posCounter");
  if (!gateSelect || !select) return;
  gateSelect.innerHTML = select.innerHTML;
  gateSelect.value = select.value;
}

// Live counter occupancy for the gate: who (if anyone) already has an open
// shift on each counter, so a cashier on a taken counter can pick a free
// one instead of hitting a dead end.
async function refreshGateOccupancy() {
  const box = document.getElementById("gateOccupancy");
  if (!box) return;
  try {
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
      params: { operation: "getCounterOccupancy" },
    });
    const data = response.data;
    if (!data.success || !Array.isArray(data.counters)) {
      box.innerHTML = "";
      return;
    }
    const current = document.getElementById("posCounter")?.value || "";
    box.innerHTML = data.counters
      .map((c) => {
        let status;
        if (!c.open_shift) {
          status = `<span class="text-success fw-semibold">Available</span>`;
        } else if (c.open_shift.is_mine) {
          status =
            `<span class="text-primary fw-semibold">Your shift #${c.open_shift.shift_id} ` +
            `is open here</span>`;
        } else {
          status =
            `<span class="text-warning-emphasis fw-semibold">` +
            `${esc(c.open_shift.cashier_name)} — open since ` +
            `${esc(formatDateTime(c.open_shift.opened_at))}</span>`;
        }
        const nameClass =
          String(c.counter_id) === String(current) ? ` class="fw-bold"` : "";
        return (
          `<div class="gate-occ-row"><span${nameClass}>` +
          `${esc(c.counter_name)} (#${c.counter_number})</span>${status}</div>`
        );
      })
      .join("");
  } catch (error) {
    box.innerHTML = "";
  }
}

async function fetchDrawer(counterId) {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getCashDrawer", counter_id: counterId },
  });
  return response.data;
}

function bindGateEvents() {
  if (gateEventsBound) return;
  gateEventsBound = true;
  document.getElementById("gateForm").addEventListener("submit", (e) => {
    e.preventDefault();
    confirmOpeningCash();
  });
  document
    .getElementById("openingFloatInput")
    .addEventListener("focus", (e) => e.target.select());
  document
    .getElementById("btnGateContinue")
    .addEventListener("click", () => closeGate());
  document
    .getElementById("gateCounterSelect")
    ?.addEventListener("change", (e) => {
      setCounterSelection(e.target.value);
      refreshGateOccupancy();
      evaluateGateCounter();
    });
  [
    "gateBackToLogin",
    "gateBackToLogin2",
    "gateBackToLogin3",
    "gateBackToLogin4",
  ].forEach((id) => {
    document.getElementById(id)?.addEventListener("click", (e) => {
      e.preventDefault();
      logout();
    });
  });
}

function showGateView(viewId) {
  [
    "gateForm",
    "gateAlreadyOpenView",
    "gateNoCounterView",
    "gateBlockedView",
  ].forEach((id) => {
    document.getElementById(id).classList.toggle("d-none", id !== viewId);
  });
  document.getElementById("openingCashGate").classList.remove("d-none");
}

async function runOpeningCashGate() {
  bindGateEvents();
  // New shift = new chance to pick the counter, before it locks again
  // in closeGate(). Without this, Start New Shift would inherit the
  // previous shift's locked counter with no way to change it.
  unlockPosCounter();
  const select = document.getElementById("posCounter");
  document.getElementById("gateCashierName").textContent =
    document.getElementById("cashierName").textContent || "—";
  document.getElementById("gateCounterLabel").textContent =
    select.options[select.selectedIndex]?.textContent || "—";
  document.getElementById("gateDate").textContent =
    new Date().toLocaleDateString();
  refreshGateOccupancy();
  // One pending resolver for the whole gate: whichever evaluation finally
  // passes (initial counter or one the cashier switches to at the gate)
  // completes it through closeGate().
  return new Promise((resolve) => {
    pendingGateResolve = resolve;
    evaluateGateCounter();
  });
}

// Evaluates the currently selected counter for the gate. Runs once at
// startup and again whenever the cashier switches counter at the gate.
// Passing evaluations complete the gate via closeGate(); non-passing ones
// just swap the visible view and leave the gate pending.
async function evaluateGateCounter() {
  const seq = ++gateEvalSeq;
  const stale = () => seq !== gateEvalSeq;
  const counterId = document.getElementById("posCounter").value;
  document.getElementById("gateError").textContent = "";

  if (!counterId) {
    showGateView("gateNoCounterView");
    return;
  }

  let drawer;
  try {
    drawer = await fetchDrawer(counterId);
  } catch (error) {
    if (stale()) return;
    document.getElementById("gateError").textContent =
      "Unable to reach the server. Please try again.";
    showGateView("gateForm");
    return;
  }
  if (stale()) return;

  // Shift state decides the gate, not the float alone: a shift is the Open
  // row for this cashier + counter. Closed shifts never block — a new float
  // simply opens a NEW shift (closed shifts are permanent, never reopened).
  let st = null;
  try {
    st = await shiftGet("getShiftStatus");
  } catch (error) {
    // If the status check fails, fall through to the drawer-based behaviour
    // below; the End Shift flow re-checks before closing.
  }
  if (stale()) return;

  if (st && st.success) {
    if (st.counter_open_shift) {
      // This counter's Open shift belongs to another cashier: no selling
      // here until that shift is closed — but the cashier can switch to
      // an available counter above instead of logging out.
      document.getElementById("gateBlockedMsg").textContent =
        `Counter already has an open shift under ${st.counter_open_shift.cashier_name} ` +
        `(opened ${st.counter_open_shift.opened_at}).`;
      showGateView("gateBlockedView");
      return;
    }
    if (st.shift) {
      // Own Open shift: straight to sales. (Drawer figures follow the
      // shift, so this also covers floats that predate this login.)
      closeGate();
      return;
    }
    // No Open shift for this cashier + counter: fall through to the opening
    // form for a NEW shift, noting any shift already closed today.
    const note = document.getElementById("gateNewShiftNote");
    if (st.closed_today && st.last_closed_shift_id) {
      note.textContent =
        `Your previous shift #${st.last_closed_shift_id} is closed. ` +
        "Entering a float opens a new shift.";
      note.classList.remove("d-none");
    } else {
      note.classList.add("d-none");
    }
  } else if (drawer.opened) {
    // Legacy fallback (status check failed): preserve the old behaviour —
    // an open drawer means sales, with ensureShift backfilling the row.
    try {
      await shiftPost("ensureShift", {});
    } catch (error) {
      // Fall through to sales; the End Shift flow re-checks before closing.
    }
    if (stale()) return;
    closeGate();
    return;
  }

  document.getElementById("openingFloatInput").value = "";
  showGateView("gateForm");
  setTimeout(() => document.getElementById("openingFloatInput").focus(), 50);
}

let gateOpenSubmitting = false;

// One shared drawer-open call for BOTH entry points (login gate and the
// sales-screen drawer panel) — same validation, same server operation,
// so the two paths can never drift apart again.
async function postOpenCashFloat(counterId, amount) {
  const formData = new FormData();
  formData.append("operation", "openCashFloat");
  formData.append("json", JSON.stringify({ counter_id: counterId, amount }));
  const response = await axios({
    url: `${baseApiUrl}/pos.php`,
    method: "POST",
    data: formData,
  });
  return response.data;
}

async function confirmOpeningCash() {
  if (gateOpenSubmitting) return;
  const input = document.getElementById("openingFloatInput");
  const errorBox = document.getElementById("gateError");
  const amount = parseFloat(input.value);
  const counterId = document.getElementById("posCounter").value;
  if (!Number.isFinite(amount) || amount <= 0) {
    errorBox.textContent = "Enter an opening cash amount greater than zero.";
    input.focus();
    input.select();
    return;
  }
  errorBox.textContent = "";
  const btn = document.getElementById("btnConfirmOpeningCash");
  gateOpenSubmitting = true;
  setBusy(btn, true, "Opening…");
  try {
    const data = await postOpenCashFloat(counterId, amount);
    if (!data.success) {
      errorBox.textContent = data.message || "Unable to open the cash drawer.";
      return;
    }
    refreshGateOccupancy();
    if (data.already_open) {
      document.getElementById("gateExistingFloat").textContent = formatPeso(
        data.beginning_float || amount,
      );
      showGateView("gateAlreadyOpenView");
      setTimeout(() => document.getElementById("btnGateContinue").focus(), 50);
      return;
    }
    showToast(data.message, "success");
    closeGate();
  } catch (error) {
    errorBox.textContent = "Unable to reach the server. Please try again.";
  } finally {
    gateOpenSubmitting = false;
    setBusy(btn, false);
  }
}

// Counter lock: once the opening gate passes, the sales-screen
// counter is no longer clickable. The counter is the physical terminal
// the cashier is on, so letting it change mid-shift is what made End
// Shift records lie (sales recorded on Counter 3 while the cashier
// was still on the Counter 1 PC). The gate select stays changeable,
// because that is where the counter for this device is chosen, and
// runOpeningCashGate() unlocks again for the next shift.
function lockPosCounter() {
  const select = document.getElementById("posCounter");
  if (select) select.disabled = true;
}

function unlockPosCounter() {
  const select = document.getElementById("posCounter");
  if (select) select.disabled = false;
}

function closeGate() {
  saveRememberedCounterId(document.getElementById("posCounter")?.value || "");
  lockPosCounter();
  document.getElementById("openingCashGate").classList.add("d-none");
  if (pendingGateResolve) {
    pendingGateResolve(true);
    pendingGateResolve = null;
  }
}

function populateCounters() {
  const select = document.getElementById("posCounter");
  const openCounters = lookups.counters.filter((c) => c.status === "Open");
  select.innerHTML = openCounters.length
    ? openCounters
        .map(
          (c) =>
            `<option value="${c.counter_id}">${esc(c.counter_name)} (#${c.counter_number})</option>`,
        )
        .join("")
    : `<option value="">No open counters</option>`;
  // Per-device memory: preselect the counter this terminal last used.
  const remembered = readRememberedCounterId();
  if (
    remembered &&
    [...select.options].some((o) => o.value === String(remembered))
  ) {
    select.value = String(remembered);
  }
  populateGateCounterSelect();
}

function populateCategoryFilter() {
  document.getElementById("posCategoryFilter").innerHTML =
    `<option value="0">All Categories</option>` +
    lookups.categories
      .map(
        (c) =>
          `<option value="${c.category_id}">${esc(c.category_name)}</option>`,
      )
      .join("");
}

function populateDiscounts() {
  document.getElementById("posDiscount").innerHTML =
    `<option value="">No Discount</option>` +
    lookups.discounts
      .map((d) => {
        const label =
          d.discount_type === "Percentage"
            ? `${esc(d.discount_name)} (${parseFloat(d.discount_value)}%)`
            : `${esc(d.discount_name)} (- ${formatPeso(d.discount_value)})`;
        return `<option value="${d.discount_id}">${label}</option>`;
      })
      .join("");
}

function populatePaymentTypes() {
  const pills = document.getElementById("paymentMethodPills");
  pills.innerHTML = lookups.payment_types
    .map(
      (p, i) =>
        `<button type="button" class="btn btn-sm btn-outline-secondary tender-pill" data-payment-type-id="${p.payment_type_id}" title="Alt+${i + 1}">${esc(p.type_name)}<span class="tender-pill-key">Alt+${i + 1}</span></button>`,
    )
    .join("");
  pills
    .querySelectorAll("[data-payment-type-id]")
    .forEach((btn) =>
      btn.addEventListener("click", () =>
        setPaymentMethod(btn.dataset.paymentTypeId),
      ),
    );
  updatePaymentEntryFields();
}

function setPaymentMethod(paymentTypeId) {
  document.getElementById("paymentMethod").value = paymentTypeId;
  document
    .querySelectorAll("#paymentMethodPills [data-payment-type-id]")
    .forEach((btn) => {
      const on = String(btn.dataset.paymentTypeId) === String(paymentTypeId);
      btn.classList.toggle("btn-primary", on);
      btn.classList.toggle("btn-outline-secondary", !on);
    });
  updatePaymentEntryFields();
}

// Barcode fields are digits only. Single printable characters that
// are not 0-9 are blocked; control keys (Enter, Backspace, arrows,
// Tab, Ctrl shortcuts) have multi-character key names and pass through.
function blockNonDigitKeys(e) {
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  if (e.key.length === 1 && !/^\d$/.test(e.key)) e.preventDefault();
}

function stripNonDigits(e) {
  const digits = e.target.value.replace(/\D/g, "");
  if (e.target.value !== digits) e.target.value = digits;
}

async function handleBarcodeKeydown(e) {
  if (e.key !== "Enter") return;
  e.preventDefault();
  await scanBarcode();
}

function resetQtyBox() {
  document.getElementById("posQtyInput").value = "1";
}

// In-flight guard: a double Enter cannot add the same scan twice.
let scanInFlight = false;

async function scanBarcode() {
  if (scanInFlight) return;
  const input = document.getElementById("posBarcodeInput");
  const code = input.value.trim();
  if (!code) return;
  // Quantity-first validation: a whole number from 1 to 999. Anything else
  // stops here — nothing is scanned and focus returns to the Qty box.
  const qtyInput = document.getElementById("posQtyInput");
  const qtyRaw = qtyInput.value.trim();
  if (!/^\d+$/.test(qtyRaw) || Number(qtyRaw) < 1 || Number(qtyRaw) > 999) {
    setScanMessage("Enter a quantity from 1 to 999.", true);
    qtyInput.focus();
    if (typeof qtyInput.select === "function") qtyInput.select();
    return;
  }
  const qty = Number(qtyRaw);
  scanInFlight = true;
  try {
    const priceTierId = selectedCustomer
      ? selectedCustomer.price_tier_id
      : lookups.default_price_tier_id;
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
      params: {
        operation: "getPosProducts",
        keyword: code,
        price_tier_id: priceTierId,
        category_id: 0,
        customer_type: selectedCustomer?.customer_type || "",
        // A scan is a barcode, so it must not match on product name either.
        barcode_only: 1,
      },
    });
    const exact = response.data.find((p) => String(p.barcode) === code);
    const product =
      exact || (response.data.length === 1 ? response.data[0] : null);
    if (product) {
      // Wholesale refusal at scan: no line is added for a customer without
      // Wholesale access. The server (insertSale) still rejects it as a
      // backstop, and lines that became blocked by a mid-sale customer
      // change keep the badge + checkout block below.
      if (
        isWholesaleBarcode(product) &&
        !hasWholesaleAccess(selectedCustomer)
      ) {
        setScanMessage(WHOLESALE_BLOCK_MESSAGE, true);
        input.value = "";
        resetQtyBox();
        focusScanner();
        return;
      }
      if (addProductToCart(product, qty)) {
        setScanMessage(`${product.product_name} × ${qty} added.`);
      }
      input.value = "";
      resetQtyBox();
      focusScanner();
      return;
    }

    if (response.data.length > 1) {
      document.getElementById("posProductSearch").value = code;
      renderProductTable(response.data);
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("productListModal"),
      ).show();
      setScanMessage("Multiple matches found. Select the product.");
    } else {
      setScanMessage("Product not found.", true);
      input.value = "";
      resetQtyBox();
      focusScanner();
    }
  } catch (error) {
    setScanMessage("Unable to look up the product. Try again.", true);
    input.value = "";
    resetQtyBox();
    focusScanner();
  } finally {
    scanInFlight = false;
  }
}

let scanMessageTimer = null;
function setScanMessage(message, error = false) {
  const box = document.getElementById("posScanMessage");
  box.textContent = message;
  box.className = `small ${error ? "text-danger" : "text-muted"}`;
  if (scanMessageTimer) clearTimeout(scanMessageTimer);
  if (message) {
    scanMessageTimer = setTimeout(() => setScanMessage(""), 4000);
  }
}

async function searchProducts() {
  const keyword = document.getElementById("posProductSearch").value.trim();
  const categoryId = document.getElementById("posCategoryFilter").value;
  const priceTierId = selectedCustomer
    ? selectedCustomer.price_tier_id
    : lookups.default_price_tier_id;

  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: {
      operation: "getPosProducts",
      keyword,
      price_tier_id: priceTierId,
      category_id: categoryId,
      customer_type: selectedCustomer?.customer_type || "",
      // F2 list searches by barcode only, never by product name.
      barcode_only: 1,
    },
  });
  allProducts = response.data;
  renderProductTable(allProducts);
}

function renderProductTable(products) {
  const tbody = document.getElementById("posProductTableBody");
  tbody.innerHTML = "";
  if (!products || products.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">No matching products.</td></tr>`;
    return;
  }
  products.forEach((product) => {
    const row = document.createElement("tr");
    row.innerHTML = `
            <td>${esc(product.barcode || "—")}</td>
            <td><strong>${esc(product.product_name)}</strong><div class="small text-muted">${esc(product.unit)}</div></td>
            <td>${esc(product.category_name)}</td>
            <td class="text-end">${formatPeso(product.price)}</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-primary">ADD</button></td>`;
    row.querySelector("button").addEventListener("click", () => {
      // Qty-first: the F2 ADD button must use the qty box, exactly like
      // scanning does. It used to hardcode 1, so a qty of 5 entered
      // first was silently ignored and the line came in as 1 (or 2
      // after a second click / a + press).
      const qtyInput = document.getElementById("posQtyInput");
      const qtyRaw = qtyInput ? qtyInput.value.trim() : "1";
      if (
        !/^\d+$/.test(qtyRaw) ||
        Number(qtyRaw) < 1 ||
        Number(qtyRaw) > 999
      ) {
        showToast("Enter a quantity from 1 to 999.", "warning");
        if (qtyInput) {
          qtyInput.focus();
          if (typeof qtyInput.select === "function") qtyInput.select();
        }
        return;
      }
      if (!addProductToCart(product, Number(qtyRaw))) return;
      bootstrap.Modal.getInstance(
        document.getElementById("productListModal"),
      )?.hide();
      // Same clean-up as a successful scan: barcode cleared, qty back
      // to 1, ready for the next item.
      const barcodeInput = document.getElementById("posBarcodeInput");
      if (barcodeInput) barcodeInput.value = "";
      resetQtyBox();
      focusScanner();
    });
    tbody.appendChild(row);
  });
}

function addProductToCart(product, quantity) {
  // Wholesale-barcode block: no supervisor override — the cashier rings
  // the contents with the regular product barcode instead. Refused here
  // too (not only at scan) so the product-list ADD button and any other
  // caller can never sneak a blocked line in.
  if (isWholesaleBarcode(product) && !hasWholesaleAccess(selectedCustomer)) {
    showToast(WHOLESALE_BLOCK_MESSAGE, "warning");
    setScanMessage(WHOLESALE_BLOCK_MESSAGE, true);
    return false;
  }
  const qty = Math.max(1, parseInt(quantity, 10) || 1);
  const existingIndex = cart.findIndex(
    (line) => line.product_id === product.product_id,
  );
  if (existingIndex >= 0) {
    cart[existingIndex].quantity += qty;
    cart[existingIndex].is_wholesale_barcode = Number(
      product.is_wholesale_barcode || 0,
    );
    cart[existingIndex].is_suki_eligible = Number(product.is_suki_eligible ?? 1);
    cart[existingIndex].blocked = lineIsBlocked(cart[existingIndex]);
    selectedLineIndex = existingIndex;
  } else {
    cart.push({
      product_id: product.product_id,
      product_name: product.product_name,
      unit: product.unit,
      barcode: product.barcode || "",
      price: parseFloat(product.price),
      quantity: qty,
      is_wholesale_barcode: Number(product.is_wholesale_barcode || 0),
      is_suki_eligible: Number(product.is_suki_eligible ?? 1),
      blocked: false,
    });
    selectedLineIndex = cart.length - 1;
  }
  renderCart();
  renderTotals();
  ensureSelectedVisible();
  return true;
}

function renderCart() {
  const tbody = document.getElementById("posCartBody");
  tbody.innerHTML = "";
  if (selectedLineIndex >= cart.length) selectedLineIndex = cart.length - 1;

  if (cart.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-5"><i class="bi fs-2 d-block mb-2"></i>Enter a product to start the transaction.</td></tr>`;
  } else {
    cart.forEach((line, index) => {
      const row = document.createElement("tr");
      if (index === selectedLineIndex) row.classList.add("table-primary");
      if (line.blocked) row.classList.add("table-danger");
      row.innerHTML = `
                <td class="text-muted">${index + 1}</td>
                <td class="small">${esc(line.barcode || "—")}</td>
                <td><strong>${esc(line.product_name)}</strong>${line.blocked ? ' <span class="badge bg-danger">Wholesale — blocked</span>' : ""}<div class="small text-muted">${esc(line.unit)}</div></td>
                <td class="text-end">${formatPeso(line.price)}</td>
                <td>
                    <div class="input-group input-group-sm">
                        <button class="btn btn-outline-secondary qty-minus" type="button" title="Decrease (supervisor approval)">−</button>
                        <input type="number" min="1" class="form-control text-center cart-qty" value="${line.quantity}" aria-label="Quantity">
                        <button class="btn btn-outline-secondary qty-plus" type="button" title="Increase">+</button>
                    </div>
                </td>
                <td class="text-end fw-semibold">${formatPeso(line.price * line.quantity)}</td>`;

      row.addEventListener("click", (e) => {
        if (e.target.closest("button,input")) return;
        selectLine(index);
      });
      row
        .querySelector(".qty-minus")
        .addEventListener("click", () =>
          requestQtyChange(line.product_id, line.quantity - 1),
        );
      row.querySelector(".qty-plus").addEventListener("click", () => {
        const i = cart.findIndex((l) => l.product_id === line.product_id);
        if (i >= 0) {
          cart[i].quantity += 1;
          selectedLineIndex = i;
          renderCart();
          focusScanner();
        }
      });
      row
        .querySelector(".cart-qty")
        .addEventListener("change", (e) =>
          requestQtyChange(line.product_id, e.target.value),
        );
      // ↑/↓ inside a cart qty box moves to the previous / next cart
      // line's qty box, instead of Tab / Shift+Tab and instead of
      // the number spinner. Qty itself is still changed by typing a
      // number, or by the + / − buttons and keys on the main screen,
      // so this only replaces how you move between lines.
      row.querySelector(".cart-qty").addEventListener("keydown", (e) => {
        if (e.key !== "ArrowUp" && e.key !== "ArrowDown") return;
        e.preventDefault();
        const inputs = [...document.querySelectorAll(".cart-qty")];
        const current = inputs.indexOf(e.target);
        if (current < 0 || !inputs.length) return;
        const delta = e.key === "ArrowDown" ? 1 : -1;
        const next = (current + delta + inputs.length) % inputs.length;
        selectedLineIndex = next;
        renderCart();
        const fresh = document.querySelectorAll(".cart-qty")[next];
        if (fresh) {
          fresh.focus();
          if (typeof fresh.select === "function") fresh.select();
        }
      });
      tbody.appendChild(row);
    });
  }

  document.getElementById("posItemCount").textContent = cart.reduce(
    (sum, line) => sum + line.quantity,
    0,
  );
  renderTotals();
}

function selectLine(index) {
  if (index < 0 || index >= cart.length) return;
  selectedLineIndex = index;
  renderCart();
  ensureSelectedVisible();
}

function moveSelection(delta) {
  if (!cart.length) return;
  selectedLineIndex =
    ((selectedLineIndex < 0 ? 0 : selectedLineIndex) + delta + cart.length) %
    cart.length;
  renderCart();
  ensureSelectedVisible();
}

/**
 * Keep the keyboard-selected cart line in view inside the internally
 * scrolling cart list, so the cashier never touches the mouse to scroll.
 */
function ensureSelectedVisible() {
  document
    .querySelector("#posCartBody tr.table-primary")
    ?.scrollIntoView({ block: "nearest" });
}

function bumpSelectedQty(amount) {
  if (selectedLineIndex < 0 || !cart[selectedLineIndex]) return;
  cart[selectedLineIndex].quantity += amount;
  renderCart();
  // Focus is intentionally left where it is so + can be pressed repeatedly.
}

/**
 * Any decrease of a line quantity requires supervisor approval BEFORE it is
 * applied. Increases are applied directly.
 */
function requestQtyChange(productId, value) {
  const index = cart.findIndex((l) => l.product_id === productId);
  if (index < 0) return;
  const current = cart[index].quantity;
  const next = parseInt(value, 10);
  if (!Number.isFinite(next) || next < 1) {
    showToast(
      "Quantity must be at least 1. Removing an item requires Supervisor authorization.",
      "warning",
    );
    renderCart();
    focusScanner();
    return;
  }
  if (next === current) {
    renderCart();
    focusScanner();
    return;
  }
  if (next < current) {
    const name = cart[index].product_name;
    requestSupervisorAuthorization(
      `Decrease quantity of ${name} from ${current} to ${next}`,
      () => {
        const i = cart.findIndex((l) => l.product_id === productId);
        if (i >= 0) {
          cart[i].quantity = next;
          selectedLineIndex = i;
        }
        renderCart();
        focusScanner();
      },
    );
    return;
  }
  cart[index].quantity = next;
  selectedLineIndex = index;
  renderCart();
  focusScanner();
}

function computeTotals() {
  const subtotal = cart.reduce(
    (sum, line) => sum + line.price * line.quantity,
    0,
  );
  const discountId = document.getElementById("posDiscount").value;
  const discount = lookups.discounts.find(
    (d) => String(d.discount_id) === discountId,
  );
  let discountAmount = 0;
  if (discount) {
    discountAmount =
      discount.discount_type === "Percentage"
        ? subtotal * (parseFloat(discount.discount_value) / 100)
        : Math.min(parseFloat(discount.discount_value), subtotal);
  }
  const total = Math.max(subtotal - discountAmount, 0);
  const paid = salePayments.reduce(
    (sum, p) => sum + parseFloat(p.amount || 0),
    0,
  );
  const remaining = Math.max(total - paid, 0);
  const cashPaid = salePayments
    .filter((p) => String(p.type_name || "").toLowerCase() === "cash")
    .reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
  const nonCashPaid = paid - cashPaid;
  const change = useAr
    ? 0
    : Math.max(cashPaid - Math.max(total - nonCashPaid, 0), 0);
  return { subtotal, discountAmount, total, paid, remaining, change };
}

function renderTotals() {
  // Keep the AR-charge checkbox honest as the cart changes: if the cart
  // grows past the available credit (or the customer changes), the box
  // is unchecked and disabled before the cashier reaches the tender.
  updateArEligibility();
  const { subtotal, discountAmount, total, paid, remaining, change } =
    computeTotals();
  document.getElementById("posSubtotal").textContent = formatPeso(subtotal);
  document.getElementById("posDiscountAmount").textContent =
    `- ${formatPeso(discountAmount)}`;
  document.getElementById("posTotal").textContent = formatPeso(total);
  const discountSelect = document.getElementById("posDiscount");
  const panelDiscountName = document.getElementById("panelDiscountName");
  if (panelDiscountName) {
    const opt = discountSelect.options[discountSelect.selectedIndex];
    panelDiscountName.textContent =
      discountSelect.value && opt ? opt.textContent.trim() : "None";
  }
  document.getElementById("posTotalQty").textContent = String(
    cart.reduce((sum, line) => sum + line.quantity, 0),
  );
  const { ar_account_id: arId } = selectedCustomer || {};
  if (useAr && arId) {
    const available =
      parseFloat(selectedCustomer.credit_limit) -
      parseFloat(selectedCustomer.current_balance);
    document.getElementById("posArAvailability").textContent =
      `AR available: ${formatPeso(available)} → after this sale: ${formatPeso(available - total)}`;
  }
  // Suki Points estimate for this sale (server confirms at checkout).
  // Eligible lines only, after discount, minus points already added
  // as a payment in this sale; AR sales earn 0.
  const sukiEarnedLine = document.getElementById("posSukiEarnedLine");
  if (sukiEarnedLine) {
    if (selectedCustomer?.suki_card_id && !useAr && cart.length > 0) {
      const eligibleSubtotal = cart.reduce(
        (sum, line) =>
          Number(line.is_suki_eligible ?? 1) === 1
            ? sum + Number(line.price) * Number(line.quantity)
            : sum,
        0,
      );
      const ratio = subtotal > 0 ? total / subtotal : 0;
      const redeemedSoFar = salePayments
        .filter((p) => p.type_name.toLowerCase() === "suki points")
        .reduce((sum, p) => sum + Number(p.amount), 0);
      const eligiblePaid = Math.max(0, eligibleSubtotal * ratio - redeemedSoFar);
      const progress = Number(selectedCustomer.suki_progress_amount || 0);
      const earned = Math.floor((progress + eligiblePaid) / 400);
      const progressAfter = progress + eligiblePaid - earned * 400;
      sukiEarnedLine.textContent = `Points Earned (this sale, estimated): ${earned} · Progress after this sale: ${formatPeso(progressAfter)} / ₱400.00`;
    } else if (selectedCustomer?.suki_card_id) {
      sukiEarnedLine.textContent = useAr
        ? "Points Earned (this sale): 0 — AR sales do not earn points."
        : "Points Earned (this sale): 0";
    } else {
      sukiEarnedLine.textContent = "";
    }
  }
  if (document.getElementById("paymentModal")?.classList.contains("show"))
    updatePaymentSummary();
}

function updateCounterDisplay() {
  const select = document.getElementById("posCounter");
  const option = select.options[select.selectedIndex];
  const label = option ? option.textContent : "No counter";
  document.getElementById("cashierCounterLabel").textContent = label;
}

function openProductList() {
  // F2 must always open with an empty barcode search, never the last
  // search term. The old code left posProductSearch untouched, so a
  // previous "3" was still there on reopen, filtering the list to
  // barcode matches for 3 even though the cashier had not searched
  // again. Clearing it first makes searchProducts() return the full
  // list, ready for a new barcode. (The scan path that shows this
  // modal for multiple matches does NOT go through here, so it keeps
  // its own search term.)
  document.getElementById("posProductSearch").value = "";
  searchProducts();
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("productListModal"),
  ).show();
}

function openCustomerModal() {
  pendingCustomer = selectedCustomer ? { ...selectedCustomer } : null;
  pendingCustomerFilter = null;
  document.getElementById("posCustomerSearch").value = "";
  // Opening the modal now automatically shows the AR-account customer
  // list (the filter defaults to AR accounts only), instead of an
  // empty box that only filled in after typing. The search box stays,
  // and the filter can be switched to All customers at any time.
  const filter = document.getElementById("posCustomerArFilter");
  if (filter) filter.value = "ar";
  searchCustomers();
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("customerModal"),
  ).show();
}

async function searchCustomers() {
  const keyword = document.getElementById("posCustomerSearch").value.trim();
  const box = document.getElementById("posCustomerResults");
  const customerFilter =
    document.getElementById("posCustomerArFilter")?.value || "ar";
  try {
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
      params: {
        operation: "getPosCustomers",
        keyword,
        customer_filter: customerFilter,
      },
    });
    const customers = response.data;
    box.innerHTML = customers.length
      ? customers
          .map((c) => {
            // Row layout follows the filter, so the list only shows
            // what that filter is about (2026-10-29):
            // - "ar": name + green AR account badge + tier only. No
            //   Suki card line under the tier.
            // - "suki": name + Suki card number only. No tier line
            //   (no Wholesale / Regular / Retailer) and no AR badge,
            //   even if the customer also has an AR account — in a
            //   Suki pick, the AR account is not what is being used.
            // - "all": name + badge + tier + card, as before.
            // Customer and Suki card are separate lines, and the card
            // code is shown on its own, without the redundant "Suki"
            // word in front of it (SUKI-0003, not Suki SUKI-0003).
            const badge =
              customerFilter !== "suki" && c.ar_account_id
                ? ` <span class="badge text-bg-success ms-1">AR account</span>`
                : "";
            const tierLine =
              customerFilter === "suki"
                ? ""
                : `<div class="small text-muted">${esc(c.tier_name || "Regular")}</div>`;
            const cardLine =
              customerFilter !== "ar" && c.card_number
                ? `<div class="small text-muted">${esc(c.card_number)}</div>`
                : "";
            return `<button type="button" class="list-group-item list-group-item-action text-start" data-id="${c.customer_id}"><strong>${esc(c.full_name)}</strong>${badge}${tierLine}${cardLine}</button>`;
          })
          .join("")
      : `<div class="list-group-item text-muted">${customerFilter === "ar" ? "No AR-account customers found." : customerFilter === "suki" ? "No Suki card customers found." : "No matching customer."}</div>`;
    box.querySelectorAll("button[data-id]").forEach((button) => {
      const customer = customers.find(
        (c) => String(c.customer_id) === button.dataset.id,
      );
      button.addEventListener("click", () =>
        loadCustomerSelection(customer.customer_id),
      );
    });
  } catch (error) {
    box.innerHTML = `<div class="list-group-item text-danger">Unable to search customers.</div>`;
  }
}

async function loadCustomerSelection(customerId) {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getPosCustomer", customer_id: customerId },
  });
  if (!response.data) {
    showToast("Customer record is no longer available.", "danger");
    return;
  }
  // Capture the filter at pick time, not at Use time: the preview
  // replaces the list, but the filter value is still the intent the
  // cashier chose to find this customer.
  pendingCustomerFilter =
    document.getElementById("posCustomerArFilter")?.value || "ar";
  pendingCustomer = response.data;
  showCustomerPreview(pendingCustomer, true);
}

function showCustomerPreview(customer, showUseButton = true) {
  const box = document.getElementById("posCustomerResults");
  const suki = customer.suki_card_id
    ? `<div class="mt-2"><span class="text-muted">Suki Card:</span> ${esc(customer.card_number)}<br><span class="text-muted">Available Points:</span> <strong>${Number(customer.points_balance || 0)}</strong><br><span class="text-muted">Progress Toward Next Point:</span> ${formatPeso(Number(customer.suki_progress_amount || 0))} / ₱400.00<br><span class="text-muted">Amount Needed:</span> ${formatPeso(Number(customer.suki_amount_needed ?? 400))}</div>`
    : `<div class="mt-2 text-muted">No active Suki Card.</div>`;
  box.innerHTML = `<div class="card border-0 bg-light"><div class="card-body"><div class="small text-muted">Selected customer</div><h5 class="mb-1">${esc(customer.full_name)}</h5><div class="small text-muted">${esc(customer.contact_number || "No contact number")} · ${esc(customer.tier_name || "Regular")}</div>${suki}</div></div>${showUseButton ? `<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-outline-secondary" id="btnCustomerBack">Back to Search</button><button type="button" class="btn btn-primary" id="btnUseCustomer">Continue</button></div>` : ""}`;
  // Explicit Use This Customer click gets its own feedback.
  // applyPendingCustomer() itself must stay silent, because resume
  // also calls it, and that path already toasts "Transaction resumed."
  document
    .getElementById("btnUseCustomer")
    ?.addEventListener("click", async () => {
      const filterUsed = pendingCustomerFilter;
      await applyPendingCustomer();
      if (selectedCustomer)
        showToast(
          filterUsed === "suki"
            ? `Customer selected: ${selectedCustomer.full_name}. Suki card selected — AR not auto-charged, pay in cash.`
            : `Customer selected: ${selectedCustomer.full_name}.`,
          "success",
        );
    });
  document.getElementById("btnCustomerBack")?.addEventListener("click", () => {
    pendingCustomer = null;
    pendingCustomerFilter = null;
    openCustomerModal();
  });
}

async function applyPendingCustomer() {
  if (!pendingCustomer) return;
  selectedCustomer = pendingCustomer;
  pendingCustomer = null;
  const filterUsed = pendingCustomerFilter;
  pendingCustomerFilter = null;
  salePayments = [];
  useAr = false;
  document.getElementById("posSelectedCustomer").classList.remove("d-none");
  document.getElementById("panelCustomerName").textContent =
    selectedCustomer.full_name;
  document.getElementById("btnClearCustomer").classList.remove("d-none");
  document.getElementById("posPriceTierLabel").textContent =
    selectedCustomer.tier_name || "Regular";
  document.getElementById("posSukiIndicator").textContent =
    selectedCustomer.suki_card_id
      ? `Suki Card: ${selectedCustomer.full_name} ✓`
      : "No Suki Card";
  // Suki Points display (updated 2026-10-29, ₱400 = 1 point).
  // Available / Progress / Amount Needed come from the server
  // (getPosCustomer); Points Earned for this sale is an estimate
  // updated in renderTotals() and confirmed by the server at checkout.
  const sukiPointsLine = document.getElementById("posSukiPointsLine");
  const sukiProgressLine = document.getElementById("posSukiProgressLine");
  if (sukiPointsLine)
    sukiPointsLine.textContent = selectedCustomer.suki_card_id
      ? `Available Points: ${Number(selectedCustomer.points_balance || 0)}`
      : "";
  if (sukiProgressLine)
    sukiProgressLine.textContent = selectedCustomer.suki_card_id
      ? `Progress Toward Next Point: ${formatPeso(Number(selectedCustomer.suki_progress_amount || 0))} / ₱400.00 · Amount Needed: ${formatPeso(Number(selectedCustomer.suki_amount_needed ?? 400))}`
      : "";
  // Customer type, Suki presence, and AR standing stay three separate
  // lines — Suki membership never implies AR credit on its own.
  document.getElementById("posCustomerTypeLine").textContent =
    `Customer type: ${selectedCustomer.customer_type || "Regular"}`;
  const arLine = document.getElementById("posArAvailability");
  const arToggle = document.getElementById("posUseArWrapper");
  if (selectedCustomer.ar_account_id) {
    const available =
      parseFloat(selectedCustomer.credit_limit) -
      parseFloat(selectedCustomer.current_balance);
    arLine.textContent = `AR available: ${formatPeso(available)} (Status: ${selectedCustomer.credit_status})${selectedCustomer.hold_reason ? " — " + selectedCustomer.hold_reason : ""}`;
    arToggle.classList.toggle(
      "d-none",
      selectedCustomer.credit_status === "Hold",
    );
  } else {
    arLine.textContent = "No AR account.";
    arToggle.classList.add("d-none");
  }
  // Auto-charge to AR, by the filter the cashier used to pick this
  // customer (2026-10-29): "ar" (and "all", and non-modal picks like
  // a resumed held sale) keep the old behaviour — the box is ticked
  // automatically when the customer is AR-eligible. "suki" does NOT
  // auto-tick, even if the same customer also has an AR account:
  // that pick means Suki card + cash payment, so the box stays
  // unticked and enabled, and the cashier can still tick it by hand
  // later if they change their mind. updateArEligibility() is still
  // the gatekeeper: it leaves the box disabled when there is no AR
  // account, the credit is on Hold, or the current total is already
  // over the available credit, and the server re-checks all of that
  // again at checkout.
  document.getElementById("posUseAr").checked = false;
  const arEligible = updateArEligibility();
  if (arEligible && filterUsed !== "suki") {
    useAr = true;
    document.getElementById("posUseAr").checked = true;
  }
  bootstrap.Modal.getInstance(document.getElementById("customerModal"))?.hide();
  searchProducts();
  await repriceCart();
  focusScanner();
}

async function clearCustomer() {
  selectedCustomer = null;
  pendingCustomer = null;
  pendingCustomerFilter = null;
  salePayments = [];
  useAr = false;
  document.getElementById("posSelectedCustomer").classList.add("d-none");
  document.getElementById("panelCustomerName").textContent = "Walk-in";
  document.getElementById("btnClearCustomer").classList.add("d-none");
  document.getElementById("posPriceTierLabel").textContent = "Regular";
  document.getElementById("posUseAr").checked = false;
  document.getElementById("posCustomerTypeLine").textContent = "";
  document.getElementById("posSukiIndicator").textContent = "No Suki Card";
  const sukiPointsLine = document.getElementById("posSukiPointsLine");
  const sukiProgressLine = document.getElementById("posSukiProgressLine");
  const sukiEarnedLine = document.getElementById("posSukiEarnedLine");
  if (sukiPointsLine) sukiPointsLine.textContent = "";
  if (sukiProgressLine) sukiProgressLine.textContent = "";
  if (sukiEarnedLine) sukiEarnedLine.textContent = "";
  updateArEligibility();
  searchProducts();
  await repriceCart();
}

async function handleCheckout(e) {
  e.preventDefault();
  if (!cart.length) {
    setScanMessage("Scan at least one product.", true);
    focusScanner();
    return;
  }
  const counterId = document.getElementById("posCounter").value;
  if (!counterId) {
    setScanMessage("No open counter available.", true);
    return;
  }
  if (useAr && !selectedCustomer?.ar_account_id) {
    setScanMessage("Select a customer with an AR account.", true);
    return;
  }
  openPaymentModal();
}

function getPaymentTypeName(paymentTypeId) {
  return (
    lookups.payment_types.find(
      (p) => String(p.payment_type_id) === String(paymentTypeId),
    )?.type_name || ""
  );
}

// Tender AR gating — UI ONLY. insertSale on the server remains the final
// enforcer; this only keeps the checkbox honest and explains why AR is
// unavailable, so the cashier finds out before submitting, not after.
function arEligibility() {
  const { total } = computeTotals();
  const c = selectedCustomer;
  if (!c) {
    // Walk-in: AR simply does not apply, so no reason line is shown.
    return { eligible: false, reason: "", available: 0 };
  }
  if (!c.ar_account_id) {
    return {
      eligible: false,
      reason:
        "This customer has no active AR account — AR charge is not available.",
      available: 0,
    };
  }
  if (c.credit_status === "Hold") {
    let reason =
      "This customer's credit is on Hold — AR charge is not available.";
    if (c.hold_reason) reason += ` ${c.hold_reason}`;
    return { eligible: false, reason, available: 0 };
  }
  const available =
    parseFloat(c.credit_limit) - parseFloat(c.current_balance);
  if (total > available + 0.001) {
    return {
      eligible: false,
      reason: `Over credit limit. Available credit is only ${formatPeso(available)}.`,
      available,
    };
  }
  return { eligible: true, reason: "", available };
}

function updateArEligibility() {
  const box = document.getElementById("tenderArEligibility");
  const checkbox = document.getElementById("posUseAr");
  const { eligible, reason, available } = arEligibility();
  if (!eligible) {
    useAr = false;
    if (checkbox) {
      checkbox.checked = false;
      checkbox.disabled = true;
    }
    if (box) {
      box.textContent = reason;
      box.className = "small text-danger mt-1";
    }
  } else {
    if (checkbox) checkbox.disabled = false;
    if (box) {
      box.textContent = `Available credit: ${formatPeso(available)}`;
      box.className = "small text-success mt-1";
    }
  }
  return eligible;
}

function openPaymentModal() {
  if (!cart.length) {
    setScanMessage("Scan at least one product.", true);
    return;
  }
  if (cartHasBlockedLine()) {
    setScanMessage(
      "A wholesale-barcode line is blocked for this customer. Remove it (supervisor approval) before checkout.",
      true,
    );
    showToast(
      "Checkout blocked: remove the wholesale-barcode line first.",
      "warning",
    );
    return;
  }
  // Re-gate AR at tender time: the cart may have grown since the customer
  // was selected, so eligibility is re-checked against the current total.
  updateArEligibility();
  salePayments = [];
  selectedPaymentIndex = -1;
  document.getElementById("paymentError").textContent = "";
  document.getElementById("paymentGiftCertificate").value = "";
  document.getElementById("paymentGcInfo").textContent = "";
  document.getElementById("paymentReference").value = "";
  document
    .getElementById("paymentAddSection")
    .classList.toggle("d-none", useAr);
  document
    .getElementById("paymentArSection")
    .classList.toggle("d-none", !useAr);
  document.getElementById("paymentCompleteBtn").textContent = useAr
    ? "Charge to AR"
    : "Complete Sale";
  if (!useAr) {
    const cash = lookups.payment_types.find(
      (p) => String(p.type_name).toLowerCase() === "cash",
    );
    if (cash) setPaymentMethod(cash.payment_type_id);
  }
  renderPaymentRows();
  updatePaymentEntryFields();
  updatePaymentSummary();
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("paymentModal"),
  ).show();
  if (!useAr) prefillPaymentAmount();
}

function prefillPaymentAmount() {
  const { remaining } = computeTotals();
  const input = document.getElementById("paymentAmount");
  input.value = remaining > 0.001 ? remaining.toFixed(2) : "";
  input.focus();
  input.select();
}

function updatePaymentEntryFields() {
  const method = getPaymentTypeName(
    document.getElementById("paymentMethod").value,
  ).toLowerCase();
  document
    .getElementById("paymentGiftCertificateWrapper")
    .classList.toggle("d-none", method !== "gift certificate");
  document
    .getElementById("paymentReferenceWrapper")
    .classList.toggle(
      "d-none",
      method === "gift certificate" || method === "suki points",
    );
  if (method === "suki points" && selectedCustomer?.suki_card_id) {
    document.getElementById("paymentHint").textContent =
      `Available: ${Number(selectedCustomer.points_balance || 0)} point(s) · 1 point = ₱1.00`;
  } else if (method === "gift certificate") {
    document.getElementById("paymentHint").textContent =
      "Enter the Gift Certificate ID or scan a GC code — the system will validate it.";
  } else {
    document.getElementById("paymentHint").textContent =
      "Add the amount for this payment method.";
  }
}

function isGcCodeInput(value) {
  return /^gc-/i.test(value.trim());
}

async function lookupPaymentGiftCertificate() {
  const method = getPaymentTypeName(
    document.getElementById("paymentMethod").value,
  ).toLowerCase();
  if (method !== "gift certificate") return;
  const gcId = document.getElementById("paymentGiftCertificate").value.trim();
  const box = document.getElementById("paymentGcInfo");
  pendingGcCode = null;
  if (!gcId) {
    box.textContent = "";
    return;
  }
  try {
    if (isGcCodeInput(gcId)) {
      // Standalone code-based certificate (no Suki association).
      const response = await axios.get(`${baseApiUrl}/gc-codes.php`, {
        params: { operation: "validateCode", code: gcId },
      });
      if (!response.data.success) {
        box.textContent = response.data.message;
        box.className = "small text-danger mt-1";
        return;
      }
      const cert = response.data.certificate;
      pendingGcCode = { code: cert.code, face_value: Number(cert.face_value) };
      // A code is consumed in full on use, so its applied amount is always
      // min(face value, amount still due). Set it for the cashier instead
      // of letting a hand-typed partial amount silently burn the rest.
      const { remaining } = computeTotals();
      const applied = Math.min(Number(cert.face_value), remaining);
      document.getElementById("paymentAmount").value = applied.toFixed(2);
      updatePaymentSummary();
      const forfeited = Number(cert.face_value) - applied;
      box.textContent =
        forfeited > 0.001
          ? `Valid · Face value: ${formatPeso(cert.face_value)} · ${formatPeso(applied)} will be applied and ${formatPeso(forfeited)} forfeited — this code is used up in full.`
          : `Valid · Face value: ${formatPeso(cert.face_value)} · the full value will be applied.`;
      box.className = "small text-success mt-1";
      return;
    }
    const response = await axios.get(`${baseApiUrl}/gift-certificates.php`, {
      params: { operation: "getForPayment", gc_id: gcId },
    });
    if (!response.data.success) {
      box.textContent = response.data.message;
      box.className = "small text-danger mt-1";
      return;
    }
    const gc = response.data.gift_certificate;
    box.textContent = `Valid · Remaining balance: ${formatPeso(gc.remaining_balance)}`;
    box.className = "small text-success mt-1";
  } catch (error) {
    box.textContent = "Unable to validate the Gift Certificate.";
    box.className = "small text-danger mt-1";
  }
}

async function addPaymentEntry() {
  const error = document.getElementById("paymentError");
  error.textContent = "";
  const { total } = computeTotals();
  const paid = salePayments.reduce(
    (sum, p) => sum + parseFloat(p.amount || 0),
    0,
  );
  const remaining = Math.max(total - paid, 0);
  const paymentTypeId = document.getElementById("paymentMethod").value;
  const typeName = getPaymentTypeName(paymentTypeId);
  const method = typeName.toLowerCase();
  const amount = parseFloat(document.getElementById("paymentAmount").value);
  const referenceNo = document.getElementById("paymentReference").value.trim();
  const gcId = document.getElementById("paymentGiftCertificate").value.trim();

  if (!paymentTypeId) {
    error.textContent = "Select a payment method.";
    return;
  }
  if (!Number.isFinite(amount) || amount <= 0) {
    error.textContent = "Enter a positive payment amount.";
    return;
  }
  if (remaining <= 0.001) {
    error.textContent = "The sale is already fully paid.";
    return;
  }
  if (method !== "cash" && amount > remaining + 0.001) {
    error.textContent = `This payment cannot exceed the remaining balance of ${formatPeso(remaining)}.`;
    return;
  }
  if (method === "suki points") {
    if (!selectedCustomer?.suki_card_id) {
      error.textContent = "Select a customer with a Suki Card first.";
      return;
    }
    if (Math.abs(amount - Math.round(amount)) > 0.001) {
      error.textContent = "Suki Points must be a whole number.";
      return;
    }
    const available = Math.max(
      0,
      Number(selectedCustomer.points_balance || 0) -
        salePayments
          .filter((p) => p.type_name.toLowerCase() === "suki points")
          .reduce((sum, p) => sum + Number(p.amount), 0),
    );
    if (Math.round(amount) > available) {
      error.textContent = `Insufficient Suki Points. Available: ${available}.`;
      return;
    }
  }
  let gccCode = null;
  if (method === "gift certificate") {
    if (!gcId) {
      error.textContent = "Enter the Gift Certificate ID or scan a GC code.";
      return;
    }
    if (isGcCodeInput(gcId)) {
      // Standalone code-based certificate: validated fresh here; the face
      // value is authoritative from the server. No partial redemption — the
      // amount is capped at the amount due and any excess is forfeited.
      const response = await axios.get(`${baseApiUrl}/gc-codes.php`, {
        params: { operation: "validateCode", code: gcId },
      });
      if (!response.data.success) {
        error.textContent = response.data.message;
        return;
      }
      const cert = response.data.certificate;
      // The code is redeemed in full regardless of the amount applied, so
      // the amount is not the cashier's choice: it must be exactly
      // min(face value, remaining due). Anything else either overpays or
      // silently forfeits value the customer could have used.
      const appliedRequired = Math.min(Number(cert.face_value), remaining);
      if (Math.abs(amount - appliedRequired) > 0.001) {
        error.textContent = `GC codes are used in full: the amount for ${cert.code} must be ${formatPeso(appliedRequired)} (face value ${formatPeso(cert.face_value)}${Number(cert.face_value) > appliedRequired + 0.001 ? `, ${formatPeso(Number(cert.face_value) - appliedRequired)} forfeited` : ""}). The amount has been set for you — press Add again.`;
        document.getElementById("paymentAmount").value =
          appliedRequired.toFixed(2);
        return;
      }
      if (
        salePayments.some(
          (p) =>
            p.gcc_code &&
            p.gcc_code.toUpperCase() === String(cert.code).toUpperCase(),
        )
      ) {
        error.textContent = `Code ${cert.code} is already added to this sale.`;
        return;
      }
      gccCode = cert.code;
    } else {
      const response = await axios.get(`${baseApiUrl}/gift-certificates.php`, {
        params: { operation: "getForPayment", gc_id: gcId },
      });
      if (!response.data.success) {
        error.textContent = response.data.message;
        return;
      }
      if (
        amount >
        Number(response.data.gift_certificate.remaining_balance) + 0.001
      ) {
        error.textContent = `Maximum usable balance is ${formatPeso(response.data.gift_certificate.remaining_balance)}.`;
        return;
      }
    }
  }

  salePayments.push({
    payment_type_id: Number(paymentTypeId),
    type_name: typeName,
    amount: Number(amount.toFixed(2)),
    reference_no: referenceNo,
    gc_id: method === "gift certificate" && !gccCode ? Number(gcId) : null,
    gcc_code: gccCode,
  });
  document.getElementById("paymentReference").value = "";
  document.getElementById("paymentGiftCertificate").value = "";
  document.getElementById("paymentGcInfo").textContent = "";
  pendingGcCode = null;
  renderPaymentRows();
  updatePaymentSummary();
  prefillPaymentAmount();
}

function renderPaymentRows() {
  const box = document.getElementById("paymentRows");
  if (selectedPaymentIndex >= salePayments.length)
    selectedPaymentIndex = salePayments.length - 1;
  box.innerHTML = salePayments.length
    ? salePayments
        .map(
          (payment, index) =>
            `<div class="tender-line d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1${index === selectedPaymentIndex ? " tender-line-selected" : ""}" data-payment-line="${index}"><div><strong>${esc(payment.type_name)}</strong>${payment.gcc_code ? `<span class="small text-muted"> · GC ${esc(payment.gcc_code)}</span>` : payment.gc_id ? `<span class="small text-muted"> · Gift Cert #${payment.gc_id}</span>` : payment.reference_no ? `<span class="small text-muted"> · Ref: ${esc(payment.reference_no)}</span>` : ""}</div><div class="d-flex align-items-center gap-2"><strong>${formatPeso(payment.amount)}</strong><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-payment="${index}" tabindex="-1">Remove</button></div></div>`,
        )
        .join("")
    : `<div class="text-muted small border rounded p-2 text-center">No payments added yet.</div>`;
  box.querySelectorAll("[data-remove-payment]").forEach((btn) =>
    btn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      removePaymentAt(Number(btn.dataset.removePayment));
    }),
  );
  box.querySelectorAll("[data-payment-line]").forEach((row) =>
    row.addEventListener("click", () => {
      selectedPaymentIndex = Number(row.dataset.paymentLine);
      renderPaymentRows();
    }),
  );
}

function removePaymentAt(index) {
  if (index < 0 || index >= salePayments.length) return;
  salePayments.splice(index, 1);
  if (selectedPaymentIndex >= salePayments.length)
    selectedPaymentIndex = salePayments.length - 1;
  renderPaymentRows();
  updatePaymentSummary();
  prefillPaymentAmount();
}

function movePaymentSelection(dir) {
  if (!salePayments.length) return;
  selectedPaymentIndex =
    selectedPaymentIndex < 0
      ? dir > 0
        ? 0
        : salePayments.length - 1
      : Math.min(
          salePayments.length - 1,
          Math.max(0, selectedPaymentIndex + dir),
        );
  renderPaymentRows();
  document
    .querySelector(`#paymentRows [data-payment-line="${selectedPaymentIndex}"]`)
    ?.scrollIntoView({ block: "nearest" });
}

function removeSelectedPayment() {
  if (selectedPaymentIndex >= 0) removePaymentAt(selectedPaymentIndex);
}

function handleTenderKeydown(e) {
  // Alt+1..Alt+4: pick a payment method pill.
  if (e.altKey && ["1", "2", "3", "4"].includes(e.key)) {
    const pills = [
      ...document.querySelectorAll(
        "#paymentMethodPills [data-payment-type-id]",
      ),
    ];
    const pill = pills[Number(e.key) - 1];
    if (pill) {
      e.preventDefault();
      pill.click();
    }
    return;
  }
  // Ctrl+Enter: complete the sale when fully paid.
  if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
    e.preventDefault();
    e.stopPropagation();
    const btn = document.getElementById("paymentCompleteBtn");
    if (!btn.disabled) completeSaleFromPaymentModal();
    return;
  }
  const activeId = document.activeElement?.id || "";
  // Enter in an entry field adds the payment line.
  if (
    e.key === "Enter" &&
    ["paymentAmount", "paymentReference", "paymentGiftCertificate"].includes(
      activeId,
    )
  ) {
    e.preventDefault();
    e.stopPropagation();
    addPaymentEntry();
    return;
  }
  // Payment-line navigation when not typing in a field.
  const tag = document.activeElement?.tagName?.toLowerCase();
  if (!["input", "textarea", "select"].includes(tag)) {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      if (salePayments.length) {
        e.preventDefault();
        movePaymentSelection(e.key === "ArrowDown" ? 1 : -1);
      }
      return;
    }
    if (e.key === "Delete" && selectedPaymentIndex >= 0) {
      e.preventDefault();
      removeSelectedPayment();
    }
  }
}

function updatePaymentSummary() {
  const { total, paid, remaining, change } = computeTotals();
  document.getElementById("paymentTotalAmount").textContent = formatPeso(total);
  document.getElementById("paymentTotalPaid").textContent = formatPeso(paid);
  document.getElementById("paymentRemaining").textContent =
    formatPeso(remaining);
  document.getElementById("paymentChange").textContent = formatPeso(change);
  const fullyPaid = paid + 0.001 >= total;
  document.getElementById("tenderTotalDue").textContent = formatPeso(total);
  const dynLabel = document.getElementById("tenderDynamicLabel");
  const dynValue = document.getElementById("tenderDynamicValue");
  if (fullyPaid) {
    dynLabel.textContent = "Change";
    dynValue.textContent = formatPeso(change);
    dynValue.classList.remove("text-warning");
    dynValue.classList.add("text-success");
  } else {
    dynLabel.textContent = "Remaining";
    dynValue.textContent = formatPeso(remaining);
    dynValue.classList.remove("text-success");
    dynValue.classList.add("text-warning");
  }
  document.getElementById("tenderProgress").style.width =
    `${total > 0 ? Math.min((paid / total) * 100, 100) : 0}%`;
  const canComplete = useAr
    ? Boolean(selectedCustomer?.ar_account_id)
    : fullyPaid;
  document.getElementById("paymentCompleteBtn").disabled = !canComplete;
}

async function completeSaleFromPaymentModal() {
  const errorBox = document.getElementById("paymentError");
  errorBox.textContent = "";
  if (cartHasBlockedLine()) {
    errorBox.textContent =
      "A wholesale-barcode line is blocked for this customer. Remove it before completing the sale.";
    return;
  }
  const { total, paid } = computeTotals();
  if (!useAr && paid + 0.001 < total) {
    errorBox.textContent = `Remaining balance is ${formatPeso(total - paid)}.`;
    return;
  }
  const counterId = document.getElementById("posCounter").value;
  const submitBtn = document.getElementById("paymentCompleteBtn");
  submitBtn.disabled = true;
  try {
    const payload = {
      counter_id: counterId,
      customer_id: selectedCustomer ? selectedCustomer.customer_id : null,
      discount_id: document.getElementById("posDiscount").value || null,
      use_ar: useAr,
      payments: useAr
        ? []
        : salePayments.map((p) => ({
            payment_type_id: p.payment_type_id,
            amount: p.amount,
            reference_no: p.reference_no || "",
            gc_id: p.gc_id,
            gcc_code: p.gcc_code || "",
          })),
      items: cart.map((line) => ({
        product_id: line.product_id,
        quantity: line.quantity,
      })),
    };
    const formData = new FormData();
    formData.append("operation", "insertSale");
    formData.append("json", JSON.stringify(payload));
    const response = await axios({
      url: `${baseApiUrl}/pos.php`,
      method: "POST",
      data: formData,
    });
    if (!response.data.success) {
      errorBox.textContent = response.data.message;
      return;
    }
    bootstrap.Modal.getInstance(
      document.getElementById("paymentModal"),
    )?.hide();
    showToast(response.data.message, "success");
    lastSaleId = response.data.sale_id;
    await showReceipt(lastSaleId);
    // Paid = gone from the held list. This is the only automatic
    // removal: a resumed held transaction stays held until this point.
    if (activeHeldId) {
      await deleteHeldById(activeHeldId);
      activeHeldId = null;
      await refreshHeldTransactions();
    }
    resetSale(false);
    // End the completed sale at a fresh sale, ready to scan (qty first).
    // Silent here: the payment toast + receipt are already the feedback
    // for this sale, a "New sale started" toast on top would be noise.
    await newSale(true);
    await refreshCashDrawer();
    document.dispatchEvent(new CustomEvent("pos:saleCompleted"));
  } catch (error) {
    errorBox.textContent = "Unable to complete the sale.";
  } finally {
    submitBtn.disabled = false;
    updatePaymentSummary();
  }
}

export async function showReceipt(saleId) {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getSaleDetail", sale_id: saleId },
  });
  if (!response.data.success) {
    showToast(response.data.message, "danger");
    return null;
  }
  document.getElementById("receiptContent").innerHTML = buildReceiptHtml(
    response.data.sale,
  );
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("receiptModal"),
  ).show();
  return response.data.sale;
}

export function buildReceiptHtml(sale) {
  const itemRows = sale.items
    .map(
      (item) =>
        `<tr><td>${esc(item.product_name)}</td><td class="text-center">${item.quantity}</td><td class="text-end">${formatPeso(item.line_total)}</td></tr>`,
    )
    .join("");
  const paymentRows = (sale.payments || [])
    .map(
      (p) =>
        `<tr><td>${esc(p.type_name)}${p.gcc_code ? ` · ${esc(p.gcc_code)}` : p.gc_id ? ` #${p.gc_id}` : ""}</td><td class="text-end">${formatPeso(p.amount_paid)}</td></tr>`,
    )
    .join("");
  const pointsLine =
    Number(sale.points_earned || 0) > 0
      ? `<div>Points earned: ${Number(sale.points_earned)}</div>`
      : "";
  const redeemedLine =
    Number(sale.points_redeemed || 0) > 0
      ? `<div>Points used: ${Number(sale.points_redeemed)} (₱${Number(sale.points_redeemed).toFixed(2)})</div>`
      : "";
  // Totals block: display only, no math changes. When VAT is enabled the
  // stored sale fields break the total down; when disabled, no VAT lines
  // render at all and the original three-row block is kept verbatim.
  const totalsRows = (() => {
    if (!lookups.vat_enabled) {
      return `<tr><td>SUBTOTAL</td><td class="text-end">${formatPeso(sale.subtotal)}</td></tr><tr><td>DISCOUNT</td><td class="text-end">- ${formatPeso(sale.discount_amount)}</td></tr><tr class="fw-bold"><td>TOTAL</td><td class="text-end">${formatPeso(sale.total_amount)}</td></tr>`;
    }
    const subtotal = Number(sale.subtotal || 0);
    const discount = Number(sale.discount_amount || 0);
    const exempt =
      sale.vat_exempt_sales != null ? Number(sale.vat_exempt_sales) : 0;
    // Contract field; falls back to net-of-discount less exempt lines
    // until the backend selects vatable_sales in getSaleDetail.
    const vatable =
      sale.vatable_sales != null
        ? Number(sale.vatable_sales)
        : Math.max(0, subtotal - discount - exempt);
    const vat = Number(sale.vat_amount || 0);
    return `<tr><td>SUBTOTAL</td><td class="text-end">${formatPeso(subtotal)}</td></tr>${discount > 0 ? `<tr><td>DISCOUNT</td><td class="text-end">- ${formatPeso(discount)}</td></tr>` : ""}<tr><td>VATABLE SALES</td><td class="text-end">${formatPeso(vatable)}</td></tr><tr><td>VAT (12%)</td><td class="text-end">${formatPeso(vat)}</td></tr>${exempt > 0 ? `<tr><td>VAT-EXEMPT SALES</td><td class="text-end">${formatPeso(exempt)}</td></tr>` : ""}<tr class="fw-bold"><td>TOTAL DUE</td><td class="text-end">${formatPeso(sale.total_amount)}</td></tr>`;
  })();
  return `<div class="receipt"><div class="text-center"><strong>GROCERY STORE</strong></div><div class="text-center">SALES RECEIPT</div><hr><div>Receipt #: ${esc(sale.receipt_number)}</div><div>Date: ${formatDateTime(sale.created_at)}</div><div>Cashier: ${esc(sale.cashier_name)}</div><div>Counter: ${esc(sale.counter_name)}</div>${sale.customer_name ? `<div>Customer: ${esc(sale.customer_name)}</div>` : ""}<hr><table class="w-100"><thead><tr><th>ITEM</th><th>QTY</th><th class="text-end">AMOUNT</th></tr></thead><tbody>${itemRows}</tbody></table><hr><table class="w-100">${totalsRows}</table><hr>${sale.ar_account_id ? `<div>CHARGED TO AR ACCOUNT</div>` : `<table class="w-100"><tbody>${paymentRows}</tbody></table><div class="d-flex justify-content-between"><span>Change</span><strong>${formatPeso(sale.change_amount)}</strong></div>`}${redeemedLine}${pointsLine}<hr><div class="text-center">THANK YOU FOR SHOPPING</div></div>`;
}

function resetSale(focus = true) {
  cart = [];
  salePayments = [];
  selectedLineIndex = -1;
  clearCustomer();
  document.getElementById("posDiscount").value = "";
  document.getElementById("paymentError").textContent = "";
  renderCart();
  if (focus) focusScanner();
}

async function deleteHeldById(heldId) {
  if (!heldId) return;
  try {
    await axios.post(
      `${baseApiUrl}/pos.php`,
      new URLSearchParams({
        operation: "deleteHeldTransaction",
        held_id: heldId,
      }),
    );
  } catch (error) {
    // Leave the entry visible in the held list if the delete failed;
    // the cashier can see it is still there instead of it silently
    // vanishing while unpaid.
    showToast("Held entry could not be removed.", "warning");
  }
}

async function holdCurrentTransaction() {
  if (!cart.length) {
    showToast("There is no transaction to hold.", "warning");
    return;
  }
  const counterId = document.getElementById("posCounter").value;
  const label =
    selectedCustomer?.full_name || `Held #${new Date().toLocaleTimeString()}`;
  const formData = new FormData();
  formData.append("operation", "holdTransaction");
  formData.append(
    "json",
    JSON.stringify({
      counter_id: counterId,
      customer_id: selectedCustomer?.customer_id || null,
      discount_id: document.getElementById("posDiscount").value || null,
      use_ar: useAr,
      held_label: label,
      items: cart.map((line) => ({
        product_id: line.product_id,
        quantity: line.quantity,
      })),
    }),
  );
  const response = await axios({
    url: `${baseApiUrl}/pos.php`,
    method: "POST",
    data: formData,
  });
  if (!response.data.success) {
    showToast(response.data.message, "danger");
    return;
  }
  // Re-holding a resumed transaction: the cart is now saved as a new
  // held entry, so remove the old one to avoid a duplicate. If the
  // cart was fresh, activeHeldId is null and nothing is removed.
  if (activeHeldId) {
    await deleteHeldById(activeHeldId);
    activeHeldId = null;
  }
  showToast("Transaction paused.", "success");
  resetSale();
  refreshHeldTransactions();
}

async function refreshHeldTransactions() {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getHeldTransactions" },
  });
  renderHeldTable(response.data);
}

function renderHeldTable(held) {
  const badge = document.getElementById("heldCountBadge");
  if (badge) {
    badge.textContent = held.length;
    badge.classList.toggle("d-none", held.length === 0);
  }
  const tbody = document.getElementById("heldTransactionBody");
  if (!tbody) return;
  tbody.innerHTML = held.length
    ? held
        .map(
          (h) =>
            `<tr><td><strong>${esc(h.held_label)}</strong></td><td>${esc((h.customer_name || "Walk-in").trim())}</td><td>${esc(h.counter_name)}</td><td>${h.item_count || 0}</td><td>${formatDateTime(h.created_at)}</td><td class="text-end"><button class="btn btn-sm btn-outline-primary resume-held" data-id="${h.held_id}">Resume</button></td></tr>`,
        )
        .join("")
    : `<tr><td colspan="6" class="text-center text-muted py-3">No held transactions.</td></tr>`;
  tbody
    .querySelectorAll(".resume-held")
    .forEach((btn) =>
      btn.addEventListener("click", () => resumeHeld(Number(btn.dataset.id))),
    );
}

async function openHeldModal() {
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getHeldTransactions" },
  });
  const list = document.getElementById("heldModalList");
  list.innerHTML = response.data.length
    ? response.data
        .map(
          (h) =>
            `<button type="button" class="list-group-item list-group-item-action" data-id="${h.held_id}"><div class="d-flex justify-content-between"><strong>${esc(h.held_label)}</strong><span>${formatDateTime(h.created_at)}</span></div><div class="small text-muted">${esc((h.customer_name || "Walk-in").trim())} · ${h.item_count || 0} item(s) · ${esc(h.counter_name)}</div></button>`,
        )
        .join("")
    : `<div class="list-group-item text-muted">No held transactions.</div>`;
  list
    .querySelectorAll("button[data-id]")
    .forEach((btn) =>
      btn.addEventListener("click", () => resumeHeld(Number(btn.dataset.id))),
    );
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("heldModal"),
  ).show();
}

async function resumeHeld(heldId) {
  // Switching held transactions must not lose the one being left.
  // If the current cart came from a held transaction, its entry is
  // still in the held list (resume no longer deletes it), so switching
  // to another held transaction is safe and needs no confirmation.
  // Only a fresh, never-held cart still asks, because replacing it
  // would lose it unless the cashier holds it first.
  if (
    cart.length &&
    !activeHeldId &&
    !(await confirmAlert({
      icon: "question",
      title: "Replace current transaction?",
      text: "The current cart will be replaced by the held transaction. Hold the current cart first if you want to keep it.",
      confirmText: "Replace",
    }))
  )
    return;
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getHeldTransaction", held_id: heldId },
  });
  if (!response.data.success) {
    showToast(response.data.message, "danger");
    return;
  }
  const held = response.data.held;
  const state = held.state;
  const counterOption = [...document.getElementById("posCounter").options].find(
    (o) => String(o.value) === String(held.counter_id),
  );
  if (!counterOption) {
    showToast(
      "The original counter is not open. Open that counter before resuming.",
      "warning",
    );
    return;
  }
  // Counter is locked after the gate, so a held transaction from a
  // different counter cannot be resumed here and recorded on this
  // terminal's counter. Resume it on its original counter instead.
  // (Without this, resuming would silently switch the sales counter
  // mid-shift, the exact End Shift confusion this lock prevents.)
  const lockedCounter = document.getElementById("posCounter").value;
  if (String(held.counter_id) !== String(lockedCounter)) {
    const lockedName =
      [...document.getElementById("posCounter").options].find(
        (o) => String(o.value) === String(lockedCounter),
      )?.textContent || `Counter ${lockedCounter}`;
    showToast(
      `This held transaction is for ${counterOption.textContent}. This terminal is locked to ${lockedName}. Resume it on its original counter.`,
      "warning",
    );
    return;
  }

  let customer = null;
  if (state.customer_id) {
    const customerResponse = await axios.get(`${baseApiUrl}/pos.php`, {
      params: { operation: "getPosCustomer", customer_id: state.customer_id },
    });
    customer = customerResponse.data;
    if (!customer) {
      showToast("The held customer's record is no longer available.", "danger");
      return;
    }
  }

  const tierId = customer
    ? customer.price_tier_id
    : lookups.default_price_tier_id;
  const restored = [];
  for (const item of state.items || []) {
    const productResponse = await axios.get(`${baseApiUrl}/pos.php`, {
      params: {
        operation: "getPosProducts",
        keyword: String(item.product_id),
        price_tier_id: tierId,
        category_id: 0,
        customer_type: customer?.customer_type || "",
      },
    });
    const product = productResponse.data.find(
      (p) => String(p.product_id) === String(item.product_id),
    );
    if (!product) {
      showToast(
        `Product ${item.product_id} is no longer available at a valid price.`,
        "danger",
      );
      return;
    }
    restored.push({
      product_id: product.product_id,
      product_name: product.product_name,
      unit: product.unit,
      barcode: product.barcode || "",
      price: parseFloat(product.price),
      quantity: parseInt(item.quantity, 10),
      is_wholesale_barcode: Number(product.is_wholesale_barcode || 0),
      is_suki_eligible: Number(product.is_suki_eligible ?? 1),
      blocked: false,
    });
  }

  cart = restored;
  document.getElementById("posCounter").value = String(held.counter_id);
  if (customer) {
    pendingCustomer = customer;
    // Not a modal pick: no filter intent, so applyPendingCustomer
    // keeps its old auto-tick behaviour, and the held sale's own
    // use_ar below overwrites it afterwards, as before.
    pendingCustomerFilter = null;
    applyPendingCustomer();
  } else clearCustomer();
  document.getElementById("posDiscount").value = state.discount_id
    ? String(state.discount_id)
    : "";
  useAr = !!state.use_ar;
  document.getElementById("posUseAr").checked = useAr;
  renderCart();
  // Do NOT delete the held entry here anymore. It stays in the held
  // list, with this cart now pointing at it (activeHeldId), so the
  // cashier can switch to another held transaction and come back to
  // this one. It is removed only when this sale is paid, when the
  // cashier clears it with New Sale / Void, or when it is re-held.
  activeHeldId = heldId;
  bootstrap.Modal.getInstance(document.getElementById("heldModal"))?.hide();
  showToast("Transaction resumed.", "success");
  refreshHeldTransactions();
  refreshCashDrawer();
  focusScanner();
}

// ---------- Cash Float denominations + replenish ----------

// Philippine cash denominations for float breakdowns (bills and coins).
const FLOAT_DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

// Render a pieces-count grid that live-sums into a total input. The total
// input stays the source of truth; the grid is only a counting aid, and
// the same helper serves the opening gate and the replenish form.
function bindDenominationGrid(gridEl, totalInput) {
  if (!gridEl || !totalInput) return;
  gridEl.innerHTML = FLOAT_DENOMINATIONS.map(
    (d) => `
    <div class="d-flex align-items-center gap-2 mb-1">
      <span class="small text-muted" style="width: 64px">₱${d}</span>
      <input type="number" min="0" step="1" inputmode="numeric" class="form-control form-control-sm" data-denomination="${d}" placeholder="0" autocomplete="off" />
    </div>`,
  ).join("");
  gridEl.querySelectorAll("[data-denomination]").forEach((input) => {
    input.addEventListener("input", () => {
      const sum = collectDenominations(gridEl).reduce(
        (s, d) => s + d.denomination * d.pieces,
        0,
      );
      totalInput.value = sum.toFixed(2);
    });
  });
}

function collectDenominations(gridEl) {
  if (!gridEl) return [];
  return [...gridEl.querySelectorAll("[data-denomination]")]
    .map((input) => ({
      denomination: Number(input.dataset.denomination),
      pieces: Math.max(0, parseInt(input.value, 10) || 0),
    }))
    .filter((d) => d.pieces > 0);
}

function resetDenominationGrid(gridEl) {
  gridEl
    ?.querySelectorAll("[data-denomination]")
    .forEach((input) => (input.value = ""));
}

let replenishSubmitting = false;

async function submitReplenishFloat() {
  if (replenishSubmitting) return;
  const errorBox = document.getElementById("replenishFloatError");
  errorBox.textContent = "";
  const counterId = document.getElementById("posCounter").value;
  const amount = parseFloat(
    document.getElementById("replenishFloatInput").value,
  );
  const gridEl = document.getElementById("replenishDenominationGrid");
  const denominations = collectDenominations(gridEl);
  const breakdownSum = denominations.reduce(
    (s, d) => s + d.denomination * d.pieces,
    0,
  );
  if (!counterId || !Number.isFinite(amount) || amount <= 0) {
    errorBox.textContent =
      "Enter a valid amount greater than zero and select a counter.";
    return;
  }
  if (Math.abs(breakdownSum - amount) > 0.01) {
    errorBox.textContent = `The denomination breakdown (₱${breakdownSum.toFixed(2)}) must add up to the amount (₱${amount.toFixed(2)}).`;
    return;
  }
  // Add Float is supervisor-verified server-side (same as Cash Lift),
  // so the credentials must travel with the request.
  const username = document
    .getElementById("replenishSupervisorUsername")
    .value.trim();
  const password = document.getElementById(
    "replenishSupervisorPassword",
  ).value;
  if (!username || !password) {
    errorBox.textContent =
      "Supervisor username and password are required to add float.";
    return;
  }
  const submitBtn = document.getElementById("btnSubmitReplenishFloat");
  replenishSubmitting = true;
  setBusy(submitBtn, true, "Adding…");
  try {
    const formData = new FormData();
    formData.append("operation", "addCashFloat");
    formData.append(
      "json",
      JSON.stringify({
        counter_id: counterId,
        amount,
        denominations,
        username,
        password,
      }),
    );
    const response = await axios({
      url: `${baseApiUrl}/pos.php`,
      method: "POST",
      data: formData,
    });
    if (!response.data.success) {
      errorBox.textContent =
        response.data.message || "Unable to add float.";
      return;
    }
    showToast(response.data.message || "Float added.", "success");
    if (username) lastAuthorizerUser = username;
    document.getElementById("replenishFloatInput").value = "";
    document.getElementById("replenishSupervisorPassword").value = "";
    resetDenominationGrid(gridEl);
    bootstrap.Modal.getInstance(
      document.getElementById("drawerModal"),
    )?.hide();
    refreshCashDrawer();
  } catch (error) {
    errorBox.textContent = "Unable to reach the server. Please try again.";
  } finally {
    replenishSubmitting = false;
    setBusy(submitBtn, false);
  }
}

async function openCashDrawer() {
  const btn = document.getElementById("btnOpenCashDrawer");
  if (btn?.disabled) return;
  const amount = parseFloat(
    document.getElementById("beginningFloatInput").value,
  );
  const counterId = document.getElementById("posCounter").value;
  if (!counterId || !Number.isFinite(amount) || amount <= 0) {
    // Same rule as the opening gate: a float must be greater than zero.
    await showAlert({
      icon: "warning",
      title: "Enter a beginning float",
      text: "Enter a valid beginning float (greater than zero) and select a counter.",
    });
    return;
  }
  setBusy(btn, true, "Opening…");
  try {
    const data = await postOpenCashFloat(counterId, amount);
    showToast(data.message, data.success ? "success" : "danger");
    if (data.success) refreshCashDrawer();
  } catch (error) {
    await showAlert({
      icon: "error",
      title: "Unable to reach the server",
      text: "The cash drawer was not opened. Please try again.",
    });
  } finally {
    setBusy(btn, false);
  }
}

let lastDrawerExpectedCash = 0;

async function refreshCashDrawer() {
  const counterId = document.getElementById("posCounter").value;
  if (!counterId) return;
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: { operation: "getCashDrawer", counter_id: counterId },
  });
  const drawer = response.data;
  lastDrawerExpectedCash = Number(drawer.expected_cash || 0);
  document.getElementById("cashDrawerStatus").textContent = drawer.opened
    ? "Open"
    : "Not Open";
  document.getElementById("cashDrawerStatus").className =
    `badge ${drawer.opened ? "text-bg-success" : "text-bg-secondary"}`;
  document.getElementById("beginningFloatInput").value = drawer.opened
    ? Number(drawer.beginning_float).toFixed(2)
    : "";
  document.getElementById("beginningFloatInput").disabled = drawer.opened;
  // Once the drawer is open, the Open button is not just disabled, it
  // is hidden. A disabled button still looks clickable, which is
  // exactly the confusion in the screenshot. It comes back automatically
  // when the drawer is not open (next shift), so opening from this
  // panel still works then.
  const openBtn = document.getElementById("btnOpenCashDrawer");
  openBtn.disabled = drawer.opened;
  openBtn.classList.toggle("d-none", drawer.opened);
  document.getElementById("drawerCashSales").textContent = formatPeso(
    drawer.cash_sales || 0,
  );
  document.getElementById("drawerCashLifts").textContent = formatPeso(
    drawer.cash_lifts || 0,
  );
  document.getElementById("drawerCashReplenishments").textContent = formatPeso(
    drawer.cash_replenishments || 0,
  );
  document.getElementById("drawerExpectedCash").textContent = formatPeso(
    drawer.expected_cash || 0,
  );
  // Prefill (never the password) when a supervisor authorized earlier
  // in this session — mirrors the Cash Lift form.
  const replenishUser = document.getElementById(
    "replenishSupervisorUsername",
  );
  if (replenishUser && !replenishUser.value)
    replenishUser.value = lastAuthorizerUser || "";
  document.getElementById("cashLiftExpected").textContent = formatPeso(
    drawer.expected_cash || 0,
  );
}

function openCashLiftModal() {
  refreshCashDrawer().then(() => {
    document.getElementById("cashLiftAmount").value = "";
    // Prefill (never the password) when a supervisor authorized earlier
    // in this session — one less thing to retype at End Shift too.
    document.getElementById("cashLiftUsername").value =
      lastAuthorizerUser || "";
    document.getElementById("cashLiftPassword").value = "";
    document.getElementById("cashLiftError").textContent = "";
    bootstrap.Modal.getOrCreateInstance(
      document.getElementById("cashLiftModal"),
    ).show();
  });
}

let liftSubmitting = false;

async function handleCashLiftSubmit(e) {
  e.preventDefault();
  if (liftSubmitting) return;
  liftSubmitting = true;
  const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
  setBusy(submitBtn, true, "Recording…");
  try {
    const counterId = document.getElementById("posCounter").value;
    const username = document.getElementById("cashLiftUsername").value.trim();
    const formData = new FormData();
    formData.append("operation", "cashLift");
    formData.append(
      "json",
      JSON.stringify({
        counter_id: counterId,
        amount: parseFloat(document.getElementById("cashLiftAmount").value),
        username,
        password: document.getElementById("cashLiftPassword").value,
        notes: document.getElementById("cashLiftNotes").value.trim(),
      }),
    );
    const response = await axios({
      url: `${baseApiUrl}/pos.php`,
      method: "POST",
      data: formData,
    });
    document.getElementById("cashLiftError").textContent = response.data.success
      ? ""
      : response.data.message;
    if (!response.data.success) return;
    if (username) lastAuthorizerUser = username;
    showToast(response.data.message, "success");
    bootstrap.Modal.getInstance(
      document.getElementById("cashLiftModal"),
    )?.hide();
    refreshCashDrawer();
  } catch (error) {
    document.getElementById("cashLiftError").textContent =
      "Unable to reach the server. Please try again.";
  } finally {
    liftSubmitting = false;
    setBusy(submitBtn, false);
  }
}

// Cash Lift from inside the Cash Float modal: same server call as the
// separate Cash Lift modal, but using the Cash Float modal's own
// supervisor fields and the new Lift Amount field, and staying in
// that modal. No second modal opens, and the updated Expected Cash
// shows immediately in the same modal.
let drawerLiftSubmitting = false;

async function submitDrawerCashLift() {
  if (drawerLiftSubmitting) return;
  const errorBox = document.getElementById("drawerLiftError");
  errorBox.textContent = "";
  const counterId = document.getElementById("posCounter").value;
  const amount = parseFloat(
    document.getElementById("drawerLiftAmount").value,
  );
  const username = document
    .getElementById("replenishSupervisorUsername")
    .value.trim();
  const password = document.getElementById(
    "replenishSupervisorPassword",
  ).value;
  if (!counterId || !Number.isFinite(amount) || amount <= 0) {
    errorBox.textContent = "Enter a positive cash lift amount.";
    return;
  }
  if (!username || !password) {
    errorBox.textContent =
      "Enter the Supervisor / Administrator username and password above.";
    return;
  }
  if (amount > lastDrawerExpectedCash) {
    errorBox.textContent =
      "Cash lift cannot exceed the expected cash in the drawer.";
    return;
  }
  drawerLiftSubmitting = true;
  const submitBtn = document.getElementById("btnCashLiftDrawer");
  setBusy(submitBtn, true, "Recording…");
  try {
    const formData = new FormData();
    formData.append("operation", "cashLift");
    formData.append(
      "json",
      JSON.stringify({
        counter_id: counterId,
        amount,
        username,
        password,
        notes: "Cash lift",
      }),
    );
    const response = await axios({
      url: `${baseApiUrl}/pos.php`,
      method: "POST",
      data: formData,
    });
    if (!response.data.success) {
      errorBox.textContent = response.data.message;
      return;
    }
    if (username) lastAuthorizerUser = username;
    showToast(response.data.message, "success");
    document.getElementById("drawerLiftAmount").value = "";
    // Stay in the Cash Float modal, with fresh totals, so the cashier
    // sees the new Expected Cash without another modal on top.
    await refreshCashDrawer();
  } catch (error) {
    errorBox.textContent = "Unable to reach the server. Please try again.";
  } finally {
    drawerLiftSubmitting = false;
    setBusy(submitBtn, false);
  }
}

// ---------- End of Shift ----------

let pendingShiftReport = null;
let pendingShiftClose = null;
let shiftEventsBound = false;

async function shiftGet(operation, extra) {
  const counterId = document.getElementById("posCounter")?.value || "";
  const response = await axios.get(`${baseApiUrl}/pos.php`, {
    params: Object.assign({ operation, counter_id: counterId }, extra || {}),
  });
  return response.data;
}

async function shiftPost(operation, payload) {
  const counterId = document.getElementById("posCounter")?.value || "";
  const formData = new FormData();
  formData.append("operation", operation);
  formData.append(
    "json",
    JSON.stringify(Object.assign({ counter_id: counterId }, payload || {})),
  );
  const response = await axios({
    url: `${baseApiUrl}/pos.php`,
    method: "POST",
    data: formData,
  });
  return response.data;
}

// ---------- End-Shift wizard (2026-10-21 improvement build) ----------
// One modal, three panes (Count -> Deposit -> Review). Credentials are
// verified in the step where they are typed (authorizeAction /
// verifyDepositVerifier); closeShift still re-verifies both inside its
// transaction, so the early checks are UX only and change no rule.
// The deposit reference is derived from the shift (DEP-YYYYMMDD-####,
// #### = shift_id) in exactly the same way the server derives it, so
// the number previewed at Review is the number on the final report.
let wizardStep = 1;
let lastAuthorizerUser = "";
let shiftLoading = false;

function depositRefForShift(shift) {
  if (!shift) return "";
  const ymd = String(shift.shift_date || "").replaceAll("-", "");
  return `DEP-${ymd}-${String(shift.shift_id).padStart(4, "0")}`;
}

async function verifyCreds(operation, username, password) {
  const formData = new FormData();
  formData.append("operation", operation);
  formData.append("username", username);
  formData.append("password", password);
  formData.append("json", JSON.stringify({ username, password }));
  const response = await axios({
    url: `${baseApiUrl}/pos.php`,
    method: "POST",
    data: formData,
  });
  return response.data;
}

async function openEndShift() {
  if (shiftLoading) return;
  if (cart.length > 0) {
    await showAlert({
      icon: "warning",
      title: "Sale in progress",
      text: "Tender or clear the current sale before ending the shift.",
    });
    focusScanner();
    return;
  }
  const btn = document.getElementById("btnEndShift");
  shiftLoading = true;
  setBusy(btn, true, "Loading…");
  try {
    let status;
    try {
      status = await shiftGet("getShiftStatus");
    } catch (error) {
      await showAlert({
        icon: "error",
        title: "Unable to reach the server",
        text: "The shift status could not be checked. Please try again.",
      });
      return;
    }
    if (!status.success) {
      await showAlert({
        icon: "error",
        title: "Cannot end shift",
        text: status.message || "Unable to check shift status.",
      });
      return;
    }
    if (!status.shift) {
      await showAlert({
        icon: "warning",
        title: "No open shift",
        text: "Open the cash drawer first.",
      });
      return;
    }
    let data;
    try {
      data = await shiftGet("getShiftReport");
    } catch (error) {
      await showAlert({
        icon: "error",
        title: "Unable to reach the server",
        text: "The shift report could not be loaded. Please try again.",
      });
      return;
    }
    if (!data.success || !data.report) {
      await showAlert({
        icon: "error",
        title: "Cannot end shift",
        text: data.message || "Unable to load the shift report.",
      });
      return;
    }
    pendingShiftReport = data.report;
    pendingShiftClose = null;
    openShiftWizard();
  } finally {
    shiftLoading = false;
    setBusy(btn, false);
  }
}

function openShiftWizard() {
  const report = pendingShiftReport;
  document.getElementById("shiftExpectedCash").textContent = formatPeso(
    report.cash.expected_cash,
  );
  // Fresh fields on every open (values only survive Back/Continue inside
  // this one wizard run, because the panes share one modal).
  document.getElementById("shiftActualCash").value = "";
  document.getElementById("shiftClosingNotes").value = "";
  document.getElementById("shiftSupUser").value = lastAuthorizerUser || "";
  document.getElementById("shiftSupPass").value = "";
  document.getElementById("shiftBagNo").value = "";
  document.getElementById("shiftSealedAmount").value = "";
  document.getElementById("shiftVerifierSame").checked = false;
  document.getElementById("shiftVerifierFields").classList.remove("d-none");
  document.getElementById("shiftVerifierUser").value = "";
  document.getElementById("shiftVerifierPass").value = "";
  document.getElementById("shiftVerifiedAmount").value = "";
  document.getElementById("shiftCountError").textContent = "";
  document.getElementById("shiftDepositError").textContent = "";
  document.getElementById("shiftSummaryError").textContent = "";
  const held = report.held || [];
  document
    .getElementById("shiftHeldBox")
    .classList.toggle("d-none", held.length === 0);
  document.getElementById("shiftHeldCount").textContent = held.length;
  document.getElementById("shiftHeldList").innerHTML = held
    .map(
      (h) =>
        `<li>${esc(h.held_label)} <span class="text-muted">(${esc(
          h.created_at,
        )})</span></li>`,
    )
    .join("");
  updateShiftCountState();
  showWizardStep(1);
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("shiftWizardModal"),
  ).show();
  setTimeout(() => {
    const actualInput = document.getElementById("shiftActualCash");
    actualInput.focus();
    actualInput.select();
  }, 150);
}

function showWizardStep(step) {
  wizardStep = step;
  const panes = {
    1: "shiftPaneCount",
    2: "shiftPaneDeposit",
    3: "shiftPaneReview",
  };
  Object.entries(panes).forEach(([n, id]) => {
    document.getElementById(id).classList.toggle("d-none", Number(n) !== step);
  });
  document.querySelectorAll("#shiftWizardSteps .wizard-step").forEach((el) => {
    const n = Number(el.dataset.wstep);
    el.classList.toggle("is-active", n === step);
    el.classList.toggle("is-done", n < step);
  });
  document
    .getElementById("btnWizardBack")
    .classList.toggle("d-none", step === 1);
  const next = document.getElementById("btnWizardNext");
  next.classList.toggle("btn-success", step === 3);
  next.classList.toggle("btn-primary", step !== 3);
  next.innerHTML =
    step === 1
      ? "Continue"
      : step === 2
        ? "Continue to Review"
        : "Confirm Close Shift";
  if (step === 1) {
    setTimeout(() => document.getElementById("shiftActualCash")?.focus(), 60);
  } else if (step === 2) {
    setTimeout(() => document.getElementById("shiftBagNo")?.focus(), 60);
  } else {
    setTimeout(() => next.focus(), 60);
  }
}

function shiftCountDiff() {
  const expected = pendingShiftReport.cash.expected_cash;
  const raw = document.getElementById("shiftActualCash").value;
  if (raw === "" || !Number.isFinite(parseFloat(raw))) return null;
  const actual = parseFloat(raw);
  return Math.round((actual - expected) * 100) / 100;
}

function updateShiftCountState() {
  const diffEl = document.getElementById("shiftDifference");
  const hint = document.getElementById("shiftSupervisorHint");
  const diff = shiftCountDiff();
  const heldCount = (pendingShiftReport.held || []).length;
  if (diff === null) {
    diffEl.textContent = "—";
    diffEl.className = "fs-4 text-muted";
  } else if (Math.abs(diff) <= 0.004) {
    diffEl.textContent = formatPeso(0);
    diffEl.className = "fs-4 text-success";
  } else if (diff < 0) {
    diffEl.textContent = "−" + formatPeso(Math.abs(diff));
    diffEl.className = "fs-4 text-danger";
  } else {
    diffEl.textContent = "+" + formatPeso(diff);
    diffEl.className = "fs-4 text-warning";
  }
  // Authorization is required on every close; the hint only adds the
  // extra context when held transactions or a variance are in play.
  const reasons = [];
  if (heldCount > 0) {
    reasons.push(
      heldCount +
        " held transaction(s) will be overridden by this authorization.",
    );
  }
  if (diff !== null && Math.abs(diff) > 0.004) {
    reasons.push(
      (diff < 0 ? "Shortage of " : "Excess of ") +
        formatPeso(Math.abs(diff)) +
        " will be recorded.",
    );
  }
  hint.textContent = reasons.join(" ");
}

async function wizardNext() {
  if (wizardStep === 1) return wizardStepCount();
  if (wizardStep === 2) return wizardStepDeposit();
  return confirmShiftClose();
}

function wizardBack() {
  if (wizardStep === 2) showWizardStep(1);
  else if (wizardStep === 3) {
    primeDepositPane();
    showWizardStep(2);
  }
}

// Step 1: local validation, then the supervisor credentials are checked
// NOW (authorizeAction) so a typo is fixed here — not after Step 3.
async function wizardStepCount() {
  const errBox = document.getElementById("shiftCountError");
  errBox.textContent = "";
  const raw = document.getElementById("shiftActualCash").value;
  const actual = parseFloat(raw);
  if (!Number.isFinite(actual) || actual < 0) {
    errBox.textContent = "Enter the actual cash counted (0 or more).";
    document.getElementById("shiftActualCash").focus();
    return;
  }
  const diff = shiftCountDiff();
  const notes = document.getElementById("shiftClosingNotes").value.trim();
  if (Math.abs(diff) > 0.004 && notes === "") {
    errBox.textContent = "Add a note explaining the cash difference.";
    document.getElementById("shiftClosingNotes").focus();
    return;
  }
  // Authorization is required on EVERY close.
  const supUser = document.getElementById("shiftSupUser").value.trim();
  const supPass = document.getElementById("shiftSupPass").value;
  if (supUser === "" || supPass === "") {
    errBox.textContent =
      "Supervisor / Admin credentials are required to close the shift.";
    document.getElementById("shiftSupUser").focus();
    return;
  }
  const btn = document.getElementById("btnWizardNext");
  setBusy(btn, true, "Verifying…");
  try {
    const data = await verifyCreds("authorizeAction", supUser, supPass);
    if (!data.success) {
      await showAlert({
        icon: "error",
        title: "Authorization failed",
        text: data.message || "Invalid Supervisor/Administrator credentials.",
      });
      document.getElementById("shiftSupPass").focus();
      document.getElementById("shiftSupPass").select();
      return;
    }
    lastAuthorizerUser = supUser;
    pendingShiftClose = {
      actual_cash: Math.round(actual * 100) / 100,
      closing_notes: notes,
      supervisor_user: supUser,
      supervisor_pass: supPass,
      authorized_name: data.authorized_by || supUser,
    };
    primeDepositPane();
    showWizardStep(2);
  } catch (error) {
    await showAlert({
      icon: "error",
      title: "Unable to reach the server",
      text: "The authorization could not be checked. Please try again.",
    });
  } finally {
    setBusy(btn, false);
  }
}

function primeDepositPane() {
  const counted = pendingShiftClose ? pendingShiftClose.actual_cash : 0;
  document.getElementById("shiftDepositCounted").textContent =
    formatPeso(counted);
  const sealedInput = document.getElementById("shiftSealedAmount");
  // Prefill the sealed amount with the counted amount; editable if what
  // physically went into the bag differs.
  if (!sealedInput.value) {
    sealedInput.value = counted.toFixed(2);
  }
  document.getElementById("shiftDepositError").textContent = "";
}

// Step 2: local validation, then the verifier credentials are checked
// NOW (verifyDepositVerifier) — including the "not the cashier" rule.
async function wizardStepDeposit() {
  const errBox = document.getElementById("shiftDepositError");
  errBox.textContent = "";
  const bagNo = document.getElementById("shiftBagNo").value.trim();
  if (bagNo === "") {
    errBox.textContent = "Enter the deposit bag label / number.";
    document.getElementById("shiftBagNo").focus();
    return;
  }
  const sealed = parseFloat(document.getElementById("shiftSealedAmount").value);
  if (!Number.isFinite(sealed) || sealed < 0) {
    errBox.textContent = "Enter the amount sealed in the bag (0 or more).";
    document.getElementById("shiftSealedAmount").focus();
    return;
  }
  const sameVerifier = document.getElementById("shiftVerifierSame").checked;
  const verifierUser = sameVerifier
    ? pendingShiftClose.supervisor_user
    : document.getElementById("shiftVerifierUser").value.trim();
  const verifierPass = sameVerifier
    ? pendingShiftClose.supervisor_pass
    : document.getElementById("shiftVerifierPass").value;
  if (verifierUser === "" || verifierPass === "") {
    errBox.textContent =
      "The verifier's credentials are required — another staff member or a supervisor must double-count the sealed cash.";
    document.getElementById("shiftVerifierUser").focus();
    return;
  }
  const verified = parseFloat(
    document.getElementById("shiftVerifiedAmount").value,
  );
  if (!Number.isFinite(verified) || verified < 0) {
    errBox.textContent = "Enter the verifier's counted amount (0 or more).";
    document.getElementById("shiftVerifiedAmount").focus();
    return;
  }
  const btn = document.getElementById("btnWizardNext");
  setBusy(btn, true, "Verifying…");
  try {
    const data = await verifyCreds(
      "verifyDepositVerifier",
      verifierUser,
      verifierPass,
    );
    if (!data.success) {
      await showAlert({
        icon: "error",
        title: "Verification failed",
        text: data.message || "Invalid verifier credentials.",
      });
      if (!sameVerifier) {
        document.getElementById("shiftVerifierPass").focus();
        document.getElementById("shiftVerifierPass").select();
      }
      return;
    }
    pendingShiftClose.deposit_bag_no = bagNo;
    pendingShiftClose.deposit_amount = Math.round(sealed * 100) / 100;
    pendingShiftClose.deposit_verified_amount =
      Math.round(verified * 100) / 100;
    pendingShiftClose.verifier_user = verifierUser;
    pendingShiftClose.verifier_pass = verifierPass;
    pendingShiftClose.verified_name = data.verified_by || verifierUser;
    renderShiftSummary();
    showWizardStep(3);
  } catch (error) {
    await showAlert({
      icon: "error",
      title: "Unable to reach the server",
      text: "The verifier could not be checked. Please try again.",
    });
  } finally {
    setBusy(btn, false);
  }
}

function shiftMoneyRow(label, value, strong) {
  return `<div class="d-flex justify-content-between py-1 border-bottom">
    <span class="${strong ? "fw-semibold" : "text-muted"}">${label}</span>
    <strong class="${strong ? "" : "fw-semibold"}">${formatPeso(value)}</strong>
  </div>`;
}

function renderShiftSummary() {
  const r = pendingShiftReport;
  const c = pendingShiftClose;
  const diff = Math.round((c.actual_cash - r.cash.expected_cash) * 100) / 100;
  const diffClass =
    Math.abs(diff) <= 0.004
      ? "text-success"
      : diff < 0
        ? "text-danger"
        : "text-warning";
  const payRows = r.payments
    .map(
      (p) => `<div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">${esc(p.type_name)}</span>
        <strong>${formatPeso(p.total)}</strong>
      </div>`,
    )
    .join("");
  document.getElementById("shiftSummaryContent").innerHTML = `
    <div class="small text-muted mb-2">
      ${esc(r.shift.cashier_name)} · ${esc(r.shift.counter_name)}
      (#${r.shift.counter_number}) · Opened ${esc(r.shift.opened_at)}
    </div>
    <div class="fw-semibold mb-1">Sales summary</div>
    <div class="mb-3">
      ${shiftMoneyRow("Gross sales", r.sales.gross)}
      ${shiftMoneyRow("Discounts", r.sales.discount)}
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Returns / refunds</span>
        <strong>${formatPeso(0)} <span class="badge text-bg-secondary">Not supported</span></strong>
      </div>
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Voided (${r.sales.voided_count})</span>
        <strong>${formatPeso(r.sales.voided_total)}</strong>
      </div>
      ${shiftMoneyRow("Net sales", r.sales.net, true)}
      <div class="d-flex justify-content-between py-1">
        <span class="text-muted">Transactions</span>
        <strong>${r.sales.count}</strong>
      </div>
    </div>
    <div class="fw-semibold mb-1">Payment breakdown</div>
    <div class="mb-3">
      ${payRows}
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Accounts Receivable</span>
        <strong>${formatPeso(r.ar_total)}</strong>
      </div>
    </div>
    <div class="fw-semibold mb-1">Cash reconciliation</div>
    <div class="mb-1">
      ${shiftMoneyRow("Opening cash", r.cash.opening_cash)}
      ${shiftMoneyRow("Cash received", r.cash.cash_received)}
      ${shiftMoneyRow("Cash lifts", r.cash.cash_lifts)}
      ${shiftMoneyRow("Expected cash", r.cash.expected_cash, true)}
      ${shiftMoneyRow("Actual cash counted", c.actual_cash, true)}
      <div class="d-flex justify-content-between py-1">
        <span class="fw-semibold">Difference</span>
        <strong class="fs-5 ${diffClass}">${
          Math.abs(diff) <= 0.004
            ? formatPeso(0)
            : (diff < 0 ? "−" : "+") + formatPeso(Math.abs(diff))
        }</strong>
      </div>
      ${
        c.closing_notes
          ? `<div class="small text-muted mt-1">Notes: ${esc(c.closing_notes)}</div>`
          : ""
      }
    </div>
    <div class="fw-semibold mb-1 mt-3">Authorization &amp; deposit</div>
    <div>
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Authorized by</span>
        <strong>${esc(c.authorized_name || c.supervisor_user)}</strong>
      </div>
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Deposit reference</span>
        <strong>${esc(depositRefForShift(r.shift))}</strong>
      </div>
      <div class="d-flex justify-content-between py-1 border-bottom">
        <span class="text-muted">Bag label / no.</span>
        <strong>${esc(c.deposit_bag_no || "—")}</strong>
      </div>
      ${shiftMoneyRow("Amount sealed", c.deposit_amount || 0)}
      ${shiftMoneyRow("Verifier's count", c.deposit_verified_amount || 0)}
      <div class="d-flex justify-content-between py-1">
        <span class="fw-semibold">Deposit difference</span>
        <strong class="fs-5 ${
          Math.abs(
            (c.deposit_verified_amount || 0) - (c.deposit_amount || 0),
          ) <= 0.004
            ? "text-success"
            : "text-danger"
        }">${
          Math.abs(
            (c.deposit_verified_amount || 0) - (c.deposit_amount || 0),
          ) <= 0.004
            ? formatPeso(0)
            : ((c.deposit_verified_amount || 0) - (c.deposit_amount || 0) < 0
                ? "−"
                : "+") +
              formatPeso(
                Math.abs(
                  (c.deposit_verified_amount || 0) - (c.deposit_amount || 0),
                ),
              )
        }</strong>
      </div>
      <div class="small text-muted">Verified by ${esc(c.verified_name || c.verifier_user || "—")} — the server confirms both accounts again when you confirm the close.</div>
    </div>`;
  document.getElementById("shiftSummaryError").textContent = "";
}

// Step 3: the actual close. The server re-verifies everything inside
// its transaction; its message (already closed, bad verifier, ...)
// is shown as an alert so it cannot be missed, and the wizard stays
// on Review so the cashier can go Back and fix the input. Bag labels
// are reusable since 2026-10-29, so they are no longer a close error.
async function confirmShiftClose() {
  const errBox = document.getElementById("shiftSummaryError");
  errBox.textContent = "";
  const btn = document.getElementById("btnWizardNext");
  setBusy(btn, true, "Closing shift…");
  try {
    const data = await shiftPost("closeShift", pendingShiftClose);
    if (!data.success) {
      await showAlert({
        icon: "error",
        title: "Shift was not closed",
        text: data.message || "Failed to close the shift.",
      });
      return;
    }
    renderShiftReport(data.report);
    renderDepositSlip(data.report);
    bootstrap.Modal.getInstance(
      document.getElementById("shiftWizardModal"),
    )?.hide();
    await showAlert({
      icon: "success",
      title: `Shift #${data.report.shift.shift_id} closed`,
      text: `Deposit ${data.report.deposit?.ref || ""} recorded for bag ${data.report.deposit?.bag_no || ""}. You can print the report or the deposit slip, start a new shift, or log out.`,
    });
    document.body.classList.remove("printing-slip");
    bootstrap.Modal.getOrCreateInstance(
      document.getElementById("shiftReportModal"),
    ).show();
  } catch (error) {
    await showAlert({
      icon: "error",
      title: "Unable to reach the server",
      text: "The shift was not closed. Please try again.",
    });
  } finally {
    setBusy(btn, false);
  }
}

function renderShiftReport(r) {
  const diff = r.cash.cash_difference === null ? null : r.cash.cash_difference;
  const diffWord =
    diff === null || Math.abs(diff) <= 0.004
      ? formatPeso(0)
      : (diff < 0 ? "−" : "+") +
        formatPeso(Math.abs(diff)) +
        (diff < 0 ? " SHORTAGE" : " EXCESS");
  const row = (label, value, bold) => `
      <div class="receipt-row${bold ? " fw-bold" : ""}"><span>${label}</span><span>${value}</span></div>`;
  const payRows = r.payments
    .map((p) => row(esc(p.type_name), formatPeso(p.total)))
    .join("");
  const dep = r.deposit || {};
  const depDiff =
    dep.difference === null || dep.difference === undefined
      ? null
      : dep.difference;
  document.getElementById("shiftReportContent").innerHTML = `
    <div class="receipt receipt-shift">
      <div class="text-center">
        <div class="fw-bold">ORORAMA GROCERY STORE</div>
        <div>END-OF-SHIFT REPORT</div>
      </div>
      <hr>
      ${row("Shift", `#${r.shift.shift_id} · ${esc(r.shift.shift_date)}`)}
      ${row("Cashier", `${esc(r.shift.cashier_name)} (#${r.shift.employee_id})`)}
      ${row("Counter", `${esc(r.shift.counter_name)} (#${r.shift.counter_number})`)}
      ${row("Opened", esc(r.shift.opened_at))}
      ${row("Closed", esc(r.shift.closed_at || "—"))}
      <hr>
      ${row("Gross sales", formatPeso(r.sales.gross))}
      ${row("Discounts", formatPeso(r.sales.discount))}
      ${row("Net sales", formatPeso(r.sales.net), true)}
      ${row("Transactions", String(r.sales.count))}
      ${row(`Voided (${r.sales.voided_count})`, formatPeso(r.sales.voided_total))}
      <hr>
      ${payRows}
      ${row("AR credit", formatPeso(r.ar_total))}
      <hr>
      ${row("Opening cash", formatPeso(r.cash.opening_cash))}
      ${row("Cash received", formatPeso(r.cash.cash_received))}
      ${row("Cash lifts", formatPeso(r.cash.cash_lifts))}
      ${row("Expected cash", formatPeso(r.cash.expected_cash), true)}
      ${row("Actual counted", formatPeso(r.cash.actual_cash === null ? 0 : r.cash.actual_cash), true)}
      ${row("Difference", diffWord, true)}
      ${
        r.shift.closing_notes
          ? `<div>Note: ${esc(r.shift.closing_notes)}</div>`
          : ""
      }
      ${row(
        "Authorized by",
        r.shift.authorized_by
          ? `${esc(r.shift.authorized_by.name)} (${esc(r.shift.authorized_by.role)})`
          : "—",
      )}
      <hr>
      <div class="text-center fw-bold">DEPOSIT</div>
      ${row("Deposit Ref.", esc(dep.ref || depositRefForShift(r.shift)))}
      ${row("Bag No.", esc(dep.bag_no || "—"))}
      ${row("Sealed", dep.amount === null || dep.amount === undefined ? "—" : formatPeso(dep.amount))}
      ${row("Verified", dep.verified_amount === null || dep.verified_amount === undefined ? "—" : formatPeso(dep.verified_amount))}
      ${row(
        "Verified by",
        dep.verified_by
          ? `${esc(dep.verified_by.name)} (${esc(dep.verified_by.role)})`
          : "—",
      )}
      ${row(
        "Deposit diff.",
        depDiff === null
          ? "—"
          : Math.abs(depDiff) <= 0.004
            ? formatPeso(0)
            : (depDiff < 0 ? "−" : "+") + formatPeso(Math.abs(depDiff)),
        true,
      )}
      <hr>
      <div class="receipt-signatures">
        <div>Cashier: ______________________</div>
        <div>Supervisor: ___________________</div>
      </div>
      <div class="text-center">*** END OF SHIFT #${r.shift.shift_id} ***</div>
    </div>`;
}

function renderDepositSlip(r) {
  const dep = r.deposit || {};
  const depDiff =
    dep.difference === null || dep.difference === undefined
      ? null
      : dep.difference;
  const row = (label, value, bold) => `
      <div class="receipt-row${bold ? " fw-bold" : ""}"><span>${label}</span><span>${value}</span></div>`;
  document.getElementById("depositSlipContent").innerHTML = `
    <div class="receipt receipt-shift">
      <div class="text-center">
        <div class="fw-bold">ORORAMA GROCERY STORE</div>
        <div>CASH DEPOSIT SLIP</div>
      </div>
      <hr>
      ${row("Deposit Ref.", esc(dep.ref || depositRefForShift(r.shift)), true)}
      ${row("Shift", `#${r.shift.shift_id} · ${esc(r.shift.shift_date)}`)}
      ${row("Cashier", `${esc(r.shift.cashier_name)} (#${r.shift.employee_id})`)}
      ${row("Counter", `${esc(r.shift.counter_name)} (#${r.shift.counter_number})`)}
      ${row("Closed", esc(r.shift.closed_at || "—"))}
      <hr>
      ${row("Bag No.", esc(dep.bag_no || "—"), true)}
      ${row("Amount sealed", dep.amount === null || dep.amount === undefined ? "—" : formatPeso(dep.amount), true)}
      ${row("Verifier's count", dep.verified_amount === null || dep.verified_amount === undefined ? "—" : formatPeso(dep.verified_amount))}
      ${row(
        "Verified by",
        dep.verified_by
          ? `${esc(dep.verified_by.name)} (${esc(dep.verified_by.role)})`
          : "—",
      )}
      ${row(
        "Deposit diff.",
        depDiff === null
          ? "—"
          : Math.abs(depDiff) <= 0.004
            ? formatPeso(0)
            : (depDiff < 0 ? "−" : "+") + formatPeso(Math.abs(depDiff)),
        true,
      )}
      <hr>
      <div class="receipt-signatures">
        <div>Cashier: ______________________</div>
        <div>Verifier: _____________________</div>
        <div>Received by: __________________</div>
      </div>
      <div class="text-center">*** DEPOSIT ${esc(dep.ref || depositRefForShift(r.shift))} ***</div>
    </div>`;
}

// Done — Start New Shift: never returns to the sales screen on a closed
// shift. It re-runs the opening-cash gate, so the next shift starts with
// a fresh float (or the cashier picks "Back to login" at the gate).
async function startNewShiftFromReport() {
  bootstrap.Modal.getInstance(
    document.getElementById("shiftReportModal"),
  )?.hide();
  pendingShiftClose = null;
  pendingShiftReport = null;
  const passed = await runOpeningCashGate();
  if (passed) {
    refreshCashDrawer();
    focusScanner();
  }
}

// Strict logout (header button): while a shift is Open anywhere, the
// only choices are End Shift or Cancel — logging out must not bypass
// closing. With no Open shift (or after a close) logout is direct.
export async function requestLogout() {
  let mine = null;
  try {
    const response = await axios.get(`${baseApiUrl}/pos.php`, {
      params: { operation: "getCounterOccupancy" },
    });
    const counters = response.data?.counters || [];
    for (const c of counters) {
      if (c.open_shift && c.open_shift.is_mine) {
        mine = { counter: c, shift: c.open_shift };
        break;
      }
    }
  } catch (error) {
    await showAlert({
      icon: "error",
      title: "Cannot verify your shift",
      text: "Your shift status could not be checked, so logout is blocked. Please try again.",
    });
    return;
  }
  if (!mine) {
    logout();
    return;
  }
  const goEndShift = await confirmAlert({
    icon: "warning",
    title: `Shift #${mine.shift.shift_id} is still open`,
    text: `You have an open shift on ${mine.counter.counter_name} (#${mine.counter.counter_number}), opened ${mine.shift.opened_at}. Log out is not allowed until the shift is closed.`,
    confirmText: "End Shift",
    cancelText: "Cancel",
  });
  if (goEndShift) {
    setCounterSelection(mine.counter.counter_id);
    openEndShift();
  }
}

function bindShiftEvents() {
  if (shiftEventsBound) return;
  shiftEventsBound = true;
  document
    .getElementById("btnEndShift")
    ?.addEventListener("click", openEndShift);
  document
    .getElementById("shiftActualCash")
    ?.addEventListener("input", updateShiftCountState);
  document
    .getElementById("btnWizardNext")
    ?.addEventListener("click", wizardNext);
  document
    .getElementById("btnWizardBack")
    ?.addEventListener("click", wizardBack);
  document
    .getElementById("shiftVerifierSame")
    ?.addEventListener("change", (e) => {
      document
        .getElementById("shiftVerifierFields")
        .classList.toggle("d-none", e.target.checked);
    });
  document
    .getElementById("btnPrintShiftReport")
    ?.addEventListener("click", () => {
      document.body.classList.remove("printing-slip");
      window.print();
    });
  document
    .getElementById("btnPrintDepositSlip")
    ?.addEventListener("click", () => {
      document.body.classList.add("printing-slip");
      window.print();
    });
  window.addEventListener("afterprint", () => {
    document.body.classList.remove("printing-slip");
  });
  document
    .getElementById("btnShiftDone")
    ?.addEventListener("click", () => logout());
  document
    .getElementById("btnShiftNewShift")
    ?.addEventListener("click", startNewShiftFromReport);
}

function requestSupervisorAuthorization(action, callback) {
  pendingSupervisorAction = callback;
  document.getElementById("supervisorModalTitle").textContent =
    "Supervisor Authorization";
  document.getElementById("supervisorActionLabel").textContent =
    `${action} requires Supervisor approval.`;
  document.getElementById("supervisorUsername").value = "";
  document.getElementById("supervisorPassword").value = "";
  document.getElementById("supervisorError").textContent = "";
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("supervisorModal"),
  ).show();
}

async function handleSupervisorSubmit(e) {
  e.preventDefault();
  const username = document.getElementById("supervisorUsername").value.trim();
  const password = document.getElementById("supervisorPassword").value;
  if (!username || !password) {
    document.getElementById("supervisorError").textContent =
      `The form is reading an empty ${!username ? "username" : "password"}. Please type it directly into this dialog.`;
    return;
  }
  const formData = new FormData(e.currentTarget);
  formData.append("operation", "authorizeAction");
  formData.append(
    "json",
    JSON.stringify({
      username,
      password,
    }),
  );
  const response = await axios({
    url: `${baseApiUrl}/pos.php`,
    method: "POST",
    data: formData,
  });
  if (!response.data.success) {
    document.getElementById("supervisorError").textContent =
      response.data.message;
    return;
  }
  const callback = pendingSupervisorAction;
  pendingSupervisorAction = null;
  if (username) lastAuthorizerUser = username;
  bootstrap.Modal.getInstance(
    document.getElementById("supervisorModal"),
  )?.hide();
  if (callback) callback();
  focusScanner();
}

async function newSale(silent = false) {
  if (
    cart.length &&
    !(await confirmAlert({
      icon: "question",
      title: "Start a new sale?",
      text: "The current transaction will be cleared.",
      confirmText: "New Sale",
    }))
  )
    return;
  // Cashier explicitly cleared the cart, so a resumed held entry is
  // removed too. This is one of the two ways a held entry disappears
  // (the other is paying for it).
  if (activeHeldId) {
    await deleteHeldById(activeHeldId);
    activeHeldId = null;
    await refreshHeldTransactions();
  }
  setScanMessage("");
  resetSale(true);
  // This was a silent success before: the cart cleared, but nothing
  // said so. Now the cashier gets a short confirmation, unless the
  // caller already gave feedback (payment completion).
  if (!silent) showToast("New sale started.", "success");
}

function voidCurrentSale() {
  if (cart.length === 0) return;
  requestSupervisorAuthorization("Void current transaction", async () => {
    if (activeHeldId) {
      await deleteHeldById(activeHeldId);
      activeHeldId = null;
      await refreshHeldTransactions();
    }
    resetSale(true);
    showToast("Sale voided.", "warning");
  });
}

function removeSelectedLine() {
  if (selectedLineIndex < 0 || !cart[selectedLineIndex]) {
    setScanMessage("Select a cart line first (↑/↓).", true);
    return;
  }
  const index = selectedLineIndex;
  const name = cart[index].product_name;
  requestSupervisorAuthorization(`Remove item (${name})`, () => {
    cart.splice(index, 1);
    if (selectedLineIndex >= cart.length) selectedLineIndex = cart.length - 1;
    renderCart();
    focusScanner();
    showToast(`Removed ${name}.`, "success");
  });
}

async function reprintLastReceipt() {
  if (!lastSaleId) {
    setScanMessage("No receipt to reprint yet.", true);
    return;
  }
  await showReceipt(lastSaleId);
}

function openDiscountModal() {
  const source = document.getElementById("posDiscount");
  const target = document.getElementById("discountModalSelect");
  target.innerHTML = source.innerHTML;
  target.value = source.value;
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("discountModal"),
  ).show();
  setTimeout(() => target.focus(), 250);
}

function applyDiscountFromModal(e) {
  e.preventDefault();
  const previous = document.getElementById("posDiscount").value;
  const next = document.getElementById("discountModalSelect").value;
  document.getElementById("posDiscount").value = next;
  renderTotals();
  bootstrap.Modal.getInstance(document.getElementById("discountModal"))?.hide();
  // Discount was a silent success before: the modal closed and the
  // total changed, but nothing said what happened. Toast only when
  // it actually changed, so re-applying the same discount is not noise.
  if (previous !== next)
    showToast(
      next ? "Discount applied." : "Discount removed.",
      "success",
    );
}

function openHelpModal() {
  bootstrap.Modal.getOrCreateInstance(
    document.getElementById("helpModal"),
  ).show();
}

// ↑/↓ row navigation for modal lists (2026-10-29): Product List,
// Customer list, and Held list. Before this, the only way to move
// between rows in those modals was Tab / Shift+Tab through every
// button and field. Now ↑/↓ moves to the previous / next row button
// and focuses it, so Enter / Space activates that row, exactly like
// the main cart's ↑/↓ line navigation. Returns false when there is
// no list to move in, so the caller can fall through to other keys.
// A <select> (e.g. the Product List category) is deliberately left
// alone, because ↑/↓ there must still change the selected option.
function moveModalListFocus(delta) {
  const openModal = document.querySelector(".modal.show");
  if (!openModal) return false;
  const activeTag = document.activeElement?.tagName?.toLowerCase();
  if (activeTag === "select" || activeTag === "textarea") return false;
  let buttons = [];
  if (openModal.id === "productListModal") {
    buttons = [...document.querySelectorAll("#posProductTableBody button")];
  } else if (openModal.id === "customerModal") {
    buttons = [...document.querySelectorAll("#posCustomerResults button[data-id]")];
  } else if (openModal.id === "heldModal") {
    buttons = [...document.querySelectorAll("#heldModalList button[data-id]")];
  } else {
    return false;
  }
  if (!buttons.length) return false;
  const current = buttons.indexOf(document.activeElement);
  const next =
    current < 0
      ? delta > 0
        ? 0
        : buttons.length - 1
      : (current + delta + buttons.length) % buttons.length;
  buttons[next].focus();
  buttons[next].scrollIntoView({ block: "nearest" });
  return true;
}

function globalKeyboardShortcuts(e) {
  const active = document.activeElement;
  const tag = active?.tagName?.toLowerCase();
  const typing = ["input", "textarea", "select"].includes(tag);
  const inScanField =
    !!active &&
    (active.id === "posBarcodeInput" || active.id === "posQtyInput");
  const modalOpen = !!document.querySelector(".modal.show");

  // Esc: close the topmost dialog, otherwise return focus to the barcode input.
  if (e.key === "Escape") {
    const openModals = [...document.querySelectorAll(".modal.show")];
    if (openModals.length) {
      e.preventDefault();
      bootstrap.Modal.getInstance(openModals[openModals.length - 1])?.hide();
    } else if (!inScanField) {
      e.preventDefault();
      focusScanner();
    }
    return;
  }

  // Discount dialog: works unless a modal is already open.
  if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === "d") {
    e.preventDefault();
    if (!modalOpen) openDiscountModal();
    return;
  }

  // End of shift: works unless a modal is already open.
  if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === "e") {
    e.preventDefault();
    if (!modalOpen) openEndShift();
    return;
  }

  // Cash drawer: Alt+Shift+D opens the drawer modal (placed right after the
  // discount check so the two Shift+D combos never collide).
  if (e.altKey && e.shiftKey && e.key.toLowerCase() === "d") {
    e.preventDefault();
    if (!modalOpen)
      bootstrap.Modal.getOrCreateInstance(
        document.getElementById("drawerModal"),
      ).show();
    return;
  }

  if (e.ctrlKey && e.key === "Enter") {
    e.preventDefault();
    if (!modalOpen) document.getElementById("checkoutForm").requestSubmit();
    return;
  }

  // F1 (help) and F5 (reprint): browser defaults are always blocked, but the
  // actions only fire when no modal is open.
  if (e.key === "F1") {
    e.preventDefault();
    if (!modalOpen) openHelpModal();
    return;
  }
  if (e.key === "F5") {
    e.preventDefault();
    if (!modalOpen) reprintLastReceipt();
    return;
  }

  // ↑/↓ in a modal list, before the modal-open early return below:
  // without this, Tab was the only way to move between Product,
  // Customer, and Held rows. Payment and other form modals are not
  // list modals, so moveModalListFocus() returns false for them and
  // their own key handling (e.g. payment-line ↑/↓) is untouched.
  if (
    modalOpen &&
    (e.key === "ArrowUp" || e.key === "ArrowDown") &&
    moveModalListFocus(e.key === "ArrowDown" ? 1 : -1)
  ) {
    e.preventDefault();
    return;
  }

  // While any modal is open, remaining function keys are ignored
  // (Esc/Enter stay contextual).
  if (modalOpen) return;

  // Function keys are NOT blocked while typing: F2-F12 type no
  // character, so pressing one from any field on the main screen
  // (barcode, qty, a search box, a select) must still fire. This was
  // the bug: the old check returned early for every non-scan field,
  // so a shortcut pressed with the cursor anywhere else did nothing,
  // and F10/F12 fell through to the browser instead. Arrow keys,
  // +/-, Delete and / below still keep their own typing guards,
  // because those keys do change what is being typed.

  switch (e.key) {
    case "F2":
      e.preventDefault();
      openProductList();
      break;
    case "F3":
      e.preventDefault();
      newSale();
      break;
    case "F4":
      e.preventDefault();
      openCustomerModal();
      break;
    case "F6":
      e.preventDefault();
      holdCurrentTransaction();
      break;
    case "F7":
      e.preventDefault();
      openHeldModal();
      break;
    case "F8":
      e.preventDefault();
      openCashLiftModal();
      break;
    case "F9":
      e.preventDefault();
      openPaymentModal();
      break;
    case "F10":
      e.preventDefault();
      removeSelectedLine();
      break;
    case "F12":
      e.preventDefault();
      voidCurrentSale();
      break;
    case "ArrowUp":
      if (!typing && cart.length) {
        e.preventDefault();
        moveSelection(-1);
      }
      break;
    case "ArrowDown":
      if (!typing && cart.length) {
        e.preventDefault();
        moveSelection(1);
      }
      break;
    case "+":
      // Works even while typing in the barcode/qty fields so + can be
      // pressed repeatedly; the keystroke is swallowed, focus stays put.
      if (
        (!typing || inScanField) &&
        selectedLineIndex >= 0 &&
        cart[selectedLineIndex]
      ) {
        e.preventDefault();
        bumpSelectedQty(1);
      }
      break;
    case "-":
      // Same repeat-friendly behavior as "+". Decreases still go through
      // supervisor approval via requestQtyChange.
      if (
        (!typing || inScanField) &&
        selectedLineIndex >= 0 &&
        cart[selectedLineIndex]
      ) {
        e.preventDefault();
        requestQtyChange(
          cart[selectedLineIndex].product_id,
          cart[selectedLineIndex].quantity - 1,
        );
      }
      break;
    case "Delete":
      if (!typing && selectedLineIndex >= 0 && cart[selectedLineIndex]) {
        e.preventDefault();
        removeSelectedLine();
      }
      break;
    case "/":
      if (!typing) {
        e.preventDefault();
        focusScanner();
      }
      break;
    default:
      break;
  }
}

function focusScanner() {
  const input = document.getElementById("posQtyInput");
  if (!document.querySelector(".modal.show") && input) input.focus();
}
