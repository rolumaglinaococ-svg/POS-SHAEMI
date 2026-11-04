import { baseApiUrl, showToast, esc, formatPeso } from "./utils.js";

// End-Day Report (Admin). Data comes from api/end-day.php; finalizing and
// reopening are authorized by typed Supervisor/Admin credentials.

let currentReport = null;
let reopenDate = null;

export function initEndDay() {
    const dateInput = document.getElementById("endDayDate");
    if (!dateInput.value) {
        dateInput.value = new Date().toISOString().slice(0, 10);
    }
    dateInput.addEventListener("change", displayEndDay);
    document.getElementById("btnEndDayRefresh").addEventListener("click", displayEndDay);
    document.getElementById("btnEndDayFinalize").addEventListener("click", handleFinalize);
    document.getElementById("btnEndDayPrint").addEventListener("click", printEndDay);
    document.getElementById("btnEndDayReopenConfirm").addEventListener("click", handleReopenConfirm);
    displayEndDay();
}

async function apiGet(operation, params) {
    const response = await axios.get(`${baseApiUrl}/end-day.php`, {
        params: Object.assign({ operation }, params || {})
    });
    return response.data;
}

async function apiPost(operation, payload) {
    const formData = new FormData();
    formData.append("operation", operation);
    formData.append("json", JSON.stringify(payload || {}));
    const response = await axios({ url: `${baseApiUrl}/end-day.php`, method: "POST", data: formData });
    return response.data;
}

export async function displayEndDay() {
    const date = document.getElementById("endDayDate").value;
    if (!date) return;
    try {
        const data = await apiGet("getEndDayReport", { date });
        if (!data.success) {
            showToast(data.message || "Unable to load the End-Day report.", "danger");
            return;
        }
        currentReport = data;
        renderReport(data);
    } catch (error) {
        showToast("Unable to reach the server.", "danger");
    }
    loadHistory();
}

function pesoOrDash(value) {
    return value === null || value === undefined ? "—" : formatPeso(value);
}

function signedPeso(value) {
    if (value === null || value === undefined) return "—";
    if (Math.abs(value) <= 0.004) return formatPeso(0);
    return (value < 0 ? "−" : "+") + formatPeso(Math.abs(value));
}

function renderReport(r) {
    const chip = document.getElementById("endDayStatusChip");
    if (r.end_day && r.end_day.status === "Finalized") {
        chip.innerHTML = `<span class="badge text-bg-primary">Finalized</span>`;
    } else if (r.checks.can_finalize) {
        chip.innerHTML = `<span class="badge text-bg-success">Ready to finalize</span>`;
    } else if (!r.checks.has_shifts) {
        chip.innerHTML = `<span class="badge text-bg-secondary">No shifts</span>`;
    } else {
        chip.innerHTML = `<span class="badge text-bg-warning">Not ready</span>`;
    }

    const c = r.checks;
    const checkLine = (ok, label, detail) =>
        `<div><span class="${ok ? "text-success" : "text-danger"} fw-bold">${ok ? "✓" : "✗"}</span> ${label} — <strong>${detail}</strong></div>`;
    document.getElementById("endDayChecks").innerHTML = `
        ${checkLine(c.all_closed, "All shifts closed", `${r.shifts.length - c.open_count} of ${r.shifts.length}`)}
        ${checkLine(c.all_authorized, "All shifts authorized (Supervisor/Admin)", c.all_authorized ? "complete" : "missing")}
        ${checkLine(c.all_deposits_verified, "All deposits verified", c.all_deposits_verified ? "complete" : "missing")}
        <div><span class="text-warning fw-bold">!</span> Discrepancies documented — <strong>${c.discrepancy_count}</strong> <span class="text-muted">(do not block finalize)</span></div>`;

    const un = document.getElementById("endDayUnattributed");
    if (r.unattributed && r.unattributed.count > 0) {
        un.textContent = `Warning: ${r.unattributed.count} completed sale(s) totalling ${formatPeso(r.unattributed.total)} fall outside every shift window for this date. This does not block finalizing.`;
        un.classList.remove("d-none");
    } else {
        un.classList.add("d-none");
    }

    const t = r.totals;
    const kpi = (label, value, note, cls) => `
        <div class="col-md-3"><div class="card h-100"><div class="card-body">
            <div class="small text-muted text-uppercase">${label}</div>
            <div class="fs-4 fw-semibold ${cls || ""}">${value}</div>
            <div class="small text-muted">${note}</div>
        </div></div></div>`;
    document.getElementById("endDayKpis").innerHTML = `
        ${kpi("Net sales", formatPeso(t.net), `${t.transactions} transactions · ${r.shifts.length} shifts`)}
        ${kpi("Cash expected", formatPeso(t.expected_cash), `Opening ${formatPeso(t.opening)} · lifts ${formatPeso(t.cash_lifts)}`)}
        ${kpi("Cash counted", formatPeso(t.actual_cash), "Per closed shift reports")}
        ${kpi("Total variance", signedPeso(t.cash_difference), `Deposits verified ${formatPeso(t.deposit_verified)} · diff ${signedPeso(t.deposit_difference)}`, t.cash_difference < -0.004 ? "text-danger" : t.cash_difference > 0.004 ? "text-warning" : "")}`;

    const tbody = document.getElementById("endDayShiftBody");
    if (!r.shifts.length) {
        tbody.innerHTML = `<tr><td colspan="11" class="text-center text-muted py-4">No shifts recorded for this date.</td></tr>`;
    } else {
        tbody.innerHTML = r.shifts.map((s) => `
            <tr>
                <td>#${s.shift_id}</td>
                <td>${esc(s.cashier_name)}</td>
                <td>${esc(s.counter_name)} #${s.counter_number}</td>
                <td class="small">${esc((s.opened_at || "").slice(11, 16))}–${s.closed_at ? esc(s.closed_at.slice(11, 16)) : "…"}</td>
                <td class="num">${formatPeso(s.opening_cash)}</td>
                <td class="num">${formatPeso(s.expected_cash)}</td>
                <td class="num">${pesoOrDash(s.actual_cash)}</td>
                <td class="num ${s.cash_difference < -0.004 ? "text-danger fw-semibold" : s.cash_difference > 0.004 ? "text-warning fw-semibold" : ""}">${signedPeso(s.cash_difference)}</td>
                <td class="small">${s.authorized_by ? `${esc(s.authorized_by.name)} (${esc(s.authorized_by.role)})` : "—"}</td>
                <td class="small">${s.deposit.bag_no
                    ? `Bag ${esc(s.deposit.bag_no)} · ${pesoOrDash(s.deposit.amount)}${s.deposit.verified_by ? " ✓ verified" : ""}${Math.abs(s.deposit.difference || 0) > 0.004 ? ` · <span class="text-danger">diff ${signedPeso(s.deposit.difference)}</span>` : ""}`
                    : "—"}</td>
                <td><span class="badge ${s.status === "Closed" ? "text-bg-secondary" : "text-bg-danger"}">${esc(s.status)}</span></td>
            </tr>`).join("") + `
            <tr class="fw-semibold">
                <td colspan="4">Day total — ${r.shifts.length} shifts</td>
                <td class="num">${formatPeso(t.opening)}</td>
                <td class="num">${formatPeso(t.expected_cash)}</td>
                <td class="num">${formatPeso(t.actual_cash)}</td>
                <td class="num">${signedPeso(t.cash_difference)}</td>
                <td colspan="3" class="small">Deposits sealed ${formatPeso(t.deposit_amount)} · verified ${formatPeso(t.deposit_verified)} · diff ${signedPeso(t.deposit_difference)}</td>
            </tr>`;
    }

    const payRows = r.payments.map((p) => `
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">${esc(p.type_name)}</span><strong>${formatPeso(p.total)}</strong>
        </div>`).join("");
    document.getElementById("endDayTotals").innerHTML = `
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Gross sales</span><strong>${formatPeso(t.gross)}</strong></div>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Discounts</span><strong>${formatPeso(t.discount)}</strong></div>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Net sales</span><strong>${formatPeso(t.net)}</strong></div>
        ${payRows}
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">AR credit sales</span><strong>${formatPeso(t.ar_total)}</strong></div>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Voided</span><strong>${t.voided_count} · ${formatPeso(t.voided_total)}</strong></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Cash lifts</span><strong>${formatPeso(t.cash_lifts)}</strong></div>`;

    const disc = document.getElementById("endDayDiscrepancies");
    if (!r.discrepancies.length) {
        disc.innerHTML = `<div class="text-muted py-2">None.</div>`;
    } else {
        disc.innerHTML = r.discrepancies.map((d) => `
            <div class="py-2 border-bottom">
                <span class="${d.amount < 0 ? "text-danger" : "text-warning"} fw-semibold">${esc(d.type)} ${signedPeso(d.amount)}</span>
                — Shift #${d.shift_id}, ${esc(d.cashier_name)}, ${esc(d.counter_name)}${d.bag_no ? ` · Bag ${esc(d.bag_no)}` : ""}
                ${d.notes ? `<div class="small text-muted">Note: ${esc(d.notes)}</div>` : ""}
            </div>`).join("");
    }

    // Finalize panel state
    const btn = document.getElementById("btnEndDayFinalize");
    const note = document.getElementById("endDayFinalizeNote");
    document.getElementById("endDayActionError").textContent = "";
    if (r.end_day && r.end_day.status === "Finalized") {
        btn.disabled = true;
        note.textContent = `Finalized by ${r.end_day.finalized_by ? esc(r.end_day.finalized_by.name) + " (" + esc(r.end_day.finalized_by.role) + ")" : "—"} · ${esc(r.end_day.finalized_at || "")} · finalize #${r.end_day.finalize_count}`;
    } else if (!c.has_shifts) {
        btn.disabled = true;
        note.textContent = "No shifts recorded for this date.";
    } else if (!c.can_finalize) {
        btn.disabled = true;
        note.textContent = !c.all_closed ? "All shifts must be closed first."
            : !c.all_authorized ? "A closed shift is missing authorization."
            : "A closed shift is missing a verified deposit.";
    } else {
        btn.disabled = false;
        note.textContent = "Accepts a Supervisor or Administrator account. Discrepancies stay documented.";
    }

    const events = document.getElementById("endDayEvents");
    if (r.end_day && r.end_day.events && r.end_day.events.length) {
        events.innerHTML = r.end_day.events.map((ev) =>
            `<div>${esc(ev.event_type)} by ${esc(ev.actor.name)} (${esc(ev.actor.role)}) · ${esc(ev.created_at)}${ev.reason ? ` — “${esc(ev.reason)}”` : ""}</div>`
        ).join("");
    } else {
        events.innerHTML = "";
    }
}

async function loadHistory() {
    try {
        const data = await apiGet("getEndDayHistory", { limit: 30 });
        const tbody = document.getElementById("endDayHistoryBody");
        if (!data.success || !data.history.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">No End-Day records yet.</td></tr>`;
            return;
        }
        tbody.innerHTML = "";
        data.history.forEach((h) => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${esc(h.business_date)}</td>
                <td><span class="badge ${h.status === "Finalized" ? "text-bg-primary" : "text-bg-warning"}">${esc(h.status)}</span></td>
                <td class="num">${h.shifts_count}</td>
                <td class="num">${formatPeso(h.net_sales)}</td>
                <td class="num">${signedPeso(h.total_cash_difference)}</td>
                <td class="small">${h.finalized_by ? `${esc(h.finalized_by.name)} (${esc(h.finalized_by.role)})` : "—"}${h.finalized_at ? ` · ${esc(h.finalized_at)}` : ""}</td>
                <td class="text-end">${h.status === "Finalized" ? `<button class="btn btn-sm btn-outline-danger btn-reopen">Reopen…</button>` : ""}</td>`;
            tr.querySelector(".btn-reopen")?.addEventListener("click", () => openReopenModal(h.business_date));
            tbody.appendChild(tr);
        });
    } catch (error) {
        // History is secondary; the main report already reported any failure.
    }
}

async function handleFinalize() {
    const date = document.getElementById("endDayDate").value;
    const user = document.getElementById("endDayAuthUser").value.trim();
    const pass = document.getElementById("endDayAuthPass").value;
    const errBox = document.getElementById("endDayActionError");
    errBox.textContent = "";
    if (!user || !pass) {
        errBox.textContent = "Enter the Supervisor / Admin credentials.";
        return;
    }
    const btn = document.getElementById("btnEndDayFinalize");
    btn.disabled = true;
    try {
        const data = await apiPost("finalizeEndDay", { date, auth_user: user, auth_pass: pass });
        if (!data.success) {
            errBox.textContent = data.message || "Unable to finalize.";
            btn.disabled = false;
            return;
        }
        document.getElementById("endDayAuthPass").value = "";
        showToast(data.message || "End-Day finalized.", "success");
        currentReport = data;
        renderReport(data);
        loadHistory();
    } catch (error) {
        errBox.textContent = "Unable to reach the server. Please try again.";
        btn.disabled = false;
    }
}

function openReopenModal(date) {
    reopenDate = date;
    document.getElementById("endDayReopenDate").textContent = date;
    document.getElementById("endDayReopenUser").value = "";
    document.getElementById("endDayReopenPass").value = "";
    document.getElementById("endDayReopenReason").value = "";
    document.getElementById("endDayReopenError").textContent = "";
    bootstrap.Modal.getOrCreateInstance(document.getElementById("endDayReopenModal")).show();
}

async function handleReopenConfirm() {
    const errBox = document.getElementById("endDayReopenError");
    errBox.textContent = "";
    const user = document.getElementById("endDayReopenUser").value.trim();
    const pass = document.getElementById("endDayReopenPass").value;
    const reason = document.getElementById("endDayReopenReason").value.trim();
    if (!user || !pass) {
        errBox.textContent = "Enter the Supervisor / Admin credentials.";
        return;
    }
    if (!reason) {
        errBox.textContent = "A reason is required.";
        return;
    }
    try {
        const data = await apiPost("reopenEndDay", { date: reopenDate, auth_user: user, auth_pass: pass, reason });
        if (!data.success) {
            errBox.textContent = data.message || "Unable to reopen.";
            return;
        }
        bootstrap.Modal.getInstance(document.getElementById("endDayReopenModal"))?.hide();
        showToast(data.message || "End-Day reopened.", "success");
        if (document.getElementById("endDayDate").value === reopenDate) {
            currentReport = data;
            renderReport(data);
        }
        loadHistory();
    } catch (error) {
        errBox.textContent = "Unable to reach the server. Please try again.";
    }
}

function printEndDay() {
    const r = currentReport;
    if (!r) return;
    const t = r.totals;
    const fin = r.end_day && r.end_day.status === "Finalized"
        ? `Status: FINALIZED · Finalized by ${r.end_day.finalized_by ? esc(r.end_day.finalized_by.name) + " (" + esc(r.end_day.finalized_by.role) + ")" : "—"}, ${esc(r.end_day.finalized_at || "")}`
        : "Status: NOT FINALIZED";
    const shiftRows = r.shifts.map((s) => `
        <tr>
            <td>#${s.shift_id}</td><td>${esc(s.cashier_name)}</td><td>${esc(s.counter_name)} #${s.counter_number}</td>
            <td style="text-align:right">${formatPeso(s.expected_cash)}</td>
            <td style="text-align:right">${pesoOrDash(s.actual_cash)}</td>
            <td style="text-align:right">${signedPeso(s.cash_difference)}</td>
            <td>${s.deposit.bag_no ? `Bag ${esc(s.deposit.bag_no)}${Math.abs(s.deposit.difference || 0) > 0.004 ? ` · diff ${signedPeso(s.deposit.difference)}` : " ✓"}` : "—"}</td>
        </tr>`).join("");
    const discText = r.discrepancies.length
        ? r.discrepancies.map((d) => `${esc(d.type)} ${signedPeso(d.amount)} — Shift #${d.shift_id}, ${esc(d.cashier_name)}${d.notes ? ` (${esc(d.notes)})` : ""}`).join(" · ")
        : "None.";
    document.getElementById("endDayPrintArea").innerHTML = `
        <div style="font-family:'Segoe UI',sans-serif;color:#16191d;">
            <h2 style="font-size:18px;">End-Day Report — ${esc(r.date)}</h2>
            <div style="font-size:12px;color:#5b636c;">Ororama Grocery Store · ${fin}</div>
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-top:12px;">
                <thead><tr style="border-bottom:2px solid #999;text-align:left;">
                    <th>Shift</th><th>Cashier</th><th>Counter</th><th style="text-align:right">Expected</th><th style="text-align:right">Counted</th><th style="text-align:right">Variance</th><th>Deposit</th>
                </tr></thead>
                <tbody>
                    ${shiftRows}
                    <tr style="border-top:2px solid #999;font-weight:600;">
                        <td colspan="3">Day total — ${r.shifts.length} shifts</td>
                        <td style="text-align:right">${formatPeso(t.expected_cash)}</td>
                        <td style="text-align:right">${formatPeso(t.actual_cash)}</td>
                        <td style="text-align:right">${signedPeso(t.cash_difference)}</td>
                        <td>Verified ${formatPeso(t.deposit_verified)}</td>
                    </tr>
                </tbody>
            </table>
            <div style="font-size:12.5px;margin-top:12px;">
                Net sales ${formatPeso(t.net)} · ${r.payments.map((p) => `${esc(p.type_name)} ${formatPeso(p.total)}`).join(" · ")} · AR credit ${formatPeso(t.ar_total)} · Voided ${t.voided_count} · ${formatPeso(t.voided_total)}
            </div>
            <div style="font-size:12.5px;margin-top:10px;">
                <strong>Discrepancies — documented, not resolved:</strong> ${discText}
                Finalizing this report does not resolve these items.
            </div>
            <div class="endday-sig-row">
                <div class="endday-sig-box">Finalized by (Supervisor/Admin)</div>
                <div class="endday-sig-box">Checked by</div>
                <div class="endday-sig-box">Date / Time</div>
            </div>
        </div>`;
    document.body.classList.add("printing-endday");
    const cleanup = () => {
        document.body.classList.remove("printing-endday");
        window.removeEventListener("afterprint", cleanup);
    };
    window.addEventListener("afterprint", cleanup);
    setTimeout(cleanup, 1000);
    window.print();
}
