# Production deployment — CORRAD Laravel CMS

Run on the **application server** (or CI job that deploys the same artefact) after code is checked out.

## One command

From project root:

```bash
composer deploy
```

This runs `scripts/deploy.sh`, which:

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan optimize` (config, route, view, event caches)
4. `npm ci` + `npm run build:laravel` in `client/` (Vue admin SPA → `public/spa/`, base `/spa/`)
5. `php artisan queue:restart` (so queue workers reload; safe to ignore if queues unused)

### Frontend artefact — wajib dilayan dari server

- **Commit & deploy** direktori hasil build **`public/spa/**`** bersama kod (atau salin artefak tersebut ke server). Tanpa fail ini, Laravel tidak akan menghidangkan JS/CSS admin terkini walaupun `client/` sudah dibina di mesin lain.
- **Selepas deploy**: minta pengguna **hard refresh** (Ctrl+F5 / Cmd+Shift+R) atau kosongkan cache pelayar; jika ada **CDN / reverse proxy cache** untuk `public/spa/assets/*`, **invalidate / purge** atau naikkan cache-busting supaya bundle baharu (`index-*.js`) dilayan.

## Manual steps (same as script)

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan optimize
npm --prefix client ci
npm --prefix client run build:laravel
php artisan queue:restart
```

## Before first deploy

- Copy `.env.example` → `.env`, set `APP_KEY`, database, `APP_URL`, Sanctum stateful domains, mail, etc.
- `php artisan key:generate` if needed
- Ensure web server document root points to `public/`
- For MySQL: create database and user; SQLite: ensure `database/database.sqlite` exists if used

## Optional: maintenance mode

For zero-downtime expectations, wrap the deploy window:

```bash
php artisan down --render="errors::503" --retry=60
composer deploy
php artisan up
```

## After deploy

- **Vue admin SPA**: pastikan **`public/spa/**`** yang dibarui sudah ada pada server yang sama dengan release; uji dengan hard refresh; jika guna CDN, flush cache untuk aset `/spa/assets/`.
- **Reverb** (if used): restart the Reverb process/supervisor (`php artisan reverb:start` is long-running).
- **Scheduler**: ensure `* * * * * cd /path && php artisan schedule:run` in crontab.
- **OPcache**: restart PHP-FPM if opcode cache does not invalidate automatically.

## Rollback

- Restore previous release directory / git checkout, then run `composer deploy` again (or only `migrate` if migrations were the issue — test rollback migrations separately).

## Batal deploy di mesin pembangunan (local)

Jika anda terlepas jalankan `composer deploy` / `composer install --no-dev` secara silap, **vendor** akan jadi mod production (tiada PHPUnit, dsb.). Untuk kembali ke mod pembangunan:

```bash
composer install --no-interaction
php artisan optimize:clear
```

Jika `vendor` rosak (fail hilang), pasang semula pakej:

```bash
composer update fakerphp/faker --no-interaction
composer install --no-interaction
```
