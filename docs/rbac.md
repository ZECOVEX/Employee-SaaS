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
| overtime.view | ✅ | ✅ | ✅ | |
| overtime.manage | ✅ | ✅ | ✅ | |

## Enforcement points

1. **Route middleware** — `permission:{key}` (e.g. `audit-logs` → `permission:audit.view`, `settings` → `permission:settings.manage`, `users` → `permission:users.view`, `attendance/live` → `permission:attendance.view`, `billing` → `permission:settings.manage`, `overtime/queue` → `permission:overtime.manage`)
2. **Controllers** — `Gate::authorize(...)` before writes (e.g. `employees.create`, every `positions.*` action → `positions.manage`); read gates where the route middleware alone is not enough: the Salary Report (and its CSV) requires `reports.view` **and** `salary.view` (managers with `reports.view` only get 403), Statistics is self-only (403 without an employee profile), the analytics payroll card renders only with `salary.view`
3. **API (v1)** — same `permission:{key}` middleware on `/api/v1/*` routes behind `auth:sanctum` (e.g. `GET /api/v1/employees` → `permission:employees.view`); the caller's own profile/notifications endpoints need no extra permission
4. **Navigation** — sidebar links rendered only when `$user->canPermission($key)`
5. **Gates** — registered dynamically in `AppServiceProvider` from `CATALOG`

## Phase 4 — no new permission keys

Notifications, billing, the API, and the live attendance board reuse the existing catalog rather than adding keys:

- **Billing** (`billing.index` / `billing.plan`) → `settings.manage` (org-wide configuration territory)
- **Live attendance board** (`attendance.live.*`) → `attendance.view`
- **API v1** → mirrors the web route's key (`employees.view`, `attendance.view`, `leave.view`, `salary.view`, `audit.view`, …); `/api/v1/auth/me` and `/api/v1/notifications` are self-service (own rows only)
- **Notification center** → any authenticated verified company user (own rows only)

## Phase 5 — 2 new permission keys

`overtime.view` and `overtime.manage` (group `overtime`, catalog now 30 keys), granted to company_admin, hr and manager (not employee):

- **My Overtime** (`GET overtime`) → no permission key — self-service list of the signed-in user's own records (like own attendance)
- **Approval queue** (`overtime/queue`, `overtime/{record}/approve|reject|adjust`) → `overtime.manage`
- **Overtime policy** (`settings/overtime`) and **Scoring & risk settings** (`settings/scoring`) → `settings.manage` (org-wide configuration territory, alongside working hours)
- Statistics score/risk sections, the employee dashboard banner, and the admin dashboard score/risk/overtime widgets need no extra keys — statistics stays self-only, the admin widgets sit behind `dashboard.admin`

## Phase 6 — no new permission keys

The analytics/reporting work reuses the catalog (still 30 keys):

- **Overtime report** (`GET reports/overtime`, tab on Reports) → `reports.view`; its pay column + CSV `Estimated pay` are additionally gated by `salary.view` (same pattern as the Salary Report — managers with only `reports.view` see hours but no money)
- **Monthly Report score/status columns** and **analytics overtime trend** → `reports.view`
- **Statistics** OVERTIME section, dashboard score/[OT]/weekly-summary widgets → self-service (statistics is self-only, the employee dashboard sits behind `dashboard.employee`)

## Post-Phase 6 — no new permission keys

- **Global search** (`GET search` + navbar box) → `employees.view` for the people section, `nfc.view` for the NFC card section; the navbar input renders only when either key is held, and pure managers are team-filtered exactly like the Employees index
- **Leave attachment** → rides on `leave.view` (download route) and the un-permissioned `leave.store`; download visibility mirrors the leave index (`leave.manage` = all, approve-only = own team, otherwise own requests)
- **Payslip / monthly status notifications** → no keys; recipients are the employee themself

## Password recovery — custom gate, no catalog key

- **Forgot-password options** — the email reset link needs no auth; filing a support ticket (Option 2) validates the email against the `users` table (`organization_id` not null) and needs no gate
- **Support section** (`GET support`, `GET support/{ticket}`, `POST support/{ticket}/reply|reset-password|resolve`) → custom `Gate::support.view` (`AppServiceProvider`, deliberately outside the 30-key catalog because visibility follows `departments.manager_id`): company_admin role **or** manager role **or** manages at least one department; the sidebar "Support" entry and every staff button use the same gate
- **Admin password reset** (`POST users/{user}/reset-password`) → `users.manage` (company_admin, blocked for self); managers reach the same `PasswordResetService` through a support ticket instead
- **Must-change-password banner** → no gate; it renders for any authenticated user with `users.must_change_password` set and disappears the moment the profile form or email reset link clears it

## Phase 8 — no new catalog keys

Security & enterprise features reuse the catalog (still 30 keys):

- **API keys + SCIM tokens** (`settings/api-keys`, create/revoke endpoints) → `settings.manage`
- **Webhooks** (`settings/webhooks`, create/destroy/test endpoints) → `settings.manage`
- **SSO** (`settings/sso`, discovery test) → `settings.manage`; the login-page SSO button list and `sso/{org}` / `sso/callback` routes are **guest** routes (no gate)
- **Role permission editor** (`GET roles`, `GET/PUT roles/{role}`) → `settings.manage`; the `company_admin` role is locked (read-only) server-side regardless of the caller's intent
- **Audit CSV export** (`GET audit-logs/export`) → rides the existing `audit.view` gate (same as the audit index)
- **MFA** (`profile` enable/confirm/disable/regenerate, `mfa.challenge`) → self-service; any authenticated user manages their own MFA, the challenge gate blocks login until satisfied

### API-key & SCIM-token subjects (not `App\Models\User`)

`Auth::viaRequest('apikey'|'scim')` can authenticate an `ApiKey`/`ScimToken` model as the request "user". Catalog gates therefore check `method_exists($user, 'canPermission') && $user->canPermission($key)` instead of type-hinting `User`, and `Gate::before` / `platform.organizations` / `support.view` guard with `instanceof User`. An API key's `abilities` array is its permission set (`*` = all); a SCIM token grants everything on the SCIM routes (no catalog `permission:` middleware there — token presence + org scope is the gate). `EnsureApiOrganization` loads `roles.permissions` only for real `User` subjects.

## Phase 9 — no new permission keys

- **Stripe checkout** (`POST billing/stripe/checkout`) → `settings.manage` (same as plan switching); `POST api/stripe/webhook` is **unauthenticated** (Stripe calls it) and signature-verified via `STRIPE_WEBHOOK_SECRET`

## Self-service exceptions (no extra permission needed)

- **Own attendance** — any authenticated employee can view `GET attendance/employee/{theirOwnId}`; viewing anyone else's record requires `attendance.view` (403 otherwise). The sidebar shows a "My Attendance" entry when `attendance.view` is absent.
- **Profile identity lock** — changing name/email on the profile page requires `employees.edit` (or platform admin); otherwise the fields render disabled with a "managed by HR" note.
- **Account deletion** — only users whose sole role is `employee` can delete their own account (admins/HR/manager roles are guarded server-side with a validation error).
- **NFC card history** — `GET nfc-cards/{card}/history` requires `nfc.view`; card replacement (`POST nfc-cards/{card}/replace`) requires `nfc.manage`.

## Dashboard routing

`GET /dashboard` redirects by primary role:

- has `dashboard.admin` → `/dashboard/admin`
- otherwise → `/dashboard/employee`
