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
                <div class="flex items-start gap-5">
                    @if ($employee->photo_path)
                        <img src="{{ Storage::disk('public')->url($employee->photo_path) }}" alt=""
                             class="w-20 h-20 rounded-full object-cover shrink-0" />
                    @endif
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm grow">
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
            </div>

            @if ($canSeeSalary)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-800">Salary</h3>
                        <a href="{{ route('salary.show', $employee) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">
                            Salary history →
                        </a>
                    </div>
                    @if ($currentSalary)
                        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                            <div><dt class="text-gray-500">Basic</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->basic_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Gross</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->gross_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Net</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->net_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Effective from</dt>
                                <dd class="mt-1 text-gray-900">{{ $currentSalary->effective_from->format('d M Y') }}</dd></div>
                        </dl>
                    @else
                        <p class="text-sm text-gray-500">No salary record yet.</p>
                    @endif
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-2">Tabs</h3>
                <div class="flex flex-wrap gap-2 text-sm">
                    <a href="{{ route('attendance.employee', $employee) }}" wire:navigate
                       class="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700 hover:bg-indigo-100">Attendance</a>
                    @foreach (['Leave', 'Monthly Status', 'NFC Card', 'Audit History'] as $tab)
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-500">{{ $tab }} (soon)</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
