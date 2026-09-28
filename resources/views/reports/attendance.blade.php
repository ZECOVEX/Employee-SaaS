<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance Report</h2>
            @include('reports._tabs', ['active' => 'attendance'])
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
                <form method="GET" action="{{ route('reports.attendance') }}" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4 items-end">
                    <div>
                        <x-input-label for="from" value="From" />
                        <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="old('from', $filters['from'])" />
                    </div>
                    <div>
                        <x-input-label for="to" value="To" />
                        <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="old('to', $filters['to'])" />
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
                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All statuses</option>
                            @foreach (['PRESENT', 'LATE', 'HALF_DAY', 'ABSENT', 'LEAVE', 'WEEKEND', 'HOLIDAY'] as $statusOption)
                                <option value="{{ $statusOption }}" @selected(old('status', $filters['status'] ?? '') === $statusOption)>{{ $statusOption }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Apply
                        </button>
                        <a href="{{ route('reports.export', array_merge(['type' => 'attendance'], request()->query())) }}"
                           class="px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Export CSV
                        </a>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">Rows: {{ $rows->count() }}</h3>
                    <p class="text-xs text-gray-500">
                        Excel: open the CSV in Excel · PDF: use your browser's print dialog on this page.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">In</th>
                                <th class="px-4 py-3">Out</th>
                                <th class="px-4 py-3 text-right">Worked</th>
                                <th class="px-4 py-3 text-right">Late</th>
                                <th class="px-4 py-3 text-right">OT</th>
                                <th class="px-4 py-3">Flag</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @php($org = auth()->user()->organization)
                            @forelse ($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $row->date->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-gray-900">{{ $row->employee?->user?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row->employee?->employee_code ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row->employee?->department?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ [
                                            'PRESENT' => 'bg-green-100 text-green-800',
                                            'LATE' => 'bg-yellow-100 text-yellow-800',
                                            'HALF_DAY' => 'bg-orange-100 text-orange-800',
                                            'ABSENT' => 'bg-red-100 text-red-800',
                                            'LEAVE' => 'bg-blue-100 text-blue-800',
                                            'HOLIDAY' => 'bg-purple-100 text-purple-800',
                                        ][$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                                            {{ $row->status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $org?->formatTime($row->first_check_in) ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $org?->formatTime($row->last_check_out) ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700 text-right">{{ intdiv($row->total_work_minutes, 60) }}h {{ str_pad((string) ($row->total_work_minutes % 60), 2, '0', STR_PAD_LEFT) }}m</td>
                                    <td class="px-4 py-3 text-right {{ $row->late_minutes > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $row->late_minutes > 0 ? $row->late_minutes.'m' : '—' }}</td>
                                    <td class="px-4 py-3 text-right {{ $row->overtime_minutes > 0 ? 'text-indigo-600' : 'text-gray-400' }}">{{ $row->overtime_minutes > 0 ? $row->overtime_minutes.'m' : '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $row->review_flag ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-6 py-8 text-center text-gray-500">No attendance rows match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
