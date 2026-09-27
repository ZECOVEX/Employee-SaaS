# Database Schema

All tenant tables carry `organization_id` (FK → organizations, cascade delete) unless noted.

## organizations
| Column | Notes |
|---|---|
| id, name, slug (unique), timezone, status (`active`/`suspended`), timestamps | Tenancy root |

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
- **employees** — `organization_id`, `user_id` (nullable), `employee_code` (unique per org), `department_id` (nullable), `designation`, `employment_type`, `status`, `joining_date`, `left_date`, `phone`, `address`, `date_of_birth`, `gender`, `salary` (decimal, hidden from index UI in Phase 1)
- **audit_logs** — `organization_id`, `actor_user_id`, `action`, `resource_type`, `resource_id`, `old_value` (json), `new_value` (json), `ip_address`, `user_agent`, `created_at`

## Stock Breeze tables
- `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` (framework)

## Key constraints / uniqueness
- `organizations.slug` global unique
- `users.email` global unique
- `roles` unique (`organization_id`, `slug`)
- `departments` unique (`organization_id`, `code`)
- `employees` unique (`organization_id`, `employee_code`)
