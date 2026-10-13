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

## Email / SMTP settings (Post-Phase 17)

- Per-org outgoing mail in `organizations.settings`: `mail_enabled`, `mail_host`, `mail_port`, `mail_encryption` (`tls` | `ssl` | `none`), `mail_username`, `mail_password` (**stored `encrypt()`ed**), `mail_from_address`, `mail_from_name`; managed by `MailSettingsController` (`settings.manage`) at `settings/email` with a "Send test email" action (`App\Notifications\MailTest`, mail channel only)
- `App\Services\MailSettings::apply($organization)` overrides the runtime `mail.*` config (`mail.default` → `smtp`, host/port/scheme/credentials, from identity) when the org has SMTP enabled — so admin-entered webmail/SMTP servers (Gmail, Outlook, Zoho, self-hosted relays) are used for all outgoing email
- Applied automatically by a `NotificationSending` listener (registered in `AppServiceProvider`) that runs for **every mail-channel notification** — covering all `OrganizationNotification` subclasses, queued sends, and Laravel's default `ResetPassword` — by resolving the notifiable's organization; non-mail channels and org-less platform admins are untouched. `Organization::settingsArray()` is the typed accessor the controller/service use for the settings blob
- **Per-feature allow/deny (Post-Phase 18)** — the same listener resolves a feature key (`MailSettings::featureKeyFor()` ← `OrganizationNotification::featureKey()` (defaults to `group()`, overridden per class) + special cases `ResetPassword → password_resets`, `MailTest → null`) and returns `false` for disabled features, cancelling **only the mail channel** (in-app/database notifications still deliver). Keys: `leave`, `leave_notices`, `attendance`, `overtime`, `salary`, `monthly_progress`, `risk`, `employment`, `security`, `nfc`, `support`, `password_resets` (`MailSettings::FEATURES`); stored in `organizations.settings.mail_features` (array of enabled keys; **unset = all on**, empty = all off), edited via the checklist on `settings/email` and audited with the SMTP save

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
- Analytics (`analytics.index`) adds a 6-month overtime trend (`analytics()['overtime']`, approved vs pending countable hours) to the attendance trend, department, weekday and payroll cards; charts are pure CSS. The enrichment pass adds six more org-scoped aggregates to `analytics()`: workforce mix (employment-type bars, status pills, 30-day joiners), current-month attendance-status distribution + worked-vs-expected workload block (elapsed scheduled days × `scheduleExpectedMinutes`), leave analytics (6-month request volumes by status, approved days by type YTD, open queue), performance score bands from the latest finalized `employee_monthly_statuses` month (avg KPI + empty state pre-run), and open risk events grouped by type/level; the KPI strip grew from 4 to 8 tiles and the shared `reports/_tabs` partial links Analytics from every report page. Payroll keeps its `salary.view` gate; all queries stay `withoutGlobalScopes()` + `organization_id`. Blade caveat: inline `@php(...)` followed later by a `@endphp` block makes `storePhpBlocks`' `@php(.*?)@endphp` regex swallow everything between them — keep at most one `@php...@endphp` block per view (top of file), use inline `@php(...)` elsewhere
- Employee dashboard (§39): score card (`ScoreService::compute` + `RiskService::statusFor` band), worked-vs-expected subtitle, [OT] card (month/today/pay, `show_pay` gated, hidden entirely when not eligible), calendar OT dots + tooltips (OT status, rejection reason, OT pay, per-day deduction from `SalaryCalculator::estimate` breakdown), recent-attendance OT line, and a This Week summary (worked/expected, late minutes, late deduction, week overtime split + pay)
- Dashboard hover drill-downs (Post-Phase 19): `<x-hover-panel>` wraps every admin stat tile (15 datasets built in `DashboardController::admin()` — present/late/absent/on-leave with names + `Arrived HH:MM`, 7-day avg, employee/user counts + department mix, score bands, at-risk, risk alerts, overtime today/pending/month) and the 5 employee month tiles (per-day lists + balance by type). Rows are rendered server-side into the DOM (Alpine only toggles visibility and clamps the panel to the viewport), so the drill-down content doubles as test surface via `viewData('drilldowns')` + `assertSee`
- Month/week selector state and export links pass the same filter query string, so CSV rows always match the on-screen page

## Scheduled commands

| Command | Schedule | Purpose |
|---|---|---|
| `overtime:expire-pending` | daily 00:10 | resolve pending overtime past the policy window |
| `risk:evaluate {--date}` | daily 00:20 | sweep configured late-risk / overwork rules |
| `status:generate {--month}` | monthly 1st 00:30 | finalized `employee_monthly_status` snapshots (§17) |
| `privacy:prune` | daily 03:00 | delete login history + audit rows past the org retention windows (§43) |

## Auditing

`AuditLogger` service writes `audit_logs` rows (organization, actor, action, resource, old/new values, IP, user agent). Reads gated by `audit.view`.

## Notifications

`App\Notifications\OrganizationNotification` is the base class: `via()` returns `['database','mail']`, mail renders `resources/views/mail/notification.blade.php`. Concrete events (leave status, `LeaveNoticeAdded`, attendance correction, NFC status, salary change, password change, `LateArrival`, overtime detection, manager-stage approval `OvertimeStaged`, overtime decision `OvertimeDecision`, payslip ready, monthly status ready, `HighRiskAttendance`, `OvertimeLimitNotice`, `EmploymentStatusChanged`) pass a short title + body. In-app center: `NotificationController` (own rows only), unread bell composed into the nav.

## Attendance self-report (Post-Phase 11)

Terminal-down fallback, off unless the org enables `settings.self_attendance_enabled` (Settings checkbox). `POST attendance/self-report` (throttled 6/min, no permission key — self-service) → `AttendanceService::recordSelfReport`: today-only in the org timezone, at most one self-reported `MANUAL_IN` and one `MANUAL_OUT` per day, guarded inside a transaction with `lockForUpdate` on the day's active events (double-submit safe), events carry `source = 'self'` and flow through the normal `deriveDaily` pipeline, and each report writes an `attendance.self_reported` audit row. Employee-side submissions are immutable (no edit/delete route); the only correction path is the audited admin `attendance.manage` workflow.

## Employment status visibility (Post-Phase 11)

`employees.status` inactive/terminated never blocks sign-in (only `users.deactivated_at` does). The nav (`livewire.layout.navigation::employmentStatus()`) shows a red/gray status pill next to the user's name on every page; the employee dashboard renders a red "You have been terminated" block or an amber inactive block above the risk banners; and `EmployeeController::update` sends an `EmploymentStatusChanged` database+mail notification whenever the status moves to inactive/terminated (not on reactivation or unrelated edits).

## Leave re-review & notices (Post-Phase 12)

Approvers can change a leave decision any number of times and post notices the employee sees. `LeaveService::approve/reject` accept any status except `CANCELLED` (terminal) and keep the ledger honest across transitions: PENDING/REJECTED → APPROVED deducts `leave_balances.used_days` (balance re-checked under `lockForUpdate`, unpaid/zero-quota types skipped); APPROVED → REJECTED refunds the deduction (clamped at 0 — unsigned column) and deletes the LEAVE `daily_attendance` rows created by the approval (rows with a real check-in are untouched); same-status re-reviews are idempotent (no double deduction, no duplicate notification — `LeaveStatusChanged` fires only on an actual transition). Audit rows record the real previous status.

Notices are an append-only `leave_notices` thread: `POST leave/{leaveRequest}/notice` (`leave.approve` + team-scope `authorizeReview`) creates a row, writes a `leave.noticed` audit, and sends the employee a `LeaveNoticeAdded` database+mail notification. The detail page `GET leave/{leaveRequest}` (owner or reviewer scope, `leave.view`) shows the request, review history, the full notice thread, the notice composer, and Approve/Reject forms with optional notes (`review_note`, now actually sent from the UI — it was previously always null); quick Approve/Reject also remain on the index for PENDING rows. Immutable from the employee side: employees can read notices but never post them.

## Leave editing (Post-Phase 13)

Reviewers can edit the type, dates and reason of any non-cancelled leave request — including after approval ("reduce anytime"). `GET leave/{leaveRequest}/edit` + `PUT leave/{leaveRequest}` sit behind `permission:leave.approve` and the same `authorizeReview()` scope as approve/reject (`leave.manage`/platform admin anything, approve-only managers their own team); `CANCELLED` is terminal (403 on both verbs). Days are always recomputed from the submitted range via `workingDays()` (weekends excluded, never taken from the input) and there is deliberately no `after_or_equal:today` rule — admins may adjust past ranges.

`LeaveService::edit()` runs in one `DB::transaction` under `lockForUpdate`. APPROVED edits reconcile the ledger: the old deduction is refunded (param-based `refundDays()`, now shared with reject-after-approve — clamped at 0, zero-quota types skipped), the old LEAVE attendance stamps are deleted (rows with a real check-in untouched), the new range is re-checked and deducted (`deductDays()` throws — rolling back the refund too — when it no longer fits the balance), and the new range is stamped; type changes move days between buckets, year-boundary edits refund the old year's bucket and deduct the new one (balance rows created on demand by `ensureBalances`), and unpaid/zero-quota types never touch `leave_balances`. PENDING/REJECTED edits only update the row — the deduction happens at approval. Every save writes a `leave.edited` audit row (old → new), notifies the employee via `LeaveStatusChanged` ("updated"), and the controller re-dispatches the `LEAVE_APPROVED` webhook when the request is still approved. The edit form (`leave/edit.blade.php`) shows the current values plus a banner explaining the balance/stamp consequences for approved requests; the show page's Review card links it for reviewers.

## Docker / CI

- `Dockerfile` — three stages: composer deps (`--ignore-platform-reqs`), Vite assets (node 22, copies vendor for Tailwind's pagination-view scan), runtime `php:8.4-apache` (pdo_sqlite + pdo_mysql, `mod_rewrite`/`mod_headers`, DocumentRoot `/var/www/html/public`)
- `docker/entrypoint.sh` — APP_KEY generation, storage perms + `storage:link`, `config:cache`/`view:cache`, `migrate --force` (gated `RUN_MIGRATIONS`), `PlanSeeder` (gated `SKIP_PLAN_SEEDING`), then execs Apache
- `docker-compose.yml` — port 8000, `app-storage` named volume so the SQLite DB survives image rebuilds
- `.github/workflows/ci.yml` — backend job (Pint check + Larastan + PHPUnit on PHP 8.4), frontend job (`npm ci` + `npm run build` on Node 22); phpunit already forces `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, sqlite `:memory:`

## Observability (Phase 10, §44)

- `GET /health` — liveness probe (process up, no dependencies)
- `GET /ready` — readiness probe: DB `SELECT 1` + cache store check; 503 with a JSON body when either fails
- `SecurityHeaders` middleware (global): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, HSTS on TLS requests
- `RequestId` middleware (global): echoes `X-Request-Id` (accepts an inbound one) and `Log::shareContext`s `request_id`/`user_id`/`organization_id` so every log line in a request correlates
- JSON error envelope for `api/*` routes: ValidationException 422, AuthorizationException 403, ModelNotFound 404, ThrottleRequestsException 429 (web routes keep the normal Laravel flow)

## Login geolocation (Phase 10, §71)

- `GeoLocator` resolves an IP to `{country, division, area}` with drivers `null` (default), `static` (longest-prefix CIDR match against `config/geoip.php` ranges — offline/dev), and `maxmind` (GeoLite2 mmdb when the file exists and geoip2 is installed); results cached per IP for 1h
- `LoginHistoryService::record` writes a `user_login_history` row for every outcome and, on SUCCESS, updates the denormalized `users.last_login_*` snapshot
- Hooked into: web `LoginForm` (FAILED/SUCCESS + deactivated block), MFA challenge page (MFA_FAILED/SUCCESS), `Api\AuthController::login`, `SsoLoginController::callback`
- Surfacing: profile "Security information" section (last login + 5 recent), `audit-logs/logins` page with audit nav tabs, employee-profile audit tab
- Approximate and security-signal-only — never the sole basis for blocking access (disclosed on `/privacy`)

## Data privacy (Phase 10, §43)

- Self-service account deletion is **prohibited for every role**; the profile delete section carries the admin-contact note + the self data-export link
- Admin-controlled deactivation: `users.deactivated_at`; `POST users/{user}/deactivate|activate` (`users.manage`, self and last-company-admin protected, audited); blocks web/API/SSO/MFA login paths, each recording a FAILED history row
- Export: `PrivacyService::exportUserData` builds a JSON dump (profile, employee record, recent logins); `GET profile/export` (self) and `GET users/{user}/export` (`users.manage`)
- Profile customization (Post-Phase 19): `PUT profile` (`profile.update`, self-service for every authenticated user) updates `users.avatar_path` (JPG/PNG ≤ 2 MB on the public disk under `avatars/`, replaced/removed files deleted) and `users.phone`; audited as `profile.updated`; the nav avatar component (`<x-avatar>`) renders the photo or an initials fallback
- Retention: `privacy:prune` (daily 03:00) deletes login history past `login_retention_days` (default 180) and audit logs past `audit_retention_days` (default 365); orgs override both on the Settings page (0 = platform default)
- Public `/privacy` page discloses IP-derived location collection, retention windows and the export/removal workflow

## Terminal geo-security (Phase 10, §32)

- `attendance_terminals` optionally stores a GPS pin (`latitude`/`longitude`) + `radius_meters` and an `allowed_ips` list (exact IPv4 or CIDR)
- Punch API (`/api/attendance/nfc/punch`): source IP checked against the allowlist via `GeoLocator::matchesList` (403 when off-list); when `require_location` is on the client must send `latitude`/`longitude` and it must fall inside the haversine radius (422 missing fix, 403 outside radius)

## Development requirements (Phase 10, §62)

- `declare(strict_types=1)` across all 201 PHP files under app/routes/bootstrap/config/database
- Larastan (`larastan/larastan` 3.13, level 5) with `phpstan.neon` + `phpstan-baseline.neon` (327 baselined Eloquent-relation generics; new code must stay clean); `composer analyse`
- Form Requests for the main write endpoints (`Store/UpdateDepartmentRequest`, `Store/UpdatePositionRequest`, `StoreTerminalRequest`) — tenant-scoped unique/exists rules live in the request
- `docs/openapi.yaml` — OpenAPI 3.1 for the /api/v1 surface + NFC punch (bearer + API-key security schemes)
- `composer format|analyse|check` scripts; CI runs Pint, Larastan, PHPUnit

## Testing

- `Tests\Concerns\CreatesOrganizations` helpers (`makeOrganization`, `makeUser`, …); `WorkforceDefaults::apply($org)` when a test needs a default schedule/leave types
- Feature tests: tenancy isolation, RBAC allow/deny, employee CRUD, registration, auth (Volt), profile, attendance/leave/schedules, salary records + calculator + Statistics, payslips/revisions, reports (filters, CSV, permission gates), analytics, notifications, billing, API v1, audit filters, live attendance board, overtime detection/approvals/comp-time, attendance score engine, scoring settings, risk detection, monthly status snapshots, risk/statistics/dashboard wiring, Phase 6 statistics sections + overtime report + monthly score/status columns + analytics trend + dashboard score/[OT]/weekly-summary widgets, global search (RBAC sections, tenant isolation), leave attachments (upload/download visibility), Phase 7 payslip + monthly-status notifications, field-sync/audit/notification gap fixes, MANAGER_HR two-stage approval + EXPIRED expiry + monthly cap, positions admin UI (RBAC, audits, tenancy, employee-form round trip), support tickets + password recovery (two-option forgot password, Gate::support.view, must-change-password banner lifecycle, admin/manager resets), Phase 8 MFA (TOTP setup/confirm/login-challenge/recovery-codes), API keys (auth, abilities, org scope, revocation), SCIM 2.0 (token auth, user CRUD, active=false delete, audits), webhooks (dispatch/sign/deliver, event filtering, SSRF guard), OIDC SSO (state check, domain allowlist, link vs auto-provision, login-page org list), role permission editor (validation, company_admin lock), audit CSV export (filters + gating), Phase 9 Stripe (checkout URL, webhook signature, checkout-completed activation, invoice paid, customer portal), Phase 10 (§43 deactivation/export/retention + self-delete prohibition, §71 login history + GeoLocator, §32 terminal IP allowlist + GPS radius, §44 health/ready, Stripe portal), Post-Phase 11 (attendance self-report toggle/once-per-day/immutability/audit + employment-status login-allowed badge/dashboard blocks/`EmploymentStatusChanged` notification), Post-Phase 12 (leave re-review with balance refund/deduct transitions + reviewer notice thread + employee-visible show page), Post-Phase 13 (leave edit after approval with balance/stamp reconciliation + `leave.edited` audit), Post-Phase 14 (importance-tiered top nav: section dropdown sub-navs per role, `mobile_only` Notifications, xl breakpoint), Post-Phase 15 (branding: landing/app "Powered by Zecovex" footer with dynamic company name, organization-name navbar brand, `BrandingTest`), Post-Phase 16 (notification center full-width layout + bell dropdown `w-80` width fix), Post-Phase 17 (per-org SMTP settings + `NotificationSending` apply hook + test email, `MailSettingsTest`), Post-Phase 18 (per-feature email allow/deny checklist + channel-level cancel, `MailSettingsTest` → 16), analytics enrichment (8-KPI strip + workforce mix/status distribution/workload/leave trend/score bands/risk overview, `AnalyticsEnhancedTest`), Post-Phase 19 (dashboard hover drill-downs, `DashboardDrilldownTest`; profile photo/phone, `ProfilePhotoTest`), plus `EndToEndJourneyTest` (onboard → punch → leave → payslip → audit) (**456 tests**)
- `RefreshDatabase` + SQLite `:memory:`; each test class enables `RefreshDatabase` itself
