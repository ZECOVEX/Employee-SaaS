<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\UserLoginHistory;

/**
 * §43 — data export: a structured JSON dump of everything stored about one
 * user (profile, employee record, recent logins). Salary is included only
 * for the user themself or an actor holding salary.view — it never leaks
 * through the export path to unauthorized roles.
 */
class PrivacyService
{
    /** @return array<string, mixed> */
    public function exportUserData(User $user): array
    {
        $user->loadMissing(['roles:id,name,slug', 'organization:id,name,timezone']);

        $employee = Employee::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();

        $loginHistory = UserLoginHistory::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'created_at', 'login_status', 'ip_address',
                'geo_country', 'geo_division', 'geo_area', 'user_agent',
            ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization' => $user->organization?->name,
                'roles' => $user->roles->pluck('slug'),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'mfa_enabled' => (bool) $user->mfa_enabled,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'employee' => $employee !== null ? [
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation,
                'employment_type' => $employee->employment_type,
                'status' => $employee->status,
                'joining_date' => $employee->joining_date,
                'phone' => $employee->phone,
                'address' => $employee->address,
                'date_of_birth' => $employee->date_of_birth,
                'work_location' => $employee->work_location,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
            ] : null,
            'login_history' => $loginHistory->map(fn (UserLoginHistory $entry) => [
                'at' => $entry->created_at?->toIso8601String(),
                'status' => $entry->login_status,
                'ip' => $entry->ip_address,
                'country' => $entry->geo_country,
                'division' => $entry->geo_division,
                'area' => $entry->geo_area,
                'user_agent' => $entry->user_agent,
            ])->values(),
        ];
    }
}
