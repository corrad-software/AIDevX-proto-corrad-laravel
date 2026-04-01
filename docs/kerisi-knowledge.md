# KERISI AI — Arahan Knowledge Base

Arahan untuk mengekstrak dan mengupload pengetahuan ke KERISI Support AI.

## Prasyarat

1. **SSH tunnel** ke MYFIS database mesti aktif:
   ```bash
   ssh -i ~/.ssh/kerisi-bastion.pem -f -N -L 3307:10.102.64.103:3306 ec2-user@43.216.51.135
   ```

2. **OpenAI** — pastikan `.env` ada `OPENAI_API_KEY`, `OPENAI_VECTOR_STORE_ID`, `OPENAI_ASSISTANT_ID`

3. **KERISI URL** — untuk AI papar URL terus dalam jawapan navigasi, set `KERISI_SYSTEM_URL` dalam `.env` (default: `http://myfisv2-tourism.datasc.dev`)

---

## Arahan Ringkas

| Tujuan | Arahan |
|--------|--------|
| **Semua** — menu, pages, BL, schema, workflow, RBAC | `composer kerisi:extract-all` |
| **Business Logic (BL)** — kod PHP/JS untuk sokongan teknikal | `composer kerisi:extract-bl` |
| **Database Schema + Relationships** — skema penuh semua jadual + FK relationships | `composer kerisi:extract-schema` |
| **Workflow** — flow butang/aksi dalam setiap halaman | `composer kerisi:extract-workflow` |
| **RBAC** — capaian peranan (role → menu/modul) | `composer kerisi:extract-rbac` |
| **Tiket sokongan** — dari CSV | `composer kerisi:extract-tickets` |

---

## Arahan Terperinci

### 1. Business Logic (BL)

Ekstrak semua kod BL (13,000+ fungsi) untuk AI bantu sokongan teknikal.

```bash
php artisan kerisi:extract-knowledge --section=bl --upload
```

Atau:
```bash
composer kerisi:extract-bl
```

### 2. Semua Knowledge

Ekstrak menu, pages, BL, database schema, workflow, dan RBAC.

```bash
php artisan kerisi:extract-knowledge --section=all --upload
```

Atau:
```bash
composer kerisi:extract-all
```

### 3. Workflow

Ekstrak flow butang/aksi dalam setiap halaman (controls → triggers → BL). AI boleh jawab "apa flow bila klik Save?", "urutan butang dalam modul X?".

```bash
composer kerisi:extract-workflow
# atau
php artisan kerisi:extract-knowledge --section=workflow --upload
```

### 4. RBAC (Capaian Peranan)

Ekstrak Role-Based Access Control: peranan mana boleh akses menu/modul mana. AI boleh jawab "sapa boleh akses modul X?", "user dalam group Y boleh guna apa?", "kenapa tak nampak menu Z?".

```bash
composer kerisi:extract-rbac
# atau
php artisan kerisi:extract-knowledge --section=rbac --upload
```

### 5. Tiket Sokongan

Proses tiket dan upload ke AI. Dua mod:

**1. Dari CSV** — Letakkan CSV di `storage/app/private/kerisi-support-tickets-raw.csv`:

```bash
php artisan kerisi:process-tickets --upload
php artisan kerisi:process-tickets --file=/path/ke/archived_desk365.csv --upload
```

**2. Dari Desk365 API** — Fetch terus dari datasc.desk365.io (perlu `DESK365_API_KEY` dalam `.env`):

```bash
php artisan kerisi:process-tickets --from-api --upload
```

**Setup Desk365 API:** Tambah dalam `.env`:
```
DESK365_BASE_URL=https://datasc.desk365.io/apis
DESK365_API_KEY=your_api_key_here
```

**Sync ticket ke AI (supaya Agent boleh akses):** Di **Knowledge Base** (`/admin/kerisi/knowledge`):
- **Ticket Terkini (Desk365)** → **Sync ke AI** — semua tiket dari API Desk365.
- **Tiket terkini (dalaman)** → **Sync ke AI** — semua tiket **dalaman** (Kerisi/AFSA), termasuk perbualan & nota dalaman (ditanda), ke Vector Store.

Log: **Administration → Desk365 log** / **Ticket log** (`/admin/platform/desk365`, `/admin/platform/ticket-log`).

**Pemantauan:** **Ticket monitoring** (`/admin/tickets/monitoring`) — ringkasan tiket Desk365 (DB sync), tiket dalaman, status, modul, aktiviti 7 hari, dan kesihatan dokumen di Vector Store. Atau:
```bash
php artisan kerisi:process-tickets --from-api --upload
```

Atau (path default sahaja):
```bash
composer kerisi:extract-tickets
```

---

## Section untuk extract-knowledge

| Section | Kandungan |
|---------|-----------|
| `menu` | Struktur menu FLC_MENU |
| `menu_access` | Menu tree with MENULINK, FLC_USER_GROUP, user–group mappings (PRUSER), sample FLC_PERMISSION (`menu`), plus `KERISI_SYSTEM_URL` |
| `lookup` | Lookup / reference tables in `fims_usr` (`lookup%`, `sysref%`, `lv_%`, `ref_%`) with columns, counts, sample rows (single doc, size-capped) |
| `pages` | Pages, components, items, triggers |
| `bl` | Business Logic (kod PHP + JS) |
| `schema` | Database schema fims_usr |
| `workflow` | Flow butang/aksi dalam setiap halaman (controls → triggers → BL) |
| `rbac` | Capaian peranan (role → menu/modul) |
| `all` | Semua di atas |

Contoh:
```bash
php artisan kerisi:extract-knowledge --section=pages --upload
php artisan kerisi:extract-knowledge --section=schema --upload
```

---

## Selepas Upload

1. Pergi ke **Knowledge Base** (`/admin/kerisi/knowledge`) untuk lihat statistik
2. Klik **Upgrade AI** jika ada perubahan tools/instructions
3. Uji dalam **Chat** (`/admin/kerisi/chat`)

**Penting:** Panduan Ticket Resolution kritikal untuk Support Chat beri penyelesaian teknikal (SQL, BL, 4 bahagian) untuk ticket dari SEMUA modul.

**Langkah lengkap (tanpa SSH tunnel):**
```bash
composer kerisi:upload-ticket-guide   # Upload panduan ticket sahaja (tiada DB)
composer kerisi:upgrade-ai            # Update Assistant dengan arahan terbaru
```

**Langkah lengkap (dengan SSH tunnel ke MYFIS):**
```bash
# 1. Aktifkan tunnel
ssh -i ~/.ssh/kerisi-bastion.pem -f -N -L 3307:10.102.64.103:3306 ec2-user@43.216.51.135

# 2. Extract + upload semua knowledge
composer kerisi:extract-all

# 3. Upgrade AI
composer kerisi:upgrade-ai
```

---

## Tahap pengguna AFSA (0–4) & tiket

Untuk penyepaduan **Ticket 365 / help desk**, RBAC, dan **SELAR** / **AINA**, takrifan tahap pengguna (**Level 0–4**) adalah asas operasi. Rujukan penuh:

**[user-levels-asfa-ticketing.md](user-levels-asfa-ticketing.md)**

Ringkas: L0 pembangun, L1 pentadbir dalaman, L2 pentadbir luaran, L3 ejen, L4 pengguna/pemohon (AINA + requestor tiket).
