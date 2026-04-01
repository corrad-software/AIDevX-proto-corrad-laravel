import { API_BASE_URL } from "@/env";

function getCsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : "";
}

function buildHeaders(init?: HeadersInit) {
  const headers = new Headers(init ?? {});
  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }
  if (!headers.has("Content-Type") && !(init instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }
  const token = getCsrfToken();
  if (token) {
    headers.set("X-XSRF-TOKEN", token);
  }
  return headers;
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && getCsrfToken()) {
    return;
  }
  await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
    credentials: "include",
    cache: "no-store",
  });
  // Let the browser apply Set-Cookie before we read XSRF-TOKEN for the next request.
  await new Promise((r) => setTimeout(r, 0));
  if (!getCsrfToken()) {
    await new Promise((r) => setTimeout(r, 50));
  }
}

export async function apiRequest<T>(path: string, options: RequestInit & { timeoutMs?: number } = {}): Promise<T> {
  const { timeoutMs, ...fetchOptions } = options;
  const isForm = fetchOptions.body instanceof FormData;
  const headers = isForm ? new Headers(fetchOptions.headers) : buildHeaders(fetchOptions.headers);

  // Always include CSRF token, even for FormData uploads
  if (isForm) {
    const token = getCsrfToken();
    if (token) {
      headers.set("X-XSRF-TOKEN", token);
    }
  }

  const controller = new AbortController();
  const timeout = timeoutMs ?? (path.includes("/chat/") ? 120_000 : 30_000);
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...fetchOptions,
      credentials: "include",
      headers,
      signal: controller.signal,
    });
  } catch (err: any) {
    clearTimeout(timeoutId);
    if (err.name === "AbortError") {
      throw new Error("Permintaan tamat masa. AI masih memproses — sila cuba semula.");
    }
    throw err;
  }
  clearTimeout(timeoutId);

  const raw = await response.text();
  const trimmed = raw.trim();

  let payload: unknown;
  if (!trimmed) {
    if (response.ok && response.status === 204) {
      payload = {};
    } else if (response.ok) {
      throw new Error(
        "Server returned empty body (expected JSON). Pastikan Laravel is running and API URL is correct (e.g. composer dev: API on :8090, Vite proxy /api).",
      );
    } else {
      throw new Error(`Request failed (${response.status}). Sila semak sambungan dan cuba semula.`);
    }
  } else {
    const head = trimmed.slice(0, 200).toLowerCase();
    if (head.startsWith("<!doctype") || head.startsWith("<html")) {
      throw new Error(
        "Server returned HTML instead of JSON. Check: (1) Laravel is running (2) Vite dev proxies /api to backend (3) VITE_API_BASE_URL in production.",
      );
    }
    try {
      payload = JSON.parse(trimmed) as unknown;
    } catch {
      throw new Error(
        response.ok
          ? "Invalid response from server (not valid JSON)."
          : `Request failed (${response.status}). Sila semak sambungan dan cuba semula.`,
      );
    }
  }
  if (!response.ok) {
    const err = payload as {
      error?: { message?: string; details?: Record<string, string[] | string> };
    };
    let msg = err?.error?.message || "Request failed";
    if (
      response.status === 422 &&
      err?.error?.details &&
      typeof err.error.details === "object" &&
      !Array.isArray(err.error.details)
    ) {
      const entries = Object.entries(err.error.details);
      const first = entries[0];
      if (first) {
        const [k, v] = first;
        const vv = Array.isArray(v) ? v[0] : String(v);
        msg = `${msg} (${k}: ${vv})`;
      }
    }
    throw new Error(msg);
  }

  return payload as T;
}
