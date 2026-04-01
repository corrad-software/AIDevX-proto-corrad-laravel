# Integrasi User Chat dengan MYFIS2 / Corrad

User Chat diurus oleh **KERISI Support** (Laravel + Vue). MYFIS2 ([contoh](https://myfisv2-tourism.datasc.dev/)) ialah aplikasi Corrad berasingan — tambah **satu skrip** pada layout PHP (footer/header) supaya pengguna boleh buka chat.

**Full page dari header + kongsi sesi (SSO):** lihat **[corrad-kais-integration.md](corrad-kais-integration.md)** — endpoint `/auth/corrad-sso` dan snippet PHP untuk CORRAD.

## Syarat

1. Pengguna perlu **log masuk** ke portal KERISI Support (domain `data-cms-url`) — sama ada dalam tab lain atau dalam iframe (lihat sesi di bawah).
2. Peranan perlukan **`chat.use`**.
3. Gantikan `https://YOUR-CMS-DOMAIN` dengan URL sebenar admin Support.

---

## Disyorkan: popup **dalam** halaman MYFIS2 (`overlay` + iframe)

Skrip cipta lapisan gelap + panel dengan **iframe** ke `/admin/kerisi/user-chat?embed=1` (penjuru kanan bawah, serupa widget).

### 1) Konfigurasi Laravel (server Support)

Dalam `.env` portal Support:

```env
KERISI_EMBED_IFRAME_ORIGINS=https://myfisv2-tourism.datasc.dev
```

Asal berbilang: pisahkan dengan koma (tiada ruang).

Ini menetapkan header **`Content-Security-Policy: frame-ancestors 'self' https://...`** supaya iframe tidak disekat pelayar.

**Sesi dalam iframe (rentas domain):** pelayar hantar kuki sesi dalam iframe hanya jika kuki **SameSite=None** dan **Secure**. Pada **HTTPS production**:

```env
SESSION_SAME_SITE=none
SESSION_SECURE_COOKIE=true
```

Kemudian `php artisan config:clear`. Tanpa ini, iframe mungkin tunjuk halaman log masuk walaupun pengguna sudah log masuk di tab lain.

> Jika nginx/proxy menetapkan `X-Frame-Options: DENY`, iframe tetap gagal — longgarkan untuk laluan SPA atau buang header tersebut untuk domain Support.

### 2) Skrip pada MYFIS2 / Corrad

**Sebelum `</body>`** (layout utama):

```html
<script
  src="https://YOUR-CMS-DOMAIN/kerisi-user-chat-launcher.js"
  data-cms-url="https://YOUR-CMS-DOMAIN"
  data-open-mode="overlay"
  defer
></script>
```

- **`data-open-mode="overlay"`** — popup dalam halaman (bukan tetingkap pelayar).
- **`data-overlay-title`** — tajuk bar (lalai: `KERISI User Chat`).
- **`data-overlay-max-width`** / **`data-overlay-max-height`** — saiz panel.

Ikon bulat hijau **kanan bawah**: klik buka panel; klik lagi atau × atau klik latar → tutup.

### 3) Ikon di header Corrad (⋮ / menu atas)

```html
<script
  src="https://YOUR-CMS-DOMAIN/kerisi-user-chat-launcher.js"
  data-cms-url="https://YOUR-CMS-DOMAIN"
  data-position="manual"
  data-open-mode="overlay"
  defer
></script>

<button type="button" class="your-header-btn" onclick="window.KerisiUserChat && window.KerisiUserChat.open()">
  Bantuan AI
</button>
```

API skrip:

- `KerisiUserChat.open()` — buka overlay / popup / tab (ikut `data-open-mode`).
- `KerisiUserChat.close()` — tutup overlay sahaja.
- `KerisiUserChat.url()` — URL penuh chat embed.

---

## Kaedah lain: tetingkap pelayar (`popup`) atau tab (`tab`)

```html
<script
  src="https://YOUR-CMS-DOMAIN/kerisi-user-chat-launcher.js"
  data-cms-url="https://YOUR-CMS-DOMAIN"
  data-open-mode="popup"
  defer
></script>
```

- **`popup`** — `window.open` (kurang isu kuki daripada iframe).
- **`tab`** — `target="_blank"`.

---

## Dalam admin KERISI Support (Vue)

- **Header**: pautan User Chat (ikon mesej) jika ada `chat.use`.
- **FAB**: kanan bawah di semua skrin admin **kecuali** halaman User Chat.

**URL embed / bookmark:**  
`https://YOUR-CMS-DOMAIN/admin/kerisi/user-chat?embed=1`

---

*Skrip: `public/kerisi-user-chat-launcher.js` → `/kerisi-user-chat-launcher.js`*  
*Middleware: `App\Http\Middleware\KerisiEmbedFrameAncestors` (kumpulan `web`)*
