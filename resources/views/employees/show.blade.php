<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $employee->user?->name }} ({{ $employee->employee_code }})
            </h2>
            @can('employees.edit')
                <a href="{{ route('employees.edit', $employee) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    Edit
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-4">Overview</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                    <div><dt class="text-gray-500">Email</dt><dd class="text-gray-900">{{ $employee->user?->email }}</dd></div>
                    <div><dt class="text-gray-500">Status</dt><dd class="text-gray-900 capitalize">{{ $employee->status }}</dd></div>
                    <div><dt class="text-gray-500">Employment</dt><dd class="text-gray-900 capitalize">{{ str_replace('_', ' ', $employee->employment_type) }}</dd></div>
                    <div><dt class="text-gray-500">Department</dt><dd class="text-gray-900">{{ $employee->department?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Position</dt><dd class="text-gray-900">{{ $employee->position?->title ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Manager</dt><dd class="text-gray-900">{{ $employee->manager?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Phone</dt><dd class="text-gray-900">{{ $employee->phone ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Joined</dt><dd class="text-gray-900">{{ $employee->joining_date?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Last login</dt><dd class="text-gray-900">{{ $employee->user?->last_login_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                </dl>
            </div>

            @if ($canSeeSalary)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800 mb-2">Salary</h3>
                    <p class="text-sm text-gray-500">Salary records arrive in Phase 4. Access is gated by <code>salary.view</code>.</p>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-2">Tabs (coming soon)</h3>
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach (['Attendance', 'Leave', 'Monthly Status', 'NFC Card', 'Audit History'] as $tab)
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-500">{{ $tab }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
