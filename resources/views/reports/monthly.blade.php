<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Monthly Report</h2>
            @include('reports._tabs', ['active' => 'monthly'])
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <form method="GET" action="{{ route('reports.monthly') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    <div>
                        <x-input-label for="month" value="Month" />
                        <x-text-input id="month" name="month" type="month" class="mt-1 block w-full" :value="old('month', $filters['month'])" />
                    </div>
                    <div>
                        <x-input-label for="employee_id" value="Employee" />
                        <select id="employee_id" name="employee_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All employees</option>
                            @foreach ($options['employees'] as $option)
                                <option value="{{ $option->id }}" @selected((string) (old('employee_id', $filters['employee_id'] ?? '')) === (string) $option->id)>
                                    {{ $option->employee_code }} — {{ $option->user?->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="department_id" value="Department" />
                        <select id="department_id" name="department_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All departments</option>
                            @foreach ($options['departments'] as $option)
                                <option value="{{ $option->id }}" @selected((string) (old('department_id', $filters['department_id'] ?? '')) === (string) $option->id)>
                                    {{ $option->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Apply
                        </button>
                        <a href="{{ route('reports.export', array_merge(['type' => 'monthly'], request()->query())) }}"
                           class="px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Export CSV
                        </a>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">{{ \Carbon\Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->format('F Y') }}</h3>
                    <p class="text-xs text-gray-500">Score and monthly status arrive with the scoring phase — columns are reserved.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3 text-right">Present</th>
                                <th class="px-4 py-3 text-right">Absent</th>
                                <th class="px-4 py-3 text-right">Late</th>
                                <th class="px-4 py-3 text-right">Leave</th>
                                <th class="px-4 py-3 text-right">Working Hours</th>
                                <th class="px-4 py-3 text-right">Overtime Hours</th>
                                <th class="px-4 py-3 text-right">Score</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row['code'] }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row['department'] }}</td>
                                    <td class="px-4 py-3 text-gray-900 text-right">{{ $row['present'] }}</td>
                                    <td class="px-4 py-3 text-right {{ $row['absent'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">{{ $row['absent'] }}</td>
                                    <td class="px-4 py-3 text-right {{ $row['late'] > 0 ? 'text-yellow-700 font-medium' : 'text-gray-400' }}" title="{{ $row['late_minutes'] }} late minutes">
                                        {{ $row['late'] }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 text-right">{{ $row['leave'] }}</td>
                                    <td class="px-4 py-3 text-gray-900 text-right">{{ $row['worked_hours'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 text-right">{{ $row['overtime_hours'] > 0 ? $row['overtime_hours'] : '—' }}</td>
                                    <td class="px-4 py-3 text-gray-400 text-right">—</td>
                                    <td class="px-4 py-3 text-gray-400">—</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-6 py-8 text-center text-gray-500">No employees match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($rows !== [])
                            <tfoot class="bg-gray-50 text-xs font-medium text-gray-600 uppercase tracking-wide">
                                <tr>
                                    <td class="px-4 py-3" colspan="3">Totals ({{ count($rows) }} employees)</td>
                                    <td class="px-4 py-3 text-right">{{ array_sum(array_column($rows, 'present')) }}</td>
                                    <td class="px-4 py-3 text-right">{{ array_sum(array_column($rows, 'absent')) }}</td>
                                    <td class="px-4 py-3 text-right">{{ array_sum(array_column($rows, 'late')) }}</td>
                                    <td class="px-4 py-3 text-right">{{ array_sum(array_column($rows, 'leave')) }}</td>
                                    <td class="px-4 py-3 text-right">{{ round(array_sum(array_column($rows, 'worked_hours')), 1) }}</td>
                                    <td class="px-4 py-3 text-right">{{ round(array_sum(array_column($rows, 'overtime_hours')), 1) }}</td>
                                    <td class="px-4 py-3 text-right">—</td>
                                    <td class="px-4 py-3"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
