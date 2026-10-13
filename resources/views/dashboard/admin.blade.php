<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Dashboard
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="px-6 pt-5 pb-2 flex items-baseline justify-between">
                    <h3 class="font-semibold text-gray-800">Today's attendance</h3>
                    <span class="text-xs text-gray-400">{{ now()->format('l, d M Y') }}</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 px-6 pb-6">
                    <x-hover-panel :drill="$drilldowns['present_today'] ?? null" class="rounded-lg bg-green-50 p-4">
                        <x-slot name="trigger">
                            <div class="text-sm text-green-700">Present today</div>
                            <div class="text-3xl font-semibold text-green-800 mt-1">{{ $stats['present_today'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['late_today'] ?? null" class="rounded-lg bg-amber-50 p-4">
                        <x-slot name="trigger">
                            <div class="text-sm text-amber-700">Late today</div>
                            <div class="text-3xl font-semibold text-amber-800 mt-1">{{ $stats['late_today'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['absent_today'] ?? null" class="rounded-lg bg-red-50 p-4">
                        <x-slot name="trigger">
                            <div class="text-sm text-red-700">Absent today</div>
                            <div class="text-3xl font-semibold text-red-800 mt-1">{{ $stats['absent_today'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['on_leave_today'] ?? null" class="rounded-lg bg-blue-50 p-4">
                        <x-slot name="trigger">
                            <div class="text-sm text-blue-700">On leave</div>
                            <div class="text-3xl font-semibold text-blue-800 mt-1">{{ $stats['on_leave_today'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['avg_attendance'] ?? null" class="rounded-lg bg-indigo-50 p-4">
                        <x-slot name="trigger">
                            <div class="text-sm text-indigo-700">Avg attendance (30d)</div>
                            <div class="text-3xl font-semibold text-indigo-800 mt-1">{{ $stats['avg_attendance_hours'] }}<span class="text-base font-normal">h</span></div>
                        </x-slot>
                    </x-hover-panel>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <x-hover-panel :drill="$drilldowns['total_employees'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <x-slot name="trigger">
                        <div class="text-sm text-gray-500">Total Employees</div>
                        <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['total_employees'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['active_employees'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <x-slot name="trigger">
                        <div class="text-sm text-gray-500">Active Employees</div>
                        <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['active_employees'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['departments'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <x-slot name="trigger">
                        <div class="text-sm text-gray-500">Departments</div>
                        <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['departments'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['users'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <x-slot name="trigger">
                        <div class="text-sm text-gray-500">Users</div>
                        <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['users'] }}</div>
                    </x-slot>
                </x-hover-panel>
            </div>

            {{-- §18 score, overtime & risk widgets (§15 admin visibility). --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <x-hover-panel :drill="$drilldowns['avg_score'] ?? null" class="rounded-lg bg-indigo-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-indigo-700">Average Score</div>
                        <div class="text-3xl font-semibold text-indigo-800 mt-1">
                            @if ($stats['avg_score'] !== null)
                                {{ number_format($stats['avg_score'], 1) }}
                            @else
                                —
                            @endif
                        </div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['at_risk'] ?? null" class="rounded-lg bg-red-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-red-700">At Risk Employees</div>
                        <div class="text-3xl font-semibold text-red-800 mt-1">{{ $stats['at_risk'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['high_risk_alerts'] ?? null" class="rounded-lg bg-orange-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-orange-700">High-Risk Alerts</div>
                        <div class="text-3xl font-semibold text-orange-800 mt-1">{{ $stats['high_risk_alerts'] }}</div>
                        <div class="text-xs text-orange-600 mt-1">this week</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['overtime_today'] ?? null" class="rounded-lg bg-purple-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-purple-700">Overtime Today</div>
                        <div class="text-3xl font-semibold text-purple-800 mt-1">
                            {{ number_format($stats['overtime_today'] / 60, 1) }}<span class="text-base font-normal">h</span>
                        </div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['pending_overtime'] ?? null" class="rounded-lg bg-amber-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-amber-700">Pending Overtime Approvals</div>
                        <div class="text-3xl font-semibold text-amber-800 mt-1">{{ $stats['pending_overtime'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['overtime_month'] ?? null" class="rounded-lg bg-teal-50 p-4">
                    <x-slot name="trigger">
                        <div class="text-sm text-teal-700">Overtime Hours This Month</div>
                        <div class="text-3xl font-semibold text-teal-800 mt-1">
                            {{ number_format($stats['overtime_month'] / 60, 1) }}<span class="text-base font-normal">h</span>
                        </div>
                    </x-slot>
                </x-hover-panel>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="font-semibold text-gray-800 mb-4">Recent activity</h3>
                    @if ($recentAudit->isEmpty())
                        <p class="text-sm text-gray-500">No recent audit events.</p>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($recentAudit as $log)
                                <li class="py-2 flex justify-between text-sm">
                                    <span class="text-gray-700">
                                        <span class="font-medium">{{ $log->actor?->name ?? 'System' }}</span>
                                        {{ str_replace('.', ' ', $log->action) }}
                                        @if ($log->resource_type)
                                            <span class="text-gray-400">#{{ $log->resource_id }}</span>
                                        @endif
                                    </span>
                                    <span class="text-gray-400">{{ $log->created_at->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @can('reports.view')
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Analytics &amp; exports</h3>
                        <p class="mt-1 text-sm text-gray-500">Workforce trends, attendance, overtime and salary reports — each with CSV export.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('analytics.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Open analytics
                        </a>
                        <a href="{{ route('reports.attendance') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Reports &amp; exports
                        </a>
                    </div>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
