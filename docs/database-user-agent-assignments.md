# Penugasan ejen (Level 3) — struktur DB

Penugasan “siapa melapor kepada siapa” antara **ejen (Level 3)** disimpan seperti berikut:

| Komponen | Keterangan |
|----------|------------|
| `users.managed_by_user_id` | FK nullable → `users.id`. Setiap **ejen** (`user_level = agent`) boleh mempunyai **satu** pengurus (pengguna Level 0, 2, atau 3 yang ditetapkan dalam UI). |
| `user_managed_agents` | Jadual hubungan eksplisit: `manager_user_id`, `agent_user_id` (unik), `timestamps`. Disegerakkan bersama `managed_by_user_id` melalui API pengguna. |

**Peraturan produk (UI):** blok **Agent** pada skrin create/edit user untuk pengguna dengan **Level 0–3** (bukan Level 4).

**API:** Skrin create/update user memuat senarai ejen melalui **`GET /api/users/agent-picklist`** (skop hierarki sama seperti `GET /users`); `PUT /users` menghantar `managed_agent_ids` seperti biasa.

Migrasi: `2026_03_23_000005_add_managed_by_user_id_to_users.php`, `2026_03_24_000001_create_user_managed_agents_table.php`.
