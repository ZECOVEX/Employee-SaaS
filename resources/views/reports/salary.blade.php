<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Salary Report</h2>
            @include('reports._tabs', ['active' => 'salary'])
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
                <form method="GET" action="{{ route('reports.salary') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
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
                        <a href="{{ route('reports.export', array_merge(['type' => 'salary'], request()->query())) }}"
                           class="px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Export CSV
                        </a>
                    </div>
                </form>
            </div>

            @php($currency = auth()->user()->organization?->currency() ?? 'BDT')

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-800">
                            Effective salary records — {{ \Carbon\Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->format('F Y') }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Historical figures stay correct: each month shows the record in effect for that period (§28).
                            Restricted to users with salary access.
                        </p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3 text-right">Basic</th>
                                <th class="px-4 py-3 text-right">Allowances</th>
                                <th class="px-4 py-3 text-right">Bonus</th>
                                <th class="px-4 py-3 text-right">Other Deductions</th>
                                <th class="px-4 py-3 text-right">Gross</th>
                                <th class="px-4 py-3 text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row['code'] }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $row['department'] }}</td>
                                    @if ($row['record'])
                                        <td class="px-4 py-3 text-gray-900 text-right">{{ number_format($row['basic'], 2) }}</td>
                                        <td class="px-4 py-3 text-gray-700 text-right">{{ number_format($row['allowances'], 2) }}</td>
                                        <td class="px-4 py-3 text-gray-700 text-right">{{ number_format($row['bonus'], 2) }}</td>
                                        <td class="px-4 py-3 text-gray-700 text-right">{{ number_format($row['other_deductions'], 2) }}</td>
                                        <td class="px-4 py-3 text-gray-900 font-medium text-right">{{ number_format($row['gross'], 2) }}</td>
                                        <td class="px-4 py-3 text-gray-900 font-semibold text-right">{{ number_format($row['net'], 2) }}</td>
                                    @else
                                        <td class="px-4 py-3 text-gray-400 text-right" colspan="6">No salary record covers this period</td>
                                        <td class="px-4 py-3 text-gray-400 text-right">—</td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-8 text-center text-gray-500">No employees match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @php($withRecord = array_filter($rows, fn ($row) => $row['record'] !== null))
                        @if ($withRecord !== [])
                            <tfoot class="bg-gray-50 text-xs font-medium text-gray-600 uppercase tracking-wide">
                                <tr>
                                    <td class="px-4 py-3" colspan="3">Totals ({{ count($withRecord) }} with records)</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'basic')), 2) }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'allowances')), 2) }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'bonus')), 2) }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'other_deductions')), 2) }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'gross')), 2) }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format(array_sum(array_column($withRecord, 'net')), 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                <div class="px-6 py-3 border-t border-gray-200 text-xs text-gray-500">
                    Amounts in {{ $currency }}. Attendance deductions are excluded here — see Statistics or the payslip for those.
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
