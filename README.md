# Employee Management SaaS (Phases 1–3)

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
php artisan storage:link   # serves uploaded employee photos from /storage
php artisan serve
```

Open http://127.0.0.1:8000

### Demo logins (password: `password`)

| Email | Role | Org |
|---|---|---|
| `admin@demo.test` | Company Admin | Demo Corp |
| `employee@demo.test` | Employee | Demo Corp |
| `admin@other.test` | Company Admin | Other Inc |

## What's Included

### Phase 1 — Core platform
- **Auth**: login, registration with organization provisioning, password reset, email verification
- **Tenancy**: shared database + `organization_id` on all tenant tables; `OrganizationScope` global scope auto-filters queries
- **RBAC**: 4 roles per org (Company Admin, HR, Manager, Employee), 28-permission catalog, Laravel Gates
- **Employees**: full CRUD, employee codes unique per tenant, profile + employment fields
- **Departments**: CRUD, auto-generated codes, manager assignment
- **Users & roles**: manage org members, assign roles
- **Dashboards**: admin dashboard (org stats + today's Present/Late/Absent/On leave + avg attendance) and employee dashboard (GitHub-style attendance calendar, month stats, worked-hours, leave balance, recent activity)
- **Audit logs**: CRUD actions recorded with actor, IP, old/new values
- **Settings**: org name/timezone, punch debounce window (5–300s, default 15), time format (24-hour `16:00` or 12-hour `4:00 PM` — applies to all attendance/dashboards/history clock displays)
- **Platform admin**: cross-tenant organization list + suspend/activate

### Phase 2 — Workforce operations
- **Attendance**: NFC punch API (`POST /api/attendance/nfc/punch` and versioned alias `POST /api/v1/attendance/nfc/punch`, both with `X-Terminal-Key` + optional `terminal_id`), configurable debounce (per employee+terminal, org setting), daily totals summed from completed IN→OUT segments (mid-day exits excluded), HALF_DAY + review flags (`possible_missed_checkin`, `excessive_segments`), manual corrections with audit, re-editable entries (edit or remove any event with a reason — original kept via supersede/soft-delete, day recalculated, full audit), full check-in/check-out entry in one step on the Correct Attendance page, org-local wall-clock times end-to-end (10:00 AM displays as 10:00 AM for UTC+6 orgs), effective-dated work schedules (past days keep their original schedule)
- **Self-service**: employees view their own attendance (`/attendance/employee/{id}`, "My Attendance" nav), read-only name/email, photo upload on profile & employee forms
- **NFC cards**: issue/assign/unassign/block/revoke per org, replace a lost card (new token, old card revoked but history preserved) with a per-card punch history view
- **Terminals**: register devices, API key shown once (sha256-hashed), revocation
- **Leave**: types + yearly balances, request/approve/reject/cancel workflow with balance enforcement
- **Work schedules**: working hours, grace period, breaks, holidays (defaults seeded per org), weekday selection, versioned by effective date

### Phase 3 — Compensation & insight
- **Salary records**: effective-dated basic/allowances/bonus/deductions per employee (§28) — a raise never rewrites earlier months; "Salary" / "My Salary" pages (`salary.view` / `salary.view_own`)
- **Statistics**: monthly estimate with per-day breakdown (late/absence/half-day/unpaid-leave deductions, grace + rounding + % cap rules configurable in Settings under "Salary & deductions")
- **Payslips**: finalize a completed month into an immutable snapshot, revision history when salary or rules change, print/save-as-PDF detail page
- **Reports (§41)**: Attendance (date/status/employee/department filters), Monthly (present/absent/late/leave/hours + totals), Salary (effective records; requires `reports.view` **and** `salary.view`) — CSV export for each, Excel/PDF via the browser
- **Analytics**: 6-month attendance trend, department rates, weekday late pattern, payroll progress — pure CSS charts (`reports.view`; payroll card needs `salary.view`)

## Tests

```bash
php artisan test        # 136 tests
vendor/bin/pint app tests routes database bootstrap resources --format agent # code style
```

## Documentation

- [docs/architecture.md](docs/architecture.md) — tenancy, middleware, request flow
- [docs/schema.md](docs/schema.md) — database tables and relationships
- [docs/rbac.md](docs/rbac.md) — permission catalog and role matrix
- [docs/roadmap.md](docs/roadmap.md) — Phases 4+ (Phases 1–3 complete)

## Environment Notes

- Dev database: SQLite (`database/database.sqlite`); tests use `:memory:`
- `.env` is pre-configured by the setup script (`APP_KEY` generated)
- Rebuild assets after view changes: `npm run build`
