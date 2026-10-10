<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Overtime Report</h2>
            @include('reports._tabs', ['active' => 'overtime'])
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
                <form method="GET" action="{{ route('reports.overtime') }}" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4 items-end">
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
                        <x-input-label for="day_type" value="Day type" />
                        <select id="day_type" name="day_type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All day types</option>
                            @foreach (['WORKING_DAY', 'WEEKEND', 'HOLIDAY', 'LEAVE_DAY'] as $dayTypeOption)
                                <option value="{{ $dayTypeOption }}" @selected(old('day_type', $filters['day_type'] ?? '') === $dayTypeOption)>{{ $dayTypeOption }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All statuses</option>
                            @foreach (['PENDING', 'APPROVED', 'REJECTED', 'FLAGGED'] as $statusOption)
                                <option value="{{ $statusOption }}" @selected(old('status', $filters['status'] ?? '') === $statusOption)>
                                    {{ $statusOption === 'APPROVED' ? 'Approved (incl. auto)' : $statusOption }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Apply
                        </button>
                        <a href="{{ route('reports.export', array_merge(['type' => 'overtime'], request()->query())) }}"
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
                        @unless ($showPay)
                            Pay columns are hidden (restricted to users with salary access).
                        @endunless
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Day type</th>
                                <th class="px-4 py-3 text-right">Raw minutes</th>
                                <th class="px-4 py-3 text-right">Approved minutes</th>
                                <th class="px-4 py-3 text-right">Multiplier</th>
                                @if ($showPay)
                                    <th class="px-4 py-3 text-right">Estimated pay</th>
                                @endif
                                <th class="px-4 py-3">Approver</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rows as $row)
                                @php
                                    $approval = $row->approvals->whereNotNull('approver_user_id')->last();
                                    $statusStyle = match ($row->status) {
                                        'PENDING' => 'bg-amber-100 text-amber-800',
                                        'REJECTED' => 'bg-red-100 text-red-800',
                                        'FLAGGED' => 'bg-orange-100 text-orange-800',
                                        default => 'bg-emerald-100 text-emerald-800',
                                    };
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $row->employee?->user?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row->employee?->employee_code ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row->employee?->department?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-900 whitespace-nowrap">
                                        {{ $row->work_date->format('d M Y') }}
                                        <span class="text-gray-400 text-xs">{{ $row->work_date->format('D') }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $row->day_type }}</td>
                                    <td class="px-4 py-3 text-gray-700 text-right">{{ $row->raw_minutes }}</td>
                                    <td class="px-4 py-3 text-gray-900 text-right font-medium">{{ $row->countable_minutes }}</td>
                                    <td class="px-4 py-3 text-gray-700 text-right">{{ rtrim(rtrim(number_format((float) $row->multiplier, 2, '.', ''), '0'), '.') }}×</td>
                                    @if ($showPay)
                                        <td class="px-4 py-3 text-gray-900 text-right">{{ number_format((float) $row->estimated_pay, 2) }}</td>
                                    @endif
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ $approval?->approver?->name ?? ($row->status === \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED ? 'Automatic' : '—') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusStyle }}">
                                            {{ str_replace('_', ' ', $row->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-6 py-8 text-center text-gray-500">No overtime rows match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
