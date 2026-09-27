<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            My Dashboard
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-lg text-gray-800">Good day, {{ $user->name }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ now()->format('F Y') }}</p>
            </div>

            @if ($employee)
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-gray-500">Employee ID</div>
                        <div class="text-xl font-semibold text-gray-900 mt-1">{{ $employee->employee_code }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-gray-500">Department</div>
                        <div class="text-xl font-semibold text-gray-900 mt-1">{{ $employee->department?->name ?? '—' }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-gray-500">Position</div>
                        <div class="text-xl font-semibold text-gray-900 mt-1">{{ $employee->position?->title ?? '—' }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-gray-500">Manager</div>
                        <div class="text-xl font-semibold text-gray-900 mt-1">{{ $employee->manager?->name ?? '—' }}</div>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800 mb-2">Employment</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Status</dt>
                            <dd class="text-gray-900 capitalize">{{ $employee->status }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Type</dt>
                            <dd class="text-gray-900 capitalize">{{ str_replace('_', ' ', $employee->employment_type) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Joined</dt>
                            <dd class="text-gray-900">{{ $employee->joining_date?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Work location</dt>
                            <dd class="text-gray-900">{{ $employee->work_location ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-600">
                    No employee profile linked to this account yet.
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 text-sm text-gray-600">
                Attendance score, leave balance and monthly status arrive in Phases 2–5.
            </div>
        </div>
    </div>
</x-app-layout>
