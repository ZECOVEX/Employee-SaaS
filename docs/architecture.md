# Architecture

## Stack

- Laravel 13 (PHP 8.4), Livewire 3 + Volt single-file components
- Breeze (livewire stack) for auth scaffolding
- Blade + Tailwind (Vite build)
- SQLite (dev) / PostgreSQL-ready (pgsql driver installed)
- Laravel Sanctum for the versioned JSON API (`/api/v1`, personal access tokens)
- Docker (multi-stage `php:8.4-apache`) + GitHub Actions CI

## Multi-tenancy

Shared database, row-level isolation via `organization_id`:

- **`OrganizationScope`** (`app/Support/Tenancy/OrganizationScope.php`) — global Eloquent scope applied to all tenant models. For an authenticated non-platform user, every query is constrained to `organization_id = <session user>`. Unauthenticated contexts see no tenant rows.
- **`BelongsToOrganization`** trait — auto-fills `organization_id` on create from the session user; used by Department, Position, Employee, Role, AuditLog, etc.
- **`User` has no global scope** — login, password reset, and registration resolve users before a session tenant exists. Tenant filtering for users is enforced in controllers and the `company` middleware.
- **Middleware** (aliases in `bootstrap/app.php`):
  - `organization` (`SetOrganizationContext`) — runs on every web request; logs out suspended-org users, refreshes `roles.permissions` for Gate checks
  - `company` (`EnsureCompanyUser`) — rejects platform admins and users without an org on tenant routes
  - `permission:{key}` (`EnsurePermission`) — per-route permission gate
  - `platform_admin` (`EnsurePlatformAdmin`) — `/platform/*` routes only

## Request flow (tenant pages)

```
GET /employees
  → auth, verified
  → SetOrganizationContext   (load org, roles; suspend check)
  → EnsureCompanyUser
  → Route middleware: permission:employees.view
  → EmployeeController@index
      → Employee::query()     (OrganizationScope injects organization_id)
      → Gate authorize in controller where applicable
      → AuditLogger for writes
```

## RBAC

- Permission catalog is code-defined: `RoleSeederService::CATALOG` (28 keys)
- Roles are per-organization rows (`roles` + `role_permission` pivot)
- Gates registered dynamically in `AppServiceProvider` for every catalog key
- `Gate::before` short-circuits to `true` for `is_platform_admin`
- Navigation links are filtered by `$user->canPermission()` via view composer
- Phase 4 features add no new keys — billing/live board/API map onto existing permissions (see `docs/rbac.md`)

## Registration / provisioning

`POST /register` → `RegisteredOrganizationController` → `OrganizationProvisioner`:

1. Create `Organization` (slug unique, status active)
2. `RoleSeederService::createForOrganization` — sync global catalog into 4 org roles
3. Create admin user (email pre-verified) + attach `company_admin`
4. Create `General` department (`GEN`) + `EMP-001` employee linked to admin
5. `WorkforceDefaults::apply` — default work schedule + leave types
6. `BillingService::provisionDefault` — attach the `free` plan when the catalog is seeded

## API (v1)

```
POST /api/v1/login        → Sanctum token (30 days) + user
GET  /api/v1/...          → auth:sanctum + EnsureApiOrganization + permission:{key}
```

- `EnsureApiOrganization` (`app/Http/Middleware/EnsureApiOrganization.php`) resolves the caller's org and returns 403 for suspended tenants; runs before route `permission:` middleware
- Responses use Eloquent API resources (`app/Http/Resources/*`) — collections paginate, single rows include `organization_id` where relevant
- NFC punch endpoint keeps its v1 alias (`/api/v1/attendance/nfc/punch`, `X-Terminal-Key` auth)
- `tests/Feature/ApiV1Test.php::withToken()` forgets cached guard users between requests (Laravel's `RequestGuard` caches per instance)

## Real-time (live attendance board)

- Server-rendered snapshot at `GET attendance/live` → EventSource on `GET attendance/live/stream` (SSE: `retry: 3000` + `data: {json}` events, server closes after `config('live.stream_seconds')`)
- Polling fallback `GET attendance/live/poll?after={hash}` returns `{changed: false, rows: ...}` when the `md5` of the current rows matches the cursor (line 19 fallback)
- `LiveAttendanceBoard` builds rows without tenant scopes (route middleware already gates `attendance.view`), computes status from `daily_attendance`/leave, and labels overtime as `+HH:MM` against the schedule end (naive org-local wall clock, matching Phase 2)

## Billing

`BillingService` (`app/Services/BillingService.php`) is provider-agnostic: `provisionDefault`, `switchPlan`, `issueInvoice`, `ensureCanAddEmployee` (employee-count cap → validation error). A payment provider (Stripe etc.) plugs in later without touching callers. Routes are gated `settings.manage`.

## Auditing

`AuditLogger` service writes `audit_logs` rows (organization, actor, action, resource, old/new values, IP, user agent). Reads gated by `audit.view`.

## Notifications

`App\Notifications\OrganizationNotification` is the base class: `via()` returns `['database','mail']`, mail renders `resources/views/mail/notification.blade.php`. Concrete events (leave status, attendance correction, NFC status, salary change, password change, `LateArrival`) pass a short title + body. In-app center: `NotificationController` (own rows only), unread bell composed into the nav.

## Docker / CI

- `Dockerfile` — three stages: composer deps (`--ignore-platform-reqs`), Vite assets (node 22, copies vendor for Tailwind's pagination-view scan), runtime `php:8.4-apache` (pdo_sqlite + pdo_mysql, `mod_rewrite`/`mod_headers`, DocumentRoot `/var/www/html/public`)
- `docker/entrypoint.sh` — APP_KEY generation, storage perms + `storage:link`, `config:cache`/`view:cache`, `migrate --force` (gated `RUN_MIGRATIONS`), `PlanSeeder` (gated `SKIP_PLAN_SEEDING`), then execs Apache
- `docker-compose.yml` — port 8000, `app-storage` named volume so the SQLite DB survives image rebuilds
- `.github/workflows/ci.yml` — backend job (Pint check + PHPUnit on PHP 8.4), frontend job (`npm ci` + `npm run build` on Node 22); phpunit already forces `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, sqlite `:memory:`

## Testing

- `Tests\Concerns\CreatesOrganizations` helpers (`makeOrganization`, `makeUser`, …); `WorkforceDefaults::apply($org)` when a test needs a default schedule/leave types
- Feature tests: tenancy isolation, RBAC allow/deny, employee CRUD, registration, auth (Volt), profile, attendance/leave/schedules, salary records + calculator + Statistics, payslips/revisions, reports (filters, CSV, permission gates), analytics, notifications, billing, API v1, audit filters, live attendance board (172 tests)
- `RefreshDatabase` + SQLite `:memory:`; each test class enables `RefreshDatabase` itself
