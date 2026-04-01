# Tahap pengguna (User levels) — AFSA & Ticket 365

Dokumen ini **wajib** dirujuk semasa reka bentuk tiket, SELAR (Support Chat), dan AINA (User Chat). Nilai dalam kod: `user_level` pada jadual `users`.

**Kemas kini:** 2026-03-19

---

## Ringkasan 0–4

| Tahap | Nilai `user_level` | Nama | Peranan |
|------:|-------------------|------|---------|
| **0** | `super_admin` | Super Admin | Pembangun / pemilik sistem; pintasan RBAC penuh. |
| **1** | `internal_admin` | Pentadbir dalaman | Pegawai pentadbir organisasi; boleh lantik **L2** dan **L3**. |
| **2** | `external_admin` | Pentadbir luaran | Dilantik oleh **L1**; boleh lantik **L3** (ejen). |
| **3** | `agent` | Ejen | Ejen dalaman atau luaran; boleh lantik **L4** sahaja (melalui UI pengguna — ikut kebenaran `users.*`). |
| **4** | `user` | Pengguna / pemohon | Requestor help desk, pengguna **AINA**, portal tiket (akan datang). |

**Nota:** Nilai lama `admin` dalam pangkalan data telah dipetakan ke **`internal_admin`** (L1).

---

## Peraturan perlantikan (ringkas)

- **L1** → boleh lantik **L2**, **L3**, **L4**
- **L2** → boleh lantik **L3**, **L4**
- **L3** → boleh tetapkan **L4** (jika ada kebenaran pengurusan pengguna)
- **L0** → boleh tetapkan semua tahap (termasuk L0 lain, berhati-hati)

API menolak (`403`) jika pengguna cuba menetapkan tahap di luar peraturan ini.

---

## Impersonation (ganti identiti)

- **L0:** boleh impersonate L1–L4 (bukan L0 lain secara lalai — senarai sasaran dalam kod).
- **L1:** boleh impersonate L1–L4 (bukan L0).
- **L2:** L3, L4
- **L3:** L4 sahaja

---

## Sokongan & tiket (Desk365 / Ticket 365 log)

- **L0–L3:** akses **SELAR** (Support Chat) dan paparan tiket mengikut konfigurasi / kebenaran.
- **L0, L1, L2:** biasanya nampak **semua** tiket terbuka (dashboard / analitik) jika perkhidmatan Desk365 dikonfigurasi.
- **L3:** tiket tertumpu pada ejen (penapis “assigned”).
- **L4:** pengguna akhir; **AINA** (User Chat); bukan staf SELAR.

Modul tiket penuh dalam aplikasi (borang pemohon, aliran status, nota dalaman, CSAT) akan selaras dengan takrifan tahap ini — lihat spesifikasi produk (Desk365-style).

---

## Fail rujukan kod

- `App\Enums\UserLevel` — logik tahap, `canAccessSupportChat`, `canSeeAllDeskTickets`, `assignableLevelsForActor`, `impersonatableTargetLevels`
- `App\Http\Controllers\Api\UserController` — semakan semasa cipta/kemas kini pengguna
- `database/migrations/2026_03_22_100000_migrate_user_level_admin_to_internal_admin.php`

---

## Menu AFSA

Label **Ticket 365 log** = senarai tiket disegerakkan / log rujukan (laluan `/admin/kerisi/ticket`).

---

## Dokumen berkaitan

- [smtp-and-aina-corrad-setup.md](smtp-and-aina-corrad-setup.md)
- [kerisi-myfis-user-chat-embed.md](kerisi-myfis-user-chat-embed.md)
