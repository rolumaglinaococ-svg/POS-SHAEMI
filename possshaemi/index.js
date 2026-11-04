import { baseApiUrl, formatPeso } from "./modules/utils.js";
import { checkAuth, logout } from "./modules/auth.js";
import { initActivityFeed, initActivityPage, displayActivityLog, initAuditPage, displayAuditLogs, markActivitySeen, loadRecentActivity } from "./modules/activityFeed.js";
import { initRoles } from "./modules/roles.js";
import { initPriceTiers } from "./modules/priceTiers.js";
import { initCreditTerms } from "./modules/creditTerms.js";
import { initCategories } from "./modules/categories.js";
import { initUnits } from "./modules/units.js";
import { initProducts } from "./modules/products.js";
import { initProductPrices } from "./modules/productPrices.js";
import { initDiscounts } from "./modules/discounts.js";
import { initPaymentTypes } from "./modules/paymentTypes.js";
import { initCounters } from "./modules/counters.js";
import { initCustomers } from "./modules/customers.js";
import { initSukiCards } from "./modules/sukiCards.js";
import { initGcCodes, displayGcCodes } from "./modules/gcCodes.js";
import { initEmployees } from "./modules/employees.js";
import { initArAccounts, displayArAccounts } from "./modules/arAccounts.js";
import { initSales, displaySales } from "./modules/sales.js";
import { initReports } from "./modules/reports.js";
import { initEndDay, displayEndDay } from "./modules/endDay.js";

// Section name -> page title shown in the topbar
const sectionTitles = {
    dashboard: "Dashboard",
    products: "Products",
    categories: "Product Categories",
    units: "Units",
    productPrices: "Product Prices",
    customers: "Customers",
    arAccounts: "AR Accounts",
    sukiCards: "Suki Cards",
    gcCodes: "GC Codes",
    employees: "Employees",
    roles: "Roles",
    priceTiers: "Price Tiers",
    creditTerms: "Credit Terms",
    discounts: "Discounts",
    paymentTypes: "Payment Types",
    counters: "Counters",
    sales: "Sales",
    reports: "Reports",
    endDay: "End-Day Report",
    activity: "Audit Logs"
};

// Each section is initialized once, the first time it's opened,
// so we don't fire unnecessary API calls for tabs the user never visits.
const sectionInitializers = {
    products: initProducts,
    categories: initCategories,
    units: initUnits,
    productPrices: initProductPrices,
    customers: initCustomers,
    arAccounts: initArAccounts,
    sukiCards: initSukiCards,
    gcCodes: initGcCodes,
    employees: initEmployees,
    roles: initRoles,
    priceTiers: initPriceTiers,
    creditTerms: initCreditTerms,
    discounts: initDiscounts,
    paymentTypes: initPaymentTypes,
    counters: initCounters,
    sales: initSales,
    reports: () => initReports("administratorReports", { administrator: true }),
    endDay: initEndDay,
    activity: initAuditPage
};

// These sections show data that OTHER roles change (Cashier sales, Manager approvals),
// so they reload every time they are opened again and whenever the live feed reports news.
const sectionRefreshers = {
    arAccounts: displayArAccounts,
    gcCodes: displayGcCodes,
    sales: displaySales,
    endDay: displayEndDay,
    activity: displayAuditLogs
};

const initializedSections = new Set();
let currentSection = "dashboard";

document.addEventListener("DOMContentLoaded", async () => {
    const session = await checkAuth(["Administrator"]);
    if (!session) return;

    setupSidebarNavigation();
    setupSidebarToggle();
    setupGotoLinks();
    document.getElementById("logoutBtn").addEventListener("click", logout);

    document.getElementById("topbarUserName").textContent = session.display_name || "Administrator";
    document.getElementById("topbarUserRole").textContent = session.role || "";

    loadDashboardCounts();
    loadRecentActivity("dashboardActivity");

    initActivityFeed({ employeeId: session.employee_id, onNew: handleNewActivity });
});

function handleNewActivity() {
    if (currentSection === "dashboard") {
        loadDashboardCounts();
        loadRecentActivity("dashboardActivity");
        return;
    }

    if (initializedSections.has(currentSection) && sectionRefreshers[currentSection]) {
        sectionRefreshers[currentSection]();
    }
}

function setupSidebarNavigation() {
    const navLinks = document.querySelectorAll(".sidebar-nav .nav-link");

    navLinks.forEach((link) => {
        link.addEventListener("click", (e) => {
            e.preventDefault();

            const sectionName = link.dataset.section;
            showSection(sectionName);

            navLinks.forEach((l) => l.classList.remove("active"));
            link.classList.add("active");

            // Collapse sidebar on mobile after choosing a section
            document.getElementById("sidebar").classList.remove("show");
            document.getElementById("sidebarBackdrop")?.classList.remove("show");
        });
    });
}

function showSection(sectionName) {
    currentSection = sectionName;

    document.querySelectorAll(".content-section").forEach((section) => section.classList.add("d-none"));

    const target = document.getElementById(`section-${sectionName}`);
    if (target) target.classList.remove("d-none");

    document.getElementById("pageTitle").textContent = sectionTitles[sectionName] ?? sectionName;

    if (sectionName === "activity") {
        markActivitySeen();
    }

    if (sectionName === "dashboard") {
        loadDashboardCounts();
        loadRecentActivity("dashboardActivity");
        return;
    }

    if (!initializedSections.has(sectionName) && sectionInitializers[sectionName]) {
        sectionInitializers[sectionName]();
        initializedSections.add(sectionName);
    } else if (sectionRefreshers[sectionName]) {
        sectionRefreshers[sectionName]();
    }
}

function setupSidebarToggle() {
    const toggleBtn = document.getElementById("sidebarToggle");
    const sidebar = document.getElementById("sidebar");
    const backdrop = document.getElementById("sidebarBackdrop");
    toggleBtn?.addEventListener("click", () => {
        const open = sidebar.classList.toggle("show");
        backdrop?.classList.toggle("show", open);
    });
    backdrop?.addEventListener("click", () => {
        sidebar.classList.remove("show");
        backdrop.classList.remove("show");
    });
}

// In-page shortcuts (e.g. dashboard "Open AR Accounts") reuse the sidebar nav.
function setupGotoLinks() {
    document.addEventListener("click", (e) => {
        const target = e.target.closest("[data-goto]");
        if (!target) return;
        e.preventDefault();
        document.querySelector(`.sidebar-nav .nav-link[data-section="${target.dataset.goto}"]`)?.click();
    });
}

async function loadDashboardCounts() {
    const params = { operation: "getCounts" };
    const response = await axios.get(`${baseApiUrl}/dashboard.php`, { params });
    renderDashboard(response.data);
}

/**
 * Operational overview: today's KPIs, items needing attention, store data,
 * and the recent activity feed. Replaces the old 17-card stat grid.
 */
function renderDashboard(counts) {
    const c = counts || {};
    const int = (v) => Number(v ?? 0).toLocaleString("en-PH");

    const kpis = [
        { label: "Sales today", value: formatPeso(c.sales_today_total), sub: `${int(c.sales_today)} transactions` },
        { label: "Pending AR approval", value: int(c.pending_ar), sub: "accounts awaiting review", alert: Number(c.pending_ar) > 0 },
        { label: "Voided sales", value: int(c.voided_sales), sub: "on record" },
        { label: "Active AR accounts", value: int(c.ar_accounts), sub: "open accounts" },
    ];
    document.getElementById("dashboardKpis").innerHTML = kpis
        .map(
            (k) => `
            <div class="kpi">
                <div class="kpi-label">${k.label}</div>
                <div class="kpi-value${k.alert ? " is-alert" : ""}">${k.value}</div>
                <div class="kpi-sub">${k.sub}</div>
            </div>`
        )
        .join("");

    const attention = [];
    if (Number(c.pending_ar) > 0) {
        attention.push({
            text: `<strong>${int(c.pending_ar)}</strong> AR account${Number(c.pending_ar) === 1 ? "" : "s"} waiting for approval`,
            link: "arAccounts",
            linkLabel: "Review",
        });
    } else {
        attention.push({
            text: "No AR accounts waiting for approval",
            link: "arAccounts",
            linkLabel: "Open AR Accounts",
        });
    }
    if (Number(c.voided_sales) > 0) {
        attention.push({
            text: `<strong>${int(c.voided_sales)}</strong> voided sale${Number(c.voided_sales) === 1 ? "" : "s"} on record`,
            link: "sales",
            linkLabel: "View sales",
        });
    }
    document.getElementById("dashboardAttention").innerHTML = attention
        .map(
            (a) => `
            <div class="attention-row">
                <span>${a.text}</span>
                <a href="#" data-goto="${a.link}" class="text-nowrap">${a.linkLabel}</a>
            </div>`
        )
        .join("");

    const stats = [
        ["Products", c.products],
        ["Product prices", c.product_prices],
        ["Customers", c.customers],
        ["Employees", c.employees],
        ["Suki cards", c.suki_cards],
        ["Counters", c.counters],
        ["Price tiers", c.price_tiers],
        ["Payment types", c.payment_types],
    ];
    document.getElementById("dashboardStoreStats").innerHTML = stats
        .map(([label, value]) => `<div><dt>${label}</dt><dd>${int(value)}</dd></div>`)
        .join("");
}
