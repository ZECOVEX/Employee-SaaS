# Employee Management SaaS (Phase 1 MVP)

Multi-tenant employee management platform built with Laravel 13, Livewire 3, and Breeze (Volt). Each registered organization (tenant) gets isolated employees, departments, users, roles, and audit logs.

## Requirements

- PHP 8.4+ (extensions: pdo_sqlite, mbstring, openssl, curl, zip, fileinfo)
- Composer 2
- Node.js 20+ (for asset build)

On Windows, `E:\employee-saas-setup.ps1` installs and scaffolds everything.

## Quick Start

```powershell
# From E:\employee-saas
composer install
npm install && npm run build
php artisan migrate --seed
php artisan serve
```

Open http://127.0.0.1:8000

### Demo logins (password: `password`)

| Email | Role | Org |
|---|---|---|
| `admin@demo.test` | Company Admin | Demo Corp |
| `employee@demo.test` | Employee | Demo Corp |
| `admin@other.test` | Company Admin | Other Inc |

## What's Included (Phase 1)

- **Auth**: login, registration with organization provisioning, password reset, email verification
- **Tenancy**: shared database + `organization_id` on all tenant tables; `OrganizationScope` global scope auto-filters queries
- **RBAC**: 4 roles per org (Company Admin, HR, Manager, Employee), 20-permission catalog, Laravel Gates
- **Employees**: full CRUD, employee codes unique per tenant, profile + employment fields
- **Departments**: CRUD, auto-generated codes, manager assignment
- **Users & roles**: manage org members, assign roles
- **Dashboards**: admin dashboard (employees/departments/users summary) and employee dashboard
- **Audit logs**: CRUD actions recorded with actor, IP, old/new values
- **Settings**: org name/timezone
- **Platform admin**: cross-tenant organization list + suspend/activate

## Tests

```bash
php artisan test        # 46 tests
vendor/bin/pint --dirty # code style
```

## Documentation

- [docs/architecture.md](docs/architecture.md) — tenancy, middleware, request flow
- [docs/schema.md](docs/schema.md) — database tables and relationships
- [docs/rbac.md](docs/rbac.md) — permission catalog and role matrix
- [docs/roadmap.md](docs/roadmap.md) — Phases 2+ (out of scope for MVP)

## Environment Notes

- Dev database: SQLite (`database/database.sqlite`); tests use `:memory:`
- `.env` is pre-configured by the setup script (`APP_KEY` generated)
- Rebuild assets after view changes: `npm run build`
