/** When served from Laravel (same origin), use empty string. When from Vite dev (5190), use origin for proxy. */
export const API_BASE_URL =
  import.meta.env.VITE_SERVE_FROM_LARAVEL === "1"
    ? ""
    : import.meta.env.DEV
      ? (typeof window !== "undefined" ? window.location.origin : "http://localhost:5190")
      : (import.meta.env.VITE_API_BASE_URL || "");
