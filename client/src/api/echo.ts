import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { API_BASE_URL } from "@/env";

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: { private: (ch: string) => { listen: (e: string, cb: (d: unknown) => void) => void }; leave: (ch: string) => void; disconnect: () => void };
  }
}

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;
const host = import.meta.env.VITE_REVERB_HOST || "localhost";
const port = import.meta.env.VITE_REVERB_PORT || "8080";
const scheme = import.meta.env.VITE_REVERB_SCHEME || "http";
const wsHost = host;
const wsPort = port;
const wssPort = port;
const forceTLS = scheme === "https";

export function getEcho(): (typeof window)["Echo"] | null {
  if (!key) return null;
  if (window.Echo) return window.Echo;
  try {
    window.Echo = new Echo({
      broadcaster: "reverb",
      key,
      wsHost,
      wsPort: Number(wsPort),
      wssPort: Number(wssPort),
      forceTLS,
      enabledTransports: ["ws", "wss"],
      authEndpoint: `${API_BASE_URL}/broadcasting/auth`,
      auth: {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      },
    });
    return window.Echo ?? null;
  } catch {
    return null;
  }
}

export function disconnectEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect();
    window.Echo = undefined;
  }
}
