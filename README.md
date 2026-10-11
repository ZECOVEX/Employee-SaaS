# Employee Management SaaS (Phases 1–9)

Multi-tenant employee management platform built with Laravel 13, Livewire 3, and Breeze (Volt). Each registered organization (tenant) gets isolated employees, departments, users, roles, and audit logs.

## Requirements

- PHP 8.4+ (extensions: pdo_sqlite, mbstring, openssl, curl, zip, fileinfo)
- Composer 2
- Node.js 22 (for asset build)

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

### Docker

```bash
cp .env.example .env      # optional — APP_KEY is generated on first boot
docker compose up -d --build
```

Open http://localhost:8000 — the entrypoint generates `APP_KEY`, caches config/views, runs migrations, and seeds the plan catalog. SQLite lives in the `app-storage` volume (`SKIP_PLAN_SEEDING=true` / `RUN_MIGRATIONS=false` to opt out).

### Demo logins (password: `password`)

| Email | Role | Org |
|---|---|---|
| `admin@demo.test` | Company Admin | Demo Corp |
| `employee@demo.test` | Employee | Demo Corp |
| `admin@other.test` | Company Admin | Other Inc |

## What's Included

### Phase 1 — Core platform
- **Auth**: login, registration with organization provisioning, two-option password recovery (email reset link or a support ticket admins/department managers follow up on), email verification; admin/manager password resets issue an 8-char temporary password and the user must change it at first login (amber banner until they do)
- **Tenancy**: shared database + `organization_id` on all tenant tables; `OrganizationScope` global scope auto-filters queries
- **RBAC**: 4 roles per org (Company Admin, HR, Manager, Employee), 30-permission catalog, Laravel Gates
- **Employees**: full CRUD, employee codes unique per tenant, profile + employment fields
- **Departments**: CRUD, auto-generated codes, manager assignment; **Positions**: designation catalog (title + optional department) feeding the employee form
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

### Phase 4 — Platform
- **Notifications (§25/§7)**: DB + email via a shared Blade mail template for leave status changes, attendance corrections, NFC card status, salary updates, password changes, and once-per-day late-arrival alerts; in-app center (`/notifications`) with unread bell in the nav
- **Billing (§54)**: FREE / STARTER / BUSINESS / ENTERPRISE plan catalog (`PlanSeeder`), per-org subscription + employee-count cap enforced on employee creation (`employee_limit`), plan switching with generated invoices, self-service Billing page (`settings.manage`); `BillingService` is decoupled from Stripe for later integration
- **API v1 (§29/§55)**: `/api/v1/*` with Sanctum personal-access tokens (`POST /api/v1/auth/login` → 30-day token), `EnsureApiOrganization` middleware (suspended orgs blocked), Eloquent API resources for users/employees/departments/attendance/leave/notifications/salary/audit, per-route `permission:` middleware mirroring the web UI
- **Live attendance board (§19)**: `GET attendance/live` with Server-Sent Events stream (`GET attendance/live/stream`, bounded by `LIVE_STREAM_SECONDS`) plus a JSON polling fallback (`GET attendance/live/poll?after=<hash>`); running OVERTIME badge after the scheduled end of day; gated `attendance.view`
- **Docker + CI**: multi-stage `Dockerfile` (composer + vite + php:8.4-apache) with boot entrypoint, `docker-compose.yml` (SQLite volume), and `.github/workflows/ci.yml` (Pint + PHPUnit + Vite build)

### Phase 5 — Scoring & overtime
- **Overtime (§73)**: effective-dated org policy (detection mode, start threshold, rounding, daily/weekly/monthly caps, day-type multipliers, PAID vs COMP_TIME, auto/manual approval modes) with a Settings page (`settings.manage`); daily detection on every attendance change writes one `overtime_records` row per employee-day (raw → countable minutes, multiplier, hourly-rate snapshot + estimated pay); approval queue with approve/reject/minute-adjust (`overtime.manage`) and a full decision trail; comp-time ledger, self-service "My Overtime" page, and `overtime:expire-pending` (daily) for pending-record expiry
- **Score engine (§16)**: monthly attendance score = Attendance Rate × 40% + Punctuality × 25% + Working Hours × 20% + Leave Compliance × 15% (weights configurable per org), optional capped overtime bonus, never capped at 100; Statistics shows the full component breakdown with inputs
- **Scoring & risk settings (§15)**: Settings → "Scoring & risk" configures score weights, risk status bands (label + minimum score), the weekly late-risk rule (threshold, calendar week vs rolling 7 days, level, recipients, per-window suppression) and the [OT] overwork rule (off by default; weekly/monthly minute limits + consecutive-days streak)
- **Risk detection (§15/§39)**: thresholds fire once per window into suppressible `risk_events` and notify employee/manager/HR (database + mail); surfaced on Statistics (risk status, late days this week, configured threshold, High-Risk Alert, OT Limit Notice), the employee dashboard banner, the notification center, and the admin dashboard; `risk:evaluate` (daily) sweeps missed windows
- **Monthly status (§17)**: `employee_monthly_status` snapshots (score + components, day counts, overtime totals) generated by `status:generate` on the 1st after pending overtime is settled — never rewritten once finalized
- **Admin dashboard (§18)**: average score, at-risk employees, high-risk alerts, overtime today, pending overtime approvals, overtime hours this month

### Phase 6 — Analytics & reporting
- **Statistics (§39)**: month + week (Monday-anchored, prev/next nav) filters; MONTHLY OVERVIEW card (day counts, worked vs expected, avg arrival/departure, remaining leave, score + band), WEEKLY ATTENDANCE card, and the OVERTIME / EXTRA TIME section (approved/pending/rejected splits, weekday vs weekend/holiday minutes, multipliers, earnings behind salary visibility, configured weekly/monthly limit usage with "over limit" warning, per-day breakdown with approver) — non-eligible employees see a muted note instead of numbers
- **Overtime report (§41)**: `reports/overtime` with employee/department/date-range/day-type/status filters (Approved includes auto-approved), raw/approved minutes, multiplier, pay, approver and status per row; the pay column and CSV `Estimated pay` require `salary.view`; CSV export for all four report types
- **Monthly report (§41)**: live Score (§16) + Status band (§15) columns and an overtime approved/pending hours split replace the old reserved cells
- **Analytics**: 6-month overtime trend chart (approved vs pending hours) alongside the existing attendance/department/weekday/payroll cards
- **Employee dashboard (§39)**: main attendance-score card with band, worked vs expected hours, [OT] overtime card (month approved/pending/rejected, today's record, est. overtime pay when visible — hidden entirely for non-eligible employees, deep link to Statistics), calendar overtime dots (solid approved / hollow pending) with day tooltips incl. overtime status/pay and salary deduction, recent-attendance "+1h 15m OT (pending)" line, and a This Week summary below the calendar (present/late/absent/leave, worked/expected, late minutes, late salary deduction, overtime split + pay)

## Post-phase spec leftovers (shipped)

- **Global search (§42)**: navbar search over employee code / name / email / department / designation plus NFC card tokens; tenant-scoped, per-section RBAC (`employees.view` / `nfc.view`), pure managers only their own team
- **Leave attachment**: optional PDF/JPG/PNG/WebP upload (≤ 2 MB) on the leave request form, stored on the private disk, downloadable with the same visibility rules as the leave index
- **Phase 7 notifications**: `PayslipReady` on payslip finalize/revise and `MonthlyStatusReady` (score + status band) whenever a monthly status snapshot is finalized

### Phase 8 — Security & enterprise integration
- **MFA (TOTP)**: enable from the profile (QR + confirm code), TOTP or one-time recovery code at login (rate-limited challenge page), disable/regenerate recovery codes — self-service, no admin approval needed
- **API keys**: `emsak_…` keys scoped to an organization with a permission-abilities subset (or `*`); created/revoked under Settings → API & Provisioning (`settings.manage`), full key shown once; authenticates `/api/v1/*` alongside Sanctum via the `auth:apikey` guard
- **SCIM 2.0**: `/api/scim/v2` user/group provisioning with bearer tokens (also created under API & Provisioning); Users create/list/show/patch/delete (PATCH `active=false` deletes), employee role auto-attached, all writes audited
- **Webhooks**: per-org subscriptions (`employee.*`, `leave.*`, `overtime.*`, `attendance.punched`, `payslip.finalized`, `subscription.changed`) with HMAC-SHA256-signed deliveries (`X-Webhook-Signature`), queued with retry, SSRF-guarded URL validation, secret shown once
- **OIDC SSO**: per-org discovery URL + client id/secret (encrypted at rest) + allowed email domains; "Sign in with SSO" button on the login page; callback links an existing user or auto-provisions an employee (SAML deferred — OIDC covers the spec)
- **Role permission editor**: Settings → Roles — per-role checkbox matrix against the 30-key catalog; `company_admin` locked, hr/manager/employee editable; changes audited
- **Audit CSV export**: `GET audit-logs/export` streams the filtered audit trail as CSV (same filters as the index, gated `audit.view`)

### Phase 9 — Stripe billing
- **Checkout**: "Pay with Stripe" on the Billing page for paid plans (`plans.stripe_price_id`), Stripe Checkout Session redirect; success/cancel banners on return
- **Webhooks**: `POST api/stripe/webhook` (signature-verified with `STRIPE_WEBHOOK_SECRET`, 300s tolerance) handles `checkout.session.completed` (activates plan + subscription), `customer.subscription.updated|deleted` (status sync), and `invoice.paid` (marks the open invoice paid)
- Implemented over the raw Laravel HTTP client (not the stripe-php package) so `Http::fake` keeps tests hermetic; unconfigured Stripe hides the button and returns 503 on the webhook

## Tests

```bash
php artisan test        # 350 tests
vendor/bin/pint app tests routes database bootstrap resources --format agent # code style
```

## Documentation

- [docs/architecture.md](docs/architecture.md) — tenancy, middleware, request flow, API, real-time, MFA/SSO/webhooks/Stripe
- [docs/schema.md](docs/schema.md) — database tables and relationships
- [docs/rbac.md](docs/rbac.md) — permission catalog and role matrix
- [docs/roadmap.md](docs/roadmap.md) — Phases 1–9 (all complete)

## Scheduled Commands

```bash
php artisan overtime:expire-pending        # daily 00:10 — settle pending overtime
php artisan risk:evaluate [--date=]        # daily 00:20 — sweep late-risk / overwork rules
php artisan status:generate [--month=]     # monthly 1st 00:30 — employee monthly status snapshots
```

## Environment Notes

- Dev database: SQLite (`database/database.sqlite`); tests use `:memory:`
- `.env` is pre-configured by the setup script (`APP_KEY` generated)
- Rebuild assets after view changes: `npm run build`
- Stripe (Phase 9, optional): set `STRIPE_SECRET_KEY` + `STRIPE_WEBHOOK_SECRET` and a `stripe_price_id` on paid plans; without them the checkout button is hidden and the webhook returns 503
