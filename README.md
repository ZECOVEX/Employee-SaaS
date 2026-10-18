# Employee Management SaaS (Phases 1–10)

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
- **NFC cards**: one merged page (`/nfc-cards`) holds everything NFC — an inline *"Issue a card"* panel (phone NFC via Web NFC API / USB reader machine / typed, gated `nfc.manage`, auto-opens on validation errors) above the list, plus assign/unassign/block/revoke per org, replace a lost card (new token, old card revoked but history preserved) with a per-card punch history view; issuing claims-if-available + creates-if-new, codes normalized so `04:6e 3a` and `046E3A` are the same card, terminal punches accept either shape
- **Terminals**: register devices, API key shown once (sha256-hashed), revocation
- **Leave**: types + yearly balances, request/approve/reject/cancel workflow with balance enforcement
- **Work schedules**: working hours, grace period, breaks, holidays (defaults seeded per org), weekday selection, versioned by effective date

### Phase 3 — Compensation & insight
- **Salary records**: effective-dated basic/allowances/bonus/deductions per employee (§28) — a raise never rewrites earlier months; "Salary" / "My Salary" pages (`salary.view` / `salary.view_own`)
- **Statistics**: monthly estimate with per-day breakdown (late/absence/half-day/unpaid-leave deductions, grace + rounding + % cap rules configurable in Settings under "Salary & deductions")
- **Payslips**: finalize a completed month into an immutable snapshot, revision history when salary or rules change, print/save-as-PDF detail page
- **Reports (§41)**: Attendance (date/status/employee/department filters), Monthly (present/absent/late/leave/hours + totals), Salary (effective records; requires `reports.view` **and** `salary.view`) — CSV export for each, Excel/PDF via the browser
- **Analytics**: 6-month attendance trend, department rates, weekday late pattern, payroll progress, plus workforce mix, attendance-status distribution + worked-vs-expected workload, leave trend/by-type, performance score bands and risk overview (8-KPI strip) — pure CSS charts (`reports.view`; payroll card needs `salary.view`)

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
- **Customer portal**: "Manage billing in Stripe" opens a Billing Portal session (payment methods, invoices, cancellation) whenever Stripe is configured and the org has a Stripe customer
- **Webhooks**: `POST api/stripe/webhook` (signature-verified with `STRIPE_WEBHOOK_SECRET`, 300s tolerance) handles `checkout.session.completed` (activates plan + subscription), `customer.subscription.updated|deleted` (status sync), and `invoice.paid` (marks the open invoice paid)
- Implemented over the raw Laravel HTTP client (not the stripe-php package) so `Http::fake` keeps tests hermetic; unconfigured Stripe hides the button and returns 503 on the webhook

### Phase 10 — Spec leftovers, observability & dev hardening
- **Observability (§44)**: `/health` (liveness) + `/ready` (DB + cache, 503 when down) probes; security headers (nosniff, frame deny, referrer policy, permissions policy, HSTS) and `X-Request-Id` correlation on every request
- **Login geolocation (§71)**: every login attempt (web, API, SSO, MFA — success or failure) records the client IP with a server-side GeoIP lookup (country/division/city via the `null`/`static`/`maxmind` driver); profile "Security information" section + a dedicated login-history audit page; approximate, security-signal-only, disclosed on the public `/privacy` page
- **Data privacy (§43)**: self-service account deletion prohibited for all roles; admin-controlled deactivation blocks every login path; JSON data export (self via Profile, admins via Users page); daily `privacy:prune` honors per-org retention windows (Settings → Data retention); public `/privacy` policy
- **Race conditions (§50)**: plan headcount checks and plan switches lock the subscription row; overtime/comp-time inserts handle unique-violation retries
- **Terminal geo-security (§32)**: terminals can pin a GPS location + verification radius and/or an IP allowlist (exact or CIDR); the punch API refuses off-allowlist IPs and out-of-radius/missing location fixes
- **Dev requirements (§62)**: `declare(strict_types=1)` across the codebase, Larastan level 5 (clean with a baseline so new code stays clean), Form Requests on the main write endpoints, `docs/openapi.yaml` (OpenAPI 3.1), an end-to-end journey test, `composer format|analyse|check` scripts, and a CI Larastan step

### Post-Phase 11 — Self-report attendance & employment status visibility
- **Self-report attendance**: per-org toggle (Settings → "Allow employees to self-report attendance"); when the NFC terminal is down, employees submit their own check-in and check-out time (once each per day, today-only, not in the future) from My Attendance; events reuse the normal derivation pipeline with `source = 'self'`, are immutable from the employee side, race-safe (`lockForUpdate` + throttle), and fully audited (`attendance.self_reported`)
- **Employment status visibility**: inactive/terminated employees can still sign in; a red "Terminated" / gray "Inactive" pill shows next to their name in the nav on every page; the employee dashboard shows a red "You have been terminated" block (or amber inactive notice); status changes send the employee an `EmploymentStatusChanged` bell + email notification (reactivation sends nothing)

### Post-Phase 12 — Leave re-review & employee-visible notices
- **Re-reviewable decisions**: admin/HR/managers can approve or reject a leave request as many times as they want (only employee-cancelled requests are terminal); the ledger stays correct across flips — rejecting an approved request refunds the balance and clears its LEAVE attendance stamps, re-reviews never double-deduct, and the employee is notified only when the status actually changes
- **Notices**: approvers post unlimited notices on a request (`leave_notices` append-only thread); the employee sees every notice (author + timestamp) on the new leave detail page (`GET leave/{id}`, linked as "View" from the index) and receives a `LeaveNoticeAdded` bell + email; Approve/Reject forms now carry an optional note (`review_note`) visible to the employee — employees can read but never post notices

### Post-Phase 13 — Leave editing after approval
- **Edit anytime**: reviewers (same gate as approving — `leave.approve` + team scope, admin/HR unrestricted) can edit the type, dates and reason of any non-cancelled request from a new edit form — including after approval: reducing the range refunds the freed days, extending it is re-checked against the remaining balance (a failed edit rolls back everything), type changes move days between buckets, and year-boundary edits refund the old year and deduct the new one. Days are always recomputed from the range (weekends excluded) and past dates are allowed — admins can adjust leave at any time
- **Ledger, audit & notifications stay honest**: approved edits move the LEAVE attendance stamps to the new range (rows with real check-ins untouched), pending/rejected edits never touch the balance, and unpaid types never consume one; every save writes a `leave.edited` audit row (old → new), notifies the employee (`LeaveStatusChanged` — "Leave updated"), re-dispatches the `LEAVE_APPROVED` webhook when still approved, and is refused (403) for cancelled requests or anyone outside the review scope

### Post-Phase 14 — Top navbar restructure
- **Importance-tiered nav (all roles)**: the top bar now shows only the most important destinations — Dashboard, People ▾ / Attendance ▾ / Payroll ▾ / Reports ▾ / Settings ▾ section dropdowns, and Leave — while everything else lives in those dropdown sub-navs (Employees/Departments/Positions/Users under People, Overtime + approvals + Salary under Payroll, personal Statistics under Reports, Support/Platform under Settings); Notifications is mobile-menu-only since the desktop has the bell dropdown — RBAC pruning, single-child collapse (e.g. managers still see a plain "Support" link) and Devices flyouts unchanged
- **Responsive fix**: desktop links start at `xl` (1280px) instead of `sm` (640px) where the crowded bar overflowed the search/bell/user area; below `xl` the hamburger shows the full grouped menu, labels are `whitespace-nowrap`

### Post-Phase 15 — Branding: powered-by & dynamic company name
- **Powered by Zecovex**: the landing page footer reads "Powered by Zecovex" (replacing the stock Laravel/PHP version line) and every authenticated page ends with "Powered by Zecovex | {Company Name}" — the company name comes from the Settings (organization) name and is omitted for org-less platform admins; `APP_NAME` is now `EmployeeSaaS`, so browser titles carry the product name instead of "Laravel"
- **Dynamic navbar brand**: the top-left brand shows the signed-in organization's name instead of the hardcoded "EmployeeSaaS" (fallback `config('app.name')`), and the redundant right-side org-name chip was removed — the brand carries it now

### Post-Phase 16 — Notification UI fix
- **Suitable layout**: the notification center now uses the standard full-width `max-w-7xl` body (it was a slim 768px column under the wide header) with capped text rows and the action pinned right; the bell dropdown finally gets its intended `w-80` panel width — the component only mapped `width="48"`, so `width="80"` emitted a broken literal `80` class and the panel collapsed to a slim strip

### Post-Phase 17 — Per-org email (SMTP) settings
- **Bring your own webmail/SMTP**: Settings → Email lets admins point the app at their own mail server — host, port, TLS/SSL/none encryption, username, password (stored encrypted), and the from address/name; every outgoing email (leave decisions, payslips, security alerts, password resets…) is then sent through that server, with a one-click test email to verify the connection
- **Applied automatically on send**: a `NotificationSending` hook applies the organization's saved SMTP config to every mail-channel notification (sync or queued), falling back to the platform `.env` mailer when the org has SMTP disabled; the page sits under Settings ▾ → Email and reuses `settings.manage`

### Post-Phase 18 — Per-feature email allow/deny
- **Choose what gets mailed**: Settings → Email gains a "Which emails should be sent?" checklist — leave decisions, leave notices, attendance, overtime, salary/payslips, monthly progress, warnings & risk alerts, employment status, security alerts, NFC cards, support tickets, and password resets. Everything is on by default; the selection is stored in `organizations.settings.mail_features` and audited with the SMTP save (untick-all = no email at all)
- **In-app notifications always stay**: the same `NotificationSending` hook cancels only the *mail channel* (returns `false`) for disabled features, so the in-app/database notification still arrives — and the SMTP connectivity test email is never gated by the checklist

### Post-Phase 19 — Dashboard drill-downs & profile photo
- **Hover drill-downs everywhere**: every admin dashboard stat card (present/late/absent/on-leave today, employee & user counts, department mix, score bands, at-risk, risk alerts, overtime today/pending/month) and every employee month tile (present/late/absent/leave days, balance by type) opens a hover panel listing the underlying rows — names, arrival times, late minutes, leave types, per-day lists — rendered server-side with an Alpine viewport clamp
- **Profile photo & phone**: every user uploads a profile photo (JPG/PNG ≤ 2 MB, live preview, remove option) and a contact phone on the profile page; the photo is stored on the public disk and shown as an avatar (initials fallback) in the desktop user menu and the mobile nav; changes are audited (`profile.updated`)

### Post-Phase 20 — Role-scoped profile editing
- **Identity editors keep full control**: users with `employees.edit` (or platform admins) manage their own photo, phone, name and email on the profile page, exactly as before
- **Employees get an emergency-contact card instead**: name/email/photo/phone are HR-managed; employees update their emergency contact (name + phone) on the profile page — it writes to their employee record (audited `profile.emergency_updated`), so admins immediately see it on the employee profile; 2FA, password and data export remain self-service for everyone
- **One avatar everywhere (P20b)**: the admin-uploaded employee photo is mirrored into the user's avatar, so it shows in the nav, profile and dashboard body card alike; `<x-avatar>` also falls back to the employee photo for older rows

### Post-Phase 21 — 2FA method choice & login-alert emails
- **Pick your second factor**: 2FA can use an authenticator app (QR) **or** a 6-digit code emailed on demand — the profile card offers both; the login challenge is method-aware, and recovery codes work either way. Disable/regenerate now also accept a recovery code, so removing the app from Google Authenticator never locks you out
- **Login alerts**: opt-in toggle on the profile — get an email on every successful sign-in with the IP, approximate location and device (browser + OS); off by default, audited, and toggleable from the Settings → Email security checklist

### Post-Phase 25 — Landing page light/dark mode
- **Theme toggle**: the marketing page (`/`) ships a real light **and** dark mode — a moon/sun button in the sticky header switches instantly and remembers the choice (`localStorage.theme`), defaulting to the OS `prefers-color-scheme` on first visit; Tailwind now runs `darkMode: 'class'` with a no-FOUC bootstrap script in `<head>`
- **Full dual-mode page**: header, hero, dashboard mockup, payslip card, logo cloud and all mid-page sections render light-first with `dark:` variants (white/slate-950 pairing, indigo gradient headline, dual `.hero-grid`/`.glow` CSS); the announcement bar, CTA band and footer stay always-dark accent strips so the Zecovex lockup and "Powered by" branding keep their dark backdrop in both modes

### Post-Phase 26 — Full-project audit (security, correctness & performance)
- **Privilege gaps closed**: password resets from support tickets go through a same-org / never-self / employees-only policy for actors without `users.manage`; the dashboard's users drill-down and audit feed now require `users.view` / `audit.view`
- **Correctness**: the overtime pay-visibility stale-cache bug was root-caused to Laravel caching controller instances (and their constructor-injected services) across requests in one process — the offending memos were removed, and the hourly-rate cache key now includes the employee id
- **Performance**: salary lookups batched in the salary list and report pages (`SalaryRecord::effectiveForDate()`), organizations eager-loaded into score loops, and composite indexes added for NFC punch lookups, daily-attendance rows and audit-log actor queries
- **Grant hardening**: role edits and API key issuance now intersect requested permissions/abilities with what the actor may grant (`User::grantablePermissionKeys()`) — delegated editors can no longer widen their own access, while company/platform admins are unrestricted
- **Two reported gaps fixed**: the employee *create* form now offers the profile-photo upload (the field lived only in the edit form, and create lacked multipart encoding), and NFC cards can be blocked **and unblocked** while revocation remains permanently terminal
- **Salary change path surfaced**: changing a salary = adding a new effective-dated record via Payroll → Salary → History/Add → "Add salary record" (`salary.edit`, Company Admin by default); view-only users now see *why* the form is absent on the salary page, and the profile salary card links **Change salary** for editors
- **Payroll/status polish**: the payslip Period field is a calendar date picker (any day of the month, normalized to `YYYY-MM` server-side), monthly-status snapshots and the profile Monthly tab never show months before the employee's joining month, and spec-notation markers like "(§28)" no longer leak into the UI

### Post-Phase 27 — Light/dark toggle + full console redesign
- **Left-sidebar console shell**: the top navigation is gone — every authenticated page now opens with a persistent white sidebar (expandable accordion sections with chevrons — People, Attendance ▸ Devices, Payroll, Reports, Settings — that auto-open on the active page, indented sub/sub-sub links, indigo active pills, gradient brand mark, off-canvas drawer below `xl`) and a slim solid top bar holding search, the theme toggle, the notification bell and the user menu; all links, permissions and routes are exactly the same as before
- **New visual identity, same features**: violet/indigo accent (indigo→violet gradient primary buttons), violet-ink text on a `#f7f7fb` canvas with hairline panels, Plus Jakarta Sans, uppercase micro-labels, and a violet-tinted near-black dark mode — restyled through `resources/css/app.css` + the two layouts, with every page's content, permissions and flows untouched
- **Both modes, one click**: moon/sun toggle in the top bar persists to `localStorage.theme`, follows the OS preference on first visit and applies before first paint (no flash) on app and login shells; the landing page keeps its own separate design and toggle
- **Company logo**: a Company Admin uploads a logo from Company Settings (JPEG/PNG/WebP/SVG up to 2 MB, with a preview and a remove option); it renders beside the company name in the sidebar brand row for every user, with the gradient mark as the fallback when none is set

## Tests & checks

```bash
php artisan test        # 505 tests
composer format         # Pint code style (app tests routes database bootstrap resources)
composer analyse        # Larastan level 5 (phpstan.neon + baseline)
composer check          # format + analyse + test
```

## Documentation

- [docs/architecture.md](docs/architecture.md) — tenancy, middleware, request flow, API, real-time, MFA/SSO/webhooks/Stripe, observability, privacy, geo
- [docs/schema.md](docs/schema.md) — database tables and relationships
- [docs/rbac.md](docs/rbac.md) — permission catalog and role matrix
- [docs/roadmap.md](docs/roadmap.md) — Phases 1–10 + Post-Phase 11–26 additions (all complete)
- [docs/openapi.yaml](docs/openapi.yaml) — OpenAPI 3.1 spec for /api/v1 + the NFC punch endpoint

## Scheduled Commands

```bash
php artisan overtime:expire-pending        # daily 00:10 — settle pending overtime
php artisan risk:evaluate [--date=]        # daily 00:20 — sweep late-risk / overwork rules
php artisan status:generate [--month=]     # monthly 1st 00:30 — employee monthly status snapshots
php artisan privacy:prune                  # daily 03:00 — apply login/audit retention windows
```

## Environment Notes

- Dev database: SQLite (`database/database.sqlite`); tests use `:memory:`
- `.env` is pre-configured by the setup script (`APP_KEY` generated)
- Rebuild assets after view changes: `npm run build`
- Stripe (Phase 9, optional): set `STRIPE_SECRET_KEY` + `STRIPE_WEBHOOK_SECRET` and a `stripe_price_id` on paid plans; without them the checkout button is hidden and the webhook returns 503
- GeoIP (Phase 10, optional): `GEOIP_DRIVER` (`null`/`static`/`maxmind`) + `GEOIP_MAXMIND_DATABASE`; the `static` driver reads CIDR ranges from `config/geoip.php`
