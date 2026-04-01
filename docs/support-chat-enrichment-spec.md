# Support Chat Enrichment — Spec

> Rujukan untuk pengayaan Support Chat (KERISI AI) dengan Panel 1 (sidebar) dan Panel 2 (chat).

---

## Panel 1 — Sidebar

| # | Feature | Penerangan |
|---|---------|------------|
| 1 | **Semua Modul** | Sudah ada — dropdown filter modul (Cashbook, GL, Payroll, dll.) |
| 2 | **Chat Baru / Group Chat** | Pilihan: Chat baru (solo) atau Group chat (multi-agent). Group chat = session dengan banyak peserta (agent). |
| 3 | **Favorites** | Dalam chat, agent boleh tick mesej sebagai favorite. Sidebar tunjuk 3–5 favorite + "..." untuk lebih. |
| 4 | **Chat History** | Senarai 3–5 sesi terkini + "..." untuk detail/lanjutan. |
| 5 | **Tickets Agent** | Agent klik ticket → isu masuk ke chat, AI jawab. Admin: semua ticket. Agent: hanya ticket belum closed/unanswered. |
| 6 | **Ticket Detail** | Klik ticket → modal/page dengan detail penuh termasuk semua conversation/thread. |
| 7 | **Suggestions** | Cadangan untuk agent (contoh: soalan lazim, quick actions). |

---

## Panel 2 — Chat

| # | Feature | Penerangan |
|---|---------|------------|
| 1 | **Reply to Agent** | Bila chat ada lebih dari 1 agent, boleh pilih reply kepada agent tertentu. |
| 2 | **Forward to Customer** | Hantar mesej ke customer (via Desk365 / email / dll.). |
| 3 | **Search** | Cari dalam chat (mesej, kandungan). |
| 4 | **Settings** | Toggle: papar semua docs, links, media (show/hide inline). |

---

## Schema & API (Cadangan)

### DB Changes

- **chat_sessions**: `session_type` (solo|group), `desk365_ticket_id` (nullable), `participant_ids` (JSON) untuk group.
- **chat_messages**: `reply_to_message_id`, `reply_to_user_id`, `forwarded_to` (JSON), `is_favorite`.
- **chat_message_favorites** (atau flag pada mesej): `user_id`, `chat_message_id`.

### API Endpoints

- `POST /api/chat/sessions` — body: `{ title, module_filter, session_type?, participant_ids? }`
- `GET /api/chat/sessions` — my sessions (solo + group di mana user peserta)
- `GET /api/chat/favorites` — favorite messages (3–5 + pagination)
- `POST /api/chat/messages/{id}/favorite` — toggle favorite
- `GET /api/chat/tickets` — tickets (Admin: all; Agent: open/unanswered). Filter by `assigned_to` jika API sokong.
- `GET /api/chat/tickets/{id}` — ticket detail + conversations
- `POST /api/chat/sessions/{id}/messages` — body: `{ message, reply_to_message_id?, reply_to_user_id? }`
- `POST /api/chat/messages/{id}/forward` — forward ke customer
- `GET /api/chat/sessions/{id}/messages?q=` — search dalam sesi
- `GET /api/chat/suggestions` — cadangan untuk agent

---

## Assumptions

- Desk365 API: `listTickets` mungkin sokong `assigned_to`, `status`. Jika tidak, filter client-side.
- Group chat: OpenAI Assistants API thread dikongsi; peserta = agent dalam sistem.
- Forward to customer: bergantung integrasi Desk365 (reply ke ticket conversation).
