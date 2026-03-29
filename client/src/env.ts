// In local dev we use Vite proxy (/api, /sanctum, /storage) to avoid cross-site cookie issues.
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || "";
