# Build a Production-Ready Multi-Tenant Employee Management SaaS

> **Revision note:** This version adds **Section 73 (Overtime Management)** and updates the related sections (10, 14, 15, 16, 17, 23, 25, 26, 28, 39, 40, 41, 49, 53, 61, 64, 69, 72) so employees can see their extra time on the Employee Dashboard and Statistics page. Changes are marked with **[OT]**.

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
* **Overtime / extra working time [OT]**
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
* **Approve/reject overtime; configure overtime rules [OT]**
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
* **Review/approve overtime depending on permissions [OT]**
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
* **Approve/reject team overtime requests [OT]**
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
* **View own overtime (daily, weekly, monthly), approval status, and estimated overtime earnings where company policy allows [OT]**
* View assigned NFC card status
* Update permitted profile fields

Employee cannot:
* Edit salary
* Edit attendance
* **Edit, approve, or self-approve overtime minutes or overtime pay [OT]**
* Modify score
* Modify leave balance
* Modify another employee
* Access another employee's private information
* Delete their own account
* Request or execute self-service permanent account deletion
* Delete, deactivate, suspend, or archive their own account directly
* Change their own full name
* Change their own email address

Employee full name and email must be displayed as read-only / locked fields in the employee profile. The UI must show a lock indicator and must not expose an employee self-service edit action for these fields. Backend authorization must enforce the same restriction even if the frontend is bypassed. Authorized Admin/HR users may change these fields according to RBAC, and every such change must be audited.

Employee Account Deletion Restriction

Employees must not be allowed to delete their own accounts. This restriction must be enforced in both the frontend and backend.

Requirements:
* Do not display a self-service Delete Account button or account deletion action to employees.
* Reject any employee-initiated account deletion API request with an authorization error.
* Employees may request account deactivation, resignation processing, or data-related support through an approved workflow, but they cannot directly delete their account.
* Only authorized Company Admin/HR users may deactivate, suspend, archive, or initiate an approved account-removal workflow according to RBAC and company policy.
* Any account status change must create an audit log containing the actor, timestamp, previous status, new status, and reason.
* Preserve required attendance, salary, audit, and legal records according to the organization's retention policy; do not hard-delete historical records merely because an employee is deactivated.
* Account deletion/deactivation endpoints must enforce tenant isolation, authentication, authorization, CSRF protection where applicable, and server-side validation.

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
* **Overtime eligibility (eligible / not eligible / policy default) [OT]**

## Security Information
* User account
* MFA status
* NFC card ID
* NFC card status
* Last login
* Last login IP address
* Last known access location (Division, Area/District) — derived from IP geolocation
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

**[OT]** When the employee's day includes overtime, the check-out confirmation may also display it (configurable, and only if the employee is overtime-eligible):

```text
✓ Check-out Recorded
Good evening, Pranto
Check-out:
07:15 PM
Working Time:
9h 40m
Extra Time Today:
1h 15m (pending approval)
```

This same flow handles a mid-day exit (lunch, errand) exactly the same way — the terminal doesn't distinguish "leaving for the day" from "stepping out"; every CHECK_OUT just closes the currently-open segment. If the employee taps in again later the same day, it opens a new segment (see Section 72 for the full state machine, including how duplicate/rapid re-taps and unreturned exits are handled).

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
geo_division
geo_area
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

This provides a strong audit trail. **[OT]** Overtime is also derived from these events (Section 73); it is never stored as an independently editable value.

---

# 10. DAILY ATTENDANCE
Create a daily attendance record:

```text
employee_id
date
first_check_in
last_check_out
total_work_duration
segment_count
late_minutes
early_leave_minutes
overtime_minutes            -- [OT] approved/countable overtime (see Section 73)
overtime_raw_minutes        -- [OT] detected overtime before threshold/cap/approval
overtime_status             -- [OT] NONE | PENDING | APPROVED | REJECTED | AUTO_APPROVED | FLAGGED
overtime_day_type           -- [OT] WORKING_DAY | WEEKEND | HOLIDAY | LEAVE_DAY
attendance_status
```

An employee is not limited to a single check-in/check-out pair per day. Multiple IN→OUT segments in the same day are normal — lunch, errands, meetings offsite, stepping out during working hours — and must all be counted. `total_work_duration` is the sum of every completed (CHECK_IN → CHECK_OUT) segment for that date, not simply `last_check_out - first_check_in`. See Section 72 for the full state machine and how an unreturned exit is handled.

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

Working Days Configuration

Company administrators must be able to configure the number of scheduled working days and select the exact weekdays an employee/company works.

Example configuration:

```text
Working Days Per Week: 5
[✓] Monday
[✓] Tuesday
[✓] Wednesday
[✓] Thursday
[✓] Friday
[ ] Saturday
[ ] Sunday
```

The selected weekdays must be used consistently for:
* Expected working days
* Expected monthly working hours
* Present/absent calculations
* Daily salary calculation
* Hourly salary calculation
* Monthly salary progress
* Weekly attendance statistics
* GitHub-style attendance calendar
* Risk calculations
* **Overtime day-type detection (working day vs. weekend/holiday) [OT]**

The schedule must be tenant-specific and effective-dated so future schedule changes do not rewrite historical attendance or salary calculations.

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

Overtime / Extra Time      [OT]
---------------------
This Month: 6h 45m
Approved: 5h 30m
Pending: 1h 15m
Estimated Overtime Pay: ৳1,190.00

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

**[OT]** The Overtime block is shown only if the employee is overtime-eligible. The pay line is shown only if company policy allows the employee to view salary/earnings; otherwise only hours are shown.

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

Weekly Late Risk Rule

If an employee has 3 or more late working days within the same configured week, the system must create a high-risk attendance event and notification.

The rule must be configurable for:
* Late-day threshold (default: 3)
* Evaluation period (calendar week or rolling 7 days)
* Risk level
* Notification recipients
* Whether repeated notifications are suppressed

The high-risk notification must be visible in:
* Employee Statistics
* Employee Dashboard notification area
* Authorized Admin dashboard
* Relevant employee notification center

Example:

```text
HIGH RISK
Late arrivals this week: 3
Your attendance pattern has reached the configured late-arrival risk threshold.
```

**[OT]** Overtime Overwork Risk Rule (optional, configurable, default off)

Excessive overtime is a burnout and compliance risk. If an employee's overtime exceeds a configured weekly or monthly limit, the system may create a risk event and notify the employee, manager, and HR.

Configurable:
* Weekly overtime limit (e.g. 12 hours)
* Monthly overtime limit
* Consecutive overtime days threshold
* Notification recipients
* Whether repeated notifications are suppressed

Example:

```text
NOTICE
Overtime this week: 13h 20m
You have exceeded the configured weekly overtime limit (12h).
```

This is an informational compliance signal. It never reduces the employee's score.

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

**[OT]** Overtime and the score:

* The Working Hours component is **capped at 100** and is calculated from regular expected hours only. Overtime minutes must NOT be added to worked minutes for this component, so extra hours cannot mask absences, lateness, or short days.
* Overtime never lowers the score.
* Organizations may optionally configure a small, capped **overtime bonus** (default: none, e.g. `max +2 points`). If enabled, it must be shown as its own transparent line in the score breakdown.

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
overtime_minutes_approved   -- [OT]
overtime_minutes_pending    -- [OT]
overtime_minutes_rejected   -- [OT]
overtime_days               -- [OT]
status
generated_at
```

Statuses should be calculated automatically.

**[OT]** At month-end, any overtime still PENDING is handled per the organization's setting (auto-expire, auto-approve, or carry to the next-month approval queue). The finalized monthly record stores the resulting approved/rejected values and never changes afterward.

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
Overtime Today                  [OT]
Pending Overtime Approvals      [OT]
Overtime Hours This Month       [OT]
```

Charts:
* Attendance trend
* Monthly attendance
* Late arrival trend
* Leave usage
* Department attendance
* Working hours
* Employee score distribution
* **Overtime by department / trend [OT]**

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

**[OT]** After office end, a checked-in employee is shown with an `OVERTIME` badge and a running extra-time counter.

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
* Record IP address and geolocation (division/area) on every successful and failed login attempt

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
geo_division
geo_area
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
* **Overtime approval / rejection / manual adjustment [OT]**
* **Overtime policy changes (multiplier, caps, approval mode, rate base) [OT]**
* Admin changes
* Password changes
* MFA changes

Audit logs should be append-only from the application perspective.

---

Audit Log Change View

The audit log UI should focus on what actually changed. Each change entry should show only the field-level difference needed to understand the action:

```text
Field                  Old Value          New Value
Monthly Salary         ৳30,000            ৳35,000
Working Days           Mon-Fri            Mon-Sat
Grace Period           15 minutes         10 minutes
Overtime Multiplier    1.5x               2.0x
Overtime Approval      Auto               Manager approval
```

The backend audit record must still retain the full audit metadata, including actor, organization, resource, timestamp, action, old value, and new value. The UI should not display unrelated payload noise by default.

For sensitive values such as salary and overtime pay, visibility must remain permission-controlled.

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

**[OT]** A manual correction that changes worked time automatically triggers overtime recalculation for that date (Section 73). If the recalculated overtime differs from an already approved value, the record returns to `PENDING` (or is flagged) and both values are audited.

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
Overtime detected (pending approval)       [OT]
Overtime approved                          [OT]
Overtime rejected (with reason)            [OT]
Overtime limit exceeded                    [OT]
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
overtime_policies            -- [OT]
overtime_records             -- [OT]
overtime_approvals           -- [OT]
monthly_employee_status
salary_calculations
attendance_salary_adjustments
attendance_risk_events
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
     │      ├── Overtime Records   [OT]
     │      ├── Leave
     │      └── Monthly Status
     │
     ├── Departments
     │
     ├── Work Schedules
     │
     ├── Overtime Policies         [OT]
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

Progressive Monthly Salary Calculation

The employee must be able to see their current estimated salary progress while the month is still in progress. This is an estimated/calculated value, not a finalized payroll record.

Calculation rules:

```text
Daily Salary = Monthly Salary / Scheduled Working Days in the Salary Period
Hourly Salary = Daily Salary / Expected Working Hours Per Day
Late Deduction = Late Minutes × Hourly Salary
Overtime Pay = (Approved Overtime Minutes / 60) × Overtime Hourly Rate × Multiplier   -- [OT] see Section 73

Current Estimated Salary =
Monthly Salary
− Late Deductions
− Absence/Unpaid Leave Deductions
− Other Applicable Attendance Deductions
+ Overtime Pay (approved overtime only)                                              -- [OT]
+ Applicable Earnings/Bonuses
```

Example:

```text
Monthly Salary: ৳30,000
Scheduled Working Days: 26
Expected Hours Per Day: 8

Daily Salary = ৳30,000 / 26 = ৳1,153.85
Hourly Salary = ৳1,153.85 / 8 = ৳144.23

Late by 30 minutes:
Deduction = 0.5 × ৳144.23 = ৳72.12

Overtime 1 hour, weekday, multiplier 1.5x:            [OT]
Overtime Pay = 1 × ৳144.23 × 1.5 = ৳216.35
```

The employee Statistics page must show, at minimum:
* Monthly salary
* Scheduled working days
* Completed working days
* Remaining working days
* Daily salary rate
* Hourly salary rate
* Expected working hours
* Worked hours
* Earned/current estimated salary
* Late minutes
* Late salary deductions
* Absence/unpaid deductions where applicable
* Other deductions/adjustments where applicable
* **Overtime hours (approved / pending / rejected) [OT]**
* **Overtime earnings (approved only; pending shown separately as "potential") [OT]**
* Current estimated net salary

The employee must also be able to see a daily salary-impact breakdown:

```text
Date       Status   Worked    Late      Overtime   Deduction   OT Pay
Sep 01     Present  8h 05m    0m        0m         ৳0.00       ৳0.00
Sep 02     Late     7h 40m   20m        0m         ৳48.08      ৳0.00
Sep 03     Present  9h 10m    0m        1h 00m     ৳0.00       ৳216.35
Sep 04     Present  8h 45m    0m        30m (P)    ৳0.00       — pending
```

`(P)` = pending approval; pending overtime is not paid until approved.

Salary deduction rules must be configurable by the organization, including grace period, late deduction method, rounding, absence/half-day treatment, unpaid leave treatment, and maximum deduction rules. **[OT]** Overtime rules are configured in the Overtime Policy (Section 73).

The finalized monthly salary/payroll record must remain separate from the live estimated calculation and must preserve historical salary records. **[OT]** Overtime pay is a separate payroll line item and is never blended into base salary.

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
/api/v1/overtime            [OT]
/api/v1/salary
/api/v1/dashboard
/api/v1/reports
/api/v1/audit-logs
/api/v1/notifications
```

**[OT]** Overtime endpoints:

```text
GET   /api/v1/overtime/me?month=2026-09              (employee: own overtime list + totals)
GET   /api/v1/overtime/me/summary?range=week|month   (employee dashboard widget)
GET   /api/v1/overtime/team                          (manager: team overtime, pending queue)
POST  /api/v1/overtime/{id}/approve                  (manager/HR/admin, RBAC-checked)
POST  /api/v1/overtime/{id}/reject                   (requires reason)
POST  /api/v1/overtime/{id}/adjust                   (HR/admin; audited)
GET   /api/v1/overtime/policies                      (admin)
PUT   /api/v1/overtime/policies                      (admin; audited; effective-dated)
```

Employees have no write endpoints for overtime minutes or pay. A self-approve attempt must fail with an authorization error.

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

## cPanel Hosting Constraint
A persistent WebSocket server is a long-running process, which most shared cPanel hosting plans do not allow (no arbitrary open ports, no background daemons). Choose based on the actual hosting tier:

* Shared cPanel hosting (most common for this deployment target): use a hosted realtime provider over HTTPS/WSS instead of self-hosting the socket server — e.g. Pusher, Ably, or a managed Soketi instance. Laravel Echo + `laravel-echo` client connects to the provider; your Laravel app only needs to `broadcast()` events, no persistent process runs on the cPanel box itself.
* VPS/dedicated with WHM root access: self-host Laravel Reverb (or Soketi) as a persistent process under Supervisor, fronted by the existing Apache/LiteSpeed reverse proxy.
* Fallback (no realtime provider budget): short-interval polling (e.g. every 5–10s) from the admin live-attendance board via a lightweight endpoint, backed by the cache layer from Section 35. Less elegant, but works on any cPanel plan with zero extra infrastructure.

---

# 34. BACKGROUND JOBS
Use Laravel's queue system.

Jobs:

```text
Calculate monthly score
Generate monthly reports
Send notifications
Calculate leave balances
Process attendance events
Calculate overtime                 [OT]
Expire/escalate pending overtime   [OT]
Generate payroll summaries
Send reminders
Generate analytics
```

Recommended stack (Laravel):

```text
QUEUE_CONNECTION=database   (default — works on almost any cPanel plan, no extra service needed)
QUEUE_CONNECTION=redis      (preferred if the hosting plan/VPS provides Redis)
```

On cPanel, a persistent `php artisan queue:work` process is usually not allowed on shared hosting. Instead:

* Add a Cron Job (via cPanel → Cron Jobs) that runs every minute:

```text
* * * * * php /home/USER/app_path/artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

* Use `--stop-when-empty` so the worker exits cleanly before the next cron tick starts a new one (prevents overlapping workers).
* If the hosting plan is actually a VPS with WHM/root access, a persistent worker under Supervisor (or cPanel's "Application Manager") is preferred over cron-based polling.
* Use Laravel's built-in Scheduler (`php artisan schedule:run`) for month-end processing, reminders, and report generation — registered as a single cPanel cron entry:

```text
* * * * * php /home/USER/app_path/artisan schedule:run >> /dev/null 2>&1
```

---

# 35. CACHING
Use a cache layer for:
* Sessions
* Rate limiting
* Temporary attendance state
* Dashboard caching
* Presence/live-board data
* Background job queues

Driver depends on hosting tier:

```text
CACHE_DRIVER=redis   (VPS/dedicated with Redis available — preferred)
CACHE_DRIVER=database or file  (shared cPanel hosting without Redis)
```

Do NOT use the cache layer as the permanent source of truth for attendance.

MySQL/PostgreSQL remains authoritative.

---

# 36. FILE STORAGE
Employee documents may include:
* Profile images
* Leave attachments
* Employment documents

Use Laravel's filesystem abstraction (`config/filesystems.php`) so the storage backend can change without touching application code:

```text
FILESYSTEM_DISK=public   (cPanel default — local disk under storage/app/public, symlinked via `php artisan storage:link`)
FILESYSTEM_DISK=s3       (recommended once traffic/tenant count grows)
```

Examples of S3-compatible providers: Cloudflare R2, AWS S3, Wasabi, MinIO (self-hosted).

On shared cPanel hosting, local disk storage is acceptable for an MVP but has real limits: it doesn't scale across multiple app servers, backups depend on the hosting provider's cPanel backup schedule, and disk quota is capped by the plan — migrate to S3-compatible storage before onboarding many tenants.

Never store uploaded files directly inside the database.

---

# 37. FRONTEND
Build a responsive web application.

Recommended (Laravel-native, works well on cPanel since everything ships as compiled static assets — no separate Node server needed at runtime):

```text
Laravel Blade + Livewire  (simplest — no separate frontend build/deploy step, fully server-rendered)
or
Laravel + Inertia.js + Vue 3 / React  (SPA-like feel, still one deployable app, no separate API-only backend)

Tailwind CSS
Alpine.js (for lightweight interactivity alongside Blade/Livewire)
```

Either option compiles to static JS/CSS via Vite at build time (`npm run build`), then only the compiled `public/build` assets are uploaded to cPanel — Node.js itself does not need to run on the server.

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
Overtime          [OT]
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
Overtime Approvals   [OT]
Monthly Status
```

### Employee

```text
My Dashboard
Attendance
Leave
My Overtime         [OT]
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

**[OT] Overtime / Extra Time card (REQUIRED for overtime-eligible employees):**

```text
Overtime / Extra Time
---------------------
This Month:      6h 45m
  Approved:      5h 30m
  Pending:       1h 15m
  Rejected:      0h 00m
Today:           1h 15m (pending)
Est. Overtime Pay: ৳1,190.00   (shown only if salary visibility is allowed)
```

Rules for the card:
* Overtime is always shown separately from regular worked hours. The "147h 32m of 152h" figure never includes overtime.
* Status chips: `PENDING` (amber), `APPROVED` (green), `REJECTED` (red, with reason on click), `AUTO-APPROVED`.
* If the employee is not overtime-eligible, the card is hidden entirely (not shown as zero).
* If company policy hides earnings from employees, show hours only.
* The card links to the Statistics page (Overtime section) for the daily breakdown.
* Numbers are read-only; there is no edit action on the employee side.

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
Sep 21
09:00 → 19:15
10h 15m   +1h 15m OT (pending)      [OT]
```

---

Daily Arrival Contribution Calendar — REQUIRED ON DASHBOARD

The employee dashboard must keep the current clean dashboard layout shown in the product design and add a GitHub-contribution-style attendance calendar directly on the dashboard. This calendar is specifically for daily arrival/attendance visibility and must not replace the existing dashboard cards.

Use one square/cell per calendar day, grouped by week. The current month should be visible by default, with previous/next month navigation.

Status legend:

```text
🟩 Present / On time
🟨 Late
🟥 Absent
🟦 Leave
⬜ Non-working day / Weekend
```

**[OT]** Overtime indicator: a day with overtime shows a small dot/badge on the cell (does not change the base status color):

```text
🟢• Present + approved overtime
🟡• Late + overtime
⬜• Weekend/holiday worked (overtime)
◌   Pending overtime (hollow dot)
```

Each day cell must be clickable and show a compact detail popover containing:
* Date
* Check-in time
* Check-out time
* Attendance status
* Late minutes
* Worked duration
* Expected duration
* **Overtime duration and status (Pending / Approved / Rejected, with reason if rejected) [OT]**
* **Overtime pay for the day, when approved and visible [OT]**
* Salary deduction for the day, when applicable

The dashboard calendar must respect the configured working weekdays for that employee/company.

Below the calendar, show a small weekly summary:

```text
This Week
Present: 4   Late: 1   Absent: 0   Leave: 0
Worked: 31h 20m / 40h
Late: 18m
Late Salary Deduction: ৳43.27
Overtime: 2h 10m (Approved 1h 40m, Pending 30m)      [OT]
Overtime Pay: ৳360.58                                 [OT]
```

The dashboard should also surface a high-risk warning when the configured weekly late threshold is reached.

Employee Statistics Navigation Page

Add a dedicated Statistics item to the employee navbar. The Statistics page is the single detailed view for the employee's attendance, working-hours, salary-progress, score, leave, **overtime**, and risk information.

The Statistics page must show:

```text
MONTHLY OVERVIEW
Attendance Score
Present / Late / Absent / Leave
Expected Hours / Worked Hours
Average Arrival / Average Departure
Remaining Leave

SALARY PROGRESS
Monthly Salary
Scheduled Working Days
Completed Working Days
Daily Salary
Hourly Salary
Earned / Current Estimated Salary
Late Deduction
Absence / Unpaid Deductions
Other Adjustments
Overtime Pay (approved)                 [OT]
Current Estimated Net Salary

OVERTIME / EXTRA TIME                   [OT]
Overtime Hours: Approved / Pending / Rejected
Overtime Days
Weekday vs Weekend/Holiday Overtime
Applied Multiplier(s)
Overtime Earnings (approved) and Potential (pending)
Daily Overtime Breakdown (date, start, end, minutes, status, approver, reason)
Weekly/Monthly Overtime Limit Usage (e.g. 9h of 12h this week)

WEEKLY ATTENDANCE
Present / Late / Absent / Leave
Worked Hours
Overtime Hours                          [OT]
Late Minutes
Salary Deduction

RISK & NOTIFICATIONS
Current Risk Status
Late Days This Week
Configured Late Threshold
High-Risk Alert (when triggered)
Overtime Limit Notice (when triggered)  [OT]

ATTENDANCE SCORE BREAKDOWN
Attendance Rate
Punctuality
Working Hours
Leave Compliance
Final Score
```

The Statistics page should support month and week filters and must use the same source-of-truth attendance events and derived records as the rest of the system.

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
Overtime This Month:
6h 45m (5h 30m approved)        [OT]
```

Tabs:

```text
Overview
Attendance
Overtime         [OT]
Leave
Salary
Monthly Status
NFC Card
Audit History
```

Salary tab must require appropriate permission. **[OT]** The Overtime tab shows hours to authorized viewers; overtime pay figures require salary-view permission.

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
Overtime Hours (approved / pending)   [OT]
Score
Status
```

### Overtime Report [OT]
Filters: employee, department, date range, day type (weekday/weekend/holiday), status (pending/approved/rejected).
Columns: employee, date, raw minutes, approved minutes, multiplier, pay, approver, status.
Pay columns are restricted to authorized users. Exports: CSV, Excel, PDF.

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
* Admin-controlled account deactivation and approved account-removal workflow
* Employee self-service account deletion is prohibited
* Data export
* Privacy settings
* IP-derived location data (division/area) is approximate, stored only for security/audit purposes, and must be disclosed in the privacy policy and covered by the data retention policy

Never expose salary information in:
* Public APIs
* Client-side source
* Search indexes
* Logs
* Analytics endpoints

**[OT]** Overtime pay is salary information and follows the same rules. Overtime hours alone are attendance data and may be shown to managers per RBAC.

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
Target platform: cPanel-based shared/reseller hosting, or a cPanel-managed VPS — not a Docker/Kubernetes environment. Deployment diagram for this target:

```text
Internet
     ↓

Cloudflare (optional DNS/CDN)
     ↓

cPanel (Apache/LiteSpeed + PHP-FPM)
     ↓

     ├── Laravel App  (public_html / subdomain root → public/)

     ├── Cron Jobs    (queue:work, schedule:run)

     └── MySQL/PostgreSQL  (cPanel DB Wizard)

```

Notes for this hosting target:
* The app is uploaded as PHP source (Git deploy, SFTP/rsync, or a zip extracted via cPanel File Manager) — there is no image build/registry step.
* The webroot (public_html or a subdomain's document root) must point at Laravel's `public/` folder, not the project root.
* Most cPanel plans provide MySQL/MariaDB by default; use PostgreSQL only if the host explicitly supports it.
* TLS via cPanel AutoSSL (Let's Encrypt) or Cloudflare in front.
* Scaling path: shared cPanel plan → cPanel-managed VPS (root/WHM unlocks Supervisor for persistent queue workers and Redis) → eventually a non-cPanel managed-database/managed-Redis setup if the product outgrows shared hosting.

---

# 46. CPANEL HOSTING ARCHITECTURE
Docker/containers are typically unavailable on shared cPanel hosting, so structure the deployment as a plain Laravel app instead:

```text
app/       (Laravel application code)
public/    (→ mapped to the domain/subdomain document root)
storage/   (writable — logs, cache, uploads; keep outside public/ except storage/app/public via symlink)
vendor/    (composer install --no-dev --optimize-autoloader over SSH, or pre-built and uploaded if no SSH/Composer access)
.env       (uploaded separately, never committed, permissions locked down)
```

Guidelines:
* Never commit `vendor/` or `.env` to the repository.
* Set correct file permissions on `storage/` and `bootstrap/cache/` (writable by the PHP process user only).
* Use environment variables for all secrets — same principle as a container-based deployment, only the delivery mechanism changes.
* This describes the cPanel-first path, not a permanent ceiling — migrate to a VPS/cloud + containers once true horizontal scaling is needed.

---

# 47. ENVIRONMENT VARIABLES
Example (Laravel):

```env
APP_NAME=
APP_ENV=production
APP_KEY=
APP_URL=
DB_CONNECTION=mysql
DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
CACHE_DRIVER=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
FILESYSTEM_DISK=public
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
SANCTUM_STATEFUL_DOMAINS=
BROADCAST_CONNECTION=pusher
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
STRIPE_SECRET_KEY=
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
Lint (Pint/PHP-CS-Fixer)
↓
Static Analysis (PHPStan/Larastan)
↓
Unit Tests
↓
Integration Tests
↓
Security Tests
↓
Build (composer install --no-dev, npm run build for assets)
↓
Deploy (rsync/SFTP over SSH to cPanel, or cPanel Git Version Control pull-and-deploy hook)
```

Deploy step details for a cPanel target:

* If the account has SSH access: a GitHub Actions job connects over SSH, pulls the latest code (or rsyncs a build artifact), runs `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, then reloads PHP-FPM if permitted.
* If the account has no SSH access (some shared plans): use cPanel's built-in Git Version Control feature — push to the repo cPanel is configured to pull from, then trigger its deploy hook (a `.cpanel.yml` script) to copy files into the document root and run the same Artisan commands.
* Never run raw `git pull` directly into the live document root without a deploy script — always route through a build step so `vendor/` and compiled assets are present before the app is live.

Run dependency security scanning (`composer audit`, `npm audit`).

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
* **Employee attempting to approve own overtime; manager approving overtime outside their team; cross-tenant overtime access [OT]**

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
* **Overtime manipulation: staying tapped-in to farm overtime, forged/forgotten check-outs generating fake overtime, editing overtime minutes via mass assignment, double-counting overtime and late offset, overtime on leave/holiday days, cap and threshold bypass [OT]**

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

This section covers simultaneous concurrent requests (network retries, two terminals firing at once). It's a different problem from a real, sequential double-tap seconds apart — that's a debounce rule, covered in Section 72.

**[OT]** Overtime recalculation jobs must be idempotent: one `overtime_records` row per `(employee_id, work_date)` (unique constraint), recalculated by upsert inside a transaction so concurrent punches or corrections never create duplicate overtime.

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

This is extremely important for attendance calculations. **[OT]** It matters especially for overtime that crosses midnight (Section 73).

---

# 52. MONTH-END PROCESSING
At the end of every month:

```text
Attendance finalized
       ↓
Overtime finalized (pending items expired/escalated per policy)   [OT]
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
Overtime (approved):
5h 30m
```

Historical monthly results should not change unexpectedly when future attendance data changes.

---

# 53. ADMIN SETTINGS
Company Settings:

```text
Company Information
Working Hours
Attendance Rules
Working Days / Weekday Configuration
Salary Calculation & Deduction Rules
Overtime Policy                         [OT]
Leave Policies
Holiday Calendar
Score Configuration
Attendance Risk Configuration
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
Use a standard Laravel application structure (single deployable app — no monorepo/multi-service split needed for a cPanel target):

```text
employee-saas/
app/
Models/
Http/Controllers/, Middleware/ (tenant resolution, RBAC guards), Requests/ (Form Request validation)
Domain/  (optional module-per-feature grouping: Attendance/, Leave/, Salary/, Overtime/, NfcTerminal/)
Policies/  (RBAC authorization, one per model)
Jobs/       (queued jobs — Section 34)
Events/ and Listeners/  (attendance event to score/notification pipeline)
Console/Commands/  (Artisan commands, e.g. month-end processing)
database/migrations/, factories/, seeders/
resources/views/ (Blade, if using Blade/Livewire), js/ (Vue/React, if using Inertia), css/
routes/web.php, api.php, console.php
tests/Unit/, Feature/, Security/
public/  (→ document root on cPanel)
storage/
.env.example, composer.json, package.json, README.md
```

---

# 57. RECOMMENDED TECH STACK

## Backend

```text
Laravel (latest stable release)
PHP 8.2+
MySQL/MariaDB (default on cPanel) — PostgreSQL if the host supports it
Laravel Sanctum       (API tokens / SPA auth)
spatie/laravel-permission  (RBAC — roles and permissions per organization)
Laravel Queue (database driver by default, Redis if available)
```

## Frontend

```text
Laravel Blade + Livewire   (simplest — fully server-rendered, no separate SPA build/deploy story)
or Inertia.js + Vue 3 / React  (SPA feel, still one deployable app)
Tailwind CSS
Alpine.js
```

## Supporting packages

```text
pragmarx/google2fa-laravel  (MFA/TOTP)
laravel/socialite            (Google/Microsoft OAuth)
laravel/scout (optional)     (search, if search/filtering needs grow past basic DB queries)
barryvdh/laravel-dompdf or spatie/laravel-pdf  (report/PDF generation)
maxmind-db/reader or torann/geoip  (IP geolocation for Section 71's division/area tracking)
```

This stack deploys as plain PHP plus compiled static assets — no persistent Node process, no container runtime — which matches a cPanel hosting target. If the project later moves to a VPS/cloud environment with more infrastructure control, the same Laravel codebase carries over unchanged; only the deployment mechanics (Section 45/46) would upgrade.

---

# 58. AUTHENTICATION ARCHITECTURE
Recommended (Laravel):

```text
Laravel Fortify or Breeze scaffolding (registration, login, password reset, email verification)
+
Laravel Sanctum (session/token auth for the SPA/API)
+
pragmarx/google2fa-laravel (TOTP-based MFA)
```

Optional:

```text
laravel/socialite — Google OAuth, Microsoft OAuth
```

Enterprise organizations can later use:

```text
SAML (e.g. via socialiteproviders/saml2, or a dedicated SSO service)
OIDC
SCIM (custom-built — no first-party Laravel package; plan as bespoke API endpoints if/when needed)
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
* Configurable working weekdays
* Late detection
* Attendance history
* GitHub-style dashboard attendance calendar

---

## Phase 3 — Work Schedule & Salary Calculation
Build:
* Company working-day configuration
* Selectable weekdays
* Employee work schedules
* Daily salary calculation
* Hourly salary calculation
* Live monthly salary progress
* Late salary deductions
* Daily salary-impact breakdown
* Configurable deduction rules

---

## Phase 3B — Overtime [OT]
Build:
* Overtime policy configuration (mode, threshold, caps, rounding, multipliers, rate base, approval mode)
* Overtime detection from attendance events
* Overtime approval workflow (manager → HR/admin)
* Overtime pay calculation as a separate payroll line
* Employee dashboard Overtime card and calendar overtime indicators
* Overtime section in the daily salary-impact breakdown

---

## Phase 4 — Leave
Build:
* Leave types
* Leave balances
* Leave requests
* Approval workflow
* Leave calendar

---

## Phase 5 — Scoring & Risk
Build:
* Attendance score
* Monthly score
* Status
* Risk indicator
* Weekly late-risk detection
* High-risk notifications
* Overtime limit notices
* Employee Statistics page (including Overtime section)
* Score breakdown

---

## Phase 6 — Analytics & Reporting
Build:
* Reports
* Charts
* Department analytics
* Attendance trends
* Salary progress analytics
* Overtime reports and trends
* Weekly/monthly employee statistics
* Export

---

## Phase 7 — Audit & Notifications
Build:
* Field-level audit change view
* Salary change audit
* Attendance correction audit
* Overtime approval and policy audit
* Risk notifications
* Salary notifications
* Overtime notifications
* Monthly status notifications

---

## Phase 8 — Enterprise
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

## Phase 9 — Billing
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
* PHP `declare(strict_types=1)` in all files
* Static analysis via PHPStan/Larastan (aim for a high analysis level)
* Strong typing (typed properties, typed method signatures)
* Form Request classes for input validation (Laravel's equivalent of Zod schemas)
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
Overtime          [OT]
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
Statistics
My Leave
My Overtime       [OT]
My Salary
My Monthly Status
My Profile
```

Employee navigation behavior:
* Dashboard — keep the existing dashboard layout and show the GitHub-style daily arrival/attendance contribution calendar directly on the page, **plus the Overtime / Extra Time card [OT]**.
* Statistics — detailed attendance, working-hours, salary-progress, score, leave, weekly summary, **overtime**, and risk information.
* **My Overtime [OT] — list of the employee's overtime entries (date, start, end, minutes, day type, status, approver, rejection reason), monthly totals, and estimated pay where allowed. Read-only.**
* My Salary — detailed salary history and finalized salary records, with access controlled by company policy.
* My Monthly Status — monthly finalized attendance/status record.
* My Profile — personal and employment information; employee full name and email are read-only/locked for the employee role.

Manager:

```text
Dashboard
My Team
Attendance
Leave
Overtime Approvals   [OT]
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
                       │     Laravel Web     │
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
                                        │ Score/OT/   │
                                        │ Jobs        │
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
Daily Attendance ──► Overtime Detection (Section 73)   [OT]
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
Overtime (detected → approved)     [OT]
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

# 68. PRODUCT UI / DATA SEPARATION FOR LIVE SALARY
The employee-facing Statistics page is the detailed operational view. The employee Dashboard remains the quick-glance view and must include the daily GitHub-style attendance contribution calendar **and the Overtime / Extra Time card [OT]**.

Live salary shown during the month is always labeled as Estimated / Current and is calculated from the applicable salary record, configured working schedule, attendance events, leave, deduction rules, **and approved overtime [OT]**. It must not overwrite the finalized historical salary record.

Attendance events remain the source of truth. The UI must never allow employees to manually edit attendance, late minutes, salary deductions, **overtime minutes or overtime pay [OT]**, risk status, or score.

---

# 69. FIRST IMPLEMENTATION TARGET
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
9. Work schedule and selectable working weekdays
10. Attendance API
11. NFC punch terminal
12. Daily attendance
13. Live salary calculation
14. Overtime policy, detection, approval and pay (Section 73)   [OT]
15. Leave management
16. Salary management
17. Monthly score and weekly late-risk detection
18. Employee dashboard with GitHub-style attendance calendar and Overtime card   [OT]
19. Employee Statistics page (including Overtime section)                        [OT]
20. Admin dashboard
21. Audit logs
22. Notifications
23. Reports
24. Automated tests
25. cPanel deployment package (build script, .env setup, cron/queue configuration)
26. CI/CD
```

Do not move to the next major module until the previous module has automated tests.

---

# 70. DELIVERABLES
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
cPanel deployment configuration (.cpanel.yml or deploy script, cron job setup, storage/permissions guide)
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

The resulting application should be deployable to cPanel-based hosting (per Section 45/46) and should be suitable for evolving from an MVP into a commercial multi-tenant SaaS.

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

---

# 71. IP ADDRESS & LAST ACCESS LOCATION TRACKING
Every authenticated access (login, session refresh, and NFC/API access where applicable) must capture the client IP address and resolve it to a coarse, human-readable location.

## Purpose
* Security monitoring (impossible-travel / suspicious-login detection)
* Showing users/admins where and when an account was last accessed
* Supporting audit trails required elsewhere in this document (Section 23, Section 32)

## Data Captured
On every login attempt (success or failure) and on sensitive actions already covered by audit logging:

```text
user_login_history
id
organization_id
user_id
ip_address
geo_country
geo_division
geo_area (district/upazila or city, best-effort)
user_agent
login_status (SUCCESS / FAILED / MFA_FAILED)
created_at
```

On the `users` table, maintain a denormalized "latest" snapshot for fast profile display:

```text
users
...
last_login_at
last_login_ip
last_login_geo_division
last_login_geo_area
```

## Resolution Strategy
* Use a server-side IP-to-location (GeoIP) lookup — e.g. a MaxMind GeoLite2/GeoIP2 database or equivalent provider — resolved on the backend only. Never resolve location on the client.
* Bangladesh addresses should resolve to `geo_division` (e.g. Dhaka, Chattogram, Khulna, Rajshahi, Barishal, Sylhet, Rangpur, Mymensingh) and `geo_area` (district/city where resolvable).
* Treat this as best-effort, approximate location. IP geolocation is not precise, can be wrong for VPNs/mobile carriers/proxies, and must never be used as the sole basis for blocking access — only as a signal alongside the existing rate limiting, MFA, and login-anomaly checks already defined in Section 21.
* Cache GeoIP lookups per IP (e.g. in the cache layer from Section 35 — Redis if available, otherwise the database cache driver) to avoid repeated lookups and reduce provider cost/latency.

## Where It Surfaces in the UI
* Employee Profile → Security Information (Section 4): "Last login" now also shows IP and last known division/area.
* Admin → Employee Profile → Audit History tab (Section 40): each audit/login entry shows IP + division/area alongside actor and timestamp.
* Employee Dashboard: optionally show the employee their own last 5 login locations, so they can self-report a suspicious login.
* Admin Security/Audit Logs page: filterable by division/area, useful for spotting logins from unexpected regions.

## Constraints
* Tenant isolation applies: `user_login_history` must be scoped by `organization_id` like every other tenant table (Section 22).
* This feature is additive to, not a replacement for, the terminal-level Location Security controls in Section 32 (which govern NFC attendance terminals specifically, not user/API logins).
* Must be disclosed in the privacy policy and included in data retention/export/deletion workflows (Section 43).
* IP address and derived location are personal data — apply the same encryption-at-rest and least-privilege access rules as salary data (Section 21, Section 43).

---

# 72. ATTENDANCE STATE MACHINE: DUPLICATE TAPS & MID-DAY EXITS
Two situations aren't fully solved by treating attendance as a strict single check-in/check-out pair, and both need explicit rules:

## A. Accidental Double-Tap (Same Card, Same Terminal, Seconds Apart)
Causes: a slow NFC reader that reads the same physical tap twice, an employee unsure if the first tap registered and tapping again, or a flaky network causing the terminal to retry a request it thinks failed.

Rule — debounce window:

```text
IF (employee_id + terminal_id) had an event
AND the new tap arrives within DEBOUNCE_SECONDS (default: 10–15s, configurable per organization)
THEN
Do NOT create a new attendance_event
Return the SAME result as the original tap (idempotent response)
Terminal displays: "Already recorded — Check-in at 09:03 AM" (not an error, not a new state)
ELSE
Treat as a new, legitimate event and continue to the IN/OUT toggle logic below
```

Implementation notes:
* This is a business-logic debounce, distinct from — and in addition to — the request-level idempotency-key/unique-constraint protection already defined in Section 50 (which stops two simultaneous concurrent requests from double-writing; the debounce here stops two sequential real taps seconds apart from being treated as two separate actions).
* Store the debounce check as: most recent `attendance_events` row for that `(employee_id, terminal_id)`, compare `timestamp` to `now()`. A unique partial index or cached "last event per employee" key (Section 35) makes this a fast lookup rather than a full table scan.
* The debounce window should be short enough that it never blocks a genuine fast re-entry (e.g. tapping in, realizing you forgot something, walking back out 20 seconds later is rare but should still be possible) — that's why it's configurable, not hardcoded, and why 10–15s (not minutes) is the recommended default.
* This debounce applies per event type transition, not globally: if somehow a CHECK_IN and a CHECK_OUT both arrive within the window (unlikely, but possible with a misbehaving terminal), do not silently drop the second one — flag it for admin review via Section 24 (Attendance Correction) rather than guessing.

## B. Employee Leaves the Office During Working Hours (Errand, Lunch, Offsite Meeting)
The Daily Attendance model (Section 10) already treats a day as multiple IN→OUT segments, which is what makes this solvable:

```text
09:00  CHECK_IN   (segment 1 starts)
12:30  CHECK_OUT  (segment 1 ends — employee steps out for lunch/errand)
13:15  CHECK_IN   (segment 2 starts — same terminal, same day)
18:10  CHECK_OUT  (segment 2 ends — end of day)
total_work_duration = (12:30 - 09:00) + (18:10 - 13:15)
segment_count = 2
```

State machine per tap, in plain terms:

```text
Employee currently CHECKED_IN + taps card  → creates CHECK_OUT, closes the open segment
Employee currently CHECKED_OUT + taps card → creates CHECK_IN, opens a new segment
```

This means the same terminal can be used for both "leaving the building" and "end of day" — the system doesn't need to know the employee's intent, because every tap just flips their current state and every closed segment counts toward the day's total. No new event types are required beyond the existing CHECK_IN/CHECK_OUT/BREAK_START/BREAK_END.

What still needs explicit rules:
* Early-leave / late-return flags: don't apply "late" logic to segment 2+'s check-in (that would incorrectly flag a lunch return as being "late for work"). Late-arrival scoring only applies to the first CHECK_IN of the day; early-departure scoring only applies to the last CHECK_OUT of the day. Middle segments should be invisible to the late/early scoring rules and only feed into `total_work_duration`.
* Unreturned exit (forgot to tap back in): if an employee's last event for the day is a CHECK_OUT well before shift-end and no further CHECK_IN follows before the configurable day-boundary/cutoff (Section 51's timezone-aware day boundary), the daily record should surface as `HALF_DAY` or flag a `possible_missed_checkin` review item for HR — not silently assume they simply left for the day, since a genuinely short lunch that ran long looks identical in the raw data to leaving early. This is a review flag, not an automatic penalty — resolved through the Attendance Correction workflow (Section 24).
* Excessive segment count: a very high number of segments in one day (e.g. tapping in/out 15+ times) is unusual and should be surfaced as an anomaly for HR/admin review rather than silently accepted — it may indicate a broken card, a broken terminal, or misuse.
* Optional stricter mode: organizations that want a harder boundary (e.g. security-sensitive offices) can configure a policy where only the first CHECK_IN and last CHECK_OUT of the day count toward `total_work_duration`, and any mid-day exit is only informational (visible in the audit trail) but doesn't reduce/reward hours differently. This should be a per-organization setting, not a hardcoded behavior — default to the segment-summing model above, since it's fairer to employees who legitimately step out and return.

## C. Overtime interaction [OT]
* Overtime is computed from the same completed segments as `total_work_duration` (Section 73). Mid-day exits reduce worked time and therefore never inflate overtime.
* A day flagged `possible_missed_checkin`, an open segment with no CHECK_OUT, or an excessive-segment anomaly is **not eligible for overtime calculation** until HR resolves it via Section 24. Overtime for that date stays `FLAGGED`.
* An employee still checked in past the configured maximum shift length (e.g. 16h) is treated as a probable missed check-out: no overtime accrues beyond the cap, and the day is flagged for review.

---

# 73. OVERTIME MANAGEMENT [OT]
This section defines how extra working time is detected, approved, paid, scored, and shown to employees. Attendance events remain the source of truth (Section 67); overtime is derived, never typed in by the employee.

## A. Definitions
```text
Regular Minutes    = minutes worked within the scheduled shift (up to expected daily minutes)
Overtime (raw)     = worked minutes beyond the overtime baseline (see modes below)
Overtime (countable) = raw overtime after threshold, rounding, daily/weekly caps, and approval
```

Terminology: "extra time" shown to employees means overtime.

## B. Overtime Modes (per-organization, configurable)
```text
AFTER_OFFICE_END        Only time worked after Office End (e.g. after 18:00) counts.
                        Arriving early does not create overtime.
ABOVE_EXPECTED_HOURS    Total worked minutes minus expected daily minutes.
                        Counts regardless of when the time was worked.
```

Default: `ABOVE_EXPECTED_HOURS` (fairest for flexible IT teams). Expected daily minutes = office hours minus configured break (e.g. 09:00-18:00 minus 13:00-14:00 = 8h).

Example (expected 8h, office end 18:00):

```text
Arrive 08:30, leave 18:00 (mid-day break 1h)
  AFTER_OFFICE_END       → 0 min overtime
  ABOVE_EXPECTED_HOURS   → 30 min overtime

Arrive 09:00, leave 19:15
  AFTER_OFFICE_END       → 75 min overtime
  ABOVE_EXPECTED_HOURS   → 75 min overtime
```

## C. Calculation Pipeline
```text
1. Load worked minutes from completed segments (Section 72)
2. Exclude flagged days (possible_missed_checkin, open segment, anomaly)
3. Determine day type: WORKING_DAY | WEEKEND | HOLIDAY | LEAVE_DAY (Sections 11, 12)
4. Compute raw overtime by mode
   - WEEKEND / HOLIDAY: all worked minutes are overtime (configurable)
   - LEAVE_DAY: worked minutes are flagged for review (employee on approved leave but punched)
5. Apply late offset rule (if enabled)
6. Apply start threshold (ignore below X minutes)
7. Apply rounding (e.g. nearest 15 min, down/up/nearest)
8. Apply daily cap, then weekly cap
9. Set status: PENDING (approval required) or AUTO_APPROVED
10. Upsert overtime_records row for (employee_id, work_date)
```

Recalculation runs when events are added, corrected (Section 24), or the policy version applicable to that date changes.

## D. Late / Overtime Interaction
Configurable setting `offset_late_with_overtime`:

```text
OFF (default): Late minutes are deducted per the salary rules; overtime is paid separately.
ON:            Overtime minutes first offset that day's late minutes; only the remainder counts as overtime,
               and the offset late minutes are not deducted.
```

Whichever mode is chosen, the same minutes must never be both deducted and paid, or counted twice. The daily breakdown must show the offset explicitly.

## E. Approval Workflow
Setting `approval_mode`:

```text
AUTO         Countable overtime is AUTO_APPROVED immediately (small/trusted teams).
MANAGER      Employee's manager approves or rejects.
MANAGER_HR   Manager approves, then HR/Admin confirms.
```

Default recommendation: `MANAGER` (prevents farming overtime by staying tapped in).

```text
Detected
   ↓
PENDING ──► APPROVED  (manager / HR / admin, per RBAC)
   │
   ├──► REJECTED (reason required)
   └──► EXPIRED  (not reviewed within N days; policy: auto-approve, auto-reject, or escalate)
```

Rules:
* Approver must be within the same organization and authorized for that employee (manager of the employee, or HR/admin).
* Nobody can approve their own overtime.
* Approved/rejected records are locked; changes go through the `adjust` endpoint (HR/admin) and are audited.
* Optional: employee-submitted **overtime justification note** (free text, max length) attached to a detected day. It does not create overtime; it only helps the approver.
* Optional: **pre-approval mode** — overtime only counts if a request was approved before the work date. Off by default.
* Every state change writes an audit log (Section 23) and notifies the employee (Section 25).

## F. Pay Calculation
```text
Overtime Hourly Rate = Base Hourly Rate  (rate base configurable: GROSS | BASIC | BASIC + selected allowances)
Overtime Pay = (Countable Overtime Minutes / 60) × Overtime Hourly Rate × Multiplier
```

Multipliers (configurable, effective-dated):

```text
Weekday overtime:          1.5x
Weekend overtime:          2.0x
Holiday overtime:          2.0x
Night overtime (optional): configurable window and multiplier
```

Example using the Section 28 figures (hourly ৳144.23, weekday):

```text
1h 00m × ৳144.23 × 1.5 = ৳216.35
```

Alternative compensation (optional per organization): `PAID` (default) or `COMP_TIME` (accrue time-off balance instead of pay, tracked as a leave-balance-style ledger).

Overtime pay is a **separate payroll line item**, included in Current Estimated Net Salary only when APPROVED. Pending overtime is displayed as "potential" and excluded from the total. Finalized payroll preserves the overtime lines and historical policy values.

Legal note: statutory overtime rules (rates, daily/weekly limits, covered employee categories) vary by jurisdiction, including Bangladesh. Defaults must be reviewed by a legal/HR adviser before launch; keep all values configurable so they can be corrected without code changes.

## G. Overtime Policy Settings (Company Settings → Overtime Policy)
Effective-dated and versioned, like the work schedule (Section 11), so changes never rewrite history.

```text
overtime_policies
id
organization_id
effective_from
effective_to
enabled
mode                      AFTER_OFFICE_END | ABOVE_EXPECTED_HOURS
start_threshold_minutes   default 15
rounding_minutes          default 15
rounding_method           UP | DOWN | NEAREST
daily_cap_minutes         default 120
weekly_cap_minutes        default 720
monthly_cap_minutes       nullable
max_shift_minutes         default 960 (probable missed check-out guard)
approval_mode             AUTO | MANAGER | MANAGER_HR
pending_expiry_days       default 7
pending_expiry_action     AUTO_APPROVE | AUTO_REJECT | ESCALATE
weekday_multiplier        default 1.50
weekend_multiplier        default 2.00
holiday_multiplier        default 2.00
night_window_start/end    nullable
night_multiplier          nullable
rate_base                 GROSS | BASIC | CUSTOM
offset_late_with_overtime boolean default false
weekend_all_overtime      boolean default true
compensation_type         PAID | COMP_TIME
require_pre_approval      boolean default false
show_pay_to_employee      boolean default true
show_hours_to_employee    boolean default true
overtime_score_bonus_max  default 0
created_by, created_at, updated_at
```

Per-employee override: `overtime_eligibility` on the employee record (`ELIGIBLE | NOT_ELIGIBLE | POLICY_DEFAULT`), for example excluding salaried managers.

## H. Data Model
```text
overtime_records
id
organization_id
employee_id
work_date
day_type                 WORKING_DAY | WEEKEND | HOLIDAY | LEAVE_DAY
policy_id                (policy version used)
worked_minutes
expected_minutes
raw_minutes
late_offset_minutes
countable_minutes
multiplier
hourly_rate_snapshot
estimated_pay            (derived; never client-writable)
status                   NONE | PENDING | APPROVED | REJECTED | EXPIRED | AUTO_APPROVED | FLAGGED
flag_reason              nullable
employee_note            nullable
calculated_at
UNIQUE (employee_id, work_date)

overtime_approvals
id
organization_id
overtime_record_id
approver_user_id
decision                 APPROVED | REJECTED | ADJUSTED | ESCALATED
minutes_before / minutes_after
reason
decided_at
```

`daily_attendance.overtime_minutes` (Section 10) mirrors the countable, approved value for fast queries; `overtime_records` is the detailed source.

## I. Employee Visibility (REQUIRED)
Employees can see their own extra time in:

1. **Dashboard → Overtime / Extra Time card** (Section 39): month total, approved, pending, rejected, today's overtime, estimated pay (if allowed).
2. **Dashboard calendar**: overtime dot on each day, and overtime details in the day popover.
3. **Dashboard weekly summary**: weekly overtime hours and pay.
4. **Recent attendance list**: `+1h 15m OT (pending)` next to the day.
5. **Statistics page → Overtime section**: breakdown by day type, multipliers, limit usage, daily table, earnings vs potential.
6. **My Overtime page**: full list with status, approver, rejection reason, month filter.
7. **Notifications**: detected, approved, rejected, limit exceeded.
8. **NFC terminal check-out screen** (optional): shows extra time for the day.

Employee restrictions: read-only; cannot edit minutes/pay, approve, or delete. Employees only see their own records (tenant + ownership enforced server-side). If the employee is not eligible or `show_hours_to_employee` is off, the card and page are hidden. If `show_pay_to_employee` is off, hours only.

Manager view: team overtime totals and a pending approval queue (hours only unless salary permission is granted). HR/Admin view: full details, pay, adjustment, reports.

## J. Score & Risk
* Working Hours score uses regular hours only, capped at 100 (Section 16). Overtime never masks absences or lateness and never lowers the score.
* Optional capped bonus via `overtime_score_bonus_max`, shown as its own line in the breakdown.
* Optional overwork notice when weekly/monthly caps are approached or exceeded (Section 15). Informational only.

## K. Month-End
* Pending overtime is resolved per `pending_expiry_action` before monthly status generation (Section 52).
* Finalized monthly status stores approved/pending/rejected overtime minutes (Section 17) and never changes afterward.
* Late approvals of a closed month create an adjustment line in the next payroll period, not a rewrite of the closed month.

## L. Edge Cases
```text
Overnight shift / crossing midnight   Attribute overtime to the shift's start date using the org timezone day boundary (Section 51).
Forgotten check-out                   No overtime; day flagged (Section 72-C).
Working while on approved leave       Flagged for HR review; no automatic overtime.
Holiday worked                        Holiday multiplier; all minutes overtime by default.
Policy changes mid-month              Each date uses the policy version effective on that date.
Salary changes mid-month              Hourly rate snapshot uses the salary record effective on that date (Section 28).
Manual attendance correction          Overtime recalculated; approved value returns to PENDING/flagged if changed; both audited.
Debounced or duplicate taps           Ignored by the Section 72 debounce; never generate overtime.
Part-time / shift workers             Use the employee's own schedule to derive expected minutes.
Employee not eligible                 No overtime records created; card hidden.
```

## M. Tests Required
* Unit tests for each mode, threshold, rounding, cap, late offset, multiplier, and rate base.
* Integration tests: punch → daily attendance → overtime record → dashboard/statistics values match.
* Security tests: self-approval blocked, IDOR/cross-tenant on overtime endpoints, employee cannot mass-assign minutes/pay, manager cannot approve outside their team.
* Regression: flagged days never produce overtime; overtime is never double-counted with late offset; historical months do not change when the policy changes.

---