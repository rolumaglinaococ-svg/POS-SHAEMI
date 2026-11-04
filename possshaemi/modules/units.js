import { baseApiUrl, showToast, confirmDeactivate, confirmRestore, statusBadge, esc, debounce, refreshPager } from "./utils.js";

let status = "active";

export function initUnits() {
  document.getElementById("unitForm").addEventListener("submit", save);
  document.getElementById("unitStatusFilter").addEventListener("change", (e) => { status = e.target.value; displayUnits(); });
  document.getElementById("unitSearch").addEventListener("input", debounce(displayUnits, 300));
  document.getElementById("btnAddUnit").addEventListener("click", () => {
    document.getElementById("unitId").value = "";
    document.getElementById("unitName").value = "";
    document.getElementById("unitCode").value = "";
    document.getElementById("unitModalTitle").textContent = "Add Unit";
  });
  displayUnits();
}

export async function getActiveUnitOptions() {
  const response = await axios.get(`${baseApiUrl}/units.php`, { params: { operation: "getActive" } });
  return response.data;
}

async function displayUnits() {
  const keyword = document.getElementById("unitSearch").value.trim();
  const params = keyword
    ? { operation: "search", keyword, status }
    : { operation: "getAll", status };
  const response = await axios.get(`${baseApiUrl}/units.php`, { params });
  const tbody = document.getElementById("unitTableBody");
  tbody.innerHTML = response.data.length ? response.data.map((unit) => `<tr>
    <td>${esc(unit.unit_name)}</td><td>${esc(unit.unit_code)}</td><td>${statusBadge(unit.is_active)}</td>
    <td class="text-end"><button class="btn btn-sm btn-outline-secondary edit"><i class="bi bi-pencil"></i></button>
    ${unit.is_active == 1 ? `<button class="btn btn-sm btn-outline-danger deactivate"><i class="bi bi-slash-circle"></i></button>` : `<button class="btn btn-sm btn-outline-success restore"><i class="bi bi-arrow-counterclockwise"></i></button>`}</td></tr>`).join("") : `<tr><td colspan="4" class="text-center text-muted py-4">No records found.</td></tr>`;
  [...tbody.querySelectorAll("tr")].forEach((row, index) => {
    const unit = response.data[index]; if (!unit) return;
    row.querySelector(".edit")?.addEventListener("click", () => { document.getElementById("unitId").value = unit.unit_id; document.getElementById("unitName").value = unit.unit_name; document.getElementById("unitCode").value = unit.unit_code; document.getElementById("unitModalTitle").textContent = "Edit Unit"; new bootstrap.Modal(document.getElementById("unitModal")).show(); });
    row.querySelector(".deactivate")?.addEventListener("click", async () => { if (await confirmDeactivate(unit.unit_name)) changeStatus("deactivate", unit.unit_id); });
    row.querySelector(".restore")?.addEventListener("click", async () => { if (await confirmRestore(unit.unit_name)) changeStatus("restore", unit.unit_id); });
  });

    refreshPager("unitTableBody");
}

async function save(e) { e.preventDefault(); const form = new FormData(); form.append("operation", "save"); form.append("json", JSON.stringify({ unit_id: document.getElementById("unitId").value, unit_name: document.getElementById("unitName").value.trim(), unit_code: document.getElementById("unitCode").value.trim() })); const r = await axios({ url: `${baseApiUrl}/units.php`, method: "POST", data: form }); showToast(r.data.message, r.data.success ? "success" : "danger"); if (r.data.success) { bootstrap.Modal.getInstance(document.getElementById("unitModal"))?.hide(); displayUnits(); } }
async function changeStatus(operation, unitId) { const form = new FormData(); form.append("operation", operation); form.append("json", JSON.stringify({ unit_id: unitId })); const r = await axios({ url: `${baseApiUrl}/units.php`, method: "POST", data: form }); showToast(r.data.message, r.data.success ? "success" : "danger"); if (r.data.success) displayUnits(); }
