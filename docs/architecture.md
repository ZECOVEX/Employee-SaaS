# Architecture

## Stack

- Laravel 13 (PHP 8.4), Livewire 3 + Volt single-file components
- Breeze (livewire stack) for auth scaffolding
- Blade + Tailwind (Vite build)
- SQLite (dev) / PostgreSQL-ready (pgsql driver installed)

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

- Permission catalog is code-defined: `RoleSeederService::CATALOG` (20 keys)
- Roles are per-organization rows (`roles` + `role_permission` pivot)
- Gates registered dynamically in `AppServiceProvider` for every catalog key
- `Gate::before` short-circuits to `true` for `is_platform_admin`
- Navigation links are filtered by `$user->canPermission()` via view composer

## Registration / provisioning

`POST /register` → `RegisteredOrganizationController` → `OrganizationProvisioner`:

1. Create `Organization` (slug unique, status active)
2. `RoleSeederService::createForOrganization` — sync global catalog into 4 org roles
3. Create admin user (email pre-verified) + attach `company_admin`
4. Create `General` department + `EMP-001` employee linked to admin

## Auditing

`AuditLogger` service writes `audit_logs` rows (organization, actor, action, resource, old/new values, IP, user agent). Reads gated by `audit.view`.

## Testing

- `Tests\Concerns\CreatesOrganizations` helpers (`makeOrganization`, `makeUser`, …)
- Feature tests: tenancy isolation, RBAC allow/deny, employee CRUD, registration, auth (Volt), profile, attendance/leave/schedules, salary records + calculator + Statistics, payslips/revisions, reports (filters, CSV, permission gates), analytics
- `RefreshDatabase` + SQLite `:memory:`
