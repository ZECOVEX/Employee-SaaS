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

- Permission catalog is code-defined: `RoleSeederService::CATALOG` (30 keys)
- Roles are per-organization rows (`roles` + `role_permission` pivot)
- Gates registered dynamically in `AppServiceProvider` for every catalog key
- `Gate::before` short-circuits to `true` for `is_platform_admin`
- Navigation links are filtered by `$user->canPermission()` via view composer
- Phase 4 features add no new keys — billing/live board/API map onto existing permissions (see `docs/rbac.md`)
- Phase 5 adds `overtime.view` / `overtime.manage` (admin, HR, manager); settings pages stay on `settings.manage`, self-service overtime needs no key

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
POST /api/v1/auth/login  → Sanctum token (30 days) + user
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

## Overtime (Phase 5, §73)

- `OvertimeService::recalculate(employee, date)` runs from `AttendanceService::deriveDaily` (and bulk re-derives), resolving the effective `OvertimePolicy` (`policyFor` — latest row overlapping the date, else unsaved `OvertimePolicy::defaults()`), computing raw minutes (after schedule end for `AFTER_OFFICE_END`, above expected for `ABOVE_EXPECTED_HOURS`), subtracting an optional late offset, applying the start threshold + rounding, daily/weekly/monthly caps (weekly + monthly trim the newest days first via `reduceToCap`, never touching locked decisions) and day-type multiplier, then snapshotting the hourly rate (`rate_base` × effective `SalaryRecord`) and estimated pay. Records with `countable > 0` become `PENDING` or `AUTO_APPROVED` per `approval_mode`; status transitions notify the employee
- Weekly cap rewrites the whole week's records after any change (`applyWeeklyCap`); approved records mirror their minutes into `daily_attendance.overtime_minutes`
- Approvals: `OvertimeController` queue (`overtime.manage`) → `approve`/`reject`/`adjust` in `OvertimeService`, each writing `overtime_approvals` + §24 audit + notification; `MANAGER_HR` runs two human stages (manager-or-admin first — PENDING + `OvertimeStaged` notice — then a different HR/admin, system sweeps bypass both), the expiry sweep's ESCALATE sets status `EXPIRED` (locked, still decidable late, reported as pending); COMP_TIME policy accrues `comp_time_entries` on approval (`syncCompTime`)
- `overtime:expire-pending` (daily 00:10) resolves records older than `pending_expiry_days` per `pending_expiry_action`

## Scoring & risk (Phase 5, §15/§16/§17)

- `ScoreService::compute(employee, month)` builds the §16 score from the month ledger (attendance rate, punctuality, working hours, leave compliance; approved leave excluded from attendable days; per-day worked cap), weighted by org `score_weight_*` settings, plus a separately capped OT score bonus (`overtime_score_bonus_max`); returns `null` when no scheduled day has elapsed. Effective schedule and holidays are cached per instance (`scheduleCache`/`holidayCache`) so the admin dashboard can loop over employees cheaply
- `RiskService` owns all threshold configuration (`config()`/`defaults()` with `statusFor()` band lookup) and detection: `evaluateLateRisk` (weekly late days vs `late_risk_*`), `evaluateOvertimeRisk` (`ot_risk_*` weekly/monthly minute limits + consecutive-day streak). Both are hooked from `AttendanceService::deriveDaily` and `OvertimeService::recalculate`; `risk:evaluate` (daily 00:20) sweeps windows missed while no punches happened. Each hit upserts a suppressible `risk_events` row (unique per employee × type × window) and — when new or suppression is off — notifies `HighRiskAttendance`/`OvertimeLimitNotice` to the configured recipients (employee, manager, HR)
- `MonthlyStatusService::generate(month)` snapshots finalized `employee_monthly_statuses` rows; command `status:generate` runs monthly on the 1st at 00:30 (after `overtime:expire-pending`), skipping existing rows (§17 rows are never rewritten) and employees without a score

## Reporting & analytics (Phase 6, §39/§41)

- `ReportService::rangeTotals(org, employee, from, to)` is the shared aggregation behind the Statistics sections and the dashboard weekly summary: expected minutes count only elapsed scheduled days (work schedule minus break, holidays excluded), worked minutes are capped per day so overtime never inflates the worked figure, absent = elapsed scheduled − attended − leave. `scheduleExpectedMinutes()` keeps the schedule math in one place
- Statistics (`statistics.index`, self-only) validates `month` + `week` (Monday-anchored): MONTHLY OVERVIEW, WEEKLY ATTENDANCE (prev/next nav), and the OVERTIME / EXTRA TIME section fed by `OvertimeService::summaryForRange` — status splits, weekday vs weekend/holiday minutes, multipliers, earnings behind `show_pay`, configured limit usage (`ot_risk_weekly_minutes`/`ot_risk_monthly_minutes`, approved + pending), per-day breakdown with approver; non-eligible employees get a muted note instead of numbers
- Reports (`ReportController`, `reports.view`): Attendance, Monthly (live Score §16 + Status band §15 + overtime approved/pending split from one grouped pass over `overtime_records`), Overtime (§41 — employee/department/date/day-type/status filters, `APPROVED` includes `AUTO_APPROVED`, pay column + CSV `Estimated pay` gated separately by `salary.view`), Salary (`reports.view` **and** `salary.view`); all four export via `reports/{type}/export` (`streamDownload` + `fputcsv`)
- Analytics (`analytics.index`) adds a 6-month overtime trend (`analytics()['overtime']`, approved vs pending countable hours) to the attendance trend, department, weekday and payroll cards; charts are pure CSS
- Employee dashboard (§39): score card (`ScoreService::compute` + `RiskService::statusFor` band), worked-vs-expected subtitle, [OT] card (month/today/pay, `show_pay` gated, hidden entirely when not eligible), calendar OT dots + tooltips (OT status, rejection reason, OT pay, per-day deduction from `SalaryCalculator::estimate` breakdown), recent-attendance OT line, and a This Week summary (worked/expected, late minutes, late deduction, week overtime split + pay)
- Month/week selector state and export links pass the same filter query string, so CSV rows always match the on-screen page

## Scheduled commands

| Command | Schedule | Purpose |
|---|---|---|
| `overtime:expire-pending` | daily 00:10 | resolve pending overtime past the policy window |
| `risk:evaluate {--date}` | daily 00:20 | sweep configured late-risk / overwork rules |
| `status:generate {--month}` | monthly 1st 00:30 | finalized `employee_monthly_status` snapshots (§17) |

## Auditing

`AuditLogger` service writes `audit_logs` rows (organization, actor, action, resource, old/new values, IP, user agent). Reads gated by `audit.view`.

## Notifications

`App\Notifications\OrganizationNotification` is the base class: `via()` returns `['database','mail']`, mail renders `resources/views/mail/notification.blade.php`. Concrete events (leave status, attendance correction, NFC status, salary change, password change, `LateArrival`, overtime detection, manager-stage approval `OvertimeStaged`, overtime decision `OvertimeDecision`, payslip ready, monthly status ready, `HighRiskAttendance`, `OvertimeLimitNotice`) pass a short title + body. In-app center: `NotificationController` (own rows only), unread bell composed into the nav.

## Docker / CI

- `Dockerfile` — three stages: composer deps (`--ignore-platform-reqs`), Vite assets (node 22, copies vendor for Tailwind's pagination-view scan), runtime `php:8.4-apache` (pdo_sqlite + pdo_mysql, `mod_rewrite`/`mod_headers`, DocumentRoot `/var/www/html/public`)
- `docker/entrypoint.sh` — APP_KEY generation, storage perms + `storage:link`, `config:cache`/`view:cache`, `migrate --force` (gated `RUN_MIGRATIONS`), `PlanSeeder` (gated `SKIP_PLAN_SEEDING`), then execs Apache
- `docker-compose.yml` — port 8000, `app-storage` named volume so the SQLite DB survives image rebuilds
- `.github/workflows/ci.yml` — backend job (Pint check + PHPUnit on PHP 8.4), frontend job (`npm ci` + `npm run build` on Node 22); phpunit already forces `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, sqlite `:memory:`

## Testing

- `Tests\Concerns\CreatesOrganizations` helpers (`makeOrganization`, `makeUser`, …); `WorkforceDefaults::apply($org)` when a test needs a default schedule/leave types
- Feature tests: tenancy isolation, RBAC allow/deny, employee CRUD, registration, auth (Volt), profile, attendance/leave/schedules, salary records + calculator + Statistics, payslips/revisions, reports (filters, CSV, permission gates), analytics, notifications, billing, API v1, audit filters, live attendance board, overtime detection/approvals/comp-time, attendance score engine, scoring settings, risk detection, monthly status snapshots, risk/statistics/dashboard wiring, Phase 6 statistics sections + overtime report + monthly score/status columns + analytics trend + dashboard score/[OT]/weekly-summary widgets, global search (RBAC sections, tenant isolation), leave attachments (upload/download visibility), Phase 7 payslip + monthly-status notifications, field-sync/audit/notification gap fixes, MANAGER_HR two-stage approval + EXPIRED expiry + monthly cap, positions admin UI (RBAC, audits, tenancy, employee-form round trip) (279 tests)
- `RefreshDatabase` + SQLite `:memory:`; each test class enables `RefreshDatabase` itself
