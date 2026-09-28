# RBAC Matrix

Permission catalog is defined in `App\Services\RoleSeederService::CATALOG`. Roles are created per organization and synced to this catalog at provisioning time.

## Roles

| Slug | Display | Purpose |
|---|---|---|
| `company_admin` | Company Admin | Full tenant control |
| `hr` | HR | People operations |
| `manager` | Manager | Read-only team visibility |
| `employee` | Employee | Self-service only |

Platform admins (`users.is_platform_admin = 1`) bypass all permission checks (`Gate::before`).

## Permission × Role matrix

| Permission | company_admin | hr | manager | employee |
|---|:---:|:---:|:---:|:---:|
| org.view | ✅ | ✅ | ✅ | ✅ |
| org.manage | ✅ | | | |
| settings.manage | ✅ | | | |
| users.view | ✅ | ✅ | | |
| users.manage | ✅ | | | |
| employees.view | ✅ | ✅ | ✅ | |
| employees.view_team | | | ✅ | |
| employees.create | ✅ | ✅ | | |
| employees.edit | ✅ | ✅ | | |
| employees.delete | ✅ | | | |
| departments.view | ✅ | ✅ | ✅ | |
| departments.manage | ✅ | ✅ | | |
| positions.manage | ✅ | | | |
| audit.view | ✅ | | | |
| salary.view | ✅ | ✅ | | |
| salary.view_own | | | | ✅ |
| salary.edit | ✅ | | | |
| reports.view | ✅ | ✅ | ✅ | |
| dashboard.admin | ✅ | ✅ | ✅ | |
| dashboard.employee | | | | ✅ |
| attendance.view | ✅ | ✅ | ✅ | |
| attendance.manage | ✅ | ✅ | | |
| attendance.terminal | ✅ | | | |
| leave.view | ✅ | ✅ | ✅ | ✅ |
| leave.manage | ✅ | ✅ | | |
| leave.approve | ✅ | ✅ | ✅ | |
| nfc.view | ✅ | ✅ | | |
| nfc.manage | ✅ | | | |

## Enforcement points

1. **Route middleware** — `permission:{key}` (e.g. `audit-logs` → `permission:audit.view`, `settings` → `permission:settings.manage`, `users` → `permission:users.view`)
2. **Controllers** — `Gate::authorize(...)` before writes (e.g. `employees.create`); read gates where the route middleware alone is not enough: the Salary Report (and its CSV) requires `reports.view` **and** `salary.view` (managers with `reports.view` only get 403), Statistics is self-only (403 without an employee profile), the analytics payroll card renders only with `salary.view`
3. **Navigation** — sidebar links rendered only when `$user->canPermission($key)`
4. **Gates** — registered dynamically in `AppServiceProvider` from `CATALOG`

## Self-service exceptions (no extra permission needed)

- **Own attendance** — any authenticated employee can view `GET attendance/employee/{theirOwnId}`; viewing anyone else's record requires `attendance.view` (403 otherwise). The sidebar shows a "My Attendance" entry when `attendance.view` is absent.
- **Profile identity lock** — changing name/email on the profile page requires `employees.edit` (or platform admin); otherwise the fields render disabled with a "managed by HR" note.
- **Account deletion** — only users whose sole role is `employee` can delete their own account (admins/HR/manager roles are guarded server-side with a validation error).
- **NFC card history** — `GET nfc-cards/{card}/history` requires `nfc.view`; card replacement (`POST nfc-cards/{card}/replace`) requires `nfc.manage`.

## Dashboard routing

`GET /dashboard` redirects by primary role:

- has `dashboard.admin` → `/dashboard/admin`
- otherwise → `/dashboard/employee`
