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

export async function ensureCsrfCookie(): Promise<void> {
  if (!getCsrfToken()) {
    await fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
      credentials: "include",
    });
  }
}

export async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const isForm = options.body instanceof FormData;
  const headers = isForm ? new Headers(options.headers) : buildHeaders(options.headers);

  // Always include CSRF token, even for FormData uploads
  if (isForm) {
    const token = getCsrfToken();
    if (token) {
      headers.set("X-XSRF-TOKEN", token);
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    credentials: "include",
    headers,
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload?.error?.message || "Request failed");
  }

  return payload;
}
