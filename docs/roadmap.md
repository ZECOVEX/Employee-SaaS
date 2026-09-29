# Roadmap

Phase 1 (MVP) is complete: auth, tenancy, RBAC, employees, departments, users, dashboards, audit logs, settings, platform admin.

Phase 2 (workforce operations) is complete: attendance, NFC check-in, leave management, work schedules.

Phase 3 (compensation & insight) is complete: salary records, deduction rules + Statistics, payslips, reports/CSV, analytics.

Phase 4 (platform) is complete: notifications, billing, API v1, real-time attendance board, Docker, CI.

## Phase 2 — Workforce operations ✅

- ✅ Attendance tracking (NFC punch API, manual corrections, derived daily rows, late/early/overtime rules)
- ✅ NFC cards and physical attendance terminals (key shown once, revocation)
- ✅ Leave management (types, balances, approval/rejection workflow)
- ✅ Work schedules and holidays (defaults seeded per org)
- Scoring / performance ratings (deferred at Phase 2; still deferred after Phase 3 — Monthly Report Score/Status columns show `—`)

## Phase 2 revision — spec recheck vs `idea.md` ✅

All 12 approved gaps closed + attendance entries made re-editable + configurable time format + check-in/check-out bulk entry + wall-clock timezone fix (tests: 107 green):

1. ✅ Daily totals sum completed IN→OUT segments (+ `segment_count`), not first-in→last-out
2. ✅ Configurable punch debounce — per-org `settings.debounce_seconds` (5–300, default 15), scoped per employee+terminal, same-result idempotent (`action: duplicate`)
3. ✅ Review flags — `possible_missed_checkin` (unreturned exit / open segment on past day) and `excessive_segments` (≥15); HALF_DAY short-day rule
4. ✅ Employee self-attendance — `GET attendance/employee/{me}` no longer requires `attendance.view`; others still 403; "My Attendance" nav entry
5. ✅ Employee dashboard — GitHub-style Mon–Sun attendance calendar with month nav, status legend/tooltips, review-flag ring
6. ✅ Admin dashboard — today's Present/Late/Absent/On leave + avg attendance (30d)
7. ✅ Employee dashboard enrichment — month stats, worked this week/month, avg arrival/departure, leave balance, recent table
8. ✅ Effective-dated schedules (§11) — schedule edits close the old version and open a new one; past days keep their original schedule; weekday UI already present
9. ✅ Identity lock — employee name/email read-only without `employees.edit` (or platform admin); employees cannot delete their own account (`User::isEmployeeOnly()`)
10. ✅ API — `POST /api/v1/attendance/nfc/punch` alias + optional `terminal_id` body field (mismatch → 403)
11. ✅ NFC card replace (`nfc-cards.replace`) + punch history view (`nfc-cards.history`); old card revoked with employee link kept
12. ✅ Employee profile photo upload (`image|max:2048`, `public` disk, old file cleaned up)
13. ✅ Attendance entries re-editable by admins — `GET/PUT attendance/events/{event}/edit` + `DELETE` with required reason; corrections supersede the original event (never overwritten, §9), soft-delete for removals, both days re-derived when a punch moves dates, full §24 audit (who/when/what/why/old/new); archived entries are locked (404), employees/other tenants get 403/404
14. ✅ Admin-configurable time format — `settings.time_format` (`24h` international 16:00 / `12h` am-pm 4:00 PM) selectable in the settings panel and applied to every clock display (attendance index/employee/edit, punch events, employee dashboard calendar/tooltips/averages, NFC punch history) via `Organization::formatTime()` + `<x-time>`
15. ✅ Correct Attendance check-in + check-out entry — `POST attendance/in-out` (`attendance.inout`) records both times for a day in one step (employee, date, optional check-in/check-out, reason); at least one time required, check-out must be after check-in, each event audited, day re-derived
16. ✅ Wall-clock timezone handling for non-UTC orgs — attendance is stored, derived and displayed as org-local wall-clock end-to-end (no instant conversions): a 10:00 AM entry shows 10:00 AM instead of 4:00 PM for Asia/Dhaka (UTC+6), late/early/overtime math compares wall times against the schedule, and the NFC debounce window stays in the same frame

Deferred at that point: overtime payouts §73, salary/payslips §28, scoring §15/16, Statistics page (salary/payslips + Statistics shipped in Phase 3 below; overtime and scoring still deferred), notifications §7, geo-login §71, global search §42, leave attachment.

## Phase 3 — Compensation & insight ✅

All four milestones done (tests: 136 green — SalaryTest 6, StatisticsTest 9, PayslipTest 6, ReportTest 8):

- ✅ **M1 Salary records (§28)** — effective-dated per employee (`salary.index/show/store`, routes gated `salary.view` with self-view via `salary.view_own`; cross-tenant 404); a raise closes the old range, earlier months keep their numbers
- ✅ **M2 Rules + calculator + Statistics** — org salary-rule settings (currency, late/absence/half-day/unpaid deduction modes, grace minutes, rounding mode, % cap) in Settings; `SalaryCalculator::estimate()` builds the §28 progressive estimate (per-day classification, per-row rounding so breakdown rows always sum to the total, cap + proportional scaling); Statistics page (`statistics.index`, self-only, month nav) shows the estimate + daily breakdown; nav "Statistics"
- ✅ **M3 Payslips + revisions** — finalize a completed (or current) month into an immutable snapshot (`payslips.store`, `salary.edit`); future periods and duplicate periods rejected; revision (`payslips.revise`) recalculates under a new `revision` and marks the old row `superseded_by` in one transaction; print-friendly payslip page with revision history; audit actions `salary.payslip_finalized` / `salary.payslip_revised`
- ✅ **M4 Reports + analytics (§41)** — `ReportService` (scheduled-day ledger, per-employee monthly totals, analytics aggregations) + `ReportController`:
  - Attendance Report — date range / employee / department / status filters
  - Monthly Report — present/absent/late/leave/worked & overtime hours per employee with totals; Score/Monthly Status columns reserved as `—` (scoring §15/16 deferred)
  - Salary Report — effective records for the month; requires `reports.view` **and** `salary.view` (managers without `salary.view` get 403, §41 restricted)
  - CSV export for all three (`reports/{type}/export`, `streamDownload` + `fputcsv`); Excel/PDF guidance via browser print
  - Analytics dashboard (`analytics.index`, `reports.view`) — 6-month attendance trend, current-month department rates, weekday late pattern, finalized payroll trend (`salary.view`-gated card); pure CSS charts, no JS library
  - Nav links "Reports" and "Analytics"; tenant isolation on every page + export

Deferred to later phases (as specified): scoring §15/16, overtime payouts §73 `[OT]`, notifications §7, geo-login §71, global search §42, leave attachment.

## Phase 4 — Platform ✅

All five milestones done (tests: 172 green — NotificationTest 9, BillingTest 9, ApiV1Test 11, AuditLogTest 2, LiveAttendanceTest 4, plus the Phase 1–3 suite):

- ✅ **M1 Notifications (§7/§25)** — `notifications` table (Laravel default schema), shared Blade mail template `resources/views/mail/notification.blade.php`, base class `App\Notifications\OrganizationNotification` (`via()` returns `['database','mail']`); events: leave status changes, attendance corrections, NFC card status, salary updates, password changes, once-per-day late-arrival alert (`LateArrival`, fired by `AttendanceService` on the first late transition of a day); in-app center at `/notifications` (list, mark read, mark all, unread count), unread bell + dropdown in the nav (`notification.index` gated `auth` + `verified` + `company`)
- ✅ **M2 Billing (§54)** — `plans` (global catalog: FREE 5 / STARTER 25 / BUSINESS 100 / ENTERPRISE unlimited employees, monthly prices 0/29/99/299), `subscriptions` (one active row per org, status `trial`/`active`/`past_due`/`canceled`, monthly period), `invoices` (`INV-{Ym}-{6}` unique, status `open`/`paid`/`void`); `BillingService` (provisionDefault on org create, `ensureCanAddEmployee` cap enforced in `EmployeeController@store`, `switchPlan` + `issueInvoice` for paid plans), `BillingController` + `resources/views/billing/index.blade.php` (current plan, usage bar, plan cards, invoice history), routes `billing.index`/`billing.plan` gated `settings.manage`; `PlanSeeder` runs in migrations/CI/Docker entrypoint
- ✅ **M3 API v1 (§29/§55)** — Sanctum installed; `POST /api/v1/login` (returns 30-day `plainTextToken`), `GET /api/v1/me`; routes under `auth:sanctum` + `EnsureApiOrganization` (403 suspended org) + per-route `permission:` middleware; Eloquent API resources for users/employees/departments/attendance/leave/notifications/salary/audit; index endpoints support filters + pagination; NFC punch alias kept at `/api/v1/attendance/nfc/punch`; test helper `ApiV1Test::withToken()` resets cached `RequestGuard` user between requests
- ✅ **M4 Live attendance board (§19)** — `config/live.php` (`LIVE_STREAM_SECONDS` default 30, `LIVE_POLL_INTERVAL` default 5); `LiveAttendanceBoard` service builds rows (name, department, status, time, overtime) with `Carbon::setTestNow`-friendly naive wall-clock math and `+HH:MM` overtime label; `LiveAttendanceController` (index SSR page, `stream` = SSE `response()->stream` with `retry: 3000` + JSON data events, closes after `live.stream_seconds`, `poll` = `md5` row-hash cursor returning `changed:false` when `after` matches); routes gated `attendance.view`; `resources/views/attendance/live.blade.php` (EventSource → on error falls back to polling, `@js()` URLs, badge colors incl. `ON LEAVE`); nav "Live board"
- ✅ **M5 Docker + CI** — multi-stage `Dockerfile` (composer deps → node assets → `php:8.4-apache` runtime with `pdo_sqlite`/`pdo_mysql`, `a2enmod rewrite headers`, DocumentRoot → `/var/www/html/public`, `AllowOverride All`), `docker/entrypoint.sh` (APP_KEY generation, `storage:link`, config/view cache, migrations gated `RUN_MIGRATIONS`, plan seed gated `SKIP_PLAN_SEEDING`), `docker-compose.yml` (port 8000, `app-storage` volume keeps the SQLite DB across image rebuilds), `.dockerignore`, `.github/workflows/ci.yml` (Pint + PHPUnit on PHP 8.4, `npm ci` + `npm run build` on Node 22); smoke-tested: image builds, entrypoint migrates + seeds, `/`, `/login`, `/register` all return 200 through Apache

Deferred from earlier phases (still open): scoring §15/16, overtime payouts §73, geo-login §71, global search §42, leave attachment, Stripe integration (billing is structured for it but decoupled).

## Standing decisions (locked)

| Decision | Choice |
|---|---|
| Project path | `E:\employee-saas` |
| Frontend | Blade + Livewire (Volt), not Next.js |
| Tenancy | Shared DB + `organization_id`, not stancl/tenancy |
| Scope | Phases 1–4 complete |
