<?php

namespace App\Services;

use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\WorkSchedule;

/**
 * Seeds Phase 2 defaults (work schedule + leave types) for an organization.
 */
class WorkforceDefaults
{
    public const LEAVE_TYPES = [
        ['code' => 'ANNUAL', 'name' => 'Annual Leave', 'days' => 20, 'paid' => true],
        ['code' => 'SICK', 'name' => 'Sick Leave', 'days' => 10, 'paid' => true],
        ['code' => 'CASUAL', 'name' => 'Casual Leave', 'days' => 10, 'paid' => true],
        ['code' => 'EMERGENCY', 'name' => 'Emergency Leave', 'days' => 5, 'paid' => true],
        ['code' => 'UNPAID', 'name' => 'Unpaid Leave', 'days' => 0, 'paid' => false],
    ];

    public function apply(Organization $organization): void
    {
        WorkSchedule::withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Standard',
            ],
            [
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'grace_minutes' => 15,
                'break_start' => '13:00:00',
                'break_end' => '14:00:00',
                'work_days' => [1, 2, 3, 4, 5],
                'is_default' => true,
            ],
        );

        foreach (self::LEAVE_TYPES as $type) {
            LeaveType::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $type['code'],
                ],
                [
                    'name' => $type['name'],
                    'default_days_per_year' => $type['days'],
                    'is_paid' => $type['paid'],
                    'is_active' => true,
                ],
            );
        }
    }
}
