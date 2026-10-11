# Roadmap

Phase 1 (MVP) is complete: auth, tenancy, RBAC, employees, departments, users, dashboards, audit logs, settings, platform admin.

Phase 2 (workforce operations) is complete: attendance, NFC check-in, leave management, work schedules.

Phase 3 (compensation & insight) is complete: salary records, deduction rules + Statistics, payslips, reports/CSV, analytics.

Phase 4 (platform) is complete: notifications, billing, API v1, real-time attendance board, Docker, CI.

Phase 5 (scoring & risk + overtime) is complete: attendance score engine, scoring/risk settings, late-risk + overwork detection, monthly status snapshots, overtime policy/detection/approvals/comp-time.

Phase 6 (analytics & reporting) is complete: Statistics month/week sections + OVERTIME section, overtime report, monthly report score/status/overtime columns, analytics overtime trend, employee dashboard score/[OT]/weekly-summary widgets.

Phase 8 (security & enterprise integration) is complete: MFA (TOTP + recovery codes), API keys, SCIM 2.0, outbound webhooks, OIDC SSO, role permission editor, audit CSV export (SAML deferred).

Phase 9 (monetization) is complete: Stripe checkout + webhooks over the raw HTTP client (plans/invoices already shipped in Phase 4).

## Phase 2 — Workforce operations ✅

- ✅ Attendance tracking (NFC punch API, manual corrections, derived daily rows, late/early/overtime rules)
- ✅ NFC cards and physical attendance terminals (key shown once, revocation)
- ✅ Leave management (types, balances, approval/rejection workflow)
- ✅ Work schedules and holidays (defaults seeded per org)
- ✅ Scoring / performance ratings — shipped in Phase 5 (score engine §16 + bands §15); the Monthly Report Score/Status columns landed in Phase 6 (M6d below)

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
- ✅ **M3 API v1 (§29/§55)** — Sanctum installed; `POST /api/v1/auth/login` (returns 30-day `plainTextToken`), `GET /api/v1/auth/me`; routes under `auth:sanctum` + `EnsureApiOrganization` (403 suspended org) + per-route `permission:` middleware; Eloquent API resources for users/employees/departments/attendance/leave/notifications/salary/audit; index endpoints support filters + pagination; NFC punch alias kept at `/api/v1/attendance/nfc/punch`; test helper `ApiV1Test::withToken()` resets cached `RequestGuard` user between requests
- ✅ **M4 Live attendance board (§19)** — `config/live.php` (`LIVE_STREAM_SECONDS` default 30, `LIVE_POLL_INTERVAL` default 5); `LiveAttendanceBoard` service builds rows (name, department, status, time, overtime) with `Carbon::setTestNow`-friendly naive wall-clock math and `+HH:MM` overtime label; `LiveAttendanceController` (index SSR page, `stream` = SSE `response()->stream` with `retry: 3000` + JSON data events, closes after `live.stream_seconds`, `poll` = `md5` row-hash cursor returning `changed:false` when `after` matches); routes gated `attendance.view`; `resources/views/attendance/live.blade.php` (EventSource → on error falls back to polling, `@js()` URLs, badge colors incl. `ON LEAVE`); nav "Live board"
- ✅ **M5 Docker + CI** — multi-stage `Dockerfile` (composer deps → node assets → `php:8.4-apache` runtime with `pdo_sqlite`/`pdo_mysql`, `a2enmod rewrite headers`, DocumentRoot → `/var/www/html/public`, `AllowOverride All`), `docker/entrypoint.sh` (APP_KEY generation, `storage:link`, config/view cache, migrations gated `RUN_MIGRATIONS`, plan seed gated `SKIP_PLAN_SEEDING`), `docker-compose.yml` (port 8000, `app-storage` volume keeps the SQLite DB across image rebuilds), `.dockerignore`, `.github/workflows/ci.yml` (Pint + PHPUnit on PHP 8.4, `npm ci` + `npm run build` on Node 22); smoke-tested: image builds, entrypoint migrates + seeds, `/`, `/login`, `/register` all return 200 through Apache

Deferred from earlier phases (still open): geo-login §71, global search §42, leave attachment, Stripe integration (billing is structured for it but decoupled). Scoring §15/16 and overtime §73 shipped in Phase 5 below.

## Phase 5 — Scoring & risk + overtime ✅

All milestones done (tests: 233 green — 61 new in Phase 5):

- ✅ **M1 Overtime detection (§73-G/H)** — effective-dated `overtime_policies` (mode `AFTER_OFFICE_END`/`ABOVE_EXPECTED_HOURS`, start threshold + rounding method, daily/weekly/monthly caps (weekly + monthly enforced newest-day-first in `OvertimeService::reduceToCap`, locked decisions untouched), max shift, weekday/weekend/holiday/night multipliers, rate base GROSS/BASIC/CUSTOM, `compensation_type` PAID/COMP_TIME, `overtime_score_bonus_max`), per-employee `overtime_eligibility` override; `OvertimeService::recalculate` runs on every attendance derivation (manual edits included), producing one `overtime_records` row per employee-day (raw → late-offset → countable minutes, multiplier, hourly-rate snapshot + estimated pay; approved minutes mirror into `daily_attendance.overtime_minutes`); policy editor `settings/overtime` (`settings.manage`); self-only "My Overtime" page (`overtime.index`, no permission key)
- ✅ **M2 Approval workflow (§73-E)** — approval modes AUTO / MANAGER / MANAGER_HR; queue `overtime/queue` (`overtime.manage`) with approve / reject / minute-adjust; MANAGER_HR is genuinely two-stage (employee's manager or company admin approves first — record stays PENDING with a stage-1 trail row + `OvertimeStaged` notice — then a different HR/admin confirms; system/expiry calls bypass); the ESCALATE expiry action now marks the record `EXPIRED` (locked against re-derivation, still decidable late, counted as pending everywhere); every decision, adjustment and escalation lands in `overtime_approvals` (before/after minutes, reason, decided_at) + §24 audit (`overtime.approved|rejected|adjusted|recalculated|escalated`, stage noted for MANAGER_HR) + decision/detection notifications
- ✅ **M3 Comp-time, pay + expiry (§73-F)** — COMP_TIME accrues a `comp_time_entries` ledger row per approved record (balance = sum of minutes, unique per record); pay estimated from the effective salary record × day/night multiplier; `overtime:expire-pending` (daily 00:10) resolves pending records past `pending_expiry_days` via AUTO_APPROVE / AUTO_REJECT / ESCALATE (→ status `EXPIRED`, one escalation marker)
- ✅ **M4 Score engine (§16)** — `ScoreService::compute` derives Attendance Rate / Punctuality / Working Hours / Leave Compliance for a month from the attendance + leave ledger (approved leave excluded from attendable days, absences count against working hours, per-day cap on worked minutes, elapsed schedule only), org-configurable weights (`score_weight_*`, defaults 40/25/20/15), components rounded before weighting, separate capped OT score-bonus line (never inflates the base), overall score not capped at 100, `null` when the month has no elapsed scheduled day; Statistics shows the full formula breakdown
- ✅ **M5a Scoring & risk settings (§15)** — Settings → "Scoring & risk" (`settings.manage`): score weights, status bands (label + minimum score, strictly descending, lowest must be 0), weekly late-risk rule (enabled, threshold days, `calendar_week`/`rolling_7` period, level label, recipients employee/manager/hr, per-window suppression) and the [OT] overwork rule (default off — weekly/monthly minute limits + consecutive-days streak, same level/recipients/suppression)
- ✅ **M5b Risk detection (§15/§39)** — `RiskService` records suppressible `risk_events` (unique per employee × type × window; `late_week`/`ot_week`/`ot_month`/`ot_streak`) and notifies `HighRiskAttendance` / `OvertimeLimitNotice` (database + mail); hooks fire inside attendance derivation and overtime recalculation; `risk:evaluate` (daily 00:20) sweeps windows missed by punch-time evaluation; surfaced on Statistics "Risk & Notifications" (current status, late days this week, configured threshold, High-Risk Alert, OT Limit Notice), employee dashboard banner, notification center, and admin dashboard high-risk widget
- ✅ **M5c Monthly status snapshots (§17)** — `employee_monthly_statuses` (one row per employee-month: score + component scores, present/absent/late/leave day counts, worked vs expected minutes, approved/pending/rejected overtime totals + days, status, generated_at); `status:generate --month=YYYY-MM` (default last month) runs monthly on the 1st at 00:30 — after `overtime:expire-pending` settles pending overtime — skips null scores, and never rewrites an existing row (§17 "never changed after")
- ✅ **M5d Dashboard wiring (§39/§18) + M5e tests/docs** — Statistics RISK & NOTIFICATIONS section, employee dashboard red/amber risk banners with per-rule copy + deep links, admin dashboard §18 widgets (average score, at-risk employees, high-risk alerts, overtime today, pending overtime approvals, overtime hours this month); ScoreService schedule/holiday caches keep the admin loop to one shared instance

## Phase 6 — Analytics & reporting ✅

All milestones done (tests: 245 green — 12 new in Phase 6):

- ✅ **M6a Statistics week filter + §39 sections** — StatisticsController validates `month` + `week` (Monday-anchored, prev/next nav); MONTHLY OVERVIEW card (present/late/absent/leave, worked vs expected, avg arrival/departure, remaining leave, score + band) and WEEKLY ATTENDANCE card built on `ReportService::rangeTotals` (elapsed-schedule expected minutes, per-day capped worked minutes so overtime never inflates the worked figure)
- ✅ **M6b Statistics OVERTIME / EXTRA TIME section (§39 [OT])** — `OvertimeService::summaryForRange` (approved/pending/rejected splits, weekday vs weekend/holiday minutes, multipliers, approved/pending pay, eligibility + visibility flags); earnings cards behind `show_pay`; configured weekly/monthly limit usage against `ot_risk_weekly_minutes` / `ot_risk_monthly_minutes` (approved + pending counted, "over limit" chip); daily breakdown table with approver/reason; muted note for non-eligible employees (no numbers)
- ✅ **M6c Overtime report (§41)** — `GET reports/overtime` (`reports.view`, tab between Monthly and Salary) with employee/department/date-range/day-type/status filters (status `APPROVED` covers `AUTO_APPROVED`); rows show raw/approved minutes, multiplier, pay, approver (incl. Automatic), status chips; pay column + CSV `Estimated pay` gated by `salary.view` (managers without it see "Pay columns are hidden"); `overtime_report_{from}_{to}.csv` export; empty state + validation + tenant isolation
- ✅ **M6d Monthly report completion (§41)** — live Score (§16) and Status band (§15) columns plus the Overtime approved/pending hours split (one grouped pass over `overtime_records`) replace the reserved `—` cells and the single overtime column; CSV gained `Overtime approved hours`, `Overtime pending hours`, `Score`, `Status`; the "columns are reserved" placeholder note is gone
- ✅ **M6e Analytics overtime trend** — `ReportService::analytics()['overtime']` (6 months of approved vs pending countable hours) + stacked pure-CSS chart card on Analytics with an approved/pending/none legend
- ✅ **M6f Employee dashboard (§39)** — main score card (score + band chip), "of Xh Ym expected" subtitle on worked-this-month, [OT] card (month approved/pending/rejected, today's record + status chip, est. overtime pay behind `show_pay`, deep link to Statistics §Overtime, hidden entirely for non-eligible employees), calendar overtime dots (solid approved / hollow pending / red rejected) with enriched day tooltips (OT minutes + status, rejection reason, OT pay, day salary deduction), recent-attendance "+1h 15m OT (pending)" line, weekly summary below the calendar (present/late/absent/leave, worked/expected, late minutes, late salary deduction, week overtime split + pay)
- ✅ **M6g Tests + docs** — `StatisticsSectionsTest` (4), `ReportPhase6Test` (5), `DashboardPhase6Test` (3); pint clean, production assets built, architecture/README/schema updated

No schema changes in Phase 6 — every new surface is a view over tables shipped in Phases 1–5 (`overtime_records`, `daily_attendance`, `risk_events`, `employee_monthly_statuses`).

## Post-Phase 6 — spec leftovers (shipped)

- ✅ **Global search (§42)** — `GET /search` (`SearchController`): employee code/name/email/department/designation via `orWhereHas` on user/department/position plus NFC `card_token`; per-section RBAC (`employees.view` for people, `nfc.view` for cards), pure managers restricted to their team like `EmployeeController::index`, caps of 8 employees / 5 cards, minimum query length 2; navbar Alpine dropdown in `navigation.blade.php` (visible only when either permission is held)
- ✅ **Leave attachment** — optional `attachment` upload (`mimes:pdf,jpg,jpeg,png,webp`, `max:2048`) on the leave request form, stored on the private `local` disk under `leave-attachments/{orgId}/`; `GET leave/{leaveRequest}/attachment` streams it with leave-index visibility (leave.manage = all, approve-only = own team, otherwise own requests) and the leave index shows an Attachment link; no schema change — `leave_requests.attachment_path` already existed
- ✅ **Phase 7 salary + monthly status notifications** — `PayslipReady` sent to the employee on payslip finalize and on revise (revision number in the body); `MonthlyStatusReady` sent when `MonthlyStatusService` finalizes a snapshot, with the score and band label from `RiskService::statusFor` (organizations cached per run)

Tests: 262 green at that point (17 new — `GlobalSearchTest` 8, `LeaveAttachmentTest` 6, `Phase7NotificationTest` 3).

## Phase 7 completion + field-sync audit (shipped)

- ✅ **Audit completeness (§24)** — platform `organization.status_changed` (resource-organization precedence in `AuditLogger`: the record's `organization_id` → `Organization` key → actor, so cross-tenant admin actions land in the target's trail), org registration audit (`organization.registered`), password change audit (`password.changed`, value redacted), salary-rule & scoring-settings old-value diffs captured before overwrite, `salary.range_closed` on cap, role changes audited with readable slugs both sides (`user.updated` old/new `roles` arrays), `user.deleted` keeps the user as resource
- ✅ **Notification gaps** — `AttendanceCorrected` fires on manual correction entry + full check-in/check-out entry (silent for self-corrections), `OvertimeDecision(ADJUSTED)` on minute adjustments, nav "Notifications" link, new `OvertimeStaged` for MANAGER_HR stage-1
- ✅ **Audit UI (field-level)** — `audit-logs.index` shows only changed fields per row (numeric/array-aware compare, secret keys redacted, `salary.*` amounts hidden without `salary.view`), filters for action / resource type / actor / from / to (actor `0` = system), IP + device (UA tooltip) column
- ✅ **Field sync across schema ↔ model ↔ forms ↔ API** — employee create/update validates & persists address, date of birth, work location, emergency contact name/phone, overtime eligibility (all shown on form + §40 profile; `status` accepts `terminated`), §54 reactivation headcount cap on status→active; `LeaveRequestResource` drops phantom `reviewer_id`, adds `attachment_path`/`reviewed_by`/`reviewed_at`/`review_note`; API `POST /api/v1/leave` accepts + stores `attachment`; department manager select (store/update validation, org-scoped candidate query); attendance status filter gains `HALF_DAY`/`REMOTE`; NFC issue notes captured + audited; cross-tenant manager dropdown leak fixed (org-scoped in `EmployeeController` create/edit and `DepartmentController`, `create()` takes `Request` again)
- ✅ **§40 employee profile** — `employees.show` rewritten: attendance-score + overtime-this-month header cards (`ScoreService`/`OvertimeService`/`RiskService` DI), extended overview fields (work location, DOB, address, emergency contact, overtime eligibility, last login), tabbed Leave / Monthly Status / NFC Card / Audit History with permission gating (self or matching key); fixed a Blade `@php(...)`-swallow compile bug that made the page 500
- ✅ **§73 spec leftovers** — `MANAGER_HR` two-stage approval (manager-or-company-admin stage 1 leaves PENDING + trail + `OvertimeStaged`; a different HR/admin confirms; system sweeps bypass), `EXPIRED` status from the ESCALATE expiry action (locked vs re-derivation, still decidable late, counted as pending everywhere — queue, dashboards, reports, summaries, salary potential), monthly cap enforced (`applyMonthlyCap` after weekly, shared newest-day-first `reduceToCap`), queue shows stage/expiry badges and stage-aware flash + "Confirm (HR)" button
- ✅ **Docs fixed** — API login paths corrected to `POST /api/v1/auth/login` + `GET /api/v1/auth/me` (README, roadmap, schema, rbac, architecture), notification inventory updated (incl. `OvertimeStaged`, payslip, monthly status), roadmap MANAGER_HR/EXPIRED/cap claims now match the code
- ✅ **Positions admin UI** — `Route::resource('positions')` (no `show`) + `PositionController`, every action `Gate::authorize('positions.manage')` (company_admin-only per catalog); per-tenant unique `title` (`Rule::unique` scoped by `organization_id`, `ignore` on update) + org-scoped `department_id` `Rule::exists`; audits `position.created`/`position.updated` (old-value diff) /`position.deleted`; index with title search, `withCount('employees')`, department name column; sidebar "Positions" entry under Employees gated by `positions.manage`; employee-form dropdown + profile Overview read the org-scoped `Position` model, and tests prove the full round trip (create → form dropdown → assign → profile)

Tests: **279 green** (11 from the field-sync batch + 6 new in `PositionsTest` — RBAC + nav gating, CRUD with audits, per-tenant title uniqueness + cross-tenant 404, org-scoped department rule, employee-form/profile round trip). pint clean, production assets built.

## Support tickets + password recovery (shipped)

- ✅ **Two-option forgot password** — the guest page keeps Option 1 (email reset link via `Password::sendResetLink`) and gains Option 2 "Create a support ticket" (email + optional message, validated against the `users` table with `organization_id` not null); `SupportTicketService::file()` writes the row, audits `support.created`, and pings every company_admin/manager-role user plus every `departments.manager_id` holder in the requester's org (`SupportTicketCreated` notification) — the role subquery bypasses Role's org scope, which would otherwise 1=0 in the guest context
- ✅ **Support section** — `Gate::support.view` (custom, not a catalog key: company_admin role, manager role, or manages a department), sidebar "Support" entry, index with status filter (All/Open/Resolved), show page with reply + "Mark resolved" + "Reset password & resolve"; audits `support.replied`/`support.resolved`; tenant isolation via `BelongsToOrganization`
- ✅ **Admin/manager password resets** — `PasswordResetService::issue()` generates an 8-char hard password (MD5 of random entropy, first 8 hex chars), rotates the remember token, sets `users.must_change_password`, sends `PasswordChangeRequired`, and audits `user.password_reset` with the value redacted; entry points: `POST users/{user}/reset-password` (`users.manage`, self blocked, amber one-time flash on the Users index) and `POST support/{ticket}/reset-password` (resolves the ticket in the same step)
- ✅ **Must-change-password until done** — new admin-created users (`users.store`, `employees.store`) and every admin-issued reset flip `must_change_password`; an amber banner on every authenticated page ("Please change your password" + CTA to `profile#password`) plus the standing bell notice stay until the profile form (or the email reset link) clears the flag and marks `PasswordChangeRequired` notifications read
- ✅ **Schema** — `support_tickets` table + `users.must_change_password` (boolean, default false)

Tests: **289 green** (10 new in `SupportTicketTest` — guest ticket flow + stakeholder notification + audit, known-email validation, gate matrix incl. HR-as-department-manager, cross-tenant 404/isolation, users-page reset + 8-char hash + redacted audit + self-block, ticket reset-and-resolve, reply/resolve, must-change banner lifecycle through the profile form, nav href gating). pint clean, production assets built.

## Phase 8 — Security & enterprise integration ✅

All milestones done (tests: 341 green after Phase 8; 350 after Phase 9):

- ✅ **M1 MFA (TOTP)** — `users.mfa_secret` (encrypted), `mfa_enabled`, `mfa_recovery_codes` (json, sha256-hashed); `TotpService` (RFC 6238, base32 secret, ±1 window drift) + `MfaService` (begin/confirm/disable, one-time 8×10-char recovery codes, challenge staging in session `mfa.pending_user_id`/`mfa.pending_secret`); profile Volt card with QR (`BaconQrCode` SVG backend), enable/confirm/disable/regenerate-recovery-codes; login flow stages a challenge (`mfa.challenge` Volt page, rate limited) after password success when `mfa_enabled`, accepts TOTP or a recovery code, then logs in
- ✅ **M2 API keys** — `api_keys` table (`name`, non-secret `prefix`, sha256 `key_hash`, `abilities` json, `last_used_at`/`revoked_at`); full key `emsak_{40}` shown once on create (session flash), `settings/api-keys` management page (`settings.manage`); `auth:apikey` guard via `Auth::viaRequest` (must also exist in `config/auth.php`) resolves the key from `Authorization: Bearer`/`X-Api-Key`; `ApiKey::canPermission()` checks `abilities` (`*` = all), org-scoped by the same `EnsureApiOrganization` middleware; `/api/v1/*` routes accept `auth:sanctum,apikey`
- ✅ **M3 SCIM 2.0** — `scim_tokens` table + `auth:scim` guard; `/api/scim/v2` group (`ServiceProviderConfig`, `ResourceTypes`, `Users`, `Groups`) with bearer-token auth; Users: create (employee role auto-attach, name from `name.formatted`/given+family), list (userName filter), show, PATCH (userName/name/password, `active=false` → delete), DELETE; all writes audited (`scim.user_created|updated|deleted`); token management on the API-keys page
- ✅ **M4 Webhooks** — `webhook_subscriptions` (`url`, `events` json, `secret`, `is_active`, delivery bookkeeping); `WebhookService::dispatch` filters by event + `WebhookSubscription::matches`, queues `DeliverWebhook` (POST raw JSON body, `X-Webhook-Event` + `X-Webhook-Signature: sha256=<hmac>` headers, 3-attempt retry via queue); events: `employee.created|updated|deleted`, `leave.requested|approved|rejected`, `overtime.approved|rejected`, `attendance.punched`, `payslip.finalized`, `subscription.changed`; management page with per-subscription secret shown once + SSRF guard rejecting localhost/private-IP targets
- ✅ **M5 OIDC SSO** — per-org settings (`organizations.settings`: `sso_enabled`, `sso_name`, `sso_discovery_url`, `sso_client_id`, `sso_client_secret` (encrypted at rest), `sso_allowed_domains`); settings page with discovery-URL test; login page lists SSO-enabled orgs; `GET sso/{organization}` redirects to the IdP (`state` in session, PKCE not required by spec); `GET sso/callback` verifies state, exchanges the code at the token endpoint (raw HTTP), reads identity from the userinfo endpoint (no local JWT verification), enforces the allowed-domain allowlist, then links an existing org user by email or auto-provisions a new employee-role user; SAML deferred (spec allows OIDC)
- ✅ **M6 Role permission editor** — `GET roles` + `GET/PUT roles/{role}` (`settings.manage`): per-role permission checkboxes against the 30-key catalog; `company_admin` role locked (read-only, all keys on); hr/manager/employee fully editable; validation rejects unknown keys; role/permission changes audited
- ✅ **M7 Audit CSV export** — `GET audit-logs/export` (`audit.view`): streamed CSV honoring the same action/actor/resource/date filters as the index; Export button on the audit page

## Phase 9 — Stripe billing ✅

Shipped over the raw Laravel HTTP client (`Http::fake`-friendly — the `stripe/stripe-php` package was removed in favor of `StripeService` calling `api.stripe.com` directly):

- ✅ **Config** — `services.stripe.secret` (`STRIPE_SECRET_KEY`) + `services.stripe.webhook_secret` (`STRIPE_WEBHOOK_SECRET`); `STRIPE_MODE` not needed — checkout only starts for paid plans, plan switching stays direct on the Billing page
- ✅ **Checkout** — `POST billing/stripe/checkout` (`settings.manage`) → `StripeService::checkoutUrl` creates a Checkout Session (`mode: subscription`, success/cancel back to Billing) and redirects; `plans.stripe_price_id` holds the Stripe price for paid plans; customer auto-created/reused via `organizations.settings.stripe_customer_id` → `subscriptions.stripe_customer_id`
- ✅ **Webhook** — `POST api/stripe/webhook` (no auth, throttled): HMAC-SHA256 signature check (`t=`/`v1=` with 300s tolerance, raw body); handles `checkout.session.completed` (activates the plan + subscription in place), `customer.subscription.updated|deleted` (status sync, cancellation), `invoice.paid` (marks the open invoice paid); `StripeWebhookController` returns 503 when unconfigured, 400 on bad signatures; `markInvoicePaid` bypasses the org global scope (webhook has no session user)
- ✅ **UI** — Billing page "Pay with Stripe" button for paid plans (hidden when `stripe_price_id` is empty or Stripe is unconfigured), checkout success/canceled banners

Deferred: SAML SSO (OIDC covers the spec), Stripe Customer Portal, proration rules.

## Standing decisions (locked)

| Decision | Choice |
|---|---|
| Project path | `E:\employee-saas` |
| Frontend | Blade + Livewire (Volt), not Next.js |
| Tenancy | Shared DB + `organization_id`, not stancl/tenancy |
| Scope | Phases 1–7 + spec leftovers + field-sync/audit/notification fixes + Phase 8 + Phase 9 fully complete |
