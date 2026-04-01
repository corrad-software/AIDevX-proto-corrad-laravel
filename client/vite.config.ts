import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";

const isLaravelBuild = process.env.VITE_BUILD_FOR_LARAVEL === "1";

/** Direktori `client/` (bukan bergantung pada __dirname — projek ini `"type": "module"`). */
const clientDir = path.dirname(fileURLToPath(import.meta.url));
const laravelRoot = path.join(clientDir, "..");

function createApiProxyConfig(target: string) {
  return {
    target,
    changeOrigin: true,
    /** Lalai http://localhost:* sering resolve IPv6 (::1) manakala `php artisan serve` dengar di IPv4 — proxy gagal. */
    secure: false,
    ws: true,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- http-proxy Server, no dep types in client
    configure(proxy: any) {
      proxy.on("error", (err: Error) => {
        console.warn("[vite proxy]", target, err.message);
        if (String(err.message).includes("ECONNREFUSED")) {
          console.warn(
            "→ Tiada Laravel di port itu. Dari root repo jalankan: composer dev   ATAU   php artisan serve --port=8090",
          );
          console.warn(
            "  Jika guna port lain (cth 8000), set VITE_PROXY_TARGET=http://127.0.0.1:8000 dalam .env atau client/.env, restart npm run dev.",
          );
        }
      });
    },
  };
}

export default defineConfig(({ mode }) => {
  // Isu #2: keutamaan — shell → client/.env → root Laravel .env → 127.0.0.1:8090 (composer dev)
  const envClient = loadEnv(mode, clientDir, "");
  const envRoot = loadEnv(mode, laravelRoot, "");
  const fromShell = process.env.VITE_PROXY_TARGET?.trim();
  const fromFiles = (envClient.VITE_PROXY_TARGET || envRoot.VITE_PROXY_TARGET || "").trim();
  const proxyTarget = fromShell || fromFiles || "http://127.0.0.1:8090";

  if (mode === "development" && !isLaravelBuild) {
    console.info(
      `[vite] proxy /api, /sanctum, /broadcasting → ${proxyTarget} (VITE_PROXY_TARGET: shell > client/.env > root .env)`,
    );
    console.info(
      "[vite] Wajib: Laravel hidup pada URL di atas. Hanya `cd client && npm run dev` tidak cukup — jalankan juga `composer dev` atau `php artisan serve --port=8090` dari root projek.",
    );
  }

  const apiProxy = createApiProxyConfig(proxyTarget);

  return {
    define: {
      /** ISO timestamp — hanya diisi semasa `build:laravel` (bundle dalam `public/spa`). */
      __VITE_ADMIN_BUILD__: JSON.stringify(isLaravelBuild ? new Date().toISOString() : ""),
    },
    plugins: [vue()],
    base: isLaravelBuild ? "/spa/" : "/",
    resolve: {
      alias: {
        "@": path.resolve(clientDir, "src"),
      },
    },
    build: isLaravelBuild
      ? {
          outDir: "../public/spa",
          emptyOutDir: true,
        }
      : undefined,
    server: {
      port: 5190,
      proxy: {
        "/api": { ...apiProxy },
        "/sanctum": { ...apiProxy },
        "/broadcasting": { ...apiProxy },
      },
    },
  };
});
