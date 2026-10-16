# CDP Starter Kit

> **A Laravel 12 backend starter kit with JWT auth, RBAC, org structure, and utility modules.**

A production-ready REST API skeleton built on **Laravel 12**. Instead of rebuilding auth, permissions, organizational master data, audit logging, and import/export for every project, start from this kit and extend it with your own domain modules.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Framework** | Laravel 12 |
| **PHP** | >= 8.2 |
| **Authentication** | JWT (`php-open-source-saver/jwt-auth`) |
| **API Tokens** | Laravel Sanctum (available) |
| **RBAC** | Spatie Laravel Permission |
| **Testing** | Pest PHP |
| **Code Style** | Laravel Pint |
| **Database** | MySQL (primary), SQLite (testing) |

---

## What's Included

- **JWT Authentication** — login/logout/`me`, HttpOnly `auth_token` cookie, throttled login
- **RBAC** — permissions, roles, users CRUD with Spatie Permission (`group_name` support)
- **Org Structure** — Countries, Provinces, Zonals, Regions, Branches, Departments, Designations, Groups (full CRUD + toggle-status + active-list endpoints)
- **Employees** — employee master linked to org structure, self-referential reporting tree
- **Employee Hierarchy** — temporal reporting-manager history, reassign manager, point-in-time hierarchy lookup
- **Activity Logging** — generic `ActivityLogTrait` + activity-log viewer endpoints
- **Notifications** — in-app (database channel) notifications API
- **Bulk Import** — config-driven CSV import for any registered table, with templates
- **Data Export** — CSV/table exports + multi-table Excel export
- **Database Backup** — mysqldump-based SQL export (`MYSQL_DUMP_PATH` configurable)
- **Security Middleware** — security headers, input sanitization, force HTTPS
- **Welcome Mail** — branded user-created email template

---

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
# configure DB in .env, then:
php artisan migrate --seed
php artisan serve
```

Default super admin (seeded):

```
email:    dev@localhost.com
password: password
```

### Health Checks

| Endpoint | Description |
|----------|-------------|
| `GET /api/health-check` | Simple liveness check |
| `GET /health` | Health with database connectivity check |
| `GET /up` | Laravel built-in |

---

## API Overview

All versioned routes live under `/api/v1`. Protected routes require a JWT (`Authorization: Bearer <token>` or HttpOnly `auth_token` cookie).

### Public
- `POST /api/v1/login`

### Protected
| Module | Endpoints |
|--------|-----------|
| Auth | `POST /logout`, `GET /me` |
| Permissions | `apiResource` + `GET /permissions/list` |
| Roles | `apiResource` + `GET /roles/list` |
| Users | `apiResource` + `PATCH /users/{id}/toggle-status` |
| Countries / Provinces / Zonals / Regions / Branches / Departments / Designations / Groups | `apiResource` + `toggle-status` + active list |
| Employees (hierarchy) | `GET /employees/{id}/manager-history`, `POST /employees/{id}/reassign-manager`, `GET /employees/{id}/hierarchy-as-of` |
| Notifications | index, unread-count, mark read, mark-all-read, destroy |
| Activity Logs | `index`, `show` |
| Database | `export`, `export-data`, `export-table/{table}`, `export-all-tables` |
| Import | `tables`, `catalog`, `template/{table}`, `POST /{table}` |

### Response Envelope

All endpoints return a consistent envelope:

```json
{
  "status": "success",
  "message": "...",
  "data": {}
}
```

Errors use `"status": "error"` with an optional `error` field when `APP_DEBUG=true`.

---

## Project Structure

```
app/
├── Http/
│   ├── Controllers/V1/     # Versioned API controllers
│   ├── Middleware/          # SecurityHeaders, SanitizeInput, ForceHttps
│   └── Requests/           # FormRequest validation classes
├── Models/                 # Eloquent models
├── Notifications/          # Database-channel notifications
├── Services/               # BulkImportService, DataExportService, DatabaseService
└── Traits/                 # ActivityLogTrait, FileUploadTrait
database/
├── migrations/             # Schema
└── seeders/                # PermissionsSeeder, UserSeeder
routes/
├── api.php                 # Entry: health-check + v1 include
├── v1.php                  # All versioned API routes
└── web.php                 # Landing page + health endpoint
```

---

## RBAC Model

Permissions are seeded via `database/seeders/PermissionsSeeder.php`, grouped by module (e.g. `User Management Permissions`). A `Super Admin` role receives all permissions. Controllers enforce access via middleware:

```php
new Middleware('permission:User Index', ['only' => ['index', 'show']]),
```

**To add permissions for a new module:** append entries to `PermissionsSeeder`, then re-run `php artisan db:seed --class=PermissionsSeeder`.

---

## Extending the Kit (Add a New Module)

1. **Migration** — `php artisan make:migration create_xxx_table`
2. **Model** — `app/Models/Xxx.php` (use `HasFactory` where useful)
3. **Requests** — `CreateXxxRequest` / `UpdateXxxRequest` in `app/Http/Requests`
4. **Controller** — `app/Http/Controllers/V1/XxxController.php` implementing `HasMiddleware` with permission gates; follow the `{status, message, data}` envelope and `ActivityLogTrait` usage of existing controllers (e.g. `CountryController`)
5. **Routes** — register in `routes/v1.php` (apiResource + toggle-status + list pattern)
6. **Permissions** — add to `PermissionsSeeder`
7. **Import/Export (optional)** — register the table in `BulkImportService::getImportableConfig()`

Copy any existing org-structure module (e.g. Countries) as a template — the pattern is consistent across all of them.

---

## Configuration Notes

| Env Var | Purpose |
|---------|---------|
| `JWT_*` | JWT TTL, refresh TTL, algorithm |
| `FRONTEND_URL` | Base URL used in email links |
| `CORS_ALLOWED_ORIGINS` | Comma-separated allowed origins |
| `DIALOG_SMS_*` | Dialog SMS gateway credentials |
| `MYSQL_DUMP_PATH` | Full path to `mysqldump` if not in system PATH |

Private file storage uses the `private` disk (`storage/app/private`, served with temporary URLs) — see `config/filesystems.php` and `FileUploadTrait`.

---

## Testing

```bash
php artisan test
```

Tests run on SQLite in-memory (`phpunit.xml`). Add Pest feature tests per module under `tests/Feature/`.

---

## Code Standards

- Laravel Pint for formatting: `vendor/bin/pint`
- Controllers return the `{status, message, data}` envelope
- Cross-cutting writes go through `ActivityLogTrait::logActivity()`
- Validation lives in FormRequests, never in controllers

---

## License

MIT
