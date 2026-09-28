<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between no-print">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Payslip — {{ $payslip->periodLabel() }} · v{{ $payslip->revision }}
            </h2>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('salary.show', $employee) }}" class="text-indigo-600 hover:underline" wire:navigate>← Back to salary</a>
                <button type="button" onclick="window.print()"
                        class="px-3 py-1.5 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    Print / Save PDF
                </button>
            </div>
        </div>
    </x-slot>

    <style>
        @media print {
            nav, .no-print, button { display: none !important; }
            .print-card { box-shadow: none !important; border: 1px solid #e5e7eb; }
            main { padding: 0 !important; }
        }
    </style>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6 print:p-0">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 no-print">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 no-print">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-8 print-card">
                <div class="flex items-start justify-between border-b border-gray-200 pb-6">
                    <div>
                        <div class="text-lg font-bold text-gray-900">{{ $employee->organization?->name ?? config('app.name') }}</div>
                        <div class="text-sm text-gray-500 mt-1">Payslip · {{ $payslip->period_start->format('d M Y') }} – {{ $payslip->period_end->format('d M Y') }}</div>
                    </div>
                    <div class="text-right">
                        @if ($payslip->period_end->toDateString() >= now($employee->organization?->timezone ?? 'UTC')->toDateString())
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Estimated (mid-month)</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Final</span>
                        @endif
                        <div class="mt-2 text-xs text-gray-500">Revision v{{ $payslip->revision }} of {{ $revisions->count() }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 py-6 text-sm border-b border-gray-200">
                    <div>
                        <div class="text-gray-500">Employee</div>
                        <div class="mt-1 font-semibold text-gray-900">{{ $employee->user?->name }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Employee code</div>
                        <div class="mt-1 font-semibold text-gray-900">{{ $employee->employee_code }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Department</div>
                        <div class="mt-1 font-semibold text-gray-900">{{ $employee->department?->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Currency</div>
                        <div class="mt-1 font-semibold text-gray-900">{{ $payslip->currency }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 py-6 text-sm">
                    <div>
                        <h4 class="font-semibold text-gray-800 mb-3">Earnings</h4>
                        <dl class="space-y-2">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Basic salary</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->basic_salary, 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Allowances</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->allowances, 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Bonus</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->bonus, 2) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold">
                                <dt class="text-gray-700">Gross</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->gross_salary, 2) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div>
                        <h4 class="font-semibold text-gray-800 mb-3">Deductions</h4>
                        <dl class="space-y-2">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Late deductions</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->late_deduction, 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Absence / half-day</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->absence_deduction, 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Unpaid leave</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->unpaid_leave_deduction, 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Other deductions (record)</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->other_deductions, 2) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold">
                                <dt class="text-gray-700">Total deductions</dt>
                                <dd class="text-gray-900">{{ number_format($payslip->attendance_deduction_total + $payslip->other_deductions, 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="border-t border-gray-200 py-6 flex items-center justify-between">
                    <div>
                        <div class="text-sm text-gray-500">Net pay</div>
                        @if ($payslip->capped)
                            <div class="text-xs text-amber-700 mt-1">Attendance deductions were capped by the organization's monthly limit.</div>
                        @endif
                    </div>
                    <div class="text-3xl font-bold text-gray-900">
                        {{ $payslip->currency }} {{ number_format($payslip->net_salary, 2) }}
                    </div>
                </div>

                <div class="border-t border-gray-200 pt-6 text-sm">
                    <h4 class="font-semibold text-gray-800 mb-3">Month summary</h4>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <dt class="text-gray-500">Scheduled days</dt>
                            <dd class="mt-1 text-gray-900 font-medium">{{ $payslip->completed_days }} / {{ $payslip->scheduled_days }} elapsed</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Worked</dt>
                            <dd class="mt-1 text-gray-900 font-medium">{{ intdiv($payslip->worked_minutes, 60) }}h {{ str_pad((string) ($payslip->worked_minutes % 60), 2, '0', STR_PAD_LEFT) }}m</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Late</dt>
                            <dd class="mt-1 text-gray-900 font-medium">{{ $payslip->late_minutes }} min</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Daily / hourly rate</dt>
                            <dd class="mt-1 text-gray-900 font-medium">{{ number_format($payslip->daily_rate, 2) }} / {{ number_format($payslip->hourly_rate, 2) }}</dd>
                        </div>
                    </dl>
                    @if ($payslip->overtime_minutes > 0)
                        <p class="mt-3 text-xs text-gray-500">
                            Overtime: {{ $payslip->overtime_minutes }} minutes (informational — overtime pay policy arrives in a later phase).
                        </p>
                    @endif
                </div>

                @if ($payslip->notes)
                    <div class="border-t border-gray-200 pt-4 text-sm text-gray-600">
                        <span class="text-gray-500">Notes:</span> {{ $payslip->notes }}
                    </div>
                @endif

                <div class="border-t border-gray-200 pt-4 mt-4 text-xs text-gray-500 no-print">
                    Finalized by {{ $payslip->creator?->name ?? '—' }} on {{ $payslip->created_at?->format('d M Y, H:i') }}.
                    This record is a frozen snapshot — it never changes; corrections are new revisions.
                </div>
            </div>

            @if ($revisions->count() > 1 || $canRevise)
                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden no-print">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-800">Revision history</h3>
                            <p class="text-xs text-gray-500 mt-1">Every revision is kept forever — nothing is overwritten (§28).</p>
                        </div>
                        @if ($canRevise)
                            <form action="{{ route('payslips.revise', [$employee, $payslip]) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <button type="submit"
                                        class="px-3 py-1.5 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                                    Revise (create new revision)
                                </button>
                            </form>
                        @endif
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-6 py-3">Revision</th>
                                <th class="px-6 py-3 text-right">Net</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Finalized by</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($revisions as $revision)
                                <tr class="{{ $revision->id === $payslip->id ? 'bg-indigo-50' : 'hover:bg-gray-50' }}">
                                    <td class="px-6 py-3 text-gray-900">v{{ $revision->revision }}</td>
                                    <td class="px-6 py-3 text-gray-900 text-right">{{ $revision->currency }} {{ number_format($revision->net_salary, 2) }}</td>
                                    <td class="px-6 py-3">
                                        @if ($revision->isSuperseded())
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Superseded</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Active</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-gray-500">{{ $revision->creator?->name ?? '—' }}</td>
                                    <td class="px-6 py-3 text-right">
                                        @if ($revision->id !== $payslip->id)
                                            <a href="{{ route('payslips.show', [$employee, $revision]) }}" class="text-indigo-600 hover:underline" wire:navigate>Open</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
