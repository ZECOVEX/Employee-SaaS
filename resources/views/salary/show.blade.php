<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Salary — {{ $employee->user?->name }} ({{ $employee->employee_code }})
            </h2>
            @can('salary.view')
                <a href="{{ route('salary.index') }}" class="text-sm text-indigo-600 hover:underline" wire:navigate>← All salaries</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @php($currency = auth()->user()->organization?->currency() ?? 'BDT')

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-gray-800">Current salary</h3>
                    @if ($current)
                        <span class="text-xs text-gray-500">effective from {{ $current->effective_from->format('d M Y') }}</span>
                    @endif
                </div>

                @if ($current)
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Basic</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $currency }} {{ number_format($current->basic_salary, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Gross (incl. allowances/bonus)</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $currency }} {{ number_format($current->gross_salary, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Net</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $currency }} {{ number_format($current->net_salary, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Other deductions</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $currency }} {{ number_format($current->deductions, 2) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-gray-500">No salary record yet.{{ $canEdit ? ' Add the first one below.' : '' }}</p>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-800">Finalized payslips</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Month-end payroll records are frozen snapshots — later attendance or rule changes never touch them (§28).
                            Corrections create a new revision; the old one stays as history.
                        </p>
                    </div>
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-6 py-3">Period</th>
                            <th class="px-6 py-3">Revision</th>
                            <th class="px-6 py-3 text-right">Gross</th>
                            <th class="px-6 py-3 text-right">Deductions</th>
                            <th class="px-6 py-3 text-right">Net</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Finalized by</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($payslips as $payslip)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-900">{{ $payslip->periodLabel() }}</td>
                                <td class="px-6 py-4 text-gray-500">v{{ $payslip->revision }}</td>
                                <td class="px-6 py-4 text-gray-900 text-right">{{ $payslip->currency }} {{ number_format($payslip->gross_salary, 2) }}</td>
                                <td class="px-6 py-4 text-gray-500 text-right">{{ $payslip->currency }} {{ number_format($payslip->attendance_deduction_total + $payslip->other_deductions, 2) }}</td>
                                <td class="px-6 py-4 text-gray-900 text-right font-medium">{{ $payslip->currency }} {{ number_format($payslip->net_salary, 2) }}</td>
                                <td class="px-6 py-4">
                                    @if ($payslip->period_end->toDateString() >= $today)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Estimated (mid-month)</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Final</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $payslip->creator?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('payslips.show', [$employee, $payslip]) }}" class="text-indigo-600 hover:underline" wire:navigate>View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500">No payslips finalized yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($canEdit)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <form action="{{ route('payslips.store', $employee) }}" method="POST" class="flex flex-wrap items-end gap-4">
                            @csrf
                            <div>
                                <x-input-label for="payslip_period" value="Period" />
                                <x-text-input id="payslip_period" name="period" type="month" class="mt-1 block"
                                              :value="old('period', $today ? substr($today, 0, 7) : '')" required />
                                <x-input-error :messages="$errors->get('period')" class="mt-2" />
                            </div>
                            <div class="flex-1 min-w-48">
                                <x-input-label for="payslip_notes" value="Notes (optional)" />
                                <x-text-input id="payslip_notes" name="notes" type="text" class="mt-1 block w-full"
                                              :value="old('notes')" placeholder="e.g. month-end payroll" maxlength="500" />
                            </div>
                            <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                                Finalize Payslip
                            </button>
                        </form>
                        <p class="mt-2 text-xs text-gray-500">
                            Finalizing snapshots the month's calculation. To correct it later, open the payslip and choose Revise.
                        </p>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="font-semibold text-gray-800">History</h3>
                    <p class="text-xs text-gray-500 mt-1">
                        Salary records are effective-dated and never overwritten — past reports keep their original figures (§28).
                    </p>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-6 py-3">Period</th>
                            <th class="px-6 py-3 text-right">Basic</th>
                            <th class="px-6 py-3 text-right">Allowances</th>
                            <th class="px-6 py-3 text-right">Bonus</th>
                            <th class="px-6 py-3 text-right">Deductions</th>
                            <th class="px-6 py-3 text-right">Net</th>
                            <th class="px-6 py-3">Notes</th>
                            <th class="px-6 py-3">Added by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($records as $record)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-900">
                                    {{ $record->effective_from->format('d M Y') }}
                                    –
                                    {{ $record->effective_to?->format('d M Y') ?? 'Open' }}
                                </td>
                                <td class="px-6 py-4 text-gray-900 text-right">{{ number_format($record->basic_salary, 2) }}</td>
                                <td class="px-6 py-4 text-gray-500 text-right">{{ number_format($record->allowances, 2) }}</td>
                                <td class="px-6 py-4 text-gray-500 text-right">{{ number_format($record->bonus, 2) }}</td>
                                <td class="px-6 py-4 text-gray-500 text-right">{{ number_format($record->deductions, 2) }}</td>
                                <td class="px-6 py-4 text-gray-900 text-right font-medium">{{ number_format($record->net_salary, 2) }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->notes ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->creator?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500">No salary records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800 mb-1">Add salary record</h3>
                    <p class="mb-4 text-sm text-gray-500">
                        Creates a new effective-dated version: the previous version is closed the day before
                        this one starts. Nothing is overwritten.
                    </p>

                    <form action="{{ route('salary.store', $employee) }}" method="POST" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="basic_salary" value="Basic salary ({{ $currency }})" />
                                <x-text-input id="basic_salary" name="basic_salary" type="number" step="0.01" min="0" class="mt-1 block w-full"
                                              :value="old('basic_salary')" required placeholder="30000.00" />
                                <x-input-error :messages="$errors->get('basic_salary')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="allowances" value="Allowances ({{ $currency }})" />
                                <x-text-input id="allowances" name="allowances" type="number" step="0.01" min="0" class="mt-1 block w-full"
                                             :value="old('allowances')" placeholder="0.00" />
                                <x-input-error :messages="$errors->get('allowances')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="bonus" value="Bonus ({{ $currency }})" />
                                <x-text-input id="bonus" name="bonus" type="number" step="0.01" min="0" class="mt-1 block w-full"
                                             :value="old('bonus')" placeholder="0.00" />
                                <x-input-error :messages="$errors->get('bonus')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deductions" value="Other deductions ({{ $currency }})" />
                                <x-text-input id="deductions" name="deductions" type="number" step="0.01" min="0" class="mt-1 block w-full"
                                             :value="old('deductions')" placeholder="0.00" />
                                <x-input-error :messages="$errors->get('deductions')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="effective_from" value="Effective from" />
                                <x-text-input id="effective_from" name="effective_from" type="date" class="mt-1 block w-full"
                                             :value="old('effective_from')" required />
                                <x-input-error :messages="$errors->get('effective_from')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="notes" value="Notes (optional)" />
                                <x-text-input id="notes" name="notes" type="text" class="mt-1 block w-full"
                                             :value="old('notes')" placeholder="e.g. annual raise" maxlength="500" />
                                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                            </div>
                        </div>

                        <div class="flex space-x-3">
                            <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                                Add Salary Record
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
