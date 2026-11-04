// utils.js
// Shared helpers used by every master file module.
// Keeps each module.js focused on its own table instead of repeating
// toast/modal boilerplate.

export const baseApiUrl = `${window.location.origin}/possshaemi/api`;

/**
 * Show a Bootstrap-styled toast in the fixed toast container in index.html.
 * @param {string} message
 * @param {"success"|"danger"} type
 */
export function showToast(message, type = "success") {
  const container = document.getElementById("toastContainer");

  const toastEl = document.createElement("div");
  toastEl.className = `toast align-items-center text-bg-${type} border-0`;
  toastEl.setAttribute("role", "alert");
  toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

  container.appendChild(toastEl);

  const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
  toast.show();

  toastEl.addEventListener("hidden.bs.toast", () => toastEl.remove());
}

// ==================================================
// SweetAlert2 helpers (2026-10-21, End-Shift improvement build)
// Swal is loaded from CDN on the cashier page. Every helper degrades
// safely when the CDN is unreachable (store internet down): alerts fall
// back to the existing Bootstrap toast and confirms to the native
// dialog, so a missing library can never block a cashier workflow.
// ==================================================
export function swalAvailable() {
  return typeof window !== "undefined" && typeof window.Swal !== "undefined";
}

/**
 * Show a one-button alert. Resolves when dismissed.
 * icon: "success" | "error" | "warning" | "info" | "question"
 */
export async function showAlert({ icon = "info", title = "", text = "", html = "" } = {}) {
  if (swalAvailable()) {
    await window.Swal.fire({
      icon,
      title: title || undefined,
      text: html ? undefined : text || undefined,
      html: html || undefined,
      confirmButtonText: "OK",
      confirmButtonColor: "#1d4ed8",
    });
    return;
  }
  const toastType =
    icon === "success" ? "success" : icon === "error" ? "danger" : icon === "warning" ? "warning" : "info";
  const container = document.getElementById("toastContainer");
  const message = [title, text].filter(Boolean).join(" — ");
  if (container) {
    showToast(message, toastType);
  } else {
    window.alert(message);
  }
}

/**
 * Show a confirm dialog. Resolves true only when the user confirms.
 * With no cancel button requested (showCancel: false) it behaves like
 * an alert with a single action.
 */
export async function confirmAlert({
  icon = "question",
  title = "",
  text = "",
  confirmText = "Yes",
  cancelText = "Cancel",
  showCancel = true,
  confirmColor = "#1d4ed8",
} = {}) {
  if (swalAvailable()) {
    const result = await window.Swal.fire({
      icon,
      title: title || undefined,
      text: text || undefined,
      showCancelButton: showCancel,
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      confirmButtonColor: confirmColor,
      reverseButtons: true,
    });
    return !!result.isConfirmed;
  }
  if (!showCancel) {
    window.alert([title, text].filter(Boolean).join("\n"));
    return true;
  }
  return window.confirm([title, text].filter(Boolean).join("\n"));
}

/**
 * Put a button into / out of its busy state: disabled + spinner + label.
 * The original label is restored when busy is cleared. Safe to call
 * repeatedly; the first label seen is the one restored.
 */
export function setBusy(btn, busy, busyLabel) {
  if (!btn) return;
  if (busy) {
    if (btn.dataset.busyOriginal === undefined) {
      btn.dataset.busyOriginal = btn.innerHTML;
    }
    btn.disabled = true;
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
      (busyLabel || "Working…");
  } else {
    btn.disabled = false;
    if (btn.dataset.busyOriginal !== undefined) {
      btn.innerHTML = btn.dataset.busyOriginal;
      delete btn.dataset.busyOriginal;
    }
  }
}

/**
 * Open the shared "Confirm Deactivation" modal and resolve when the user
 * confirms. Resolves false if the user cancels.
 * @param {string} recordLabel - e.g. the product name being deactivated
 * @returns {Promise<boolean>}
 */
export function confirmDeactivate(recordLabel) {
  // SweetAlert first (2026-10-22): prettier confirm for every Admin master
  // file. Falls back to the shared Bootstrap modal when Swal is missing.
  if (swalAvailable()) {
    return window.Swal.fire({
      icon: "warning",
      title: "Confirm Deactivation",
      text: `Deactivate "${recordLabel}"? It will be marked inactive and hidden from active selections — not permanently deleted.`,
      showCancelButton: true,
      confirmButtonText: "Deactivate",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc3545",
      reverseButtons: true,
    }).then((result) => !!result.isConfirmed);
  }
  return new Promise((resolve) => {
    const modalEl = document.getElementById("deactivateModal");
    const modal = new bootstrap.Modal(modalEl);
    const labelSpan = document.getElementById("deactivateRecordLabel");
    const confirmBtn = document.getElementById("confirmDeactivateBtn");

    labelSpan.textContent = recordLabel;

    const onConfirm = () => {
      cleanup();
      resolve(true);
    };

    const onHidden = () => {
      cleanup();
      resolve(false);
    };

    function cleanup() {
      confirmBtn.removeEventListener("click", onConfirm);
      modalEl.removeEventListener("hidden.bs.modal", onHidden);
    }

    confirmBtn.addEventListener("click", onConfirm);
    modalEl.addEventListener("hidden.bs.modal", onHidden);

    modal.show();

    // Ensure modal closes after confirm click (Bootstrap needs explicit hide)
    confirmBtn.addEventListener("click", () => modal.hide(), { once: true });
  });
}

/**
 * Open the shared "Confirm Restore" modal and resolve when the user confirms.
 * @param {string} recordLabel
 * @returns {Promise<boolean>}
 */
export function confirmRestore(recordLabel) {
  if (swalAvailable()) {
    return window.Swal.fire({
      icon: "question",
      title: "Confirm Restore",
      text: `Restore "${recordLabel}" back to active?`,
      showCancelButton: true,
      confirmButtonText: "Restore",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#198754",
      reverseButtons: true,
    }).then((result) => !!result.isConfirmed);
  }
  return new Promise((resolve) => {
    const modalEl = document.getElementById("restoreModal");
    const modal = new bootstrap.Modal(modalEl);
    const labelSpan = document.getElementById("restoreRecordLabel");
    const confirmBtn = document.getElementById("confirmRestoreBtn");

    labelSpan.textContent = recordLabel;

    const onConfirm = () => {
      cleanup();
      resolve(true);
    };

    const onHidden = () => {
      cleanup();
      resolve(false);
    };

    function cleanup() {
      confirmBtn.removeEventListener("click", onConfirm);
      modalEl.removeEventListener("hidden.bs.modal", onHidden);
    }

    confirmBtn.addEventListener("click", onConfirm);
    modalEl.addEventListener("hidden.bs.modal", onHidden);

    modal.show();

    confirmBtn.addEventListener("click", () => modal.hide(), { once: true });
  });
}

/**
 * Render a Bootstrap badge for an active/inactive status.
 */
export function statusBadge(isActive) {
  return isActive == 1
    ? `<span class="badge text-bg-success">Active</span>`
    : `<span class="badge text-bg-secondary">Inactive</span>`;
}

/**
 * Debounce a function so it only runs after the user stops typing.
 * @param {Function} fn
 * @param {number} delay
 */
export function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

/**
 * Escape a value for safe insertion into template-literal HTML.
 */
export function esc(value) {
  if (value === null || value === undefined) return "";
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

// ==================================================
// v2 additions (used by the Sales, Activity, Manager and Cashier modules)
// ==================================================

/**
 * Format a number as Philippine pesos, e.g. 1234.5 -> ₱1,234.50
 */
export function formatPeso(value) {
  const number = parseFloat(value ?? 0);
  return (
    "₱" +
    (isNaN(number) ? 0 : number).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

/**
 * Format a MySQL DATETIME string (2026-09-20 13:05:00) for display.
 */
export function formatDateTime(value) {
  if (!value) return "";
  const date = new Date(String(value).replace(" ", "T"));
  if (isNaN(date.getTime())) return esc(value);
  return date.toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * Today's date as YYYY-MM-DD in the browser's local time (for <input type="date">).
 */
export function todayString() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}

/**
 * One place that reacts to API failures for every module:
 *  - 401: session ended (logged out, or the Administrator deactivated this account) -> back to login
 *  - 403: role is not allowed to do this
 *  - anything else: show the server message instead of failing silently
 * Background polling passes { silent: true } so a network hiccup does not spam toasts.
 */
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.config?.silent) {
      return Promise.reject(error);
    }

    const status = error.response?.status;
    const data = error.response?.data;
    const serverMessage =
      data && typeof data === "object" ? data.message : null;

    if (status === 401) {
      window.location.href = "login.html";
    } else if (status === 403) {
      showToast(
        esc(serverMessage ?? "You do not have permission to do that."),
        "danger",
      );
    } else if (error.response) {
      showToast(
        esc(serverMessage ?? `Server error (${status}). Please try again.`),
        "danger",
      );
    } else {
      showToast("Unable to reach the server.", "danger");
    }

    return Promise.reject(error);
  },
);

// ==================================================
// Client-side pagination (Admin tables only)
// ==================================================
// Each admin list table gets a footer with a record count, a rows-per-page
// select and prev/next controls. Rows are shown/hidden in the DOM; no API or
// query changes. Call refreshPager(tbodyId) at the end of the table's render
// function - the pager wires itself up on first use from the conventional
// ids (<name>Pager / <name>Count) and resets to page 1 on every re-render.

const pagers = new Map();

function pagerRows(tbodyId) {
  const tbody = document.getElementById(tbodyId);
  return tbody ? Array.from(tbody.rows) : [];
}

function pagerIsEmpty(rows) {
  return (
    rows.length === 0 ||
    (rows.length === 1 &&
      rows[0].cells.length === 1 &&
      rows[0].querySelector("td[colspan]"))
  );
}

export function initPager(tbodyId, pagerId, countId) {
  const pagerEl = document.getElementById(pagerId);
  if (!pagerEl || pagers.has(tbodyId)) return pagers.get(tbodyId) || null;

  const sizeSelect = pagerEl.querySelector("[data-page-size]");
  const state = {
    tbodyId,
    pagerEl,
    countEl: document.getElementById(countId),
    infoEl: pagerEl.querySelector("[data-page-info]"),
    prevBtn: pagerEl.querySelector("[data-page-prev]"),
    nextBtn: pagerEl.querySelector("[data-page-next]"),
    page: 1,
    size: Number(sizeSelect?.value) || 15,
  };

  sizeSelect?.addEventListener("change", () => {
    state.size = Number(sizeSelect.value) || 15;
    state.page = 1;
    applyPager(state);
  });
  state.prevBtn?.addEventListener("click", () => {
    if (state.page > 1) {
      state.page -= 1;
      applyPager(state);
    }
  });
  state.nextBtn?.addEventListener("click", () => {
    if (state.page < pagerMaxPage(state)) {
      state.page += 1;
      applyPager(state);
    }
  });

  pagers.set(tbodyId, state);
  applyPager(state);
  return state;
}

function pagerMaxPage(state) {
  const rows = pagerRows(state.tbodyId);
  const total = pagerIsEmpty(rows) ? 0 : rows.length;
  return Math.max(1, Math.ceil(total / state.size));
}

function applyPager(state) {
  const rows = pagerRows(state.tbodyId);
  const empty = pagerIsEmpty(rows);
  const total = empty ? 0 : rows.length;
  const maxPage = Math.max(1, Math.ceil(total / state.size));
  if (state.page > maxPage) state.page = maxPage;

  const start = (state.page - 1) * state.size;
  rows.forEach((row, i) => {
    row.style.display =
      empty || (i >= start && i < start + state.size) ? "" : "none";
  });

  if (state.countEl) {
    state.countEl.textContent =
      total === 0
        ? "No records"
        : `Showing ${start + 1}\u2013${Math.min(start + state.size, total)} of ${total}`;
  }
  if (state.infoEl)
    state.infoEl.textContent = `Page ${state.page} of ${maxPage}`;
  if (state.prevBtn) state.prevBtn.disabled = state.page <= 1;
  if (state.nextBtn) state.nextBtn.disabled = state.page >= maxPage;
}

export function refreshPager(tbodyId) {
  let state = pagers.get(tbodyId);
  if (!state) {
    const base = tbodyId.replace(/TableBody$/, "");
    state = initPager(tbodyId, `${base}Pager`, `${base}Count`);
    if (!state) return;
  }
  state.page = 1;
  applyPager(state);
}
