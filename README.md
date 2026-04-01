<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## CORRAD / KERISI — Pembangunan tempatan

| Perintah | Apa yang dihidupkan |
|----------|---------------------|
| **`composer dev`** | Penuh: Laravel **8090**, Vite **5190**, queue, Reverb, Pail. **Disyorkan** untuk kerja harian. |
| **`composer run dev:minimal`** atau **`npm run dev:stack`** (dari root) | Hanya **Laravel 8090** + **Vite 5190** — elak `ECONNREFUSED` jika anda biasa `cd client && npm run dev` sahaja. |
| **`npm run dev:client`** | Vite sahaja — **wajib** hidupkan Laravel berasingan (`php artisan serve --port=8090`) atau guna baris di atas. |
| **`http://127.0.0.1:5190`** | UI daripada **Vite** (HMR) — perubahan Vue kelihatan serta-merta; API masih di **8090** melalui proxy. |
| **`http://127.0.0.1:8090`** | UI daripada **`public/spa`** (hasil `npm run build:admin`). Selepas ubah Vue, jalankan **`npm run build:admin`** atau **`npm run build:admin:watch`** supaya perubahan muncul di sini. |

- `php artisan serve` guna **`--host=127.0.0.1`** dalam skrip di atas supaya sepadan dengan proxy Vite (`127.0.0.1:8090`).
- Jika Laravel di port lain, set `VITE_PROXY_TARGET=http://127.0.0.1:PORT` dalam `.env` atau `client/.env`, kemudian restart Vite.
- Ralat **`ECONNREFUSED 127.0.0.1:8090`** = backend **tidak dijalankan** pada URL proxy — bukan ralat Vue.

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
