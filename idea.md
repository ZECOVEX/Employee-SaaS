# Build a Production-Ready Multi-Tenant Employee Management SaaS

## 1. PRODUCT OVERVIEW

Build a modern, production-ready SaaS platform for IT companies to manage:

* Employees
* Employee profiles
* Departments
* Roles
* Attendance
* NFC-based check-in/check-out
* Working hours
* Late arrivals
* Early departures
* Leave requests
* Leave balances
* Monthly salary/income
* Monthly employee status
* Performance/attendance score
* Risk indicators
* Admin management
* Company settings
* Audit logs
* Notifications
* Reports
* Dashboard analytics

The platform must be designed as a **multi-tenant SaaS**, meaning multiple companies can use the same platform while their data remains completely isolated.

The system should be scalable enough to support thousands of companies and hundreds of thousands of employees.

---

# 2. CORE CONCEPT

Each company creates an organization account.

Example:

Company A
├── Admin
├── HR
├── Manager
├── Employees
└── Departments

Company B
├── Admin
├── HR
├── Manager
└── Employees

Company A must NEVER be able to access Company B's data.

Every organization-owned database record must contain an:

`organization_id`

and every API request must enforce tenant isolation.

---

# 3. USER TYPES

Implement Role-Based Access Control.

## SUPER ADMIN

Platform owner.

Capabilities:

* Manage organizations
* Suspend organizations
* View SaaS-wide statistics
* Manage subscriptions
* Manage system configuration
* View system audit logs
* Manage platform administrators

Super Admin should NOT normally access employee salary information unless explicitly authorized through a privileged administrative workflow.

---

## COMPANY ADMIN

Company owner/administrator.

Capabilities:

* Create/manage employees
* Edit employee profiles
* Assign departments
* Assign roles
* Issue/revoke NFC cards
* View attendance
* Correct attendance
* Approve/reject leave
* Configure working hours
* Configure holidays
* Configure attendance rules
* Configure scoring rules
* View salary information
* Edit salary information
* Generate reports
* Manage HR users
* Manage managers
* View company analytics

Only authorized administrators can edit salary/income.

---

## HR

Capabilities:

* Manage employee records
* Manage attendance
* Manage leave
* Manage departments
* View salary
* Generate HR reports
* Approve/reject leave depending on permissions

Salary editing should be configurable.

---

## MANAGER

Capabilities:

* View assigned employees
* View attendance
* View team statistics
* Approve team leave requests
* View employee monthly status
* View team performance

Managers should NOT automatically see salary.

---

## EMPLOYEE

Capabilities:

* Login
* View own profile
* View own attendance
* View own monthly status
* View own leave balance
* Request leave
* View leave history
* View own salary/income
* View own attendance score
* View monthly working statistics
* View assigned NFC card status
* Update permitted profile fields

Employee cannot:

* Edit salary
* Edit attendance
* Modify score
* Modify leave balance
* Modify another employee
* Access another employee's private information

---

# 4. EMPLOYEE PROFILE

Each employee should have:

## Personal Information

* Employee ID
* Full name
* Profile picture
* Email
* Phone
* Date of birth
* Address
* Emergency contact

## Employment Information

* Joining date
* Employee status
* Department
* Designation
* Manager
* Employment type
* Work location
* Shift
* Working days

## Security Information

* User account
* MFA status
* NFC card ID
* NFC card status
* Last login
* Last attendance punch
* Account status

## Financial Information

Salary information must be private.

Fields:

* Monthly gross salary
* Basic salary
* Allowances
* Bonuses
* Deductions
* Net salary

Only authorized admins/HR can edit financial information.

Employees can view their own salary if company policy allows it.

Every salary modification must create an audit log.

---

# 5. NFC ATTENDANCE SYSTEM

This is one of the core features.

Every employee receives an NFC ID card.

The card should contain a unique identifier.

Example:

`NFC-EMP-8F29A13C`

Do NOT store sensitive employee information directly on the NFC card.

The NFC card should contain only a secure/random identifier.

Example:

```text
NFC Card
    ↓
Random Card UID / Token
    ↓
Attendance Terminal
    ↓
Backend API
    ↓
Validate Card
    ↓
Identify Employee
    ↓
Create Attendance Event
```

---

# 6. ATTENDANCE TERMINAL

Build a dedicated attendance interface.

Possible hardware:

* Android phone/tablet with NFC
* NFC reader connected to Raspberry Pi
* NFC-enabled kiosk
* USB NFC reader

The terminal should authenticate itself with the backend.

Example:

```text
Terminal ID:
TERM-DHK-001

Location:
Dhaka Office

Status:
Active
```

A terminal must NOT be trusted simply because it knows an API endpoint.

Use:

* Device credentials
* API keys
* Device certificates where appropriate
* Device registration
* Device revocation
* Request signing
* TLS

---

# 7. CHECK-IN FLOW

Employee taps NFC card.

System:

```text
NFC CARD
   ↓
READ CARD TOKEN
   ↓
SEND TOKEN + TERMINAL ID
   ↓
BACKEND
   ↓
VALIDATE TERMINAL
   ↓
VALIDATE NFC CARD
   ↓
FIND EMPLOYEE
   ↓
CHECK CURRENT ATTENDANCE STATE
   ↓
CREATE CHECK-IN EVENT
   ↓
RETURN SUCCESS
```

Display:

```text
✓ Attendance Recorded

Good morning, Pranto

Check-in:
09:03 AM

Status:
Present
```

---

# 8. CHECK-OUT FLOW

Employee taps NFC card when leaving.

Backend checks the employee's current attendance state.

If currently checked in:

```text
CHECK-IN
09:03 AM

CHECK-OUT
06:12 PM
```

Calculate:

```text
Working Duration
= Check-out - Check-in
```

Display:

```text
✓ Check-out Recorded

Good evening, Pranto

Check-out:
06:12 PM

Working Time:
9h 09m
```

---

# 9. ATTENDANCE EVENT MODEL

Do NOT simply overwrite attendance.

Store immutable attendance events.

Example:

```text
attendance_events

id
organization_id
employee_id
terminal_id
nfc_card_id
event_type
timestamp
timezone
ip_address
device_identifier
created_at
```

Event types:

```text
CHECK_IN
CHECK_OUT
BREAK_START
BREAK_END
```

Then derive daily attendance records from events.

This provides a strong audit trail.

---

# 10. DAILY ATTENDANCE

Create a daily attendance record:

```text
employee_id
date
first_check_in
last_check_out
total_work_duration
late_minutes
early_leave_minutes
overtime_minutes
attendance_status
```

Possible statuses:

```text
PRESENT
LATE
ABSENT
HALF_DAY
LEAVE
HOLIDAY
WEEKEND
REMOTE
```

---

# 11. WORKING HOURS

Company administrators should configure:

```text
Office Start:
09:00

Office End:
18:00

Grace Period:
15 minutes

Break:
13:00 - 14:00
```

Example:

Employee arrives:

```text
08:57
```

Result:

```text
Present
```

Employee arrives:

```text
09:22
```

Result:

```text
Late
Late by: 22 minutes
```

The rules must be configurable.

Do NOT hardcode working hours.

---

# 12. LEAVE MANAGEMENT

Employees can submit leave requests.

Fields:

```text
Leave Type
Start Date
End Date
Reason
Attachment
```

Leave types:

* Annual Leave
* Sick Leave
* Casual Leave
* Emergency Leave
* Unpaid Leave
* Other

Workflow:

```text
Employee
   ↓
Submit Leave
   ↓
Manager
   ↓
Approve / Reject
   ↓
HR/Admin
   ↓
Leave Balance Updated
```

Leave status:

```text
PENDING
APPROVED
REJECTED
CANCELLED
```

---

# 13. LEAVE BALANCE

Example:

```text
Annual Leave
Total: 20
Used: 7
Remaining: 13
```

The system should automatically update balances when leave is approved.

Do NOT update balance merely because a request was submitted.

---

# 14. MONTHLY EMPLOYEE DASHBOARD

Each employee should have a personal dashboard.

Example:

```text
Good Morning, Pranto

September 2026

Attendance Score
----------------
87 / 100

Status
------
GOOD

Attendance
----------
Present: 19
Late: 2
Absent: 0
Leave: 2

Working Hours
-------------
Expected: 152h
Worked: 147h 32m

Average Arrival
---------------
09:08 AM

Average Departure
-----------------
06:04 PM

Leave
-----
Remaining: 13 days

Salary
------
Monthly Income: ৳XX,XXX
```

---

# 15. EMPLOYEE RISK STATUS

Create a configurable employee status system.

Example:

```text
Score >= 90
Excellent

80 - 89
Good

70 - 79
Needs Attention

< 70
At Risk
```

IMPORTANT:

Do NOT hardcode these values.

Administrators should be able to configure:

* Thresholds
* Score weights
* Status labels
* Calculation rules

---

# 16. ATTENDANCE SCORE

Create a transparent scoring engine.

Example:

```text
Attendance Score =

Attendance Rate × 40%
Punctuality × 25%
Working Hours × 20%
Leave Compliance × 15%
```

Example:

```text
Attendance Rate: 95
Punctuality: 82
Working Hours: 90
Leave Compliance: 100

Final Score:

95 × 0.40
82 × 0.25
90 × 0.20
100 × 0.15

= 91.55
```

Display the breakdown.

Employees should understand WHY they received their score.

Never hide the scoring formula.

---

# 17. MONTHLY STATUS

Generate a monthly employee record.

Example:

```text
employee_monthly_status

id
organization_id
employee_id
month
year

attendance_score
attendance_rate
punctuality_score
working_hours_score
leave_score

days_present
days_absent
days_late
days_on_leave

total_work_minutes
expected_work_minutes

status
generated_at
```

Statuses should be calculated automatically.

---

# 18. ADMIN DASHBOARD

Admin dashboard should show:

```text
Total Employees
Active Employees
Present Today
Late Today
Absent Today
On Leave
Average Attendance
Average Score
At Risk Employees
```

Charts:

* Attendance trend
* Monthly attendance
* Late arrival trend
* Leave usage
* Department attendance
* Working hours
* Employee score distribution

---

# 19. LIVE ATTENDANCE BOARD

Admin should have a real-time view:

```text
EMPLOYEE       STATUS       TIME

Pranto         PRESENT      09:02
Rahim          PRESENT      09:08
Sakib          LATE         09:31
Nadia          ON LEAVE
Hasan          ABSENT
```

Use WebSockets or Server-Sent Events for live updates.

---

# 20. NFC CARD MANAGEMENT

Admin interface:

```text
NFC Cards

Card ID             Employee       Status
------------------------------------------------
NFC-001             Pranto         ACTIVE
NFC-002             Rahim          ACTIVE
NFC-003             Unassigned     AVAILABLE
NFC-004             Sakib          BLOCKED
```

Actions:

* Issue card
* Assign card
* Unassign card
* Block card
* Replace card
* Revoke card
* View card history

Card replacement must preserve attendance history.

---

# 21. SECURITY REQUIREMENTS

Security must be treated as a first-class feature.

Implement:

### Authentication

* Secure password hashing
* Argon2id or bcrypt
* Session management
* Refresh token rotation if using JWT
* MFA/TOTP
* Email verification
* Password reset
* Login rate limiting

### Authorization

Use RBAC.

Every backend endpoint must check:

```text
Authentication
+
Organization
+
Role
+
Resource ownership
```

Never trust frontend authorization.

---

# 22. MULTI-TENANT SECURITY

Every tenant-owned database query must include:

```text
organization_id
```

Example:

```sql
SELECT *
FROM employees
WHERE id = ?
AND organization_id = ?
```

Never allow:

```sql
SELECT *
FROM employees
WHERE id = ?
```

without tenant authorization.

Implement automated tests specifically for:

* Cross-tenant access
* IDOR
* Privilege escalation
* Broken access control

---

# 23. AUDIT LOGGING

Create a centralized audit log.

Example:

```text
audit_logs

id
organization_id
actor_user_id
action
resource_type
resource_id
old_value
new_value
ip_address
user_agent
timestamp
```

Log sensitive actions:

* Salary changes
* Employee deletion
* Role changes
* NFC card assignment
* NFC card revocation
* Attendance correction
* Leave approval
* Leave rejection
* Admin changes
* Password changes
* MFA changes

Audit logs should be append-only from the application perspective.

---

# 24. ATTENDANCE CORRECTION

Admins may need to correct attendance.

Example:

Employee forgot NFC card.

Admin can add:

```text
Manual Check-in
09:12
Reason:
Employee forgot NFC card
```

Every manual modification must record:

```text
Who changed it
When
What changed
Why
Previous value
New value
```

Employees should be able to see that an attendance record was manually adjusted where company policy permits.

---

# 25. NOTIFICATIONS

Implement notification infrastructure.

Channels:

* In-app
* Email
* Optional Telegram/WhatsApp integration later

Notifications:

```text
Leave approved
Leave rejected
Late arrival
Attendance correction
NFC card blocked
Password changed
Salary updated
Monthly status generated
```

---

# 26. DATABASE ARCHITECTURE

Use PostgreSQL.

Recommended core tables:

```text
organizations

users

roles

permissions

user_roles

employees

departments

positions

employment_records

salary_records

nfc_cards

attendance_terminals

attendance_events

daily_attendance

work_schedules

holidays

leave_types

leave_balances

leave_requests

monthly_employee_status

notifications

audit_logs

sessions

api_keys

subscriptions

invoices
```

Every tenant-specific table should include:

```text
organization_id
```

where applicable.

---

# 27. DATABASE RELATIONSHIPS

Basic architecture:

```text
Organization
     │
     ├── Users
     │
     ├── Employees
     │      │
     │      ├── Salary Records
     │      ├── NFC Card
     │      ├── Attendance
     │      ├── Leave
     │      └── Monthly Status
     │
     ├── Departments
     │
     ├── Work Schedules
     │
     ├── Holidays
     │
     └── Attendance Terminals
```

---

# 28. SALARY DATA MODEL

Do NOT overwrite historical salary records.

Use effective dates.

Example:

```text
salary_records

id
employee_id
organization_id

basic_salary
allowances
bonus
deductions
gross_salary
net_salary

effective_from
effective_to

created_by
created_at
updated_at
```

Example:

```text
Jan-Jun
Salary = 30,000

Jul-Dec
Salary = 35,000
```

Historical reports must continue showing the correct historical salary.

---

# 29. API ARCHITECTURE

Use REST API initially.

Example:

```text
/api/v1/auth

/api/v1/organizations

/api/v1/users

/api/v1/employees

/api/v1/departments

/api/v1/attendance

/api/v1/attendance/events

/api/v1/nfc

/api/v1/leave

/api/v1/salary

/api/v1/dashboard

/api/v1/reports

/api/v1/audit-logs

/api/v1/notifications
```

NFC-specific endpoint:

```text
POST /api/v1/attendance/nfc/punch
```

Request:

```json
{
  "card_token": "NFC_RANDOM_TOKEN",
  "terminal_id": "TERM-DHK-001"
}
```

Backend determines:

```text
Employee
Organization
Attendance state
Check-in/check-out
Timestamp
```

Never accept:

```json
{
  "employee_id": 123,
  "action": "check_in"
}
```

from an untrusted NFC terminal and blindly trust it.

---

# 30. API SECURITY

Implement:

* HTTPS only
* CORS policy
* CSRF protection where applicable
* Rate limiting
* Request validation
* Input sanitization
* SQL injection prevention
* Secure headers
* API authentication
* Device authentication
* API key rotation
* Replay protection for NFC punches
* Request IDs
* Structured logging

---

# 31. NFC REPLAY PROTECTION

Do not rely solely on a static NFC identifier if the threat model requires stronger protection.

Possible architecture:

```text
NFC Card
    ↓
Card identifier
    ↓
Terminal challenge
    ↓
Secure authentication protocol
    ↓
Backend validation
    ↓
Attendance event
```

For an MVP, static random card tokens may be acceptable for controlled office environments.

For stronger deployments, support secure NFC cards with cryptographic authentication.

---

# 32. LOCATION SECURITY

Attendance terminals should be registered to a physical office.

Example:

```text
Terminal:
DHK-OFFICE-01

Location:
Dhaka HQ

Status:
ACTIVE
```

Optionally support:

* IP allowlists
* Wi-Fi/network verification
* GPS verification for mobile terminals
* Device certificates

Do NOT rely on GPS alone for security.

---

# 33. REAL-TIME ARCHITECTURE

Use:

```text
NFC Terminal
      ↓
API
      ↓
Attendance Service
      ↓
PostgreSQL
      ↓
Event Bus
      ↓
WebSocket Server
      ↓
Admin Dashboard
```

When someone punches:

```text
Employee taps NFC
        ↓
Attendance event created
        ↓
Event emitted
        ↓
Admin dashboard updates instantly
```

---

# 34. BACKGROUND JOBS

Use a queue system.

Jobs:

```text
Calculate monthly score
Generate monthly reports
Send notifications
Calculate leave balances
Process attendance events
Generate payroll summaries
Send reminders
Generate analytics
```

Possible stack:

```text
Redis
+
BullMQ
```

---

# 35. CACHING

Use Redis for:

* Sessions
* Rate limiting
* Temporary attendance state
* Dashboard caching
* WebSocket presence
* Background job queues

Do NOT use Redis as the permanent source of truth for attendance.

PostgreSQL remains authoritative.

---

# 36. FILE STORAGE

Employee documents may include:

* Profile images
* Leave attachments
* Employment documents

Use object storage:

```text
S3-compatible storage
```

Examples:

* Cloudflare R2
* AWS S3
* MinIO

Never store uploaded files directly inside PostgreSQL.

---

# 37. FRONTEND

Build a responsive web application.

Recommended:

```text
Next.js
TypeScript
Tailwind CSS
shadcn/ui
React Query / TanStack Query
```

Design:

* Modern
* Professional
* Minimal
* Enterprise SaaS
* Responsive
* Dark/light mode
* Accessible

---

# 38. DASHBOARDS

Create separate dashboards.

### Super Admin

```text
Organizations
Users
Subscriptions
System Health
Platform Analytics
Audit Logs
```

### Company Admin

```text
Employees
Attendance
Leave
Salary
Departments
NFC Cards
Reports
Settings
```

### Manager

```text
My Team
Attendance
Leave
Monthly Status
```

### Employee

```text
My Dashboard
Attendance
Leave
Salary
Monthly Status
Profile
```

---

# 39. EMPLOYEE DASHBOARD UI

Top:

```text
Good morning, Pranto

September 2026
```

Main card:

```text
87
Attendance Score

GOOD
```

Cards:

```text
19
Present

2
Late

0
Absent

2
Leave
```

Working hours:

```text
147h 32m
of 152h expected
```

Attendance calendar:

```text
Mon Tue Wed Thu Fri Sat Sun
 P   P   L   P   P   -   -
 P   P   P   A   P   -   -
```

Recent attendance:

```text
Sep 23
09:03 → 18:07
9h 04m

Sep 22
09:12 → 18:03
8h 51m
```

---

# 40. ADMIN EMPLOYEE PROFILE

Admin sees:

```text
Employee Profile

[PHOTO]

Pranto Kumar
Software Engineer

Employee ID:
EMP-001

Department:
Engineering

Joined:
12 Jan 2025

Attendance Score:
87

Monthly Salary:
৳XX,XXX
```

Tabs:

```text
Overview
Attendance
Leave
Salary
Monthly Status
NFC Card
Audit History
```

Salary tab must require appropriate permission.

---

# 41. REPORTING

Generate:

### Attendance Report

Filters:

```text
Employee
Department
Date range
Status
```

Export:

```text
CSV
Excel
PDF
```

### Monthly Report

```text
Employee
Present
Absent
Late
Leave
Working Hours
Score
Status
```

### Salary Report

Restricted to authorized users.

---

# 42. SEARCH AND FILTERING

Global search should support:

```text
Employee ID
Name
Email
Department
Designation
NFC card
```

All search results must respect tenant boundaries and RBAC.

---

# 43. DATA PRIVACY

Treat salary and personal employee information as sensitive.

Implement:

* Encryption in transit
* Encryption at rest where appropriate
* Least privilege
* Access logging
* Data retention policies
* Account deletion workflow
* Data export
* Privacy settings

Never expose salary information in:

* Public APIs
* Client-side source
* Search indexes
* Logs
* Analytics endpoints

---

# 44. OBSERVABILITY

Implement:

```text
Application logs
Audit logs
Error tracking
Metrics
Health checks
Database monitoring
```

Endpoints:

```text
/health
/ready
```

Monitor:

```text
API latency
Error rate
Database connections
Queue depth
Attendance event processing
WebSocket connections
```

---

# 45. DEPLOYMENT ARCHITECTURE

Initial deployment:

```text
                    Internet
                       │
                       ▼
                Cloudflare
                       │
                       ▼
                Reverse Proxy
                       │
          ┌────────────┴────────────┐
          │                         │
       Frontend                  Backend
       Next.js                  API Server
          │                         │
          └──────────┬──────────────┘
                     │
              ┌──────┴──────┐
              │             │
          PostgreSQL       Redis
              │             │
              └──────┬──────┘
                     │
                 Worker
```

For MVP, Docker Compose is acceptable.

Production should eventually move toward:

```text
Docker
+
Managed PostgreSQL
+
Managed Redis
+
Object Storage
+
Cloudflare
+
CI/CD
```

---

# 46. DOCKER ARCHITECTURE

Create:

```text
docker-compose.yml

services:

frontend
backend
worker
postgres
redis
nginx
```

Use separate containers.

Never run the application as root inside containers.

Use environment variables for secrets.

---

# 47. ENVIRONMENT VARIABLES

Example:

```env
DATABASE_URL=
REDIS_URL=

JWT_SECRET=
SESSION_SECRET=

S3_ENDPOINT=
S3_ACCESS_KEY=
S3_SECRET_KEY=

SMTP_HOST=
SMTP_USER=
SMTP_PASSWORD=

STRIPE_SECRET_KEY=

NEXT_PUBLIC_API_URL=
```

Never commit `.env`.

Provide:

```text
.env.example
```

---

# 48. CI/CD

Use GitHub Actions.

Pipeline:

```text
Push
 ↓
Lint
 ↓
Type Check
 ↓
Unit Tests
 ↓
Integration Tests
 ↓
Security Tests
 ↓
Build
 ↓
Docker Build
 ↓
Deploy
```

Run dependency security scanning.

---

# 49. SECURITY TESTING

Because this is an employee-management SaaS, security must be tested aggressively.

Create tests for:

### Authentication

* Brute force
* Session fixation
* Token theft
* Password reset abuse
* MFA bypass

### Authorization

* IDOR
* Horizontal privilege escalation
* Vertical privilege escalation
* Cross-tenant access

### API

* SQL injection
* NoSQL injection if applicable
* XSS
* CSRF
* SSRF
* Mass assignment
* Parameter pollution
* Rate-limit bypass

### NFC

* Card replay
* Card cloning considerations
* Duplicate punch
* Race conditions
* Terminal impersonation
* Unauthorized terminal
* Forged terminal requests

### Business Logic

* Leave balance manipulation
* Salary manipulation
* Attendance manipulation
* Score manipulation
* Time manipulation
* Duplicate attendance

---

# 50. RACE CONDITION PROTECTION

This is especially important for NFC attendance.

Two simultaneous requests must not create:

```text
CHECK_IN
CHECK_IN
```

or:

```text
CHECK_OUT
CHECK_OUT
```

Use:

* Database transactions
* Row-level locking
* Unique constraints
* Idempotency keys

Example:

```text
employee_id
+
attendance_date
+
attendance_state
```

must be protected from race conditions.

---

# 51. TIMEZONE ARCHITECTURE

Never rely on server local time.

Store timestamps in:

```text
UTC
```

Organization has:

```text
timezone
```

Example:

```text
Asia/Dhaka
```

Display timestamps using organization/user timezone.

This is extremely important for attendance calculations.

---

# 52. MONTH-END PROCESSING

At the end of every month:

```text
Attendance finalized
       ↓
Monthly statistics calculated
       ↓
Score calculated
       ↓
Status generated
       ↓
Monthly report generated
       ↓
Employee notified
```

Example:

```text
September 2026

Score:
87

Status:
GOOD
```

Historical monthly results should not change unexpectedly when future attendance data changes.

---

# 53. ADMIN SETTINGS

Company Settings:

```text
Company Information
Working Hours
Attendance Rules
Leave Policies
Holiday Calendar
Score Configuration
Notification Settings
NFC Settings
Security Settings
Roles & Permissions
```

---

# 54. SUBSCRIPTION / SAAS BILLING

Design the system so billing can be added.

Plans:

```text
FREE
STARTER
BUSINESS
ENTERPRISE
```

Potential billing metric:

```text
Number of active employees
```

Example:

```text
Starter:
Up to 25 employees

Business:
Up to 100 employees

Enterprise:
Custom
```

Integrate Stripe later.

Do not tightly couple billing logic to employee management.

---

# 55. API VERSIONING

Use:

```text
/api/v1/
```

Future:

```text
/api/v2/
```

Do not break existing clients.

This is especially important for NFC terminals because deployed terminals may run old software.

---

# 56. PROJECT STRUCTURE

Use a monorepo.

Example:

```text
employee-saas/
│
├── apps/
│   ├── web/
│   ├── api/
│   └── worker/
│
├── packages/
│   ├── ui/
│   ├── database/
│   ├── auth/
│   ├── config/
│   ├── types/
│   └── validation/
│
├── infrastructure/
│   ├── docker/
│   ├── nginx/
│   └── terraform/
│
├── docs/
│
├── tests/
│
├── docker-compose.yml
├── package.json
├── pnpm-workspace.yaml
└── README.md
```

---

# 57. RECOMMENDED TECH STACK

## Frontend

```text
Next.js
TypeScript
Tailwind CSS
shadcn/ui
TanStack Query
Zod
```

## Backend

Recommended:

```text
NestJS
TypeScript
Prisma
PostgreSQL
Redis
BullMQ
```

Alternative:

```text
FastAPI
Python
SQLAlchemy
PostgreSQL
Redis
Celery
```

For this product, prefer the TypeScript stack so frontend/backend types can be shared.

---

# 58. AUTHENTICATION ARCHITECTURE

Recommended:

```text
Email
Password
+
MFA
```

Optional:

```text
Google OAuth
Microsoft OAuth
SSO/SAML
```

Enterprise organizations can later use:

```text
SAML
OIDC
SCIM
```

---

# 59. DATABASE SECURITY

For PostgreSQL:

* Separate application user
* No application access as database superuser
* Least privilege
* Encrypted connections
* Automated backups
* Point-in-time recovery
* Migration system
* Connection pooling

Consider PostgreSQL Row-Level Security for additional tenant isolation.

---

# 60. IMPORTANT SECURITY PRINCIPLE

The frontend must NEVER be considered trusted.

For example:

Bad:

```text
Frontend says:

role = admin
```

Backend must ignore that.

Instead:

```text
Authenticated Session
       ↓
Backend
       ↓
Load User
       ↓
Load Organization
       ↓
Load Roles
       ↓
Check Permission
       ↓
Execute Action
```

---

# 61. MVP DEVELOPMENT PHASES

Do NOT build everything at once.

## Phase 1 — Core

Build:

* Authentication
* Organizations
* Employees
* Roles
* Departments
* Employee dashboard
* Admin dashboard

---

## Phase 2 — Attendance

Build:

* NFC cards
* Attendance terminals
* Check-in
* Check-out
* Daily attendance
* Working hours
* Late detection
* Attendance history

---

## Phase 3 — Leave

Build:

* Leave types
* Leave balances
* Leave requests
* Approval workflow
* Leave calendar

---

## Phase 4 — Salary

Build:

* Salary records
* Salary history
* Admin salary management
* Employee salary visibility
* Salary audit logs

---

## Phase 5 — Scoring

Build:

* Attendance score
* Monthly score
* Status
* Risk indicator
* Score breakdown

---

## Phase 6 — Analytics

Build:

* Reports
* Charts
* Department analytics
* Attendance trends
* Export

---

## Phase 7 — Enterprise

Build:

* MFA
* SSO
* SAML
* SCIM
* Advanced audit
* Advanced permissions
* API keys
* Webhooks

---

## Phase 8 — Billing

Build:

* Subscription plans
* Stripe
* Employee limits
* Billing dashboard
* Invoices

---

# 62. DEVELOPMENT REQUIREMENTS

Write clean production-quality code.

Requirements:

* TypeScript strict mode
* Strong typing
* No `any` unless absolutely necessary
* Zod validation
* Centralized error handling
* Structured logging
* Unit tests
* Integration tests
* E2E tests
* Database migrations
* API documentation
* OpenAPI/Swagger
* Security headers
* Rate limiting
* Input validation

---

# 63. UI/UX REQUIREMENTS

The application should feel like a modern enterprise SaaS.

Design principles:

* Clean
* Fast
* Minimal
* Professional
* Responsive
* Accessible

Use:

```text
Sidebar navigation
Top navigation
Cards
Tables
Charts
Modal dialogs
Command/search palette
Toast notifications
Skeleton loaders
Empty states
Confirmation dialogs
```

Avoid unnecessarily complicated UI.

---

# 64. MAIN NAVIGATION

Admin:

```text
Dashboard
Employees
Attendance
Leave
Departments
NFC Cards
Reports
Salary
Notifications
Audit Logs
Settings
```

Employee:

```text
Dashboard
My Attendance
My Leave
My Salary
My Monthly Status
My Profile
```

Manager:

```text
Dashboard
My Team
Attendance
Leave
Reports
```

---

# 65. FINAL ARCHITECTURE

The final system should conceptually look like:

```text
                       ┌─────────────────────┐
                       │      USERS          │
                       │ Admin / HR / Manager│
                       │ Employee            │
                       └──────────┬──────────┘
                                  │
                                  ▼
                       ┌─────────────────────┐
                       │     Next.js Web     │
                       │      Dashboard      │
                       └──────────┬──────────┘
                                  │
                                  ▼
                       ┌─────────────────────┐
                       │     API Gateway     │
                       │ Authentication/RBAC │
                       └──────────┬──────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
              ▼                   ▼                   ▼
        ┌───────────┐       ┌───────────┐       ┌───────────┐
        │ Employee  │       │Attendance │       │   Leave   │
        │ Service   │       │  Service  │       │  Service  │
        └───────────┘       └─────┬─────┘       └───────────┘
                                  │
                                  │
                       ┌──────────▼──────────┐
                       │    NFC Terminals    │
                       │                    │
                       │ Phone / Tablet /   │
                       │ NFC Reader / Kiosk  │
                       └──────────┬──────────┘
                                  │
                                  ▼
                       ┌─────────────────────┐
                       │ Attendance Events   │
                       └──────────┬──────────┘
                                  │
                    ┌─────────────┴────────────┐
                    ▼                          ▼
             ┌─────────────┐            ┌─────────────┐
             │ PostgreSQL  │            │    Redis    │
             │ Source Truth│            │ Cache/Queue │
             └─────────────┘            └──────┬──────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │   Workers   │
                                        │ Score/Jobs  │
                                        └─────────────┘
```

---

# 66. MOST IMPORTANT DATA FLOW

The most important flow in the entire product is:

```text
Employee
   │
   │ NFC Tap
   ▼
NFC Terminal
   │
   │ Secure Request
   ▼
API
   │
   ├── Authenticate Terminal
   │
   ├── Validate NFC Card
   │
   ├── Identify Employee
   │
   ├── Validate Organization
   │
   ├── Determine IN/OUT
   │
   ├── Check Duplicate
   │
   ├── Check Attendance Rules
   │
   └── Create Transaction
             │
             ▼
      PostgreSQL
             │
             ▼
      Event / Queue
             │
       ┌─────┴─────┐
       ▼           ▼
   Dashboard    Analytics
       │
       ▼
Employee Monthly Status
       │
       ▼
Attendance Score
       │
       ▼
Risk Indicator
```

---

# 67. IMPORTANT PRODUCT PRINCIPLE

The platform should treat:

```text
Attendance Events
```

as the source of truth.

Everything else should be derived from them.

For example:

```text
NFC Event
      ↓
Daily Attendance
      ↓
Monthly Statistics
      ↓
Attendance Score
      ↓
Monthly Status
```

Do NOT independently manipulate all these values.

This makes the system much easier to audit and prevents inconsistent data.

---

# 68. FIRST IMPLEMENTATION TARGET

Build the MVP in this exact order:

```text
1. Project setup
2. PostgreSQL schema
3. Authentication
4. Multi-tenancy
5. RBAC
6. Organization management
7. Employee management
8. NFC card management
9. Attendance API
10. NFC punch terminal
11. Daily attendance
12. Leave management
13. Salary management
14. Monthly score
15. Employee dashboard
16. Admin dashboard
17. Audit logs
18. Notifications
19. Reports
20. Automated tests
21. Docker deployment
22. CI/CD
```

Do not move to the next major module until the previous module has automated tests.

---

# 69. DELIVERABLES

Produce:

```text
Source code
Database schema
Database migrations
REST API
OpenAPI documentation
Frontend
Admin dashboard
Employee dashboard
NFC attendance interface
Docker configuration
Environment configuration
Unit tests
Integration tests
E2E tests
Security tests
CI/CD pipeline
Architecture documentation
API documentation
Deployment documentation
README
```

The resulting application should be deployable using Docker and should be suitable for evolving from an MVP into a commercial multi-tenant SaaS.

Before writing implementation code, first generate:

1. Architecture diagram
2. Database ERD
3. Complete database schema
4. API specification
5. RBAC permission matrix
6. Attendance state machine
7. NFC attendance flow
8. Security threat model
9. Project directory structure
10. Development roadmap

Then implement the system incrementally.
