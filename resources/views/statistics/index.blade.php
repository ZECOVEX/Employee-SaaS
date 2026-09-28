<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Statistics — {{ \Carbon\Carbon::createFromFormat('Y-m-01', $month.'-01')->format('F Y') }}
            </h2>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('statistics.index', ['month' => $previousMonth]) }}" class="text-indigo-600 hover:underline" wire:navigate>
                    ← {{ \Carbon\Carbon::createFromFormat('Y-m-01', $previousMonth.'-01')->format('M Y') }}
                </a>
                @if ($month !== $currentMonth)
                    <a href="{{ route('statistics.index', ['month' => $nextMonth]) }}" class="text-indigo-600 hover:underline" wire:navigate>
                        {{ \Carbon\Carbon::createFromFormat('Y-m-01', $nextMonth.'-01')->format('M Y') }} →
                    </a>
                @else
                    <span class="text-gray-300 select-none">Next →</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @if (! $stats)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-center">
                    <p class="text-sm text-gray-500">
                        No salary record covers this period yet, so there is nothing to estimate.
                        Ask your administrator to add one under Salary.
                    </p>
                </div>
            @else
                @php
                    $fmtMin = fn (int $minutes) => intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
                    $statusStyles = [
                        'PRESENT' => 'bg-green-100 text-green-800',
                        'LATE' => 'bg-yellow-100 text-yellow-800',
                        'HALF_DAY' => 'bg-orange-100 text-orange-800',
                        'ABSENT' => 'bg-red-100 text-red-800',
                        'LEAVE' => 'bg-blue-100 text-blue-800',
                        'UNPAID LEAVE' => 'bg-red-100 text-red-700',
                        'HOLIDAY' => 'bg-purple-100 text-purple-800',
                        'WEEKEND' => 'bg-gray-100 text-gray-600',
                        'FUTURE' => 'bg-gray-50 text-gray-400',
                    ];
                @endphp

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="font-semibold text-gray-800">Estimated net salary</h3>
                            <p class="text-xs text-gray-500 mt-1">
                                Progressive estimate from attendance, leave and your salary record (§28).
                                The final figure comes from the payslip.
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-gray-900">
                                {{ $stats['currency'] }} {{ number_format($stats['estimated_net'], 2) }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                of {{ $stats['currency'] }} {{ number_format($stats['estimated_earnings'], 2) }} gross
                            </div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Scheduled days</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $stats['completed_days'] }} done · {{ $stats['remaining_days'] }} left ({{ $stats['scheduled_days'] }} total)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Daily rate</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($stats['daily_rate'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Hourly rate</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($stats['hourly_rate'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Expected hours / day</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($stats['expected_hours_per_day'], 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Worked hours</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $fmtMin($stats['worked_minutes']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Late</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $fmtMin($stats['late_minutes']) }} · {{ $stats['late_days'] }} day(s)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Overtime (informational)</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">{{ $fmtMin($stats['overtime_minutes']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Days</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">
                                {{ $stats['present_days'] }} present · {{ $stats['absent_days'] }} absent
                                @if ($stats['half_days'] > 0) · {{ $stats['half_days'] }} half @endif
                                @if ($stats['leave_days'] > 0) · {{ $stats['leave_days'] }} leave @endif
                                @if ($stats['unpaid_leave_days'] > 0) · {{ $stats['unpaid_leave_days'] }} unpaid @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <h3 class="font-semibold text-gray-800 mb-4">Deductions</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Late deductions</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['late_deduction'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Absence / half-day deductions</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['absence_deduction'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Unpaid leave</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['unpaid_leave_deduction'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-3">
                                <dt class="text-gray-700 font-medium">Attendance deductions</dt>
                                <dd class="text-gray-900 font-semibold">
                                    {{ $stats['currency'] }} {{ number_format($stats['attendance_deduction_total'], 2) }}
                                    @if ($stats['capped'])
                                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">capped</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Other deductions (record)</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['record_deductions'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-3">
                                <dt class="text-gray-700 font-medium">Bonus</dt>
                                <dd class="text-gray-900 font-medium">+ {{ $stats['currency'] }} {{ number_format($stats['bonus'], 2) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="bg-white shadow-sm sm:rounded-lg p-6">
                        <h3 class="font-semibold text-gray-800 mb-4">Salary basis</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Basic</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['basic_salary'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Allowances</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['currency'] }} {{ number_format($stats['allowances'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-3">
                                <dt class="text-gray-700 font-medium">Monthly base</dt>
                                <dd class="text-gray-900 font-semibold">{{ $stats['currency'] }} {{ number_format($stats['monthly_base'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Effective from</dt>
                                <dd class="text-gray-900 font-medium">{{ $stats['record']->effective_from->format('d M Y') }}</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs text-gray-500">
                            {{ $stats['completed_days'] }} of {{ $stats['scheduled_days'] }} scheduled days elapsed —
                            earned salary grows with attendance while the month runs.
                        </p>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="font-semibold text-gray-800">Daily breakdown</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Each day's salary impact: deductions sum to {{ $stats['currency'] }} {{ number_format($stats['attendance_deduction_total'], 2) }}.
                            Overtime is informational — overtime pay policy comes in a later phase.
                        </p>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-6 py-3">Date</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3 text-right">Worked</th>
                                <th class="px-6 py-3 text-right">Late</th>
                                <th class="px-6 py-3 text-right">Overtime</th>
                                <th class="px-6 py-3 text-right">Deduction</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($stats['breakdown'] as $day)
                                <tr class="{{ $day['status'] === 'FUTURE' ? 'text-gray-400' : 'hover:bg-gray-50' }}">
                                    <td class="px-6 py-3 text-gray-900">
                                        {{ \Carbon\Carbon::createFromFormat('Y-m-d', $day['date'])->format('d M') }}
                                        <span class="text-gray-400 text-xs">{{ \Carbon\Carbon::createFromFormat('Y-m-d', $day['date'])->format('D') }}</span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusStyles[$day['status']] ?? 'bg-gray-100 text-gray-600' }}">
                                            {{ $day['status'] }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-gray-700 text-right">{{ $fmtMin($day['worked_minutes']) }}</td>
                                    <td class="px-6 py-3 text-gray-700 text-right">{{ $day['late_minutes'] > 0 ? $fmtMin($day['late_minutes']) : '—' }}</td>
                                    <td class="px-6 py-3 text-gray-700 text-right">{{ $day['overtime_minutes'] > 0 ? $fmtMin($day['overtime_minutes']) : '—' }}</td>
                                    <td class="px-6 py-3 text-right {{ $day['deduction'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                                        {{ $day['deduction'] > 0 ? number_format($day['deduction'], 2) : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">No days in this period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
