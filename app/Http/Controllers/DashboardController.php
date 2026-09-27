<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(Request $request): View
    {
        Gate::authorize('dashboard.admin');

        $orgId = $request->user()->organization_id;

        $stats = [
            'total_employees' => Employee::where('organization_id', $orgId)->count(),
            'active_employees' => Employee::where('organization_id', $orgId)->where('status', 'active')->count(),
            'departments' => Department::where('organization_id', $orgId)->count(),
            'users' => User::where('organization_id', $orgId)->count(),
        ];

        $recentAudit = AuditLog::with('actor:id,name')
            ->where('organization_id', $orgId)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('dashboard.admin', compact('stats', 'recentAudit'));
    }

    public function employee(Request $request): View
    {
        Gate::authorize('dashboard.employee');

        $user = $request->user();
        $employee = $user->employee()->with(['department:id,name', 'position:id,title', 'manager:id,name'])->first();

        return view('dashboard.employee', compact('employee', 'user'));
    }
}
