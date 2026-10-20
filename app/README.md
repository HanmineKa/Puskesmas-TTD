<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Aplikasi Program TTD Puskesmas

Fondasi menggunakan PHP 8.5, Laravel 13, MySQL 8, Vue 3, Vue Router, Pinia, dan Vite. Frontend berjalan sebagai SPA dengan autentikasi sesi Sanctum.

### Persiapan Lokal

1. Siapkan database `puskesmas-ttd` dengan MySQL 8. Akun runtime harus terpisah dari root: gunakan user `puskesmas_ttd_app` dan beri hak sesuai tabel; `log_login`, `audit_log`, dan `log_akses_data` hanya memerlukan `SELECT` dan `INSERT`.
2. Salin `.env.example` menjadi `.env`, lalu isi kredensial database runtime, `BLIND_INDEX_KEY` (secret acak minimal 32 byte), serta konfigurasi awal Administrator. Jangan commit `.env` atau secret.
3. Jalankan `php artisan key:generate` untuk membuat `APP_KEY`.
4. Jalankan migrasi dari shell developer yang memiliki hak DDL pada database, bukan memakai akun runtime aplikasi: `php artisan migrate --force`.
5. Isi `PUSKESMAS_NAMA`, `TTD_ADMIN_NAMA`, `TTD_ADMIN_USERNAME`, `TTD_ADMIN_PASSWORD` (minimal 16 karakter), dan `TTD_ADMIN_TOTP_SECRET` (Base32), lalu jalankan `php artisan db:seed --class=AdministratorSeeder`.
6. Jalankan `npm install`, `npm run build`, lalu `php artisan serve`.

Di Windows, environment variable proses dapat mengesampingkan nilai `.env`. Pastikan `DB_USERNAME` pada proses web memakai akun runtime, bukan `root`. Untuk lokal HTTP, `SESSION_SECURE_COOKIE=false`; production wajib memakai HTTPS agar cookie Secure dan HSTS aktif.

### Pemeriksaan

- `php artisan test`
- `composer audit`
- `npm audit`
- `npm run build`

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

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
