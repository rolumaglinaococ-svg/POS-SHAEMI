// arApi.js - helpers shared by the AR Manager modules (API calls, date ranges, tables, modals).
import { baseApiUrl, esc, formatPeso, formatDateTime, todayString } from "./utils.js";

export { esc, formatPeso, formatDateTime, todayString };

export async function arGet(file, operation, params = {}) {
    const response = await axios.get(`${baseApiUrl}/${file}`, { params: { operation, ...params }, withCredentials: true });
    return response.data;
}

export async function arPost(file, operation, payload) {
    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(payload));
    const response = await axios({ url: `${baseApiUrl}/${file}`, method: "POST", data: formData, withCredentials: true });
    return response.data;
}

function fmt(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** Today / This Week (Mon-Sun) / This Month. "custom" is handled by the date inputs. */
export function periodRange(period) {
    const now = new Date();
    if (period === "week") {
        const start = new Date(now); start.setDate(now.getDate() - ((now.getDay() + 6) % 7));
        const end = new Date(start); end.setDate(start.getDate() + 6);
        return [fmt(start), fmt(end)];
    }
    if (period === "month") {
        return [fmt(new Date(now.getFullYear(), now.getMonth(), 1)), fmt(new Date(now.getFullYear(), now.getMonth() + 1, 0))];
    }
    const t = fmt(now);
    return [t, t];
}

export function periodControlsHtml() {
    return `<span class="ar-period"><select class="form-select" data-period style="max-width:170px">
            <option value="today">Today</option><option value="week">This Week</option>
            <option value="month" selected>This Month</option><option value="custom">Custom Range</option></select>
        <input type="date" class="form-control" data-from style="max-width:170px"><input type="date" class="form-control" data-to style="max-width:170px"></span>`;
}

/** Wires Today/Week/Month/Custom controls inside `root`; calls onChange([from,to]). Returns a getter. */
export function wirePeriod(root, onChange) {
    const sel = root.querySelector("[data-period]"), from = root.querySelector("[data-from]"), to = root.querySelector("[data-to]");
    const apply = () => {
        if (sel.value !== "custom") [from.value, to.value] = periodRange(sel.value);
        if (from.value && to.value && from.value <= to.value) onChange([from.value, to.value]);
    };
    sel.addEventListener("change", apply);
    from.addEventListener("change", () => { sel.value = "custom"; apply(); });
    to.addEventListener("change", () => { sel.value = "custom"; apply(); });
    [from.value, to.value] = periodRange(sel.value);
    return () => [from.value, to.value];
}

function fmtDate(v) {
    if (!v) return "";
    const d = new Date(String(v).slice(0, 10) + "T00:00:00");
    return isNaN(d) ? esc(v) : d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "2-digit" });
}

const BADGE = { Posted: "text-bg-success", Adjusted: "text-bg-warning", Reversed: "text-bg-danger", Good: "text-bg-success", Watch: "text-bg-warning", Hold: "text-bg-danger" };
export function badge(text) { return `<span class="badge ${BADGE[text] || "text-bg-secondary"}">${esc(text)}</span>`; }

export function overdueBadge(days) {
    const d = Number(days);
    if (!isFinite(d) || d <= 0) return `<span class="badge text-bg-success">Current</span>`;
    return `<span class="badge ${d > 60 ? "text-bg-danger" : d > 30 ? "text-bg-warning" : "text-bg-info"}">${d} day${d === 1 ? "" : "s"} overdue</span>`;
}

/** columns: [{key,label,type:"text|money|int|date|datetime|html", render?(row)}]. Rows are plain objects. */
export function tableHtml(columns, rows, emptyText = "No records found.") {
    if (!rows || rows.length === 0) return `<div class="text-center text-muted py-4">${esc(emptyText)}</div>`;
    const cell = (c, r) => {
        if (c.render) return c.render(r);
        const v = r[c.key];
        if (c.type === "money") return formatPeso(v);
        if (c.type === "date") return fmtDate(v);
        if (c.type === "datetime") return formatDateTime(v);
        return esc(v ?? "");
    };
    const cls = (c) => (c.type === "money" || c.type === "int" ? "text-end" : "");
    return `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr>${columns.map((c) => `<th class="${cls(c)}">${esc(c.label)}</th>`).join("")}</tr></thead>
        <tbody>${rows.map((r) => `<tr class="${r._rowClass || ""}">${columns.map((c) => `<td class="${cls(c)}">${cell(c, r)}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`;
}

// ---------- modal ----------
export function openModal({ title, body, footer = "", size = "" }) {
    const el = document.createElement("div");
    el.className = "modal fade";
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog ${size}"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">${esc(title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">${body}</div>${footer ? `<div class="modal-footer">${footer}</div>` : ""}</div></div>`;
    document.body.appendChild(el);
    const modal = new bootstrap.Modal(el);
    el.addEventListener("hidden.bs.modal", () => { modal.dispose(); el.remove(); });
    modal.show();
    return { el, modal };
}

export const cents = (v) => Math.round((parseFloat(v) || 0) * 100);
