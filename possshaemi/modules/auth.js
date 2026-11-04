import { baseApiUrl } from "./utils.js";

export function redirectByRole(role) {
    if (role === "Administrator") {
        window.location.href = "index.html";
    } else if (role === "Manager") {
        window.location.href = "manager.html";
    } else if (role === "Cashier") {
        window.location.href = "cashier.html";
    } else {
        window.location.href = "login.html";
    }
}

/**
 * Every protected page calls this first.
 * Returns the session (role, display_name, employee_id...) when the person is
 * signed in with an allowed role; otherwise redirects and returns null.
 * The real security check is still on the server (session-guard.php).
 */
export async function checkAuth(allowedRoles) {
    try {
        const response = await axios.get(`${baseApiUrl}/auth.php`, {
            params: { operation: "checkSession" },
            withCredentials: true
        });

        const session = response.data;

        if (session.success && allowedRoles.includes(session.role)) {
            return session;
        }

        redirectByRole(session.role);
        return null;
    } catch (error) {
        window.location.href = "login.html";
        return null;
    }
}

export async function logout() {
    const formData = new FormData();
    formData.append("operation", "logout");

    try {
        await axios({
            url: `${baseApiUrl}/auth.php`,
            method: "POST",
            data: formData,
            withCredentials: true,
            silent: true
        });
    } catch (error) {
        // Even if the request fails, leave the page - the server session expires on its own.
    }

    window.location.href = "login.html";
}
