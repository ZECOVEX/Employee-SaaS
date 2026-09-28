# Database Schema

All tenant tables carry `organization_id` (FK → organizations, cascade delete) unless noted.

## organizations
| Column | Notes |
|---|---|
| id, name, slug (unique), timezone, status (`active`/`suspended`), timestamps | Tenancy root |
| settings (json, nullable) | Per-org knobs: `debounce_seconds` (punch debounce window, 5–300, default 15), `time_format` (`24h` default → 16:00 / `12h` → 4:00 PM; drives every clock display via `Organization::formatTime()` and the `<x-time>` component), plus salary rules (`currency`, `late_deduction` `per_minute`/`none`, `deduction_grace_minutes` 0–240, `deduction_rounding` `exact`/`nearest`/`whole`, `absence_deduction` `full_day`/`half_day`/`none`, `half_day_deduction`, `unpaid_leave_deduction` bool, `max_deduction_percent` 0–100 — defaults in `Organization::salaryRules()`) |

## users (tenant-scoped but NO global scope)
| Column | Notes |
|---|---|
| organization_id | null only for platform admins |
| name, email (unique), password, remember_token | Breeze auth |
| email_verified_at, last_login_at | |
| is_platform_admin | boolean; bypasses RBAC |

## RBAC tables
- **permissions** — global catalog: `key` (unique), `name`, `group`
- **roles** — per-org: `organization_id`, `name`, `slug` (unique per org)
- **role_permission** — pivot `role_id`/`permission_id`
- **user_role** — pivot `user_id`/`role_id`

## Organization tables
- **departments** — `organization_id`, `name`, `code` (unique per org), `manager_id` (FK users, nullable)
- **positions** — `organization_id`, `title`, `code`
- **employees** — `organization_id`, `user_id` (nullable), `employee_code` (unique per org), `department_id` (nullable), `designation`, `employment_type`, `status`, `joining_date`, `left_date`, `phone`, `address`, `date_of_birth`, `gender`, `salary` (decimal, hidden from index UI in Phase 1), `photo_path` (nullable; `image|max:2048` upload stored on the `public` disk under `employees/{id}/`)
- **audit_logs** — `organization_id`, `actor_user_id`, `action`, `resource_type`, `resource_id`, `old_value` (json), `new_value` (json), `ip_address`, `user_agent`, `created_at`

## Phase 2 — Workforce tables
- **work_schedules** — `organization_id`, `name`, `work_days` (json array of ISO day numbers), `start_time`, `end_time`, `grace_minutes`, `break_start`/`break_end` (nullable), `is_default`, `effective_from`/`effective_to` (nullable dates, indexed) — effective-dated versions (§11): a schedule change closes the current version (`effective_to` = yesterday) and opens a new one (`effective_from` = today), so re-deriving past days resolves the schedule they were worked under; plain index on `organization_id`+`is_default` (multiple historical rows may exist)
- **holidays** — `organization_id`, `name`, `date` (stored `Y-m-d` via `date:Y-m-d` cast), `is_recurring`
- **nfc_cards** — `organization_id`, `employee_id` (nullable), `card_token` (unique), `status` (`active`/`blocked`/`revoked`), `issued_at`, `revoked_at`, `last_used_at`
- **attendance_terminals** — `organization_id`, `name`, `location`, `api_key_hash`, `status` (`active`/`revoked`), `last_seen_at`
- **attendance_events** — `organization_id`, `employee_id`, `terminal_id` (nullable), `nfc_card_id` (nullable), `event_type` (`check_in`/`check_out`/`break_start`/`break_end`/`manual_in`/`manual_out`), `occurred_at`, `timezone`, `source` (`nfc`/`manual`/`api`), `notes`, `created_by`, `superseded_by` (nullable self-FK), `deleted_at` (soft delete) — source of truth; raw rows are never overwritten: an admin correction (§24) creates a replacement event and points the original at it via `superseded_by`, removals are soft-deleted, and derivation/punching only reads active rows (`superseded_by IS NULL AND deleted_at IS NULL`); `occurred_at` + `timezone` hold the **org-local wall-clock as entered/tapped** (no instant conversion — derivation and display compare/format these wall times directly, so UTC+6 orgs never see a +6h shift); daily rows are derived from these
- **daily_attendance** — table name is singular by spec; `organization_id`, `employee_id`, `date` (stored `Y-m-d` via `date:Y-m-d` cast), `first_check_in`, `last_check_out`, `total_work_minutes` (sum of IN→OUT segments, gap-aware break deduction — not first-in→last-out), `late_minutes`, `early_leave_minutes`, `overtime_minutes`, `segment_count` (completed segments that day), `review_flag` (nullable: `possible_missed_checkin` / `excessive_segments`), `status` (`PRESENT`/`LATE`/`ABSENT`/`HALF_DAY`/`LEAVE`/`HOLIDAY`/`WEEKEND`/`REMOTE`), `is_manual`
- **leave_types** — `organization_id`, `name`, `code`, `default_days_per_year`, `is_paid`, `is_active`
- **leave_requests** — `organization_id`, `employee_id`, `leave_type_id`, `start_date`, `end_date`, `days`, `reason`, `status` (`pending`/`approved`/`rejected`/`cancelled`), `reviewed_by`, `review_note`
- **leave_balances** — `organization_id`, `employee_id`, `leave_type_id`, `year`, `total_days`, `used_days` — unique (`employee_id`, `leave_type_id`, `year`)

Defaults seeded per org by `WorkforceDefaults::apply()`: Standard schedule (Mon–Fri 09:00–18:00, 15 min grace) and 5 leave types (ANNUAL 20, SICK 10, CASUAL 10, EMERGENCY 5, UNPAID 0).

## Phase 3 — Compensation tables
- **salary_records** — `organization_id`, `employee_id`, `basic_salary`, `allowances`, `bonus`, `deductions`, `gross_salary`, `net_salary` (decimals 12,2), `effective_from`/`effective_to` (dates; `effective_to` nullable), `notes` (500, nullable), `created_by` (FK users, nullable) — effective-dated (§28): `SalaryRecord::effectiveFor(org, employee, date)` picks the latest row with `effective_from ≤ date` and `effective_to` null-or-≥ date, so a raise never rewrites earlier months (Salary Report and payslips both read through it); index (`organization_id`, `employee_id`, `effective_from`)
- **payslips** — `organization_id`, `employee_id`, `period` (char7 `YYYY-MM`), `period_start`/`period_end`, `revision` (default 1), `currency`; earnings snapshot (`basic_salary`, `allowances`, `bonus`, `other_deductions`, `gross_salary`); attendance-deduction snapshot (`late_deduction`, `absence_deduction`, `unpaid_leave_deduction`, `attendance_deduction_total`, `net_salary`); month-end context §52 (`scheduled_days`, `completed_days`, `worked_minutes`, `late_minutes`, `overtime_minutes`, `daily_rate`, `hourly_rate`, `capped`); `snapshot` (json — full calculator output for reproducibility), `notes`, `superseded_by` (self-FK, nullable), `created_by` (FK users, nullable) — one **active** row per employee+period (`superseded_by IS NULL`, enforced in `PayslipController`); revising bumps `revision` and points the old row's `superseded_by` at the new one inside a transaction, so finalized numbers never move when salary records or rules change later; index (`organization_id`, `employee_id`, `period`)

## Stock Breeze tables
- `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` (framework)

## Key constraints / uniqueness
- `organizations.slug` global unique
- `users.email` global unique
- `roles` unique (`organization_id`, `slug`)
- `departments` unique (`organization_id`, `code`)
- `employees` unique (`organization_id`, `employee_code`)
- `nfc_cards` unique `card_token`
- `daily_attendance` unique (`organization_id`, `employee_id`, `date`)
- `leave_balances` unique (`organization_id`, `employee_id`, `leave_type_id`, `year`)
