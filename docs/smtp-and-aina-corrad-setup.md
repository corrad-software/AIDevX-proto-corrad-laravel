# Rujukan kerja: SMTP + AINA pada CORRAD

Dokumen ini **merakam** dua item operasi/penyepaduan utama. Butiran teknikal penuh ada dalam fail yang dipautkan.

**Kemas kini:** 2026-03-18

---

## 1. SMTP (e-mel keluar)

**Tujuan:** E-mel benar-benar sampai inbox (verifikasi akaun, lupa kata laluan, notifikasi melalui mel, dsb.). Dengan `MAIL_MAILER=log` atau `array`, aplikasi masih jalan tetapi e-mel biasanya tidak dihantar ke penerima.

### Checklist

1. Dapatkan butiran daripada IT (contoh Microsoft 365): host, port, encryption, username/app password atau kaedah auth yang dibenarkan.
2. Tetapkan dalam `.env` (contoh medan lazim):
   - `MAIL_MAILER=smtp`
   - `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
   - `MAIL_ENCRYPTION` (jika perlu)
   - `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
3. Pastikan `FRONTEND_URL` betul supaya pautan dalam e-mel (verify / reset) menunjuk ke frontend yang betul.
4. Selepas ubah: `php artisan config:clear`
5. Uji: daftar / lupa kata laluan / notifikasi yang menghantar mel.

### Nota

- Polisi M365 (MFA, SMTP AUTH dilumpuhkan, OAuth sahaja) mungkin menentukan kaedah sambungan — ikut arahan IT.
- Lihat juga komen dalam `.env.example` jika ada untuk projek ini.

---

## 2. Setup AINA pada CORRAD (legacy PHP)

**Konteks:** Dalam stack KERISI Support (repo ini), **AINA** = **User Chat** admin (`/admin/kerisi/user-chat`). Kod **framework CORRAD (PHP)** tidak disimpan dalam repo Laravel ini; penyepaduan dibuat **di dalam projek CORRAD** dengan mengikut dokumen di bawah.

### Dua kaedah disokong

| Kaedah | Kegunaan | Dokumen |
|--------|----------|---------|
| **Halaman penuh + SSO (HMAC)** | Ikon di header CORRAD → satu klik ke chat, log masuk Laravel melalui `/auth/corrad-sso` | [corrad-kais-integration.md](corrad-kais-integration.md) |
| **Launcher + iframe / popup / tab** | Skrip pada layout MYFIS2/CORRAD; overlay atau tetingkap | [kerisi-myfis-user-chat-embed.md](kerisi-myfis-user-chat-embed.md) |

### Fail & endpoint dalam repo ini

- **SSO Laravel:** `GET /auth/corrad-sso?...` — aktif hanya jika `CORRAD_SSO_SECRET` ditetapkan (`config/corrad.php`).
- **Snippet PHP untuk CORRAD:** [snippets/corrad-kais-sso.php](snippets/corrad-kais-sso.php)
- **Skrip pelayar (FAB / overlay):** `public/kerisi-user-chat-launcher.js` — `data-cms-url` = root URL portal Support.
- **Iframe:** tetapkan `KERISI_EMBED_IFRAME_ORIGINS` pada server Laravel; untuk sesi dalam iframe pada HTTPS biasanya `SESSION_SAME_SITE=none` dan `SESSION_SECURE_COOKIE=true` (lihat dokumen embed).

### Prasyarat pengguna di KERISI Support

- Rekod `users` dengan **e-mel sama** seperti pengguna CORRAD (untuk SSO), akaun aktif, dan kebenaran **`chat.use`** mengikut keperluan laluan.

### Seterusnya

- Buka workspace **folder CORRAD** untuk letak snippet pada header/layout sebenar, atau serahkan path fail supaya penyepaduan boleh diperhalusi per baris.

---

## Rujukan pantas

| Topik | Fail |
|-------|------|
| SSO CORRAD ↔ KAIS | `docs/corrad-kais-integration.md` |
| Embed MYFIS2 / launcher | `docs/kerisi-myfis-user-chat-embed.md` |
| Config SSO | `config/corrad.php`, env `CORRAD_SSO_SECRET`, `CORRAD_SSO_MAX_AGE_SECONDS` |
| Ujian SSO (Laravel) | `tests/Feature/CorradSsoTest.php` |
