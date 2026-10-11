# Architecture

## Stack

- Laravel 13 (PHP 8.4), Livewire 3 + Volt single-file components
- Breeze (livewire stack) for auth scaffolding
- Blade + Tailwind (Vite build)
- SQLite (dev) / PostgreSQL-ready (pgsql driver installed)
- Laravel Sanctum for the versioned JSON API (`/api/v1`, personal access tokens) + custom `apikey`/`scim` guards (Phase 8)
- Raw Laravel HTTP client for Stripe (Phase 9 — `stripe/stripe-php` deliberately NOT installed so `Http::fake` works)
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
- Gates registered dynamically in `AppServiceProvider` for every catalog key; callbacks do **not** type-hint `App\Models\User` — they check `method_exists($user, 'canPermission')` so API-key / SCIM-token subjects authenticate too
- `Gate::before` short-circuits to `true` for `instanceof User && $user->is_platform_admin` (ApiKey/ScimToken expose a public `$is_platform_admin = false` property so Blade view composers can read it safely)
- Navigation links are filtered by `$user->canPermission()` via view composer
- Phase 4 features add no new keys — billing/live board/API map onto existing permissions (see `docs/rbac.md`)
- Phase 5 adds `overtime.view` / `overtime.manage` (admin, HR, manager); settings pages stay on `settings.manage`, self-service overtime needs no key
- Phases 8–9 add no new keys — API keys, webhooks, SSO, the role editor and Stripe checkout all sit on `settings.manage`; MFA and the role editor's per-role catalog are self-service / `settings.manage` respectively; audit CSV export rides `audit.view`

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
GET  /api/v1/...          → auth:sanctum,apikey + EnsureApiOrganization + permission:{key}
```

- `EnsureApiOrganization` (`app/Http/Middleware/EnsureApiOrganization.php`) resolves the caller's org and returns 403 for suspended tenants; runs before route `permission:` middleware; loads `roles.permissions` only for real `User` subjects (API keys skip the eager-load)
- Responses use Eloquent API resources (`app/Http/Resources/*`) — collections paginate, single rows include `organization_id` where relevant
- NFC punch endpoint keeps its v1 alias (`/api/v1/attendance/nfc/punch`, `X-Terminal-Key` auth)
- `tests/Feature/ApiV1Test.php::withToken()` forgets cached guard users between requests (Laravel's `RequestGuard` caches per instance)

## Authentication guards (Phase 8)

- **`apikey`** — `Auth::viaRequest('apikey', fn ($request) => ApiKey::resolveFromRequest($request))` in `AppServiceProvider`; the guard **must also exist in `config/auth.php`** (Laravel 13's `resolve()` calls `getConfig()` first and throws "Auth guard [x] is not defined" otherwise — just the `'driver' => 'apikey'` entry, no provider). Full key (`emsak_…`) is presented as `Authorization: Bearer` or `X-Api-Key`; the `prefix` column is the non-secret lookup, `key_hash` the sha256. `ApiKey::canPermission()` checks the `abilities` array (`*` grants everything); org scoping is identical to Sanctum (`EnsureApiOrganization`). Catalog gates must not type-hint `User` — they call `method_exists($user, 'canPermission')` so both User and ApiKey subjects pass
- **`scim`** — `Auth::viaRequest('scim', fn ($request) => ScimToken::resolveFromRequest($request))`, same `config/auth.php` requirement; `/api/scim/v2/*` uses `auth:scim,api_organization`; SCIM endpoints carry no catalog `permission:` middleware (token presence + org scope is the gate, `ScimToken::canPermission()` is true while active)

## SCIM 2.0 (Phase 8)

`ScimController` under `/api/scim/v2` (bearer SCIM token): `ServiceProviderConfig`, `ResourceTypes`, `Users` (create/list/show/patch/delete — create auto-attaches the `employee` role and picks the name from `name.formatted` or given+family; PATCH `active=false` deletes since there is no deactivated state; all writes audited `scim.user_*`), `Groups` (read-only stub). Validated SCIM payloads arrive **nested** (`name.formatted` → `$data['name']['formatted']`) — `ScimController::resolveName()` normalizes.

## MFA (Phase 8)

- `TotpService` — RFC 6238 TOTP: base32 secret, 30s period, 6 digits, ±1 window drift check, `otpauth://` URL for the QR (rendered as SVG by `BaconQrCode` on the profile page)
- `MfaService` — setup lifecycle: `beginSetup` stages the secret in session (`mfa.pending_secret`), `confirmSetup` validates a code then persists `users.mfa_secret` (encrypted cast) + `mfa_enabled`, `consumeRecoveryCode` hashes-and-matches against `users.mfa_recovery_codes` (8 × 10-char codes, sha256-stored), `regenerateRecoveryCodes` rotates them, `disable` clears both columns; challenge staging keys are `mfa.pending_user_id` + `mfa.pending_secret`
- **Login flow**: `LoginForm::authenticate` (Livewire) checks `mfa_enabled` after the password passes — if set, it logs the (just-authenticated) user out and stages the challenge in session, redirecting to the `mfa.challenge` Volt page; the challenge (rate limited) accepts a TOTP code or a recovery code, then logs the staged user in; a stale/absent session key bounces back to `/login`
- Profile card (`livewire/profile/mfa-form.blade.php`, mounted on the profile page): setup (QR + code confirm), disable, regenerate recovery codes — self-service, no catalog key

## Webhooks (Phase 8)

- `WebhookService::dispatch(event, orgId, payload)` filters `webhook_subscriptions` by `events` (json list or `['*']` via `WebhookSubscription::matches`) + `is_active`, then queues `DeliverWebhook` per matching subscription
- `DeliverWebhook` (queued; phpunit runs the queue sync): POSTs the raw JSON body with `Content-Type: application/json`, `X-Webhook-Event`, and `X-Webhook-Signature: sha256=<HMAC-SHA256 of the raw body with the subscription secret>`; records `last_delivery_at` / `last_status` (HTTP code or `failed`)
- Events (`WebhookEvent` constants): `employee.created|updated|deleted`, `leave.requested|approved|rejected`, `overtime.approved|rejected`, `attendance.punched`, `payslip.finalized`, `subscription.changed` — dispatched from the respective controllers/services
- `WebhookController` (management UI, `settings.manage`) enforces an SSRF guard on create/update: rejects `localhost`, `127.*`, `10.*`, `192.168.*`, `169.254.169.254`; the per-subscription secret is generated on create and shown **once** (session flash, gone on reload)

## OIDC SSO (Phase 8)

- Per-org settings in `organizations.settings`: `sso_enabled`, `sso_name`, `sso_discovery_url`, `sso_client_id`, `sso_client_secret` (**stored `encrypt()`ed**), `sso_allowed_domains` (array); managed by `SsoSettingsController` (`settings.manage`) with a discovery-URL test (fetches `<discovery>/.well-known/openid-configuration`)
- Login page (`pages.auth.login`) lists SSO-enabled orgs (filtered in PHP from a bounded org query to avoid DB-specific JSON boolean casting); each renders a "Sign in with SSO" button → `GET sso/{organization}` (**route order matters: `sso/callback` is registered first**, otherwise the `{organization}` wildcard shadows it and 404s)
- `SsoLoginController::redirectToProvider` — fetches the discovery document, stores `state` + org id in session, redirects to the `authorization_endpoint`
- `SsoLoginController::callback` — verifies `state`, exchanges the code at the `token_endpoint` (raw HTTP), reads the identity from the **userinfo endpoint** (no local JWT verification — deliberate), enforces the domain allowlist, then **links** an existing same-org user by email or **auto-provisions** a new user with the `employee` role; logs in and regenerates the session
- SAML deferred (spec allows OIDC-only)

## Role permission editor (Phase 8)

`RolePermissionController` — index (all org roles + permission counts), edit (checkbox matrix vs the 30-key catalog), update (validated `permissions.*` against `CATALOG`, synced in a transaction, unknown keys rejected by validation); the `company_admin` role is **locked** server-side (read-only, forced to the full catalog); hr/manager/employee are freely editable; changes audited.

## Audit CSV export (Phase 8)

`AuditLogController::export` — same filters as the index (action, resource type, actor, from, to), streamed via `response()->streamDownload` + `fputcsv`; gated `audit.view` (same as the index; returns Symfony's `StreamedResponse`).

## Real-time (live attendance board)

- Server-rendered snapshot at `GET attendance/live` → EventSource on `GET attendance/live/stream` (SSE: `retry: 3000` + `data: {json}` events, server closes after `config('live.stream_seconds')`)
- Polling fallback `GET attendance/live/poll?after={hash}` returns `{changed: false, rows: ...}` when the `md5` of the current rows matches the cursor (line 19 fallback)
- `LiveAttendanceBoard` builds rows without tenant scopes (route middleware already gates `attendance.view`), computes status from `daily_attendance`/leave, and labels overtime as `+HH:MM` against the schedule end (naive org-local wall clock, matching Phase 2)

## Billing (Phase 4 + Phase 9 Stripe)

`BillingService` (`app/Services/BillingService.php`) is provider-agnostic: `provisionDefault`, `switchPlan`, `issueInvoice`, `ensureCanAddEmployee` (employee-count cap → validation error). Routes are gated `settings.manage`.

**Phase 9 — Stripe** (raw Laravel HTTP client, NOT the `stripe/stripe-php` package — keeps `Http::fake` testable in tests):

- `StripeService` (`app/Services/Billing/StripeService.php`) — `isConfigured()` (secret present), `checkoutUrl(org, plan)` (POST `/v1/checkout/sessions`, `mode: subscription`, success/cancel back to Billing), `verifyWebhookSignature(request)` (HMAC-SHA256 over the `t=…,v1=…` payload with 300s tolerance, raw body), `activateFromCheckout(session)` (activates the plan + subscription in place), `syncSubscription(sub)` (status + period + stripe ids), `markInvoicePaid(invoice)` (marks the open invoice paid via `Invoice::withoutGlobalScopes()` — the webhook has no session user, so the org global scope would 1=0), `ensureCustomer(org)`
- Config: `services.stripe.secret` (`STRIPE_SECRET_KEY`), `services.stripe.webhook_secret` (`STRIPE_WEBHOOK_SECRET`); unconfigured → the checkout button hides and the webhook returns 503
- `POST billing/stripe/checkout` (`settings.manage`) → redirect to Stripe Checkout; plan switching for free plans stays a direct in-app action
- `POST api/stripe/webhook` — **unauthenticated** (throttled; `StripeWebhookController`): 400 on bad signature; handles `checkout.session.completed`, `customer.subscription.updated|deleted`, `invoice.paid`
- `plans.stripe_price_id` (nullable) — the Stripe price for paid plans; `subscriptions.stripe_customer_id`/`stripe_subscription_id` + `organizations.settings.stripe_customer_id` track the Stripe objects; Billing page shows checkout success/canceled banners

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
- Feature tests: tenancy isolation, RBAC allow/deny, employee CRUD, registration, auth (Volt), profile, attendance/leave/schedules, salary records + calculator + Statistics, payslips/revisions, reports (filters, CSV, permission gates), analytics, notifications, billing, API v1, audit filters, live attendance board, overtime detection/approvals/comp-time, attendance score engine, scoring settings, risk detection, monthly status snapshots, risk/statistics/dashboard wiring, Phase 6 statistics sections + overtime report + monthly score/status columns + analytics trend + dashboard score/[OT]/weekly-summary widgets, global search (RBAC sections, tenant isolation), leave attachments (upload/download visibility), Phase 7 payslip + monthly-status notifications, field-sync/audit/notification gap fixes, MANAGER_HR two-stage approval + EXPIRED expiry + monthly cap, positions admin UI (RBAC, audits, tenancy, employee-form round trip), support tickets + password recovery (two-option forgot password, Gate::support.view, must-change-password banner lifecycle, admin/manager resets), Phase 8 MFA (TOTP setup/confirm/login-challenge/recovery-codes), API keys (auth, abilities, org scope, revocation), SCIM 2.0 (token auth, user CRUD, active=false delete, audits), webhooks (dispatch/sign/deliver, event filtering, SSRF guard), OIDC SSO (state check, domain allowlist, link vs auto-provision, login-page org list), role permission editor (validation, company_admin lock), audit CSV export (filters + gating), Phase 9 Stripe (checkout URL, webhook signature, checkout-completed activation, invoice paid) (**350 tests**)
- `RefreshDatabase` + SQLite `:memory:`; each test class enables `RefreshDatabase` itself
