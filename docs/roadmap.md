# Roadmap

Phase 1 (this repository state) is complete: auth, tenancy, RBAC, employees, departments, users, dashboards, audit logs, settings, platform admin.

## Phase 2 — Workforce operations (out of scope for MVP)

- NFC / physical check-in devices and enrollment
- Attendance tracking (check-in/out, late/early rules)
- Leave management (types, balances, approval workflows)
- Scoring / performance ratings

## Phase 3 — Compensation & insight

- Salary management UI (components exist: `salary.view` / `salary.view_own` / `salary.edit` permissions are catalogued but unused)
- Payslips, revisions history
- Reports (`reports.view` permission reserved)
- Analytics dashboards

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
| Scope | Phase 1 only; Phases 2+ deferred |
