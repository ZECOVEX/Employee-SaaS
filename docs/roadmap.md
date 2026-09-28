# Roadmap

Phase 1 (MVP) is complete: auth, tenancy, RBAC, employees, departments, users, dashboards, audit logs, settings, platform admin.

Phase 2 (workforce operations) is complete: attendance, NFC check-in, leave management, work schedules.

Phase 3 (compensation & insight) is complete: salary records, deduction rules + Statistics, payslips, reports/CSV, analytics.

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

## Phase 4 — Platform

- Billing & subscription (per-org plans)
- Email/notification templates
- WebSockets / real-time events
- CI/CD pipelines
- Docker images for app deploy
- API (Eloquent API resources, versioning)

## Standing decisions (locked)

| Decision | Choice |
|---|---|
| Project path | `E:\employee-saas` |
| Frontend | Blade + Livewire (Volt), not Next.js |
| Tenancy | Shared DB + `organization_id`, not stancl/tenancy |
| Scope | Phases 1–3 complete; Phase 4 next |
