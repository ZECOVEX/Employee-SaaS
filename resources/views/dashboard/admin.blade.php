<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Dashboard
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 pt-5 pb-2 flex items-baseline justify-between">
                    <h3 class="font-semibold text-gray-800">Today's attendance</h3>
                    <span class="text-xs text-gray-400">{{ now()->format('l, d M Y') }}</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 px-6 pb-6">
                    <div class="rounded-lg bg-green-50 p-4">
                        <div class="text-sm text-green-700">Present today</div>
                        <div class="text-3xl font-semibold text-green-800 mt-1">{{ $stats['present_today'] }}</div>
                    </div>
                    <div class="rounded-lg bg-amber-50 p-4">
                        <div class="text-sm text-amber-700">Late today</div>
                        <div class="text-3xl font-semibold text-amber-800 mt-1">{{ $stats['late_today'] }}</div>
                    </div>
                    <div class="rounded-lg bg-red-50 p-4">
                        <div class="text-sm text-red-700">Absent today</div>
                        <div class="text-3xl font-semibold text-red-800 mt-1">{{ $stats['absent_today'] }}</div>
                    </div>
                    <div class="rounded-lg bg-blue-50 p-4">
                        <div class="text-sm text-blue-700">On leave</div>
                        <div class="text-3xl font-semibold text-blue-800 mt-1">{{ $stats['on_leave_today'] }}</div>
                    </div>
                    <div class="rounded-lg bg-indigo-50 p-4">
                        <div class="text-sm text-indigo-700">Avg attendance (30d)</div>
                        <div class="text-3xl font-semibold text-indigo-800 mt-1">{{ $stats['avg_attendance_hours'] }}<span class="text-base font-normal">h</span></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="text-sm text-gray-500">Total Employees</div>
                    <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['total_employees'] }}</div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="text-sm text-gray-500">Active Employees</div>
                    <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['active_employees'] }}</div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="text-sm text-gray-500">Departments</div>
                    <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['departments'] }}</div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="text-sm text-gray-500">Users</div>
                    <div class="text-3xl font-semibold text-gray-900 mt-1">{{ $stats['users'] }}</div>
                </div>
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

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 text-sm text-gray-600">
                Salary, scoring and analytics dashboards arrive in Phases 3–6.
            </div>
        </div>
    </div>
</x-app-layout>
