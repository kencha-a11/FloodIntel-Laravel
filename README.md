<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

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

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Testing against PostgreSQL

The default suite runs on SQLite `:memory:` and remains the fast path. The database-control tests live in `tests/Postgres` and are skipped unless a disposable PostgreSQL database is supplied:

```bash
createdb floodintel_test
export DB_POSTGRES_TEST_URL="postgresql://postgres:postgres@127.0.0.1:5432/floodintel_test"
composer test:postgres
```

`DB_POSTGRES_TEST_URL` is deliberately separate from `DB_URL`, and `phpunit.postgres.xml` does not read `.env`. The suite refuses a database whose name does not contain `test` unless `POSTGRES_TEST_ALLOW_NON_TEST_DB=1` is set, and it runs `config:clear` first so a cached production configuration cannot leak in. `composer test` continues to run SQLite only; the PostgreSQL tests report as skipped there, never as passed.

The server also needs **PostGIS**. The spatial migration creates the extension when it is absent, but the server must have it available to install:

```bash
docker run --rm -e POSTGRES_PASSWORD=postgres -p 55432:5432 postgis/postgis:18-3.6
createdb -h 127.0.0.1 -p 55432 -U postgres floodintel_test
export DB_POSTGRES_TEST_URL="postgresql://postgres:postgres@127.0.0.1:55432/floodintel_test"
composer test:postgres
```

If the extension cannot be created, the migration fails with a message naming PostGIS rather than a bare SQLSTATE. The extension's schema is resolved at runtime from `pg_extension`, so Supabase's `extensions` schema and a stock server's `public` both work without configuration.

## Database roles

The application and its migrations use two credentials:

| Variable | Role | Used by |
|---|---|---|
| `DB_URL` | `app_runtime` (non-owner) | The running application. Every request goes through this connection (D-61). |
| `DB_OWNER_URL` | `postgres` (owner) | `php artisan migrate --force --database=pgsql_owner` and seeders only. Owner connections bypass row-level security (D-08). |

`database/migrations/2026_10_31_120000_provision_app_runtime_role.php` creates `app_runtime` if it is missing and grants it schema usage, the tables it needs, and sequence usage. It does not set a password: create the role with `login` in Supabase and store its connection string as `DB_URL`. A role created by the migration is `nologin` until an operator sets one.

`docker/entrypoint.sh` refuses to start when `DB_OWNER_URL` is unset and runs migrations on `pgsql_owner`, so the application never needs DDL rights. For a local run, point `DB_OWNER_URL` at the same database as `DB_URL` if that connection already owns the schema.

A third role, `app_maintenance`, is created by `database/migrations/2026_10_31_130000_provision_app_maintenance_role.php` and needs no credential. It exists for the derived-address backfill only: it may read `id`, `latitude`, `longitude`, and `address` from `flood_reports` and fill a missing address, and nothing else (D-98). Application code reaches it through `App\Support\MaintenanceDatabaseContext`, which sets the role inside a transaction.

Row-level security is enabled on `flood_reports`, `flood_impact_reports`, `flood_events`, and `barangays` by `2026_10_31_131000_enable_row_level_security.php`. `App\Support\RlsScope` is the two-list scope and the migration fails when a table is on neither list; `users` is deliberately outside RLS because login, password reset, email verification, and Sanctum token resolution read it before an identity exists (D-97). Authenticated route groups use `db.identity` (`App\Http\Middleware\SetDatabaseIdentity`) after `auth:sanctum`, so every policy reads `app.user_id` for the request and denies when it is unset.


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
# FloodIntel-Laravel
