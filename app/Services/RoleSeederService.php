<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\OrganizationScope;

class RoleSeederService
{
    /**
     * Permission catalog (key => [name, group]).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CATALOG = [
        'org.view' => ['View organization', 'organization'],
        'org.manage' => ['Manage organization settings', 'organization'],
        'settings.manage' => ['Manage company settings', 'organization'],

        'users.view' => ['View users', 'users'],
        'users.manage' => ['Manage users and roles', 'users'],

        'employees.view' => ['View employees', 'employees'],
        'employees.view_team' => ['View team employees', 'employees'],
        'employees.create' => ['Create employees', 'employees'],
        'employees.edit' => ['Edit employees', 'employees'],
        'employees.delete' => ['Delete employees', 'employees'],

        'departments.view' => ['View departments', 'departments'],
        'departments.manage' => ['Manage departments', 'departments'],

        'positions.manage' => ['Manage positions', 'departments'],

        'audit.view' => ['View audit logs', 'audit'],

        'attendance.view' => ['View attendance', 'attendance'],
        'attendance.manage' => ['Manage attendance and corrections', 'attendance'],
        'attendance.terminal' => ['Manage attendance terminals', 'attendance'],

        'leave.view' => ['View leave requests', 'leave'],
        'leave.manage' => ['Manage leave types and balances', 'leave'],
        'leave.approve' => ['Approve or reject leave', 'leave'],

        'nfc.view' => ['View NFC cards', 'nfc'],
        'nfc.manage' => ['Issue and manage NFC cards', 'nfc'],

        'salary.view' => ['View salary', 'salary'],
        'salary.view_own' => ['View own salary', 'salary'],
        'salary.edit' => ['Edit salary', 'salary'],

        'reports.view' => ['View reports', 'reports'],

        'dashboard.admin' => ['Access admin dashboard', 'dashboard'],
        'dashboard.employee' => ['Access employee dashboard', 'dashboard'],
    ];

    /**
     * Role slug => permission keys.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'company_admin' => [
            'org.view', 'org.manage', 'settings.manage',
            'users.view', 'users.manage',
            'employees.view', 'employees.create', 'employees.edit', 'employees.delete',
            'departments.view', 'departments.manage', 'positions.manage',
            'audit.view',
            'attendance.view', 'attendance.manage', 'attendance.terminal',
            'leave.view', 'leave.manage', 'leave.approve',
            'nfc.view', 'nfc.manage',
            'salary.view', 'salary.edit',
            'reports.view',
            'dashboard.admin',
        ],
        'hr' => [
            'org.view',
            'users.view',
            'employees.view', 'employees.create', 'employees.edit',
            'departments.view', 'departments.manage',
            'attendance.view', 'attendance.manage',
            'leave.view', 'leave.manage', 'leave.approve',
            'nfc.view',
            'salary.view',
            'reports.view',
            'dashboard.admin',
        ],
        'manager' => [
            'org.view',
            'employees.view', 'employees.view_team',
            'departments.view',
            'attendance.view',
            'leave.view', 'leave.approve',
            'reports.view',
            'dashboard.admin',
        ],
        'employee' => [
            'org.view',
            'leave.view',
            'salary.view_own',
            'dashboard.employee',
        ],
    ];

    public const ROLE_NAMES = [
        'company_admin' => 'Company Admin',
        'hr' => 'HR',
        'manager' => 'Manager',
        'employee' => 'Employee',
    ];

    /**
     * Ensure global permission catalog exists.
     */
    public function ensureCatalog(): void
    {
        foreach (self::CATALOG as $key => [$name, $group]) {
            Permission::firstOrCreate(
                ['key' => $key],
                ['name' => $name, 'group' => $group],
            );
        }
    }

    /**
     * @return array<string, Role> slug => Role
     */
    public function createForOrganization(Organization $organization): array
    {
        $this->ensureCatalog();

        $permissions = Permission::pluck('id', 'key');
        $map = [];

        foreach (self::ROLE_PERMISSIONS as $slug => $keys) {
            // Bypass org global scope: provisioning often runs before Auth exists.
            $role = Role::withoutGlobalScope(OrganizationScope::class)
                ->firstOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'slug' => $slug,
                    ],
                    ['name' => self::ROLE_NAMES[$slug] ?? $slug],
                );

            $ids = [];
            foreach ($keys as $key) {
                if (isset($permissions[$key])) {
                    $ids[] = $permissions[$key];
                }
            }
            $role->permissions()->sync($ids);

            $map[$slug] = $role;
        }

        return $map;
    }
}
