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

            @php
                $fmtMin = fn (int $minutes) => intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
                $fmtClock = fn (int $minutes) => str_pad((string) intdiv($minutes, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
                $monthLabel = \Carbon\Carbon::createFromFormat('Y-m-01', $month.'-01')->format('F Y');
            @endphp

            {{-- §39 MONTHLY OVERVIEW --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Monthly overview</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Attendance, working hours and leave for {{ $monthLabel }} (§39) —
                            the worked figure is overtime-free and capped at the expected time.
                        </p>
                    </div>
                    @if ($score)
                        <div class="text-right">
                            <div class="text-3xl font-bold text-gray-900">{{ number_format($score['score'], 1) }}</div>
                            <div class="text-xs font-semibold text-indigo-700 mt-0.5">Attendance Score</div>
                            @if ($riskStatus)
                                <div class="text-[11px] font-semibold text-gray-500">{{ $riskStatus['label'] }}</div>
                            @endif
                        </div>
                    @endif
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Present</dt>
                        <dd class="mt-1 text-2xl font-semibold text-green-700">
                            {{ $monthOverview['present'] }}
                            @if ($monthOverview['half'] > 0)
                                <span class="text-xs font-normal text-gray-400">+ {{ $monthOverview['half'] }} half</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Late</dt>
                        <dd class="mt-1 text-2xl font-semibold text-amber-600">{{ $monthOverview['late'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Absent</dt>
                        <dd class="mt-1 text-2xl font-semibold text-red-600">{{ $monthOverview['absent'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Leave</dt>
                        <dd class="mt-1 text-2xl font-semibold text-blue-600">{{ $monthOverview['leave'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Expected / worked hours</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            {{ $fmtMin($monthOverview['worked_capped_minutes'] ?? 0) }}
                            <span class="text-xs font-normal text-gray-400">of {{ $fmtMin($monthOverview['expected_minutes']) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Avg arrival / departure</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            {{ $avgArrival !== null ? $fmtClock($avgArrival) : '—' }}
                            <span class="text-gray-400 font-normal">/</span>
                            {{ $avgDeparture !== null ? $fmtClock($avgDeparture) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Remaining leave</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900">
                            {{ $remainingLeave }}<span class="text-sm font-normal text-gray-400"> / {{ $totalLeave }}d</span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- §39 OVERTIME / EXTRA TIME [OT] --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6" id="overtime">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Overtime / extra time</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $monthLabel }} — approved / pending / rejected, the weekday split and the
                            configured limit usage (§73-I). Overtime never counts as regular worked hours.
                        </p>
                    </div>
                </div>

                @if (! $monthOt['eligible'])
                    <p class="text-sm text-gray-500">
                        You are not overtime-eligible under the current company policy, so no overtime
                        is tracked for you.
                    </p>
                @elseif (! $monthOt['show_hours'])
                    <p class="text-sm text-gray-500">
                        Company policy hides overtime hours from employees. Approvers still see your records.
                    </p>
                @else
                    <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Approved (incl. auto)</dt>
                            <dd class="mt-1 text-xl font-semibold text-emerald-700">{{ $fmtMin($monthOt['approved_minutes']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Pending</dt>
                            <dd class="mt-1 text-xl font-semibold text-amber-600">{{ $fmtMin($monthOt['pending_minutes']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Rejected</dt>
                            <dd class="mt-1 text-xl font-semibold {{ $monthOt['rejected_minutes'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $fmtMin($monthOt['rejected_minutes']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Overtime days</dt>
                            <dd class="mt-1 text-xl font-semibold text-gray-900">{{ $monthOt['days'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Weekday vs weekend/holiday</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">
                                {{ $fmtMin($monthOt['weekday_minutes']) }}
                                <span class="text-xs font-normal text-gray-400">/ {{ $fmtMin($monthOt['weekend_holiday_minutes']) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Applied multipliers</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">
                                @if ($monthOt['multipliers'] === [])
                                    —
                                @else
                                    {{ collect($monthOt['multipliers'])->map(fn ($m) => rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.').'×')->implode(', ') }}
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($monthOt['show_pay'])
                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div class="rounded-md bg-emerald-50 p-3">
                                <div class="text-xs text-emerald-700">Overtime earnings (approved)</div>
                                <div class="mt-0.5 text-lg font-semibold text-emerald-800">
                                    {{ $currency }} {{ number_format($monthOt['pay_approved'], 2) }}
                                </div>
                            </div>
                            @if ($monthOt['pay_pending'] > 0)
                                <div class="rounded-md bg-amber-50 p-3">
                                    <div class="text-xs text-amber-700">Potential (pending, excluded until approved)</div>
                                    <div class="mt-0.5 text-lg font-semibold text-amber-800">
                                        {{ $currency }} {{ number_format($monthOt['pay_pending'], 2) }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($riskConfig && ($riskConfig['ot_risk_weekly_minutes'] > 0 || $riskConfig['ot_risk_monthly_minutes'] !== null))
                        @php
                            $weekUsage = $weekOt['approved_minutes'] + $weekOt['pending_minutes'];
                            $monthUsage = $monthOt['approved_minutes'] + $monthOt['pending_minutes'];
                        @endphp
                        <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                            <div class="font-medium text-gray-700">Overtime limit usage</div>
                            <div class="mt-1 space-y-0.5 text-gray-600">
                                @if ($riskConfig['ot_risk_weekly_minutes'] > 0)
                                    <div>
                                        This week ({{ $weekStart->format('j M') }} – {{ $weekEnd->format('j M') }}):
                                        <span class="font-semibold text-gray-900">{{ $fmtMin($weekUsage) }}</span>
                                        of {{ $fmtMin($riskConfig['ot_risk_weekly_minutes']) }}
                                        @if ($weekUsage > $riskConfig['ot_risk_weekly_minutes'])
                                            <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">over limit</span>
                                        @endif
                                    </div>
                                @endif
                                @if ($riskConfig['ot_risk_monthly_minutes'] !== null)
                                    <div>
                                        This month:
                                        <span class="font-semibold text-gray-900">{{ $fmtMin($monthUsage) }}</span>
                                        of {{ $fmtMin($riskConfig['ot_risk_monthly_minutes']) }}
                                        @if ($monthUsage > $riskConfig['ot_risk_monthly_minutes'])
                                            <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">over limit</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3">In → Out</th>
                                    <th class="px-4 py-3 text-right">Overtime</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Approver</th>
                                    <th class="px-4 py-3">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse ($otRows as $otRow)
                                    @php
                                        $otDate = $otRow->work_date->toDateString();
                                        $otDay = $otDaily[$otDate] ?? null;
                                        $approval = $otRow->approvals->whereNotNull('approver_user_id')->last();
                                        $otStatusStyle = match ($otRow->status) {
                                            \App\Models\OvertimeRecord::STATUS_PENDING => 'bg-amber-100 text-amber-800',
                                            \App\Models\OvertimeRecord::STATUS_REJECTED => 'bg-red-100 text-red-800',
                                            default => 'bg-emerald-100 text-emerald-800',
                                        };
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-900 whitespace-nowrap">
                                            {{ $otRow->work_date->format('d M') }}
                                            <span class="text-gray-400 text-xs">{{ $otRow->work_date->format('D') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                            @if ($otDay?->first_check_in)
                                                <x-time :value="$otDay->first_check_in" />
                                                <span class="text-gray-400">→</span>
                                                @if ($otDay->last_check_out)
                                                    <x-time :value="$otDay->last_check_out" />
                                                @else
                                                    —
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900">{{ $fmtMin($otRow->countable_minutes) }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $otStatusStyle }}">
                                                {{ str_replace('_', ' ', $otRow->status) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $approval?->approver?->name ?? ($otRow->status === \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED ? 'Automatic' : '—') }}</td>
                                        <td class="px-4 py-3 text-gray-500">{{ $approval?->reason ?? $otRow->employee_note ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                            No overtime recorded for this month.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- §39 WEEKLY ATTENDANCE --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Weekly attendance</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Week of {{ $weekStart->format('D j M Y') }} – {{ $weekEnd->format('D j M Y') }} (§39).
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <a href="{{ route('statistics.index', ['month' => $month, 'week' => $prevWeek]) }}"
                           class="text-indigo-600 hover:underline" wire:navigate>← Prev week</a>
                        <a href="{{ route('statistics.index', ['month' => $month, 'week' => $nextWeek]) }}"
                           class="text-indigo-600 hover:underline" wire:navigate>Next week →</a>
                    </div>
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Present</dt>
                        <dd class="mt-1 text-xl font-semibold text-green-700">
                            {{ $weekTotals['present'] }}
                            @if ($weekTotals['half'] > 0)
                                <span class="text-xs font-normal text-gray-400">+ {{ $weekTotals['half'] }} half</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Late</dt>
                        <dd class="mt-1 text-xl font-semibold text-amber-600">{{ $weekTotals['late'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Absent / leave</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-900">
                            {{ $weekTotals['absent'] }} <span class="text-xs font-normal text-gray-400">/</span> {{ $weekTotals['leave'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Worked / expected</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            {{ $fmtMin($weekTotals['worked_capped_minutes']) }}
                            <span class="text-xs font-normal text-gray-400">/ {{ $fmtMin($weekTotals['expected_minutes']) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Overtime (approved / pending)</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            @if ($weekOt['eligible'] && $weekOt['show_hours'])
                                {{ $fmtMin($weekOt['approved_minutes']) }}
                                <span class="text-xs font-normal text-gray-400">/ {{ $fmtMin($weekOt['pending_minutes']) }}</span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Late minutes / deduction</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            {{ $fmtMin($weekTotals['late_minutes']) }}
                            <span class="text-xs font-normal text-gray-400">
                                @if ($stats)
                                    · {{ $currency }} {{ number_format($weekDeduction, 2) }}
                                @else
                                    · deduction —
                                @endif
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Risk &amp; Notifications</h3>
                        <p class="text-xs text-gray-500 mt-1">
                            Current risk status from this month's score, this week's late arrivals and any
                            triggered alerts (§15) — always about now, regardless of the month you're viewing.
                        </p>
                    </div>
                    @if ($riskStatus)
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold bg-indigo-100 text-indigo-800">
                            {{ $riskStatus['label'] }}
                        </span>
                    @endif
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Current Risk Status</dt>
                        <dd class="mt-1 text-gray-900 font-semibold">
                            @if ($riskStatus)
                                {{ $riskStatus['label'] }}
                                <span class="text-xs font-normal text-gray-400">— score {{ number_format($score['score'], 2) }}</span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Late Days This Week</dt>
                        <dd class="mt-1 text-gray-900 font-semibold">
                            {{ $lateThisWeek }}
                            @if ($riskConfig)
                                <span class="text-xs font-normal text-gray-400">of {{ $riskConfig['late_risk_threshold'] }} before the alert triggers</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Configured Late Threshold</dt>
                        <dd class="mt-1 text-gray-900 font-semibold">
                            @if ($riskConfig)
                                {{ $riskConfig['late_risk_threshold'] }} day(s) ·
                                {{ $riskConfig['late_risk_period'] === 'rolling_7' ? 'rolling 7 days' : 'calendar week' }}
                                @unless ($riskConfig['late_risk_enabled'])
                                    <span class="text-xs font-normal text-gray-400">(rule disabled)</span>
                                @endunless
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($riskEvents->isNotEmpty())
                    <div class="mt-4 space-y-2">
                        @foreach ($riskEvents as $event)
                            @if ($event->type === \App\Models\RiskEvent::TYPE_LATE_WEEK)
                                <div class="rounded-md bg-red-50 border border-red-200 p-3 flex items-start justify-between gap-3">
                                    <div class="text-sm text-red-800">
                                        <span class="font-bold">{{ $event->level }}</span>
                                        <span class="font-medium">— High-Risk Alert</span>
                                        <div class="mt-0.5">
                                            {{ $event->detail }}. Your attendance pattern has reached the
                                            configured late-arrival risk threshold ({{ $event->threshold }} day(s)).
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 whitespace-nowrap">
                                        {{ $event->typeLabel() }}
                                    </span>
                                </div>
                            @else
                                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 flex items-start justify-between gap-3">
                                    <div class="text-sm text-amber-800">
                                        <span class="font-bold">{{ $event->level }}</span>
                                        <span class="font-medium">— Overtime Limit Notice</span>
                                        <div class="mt-0.5">
                                            {{ $event->detail }}. Informational only — overtime never lowers your score.
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 whitespace-nowrap">
                                        {{ $event->typeLabel() }}
                                    </span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($score)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="font-semibold text-gray-800">Attendance Score Breakdown</h3>
                            <p class="text-xs text-gray-500 mt-1">
                                Every component, its weight and its contribution — the formula is never hidden (§16).
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-gray-900">{{ number_format($score['score'], 2) }}</div>
                            <div class="text-xs text-gray-500 mt-1">Final score</div>
                        </div>
                    </div>

                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ($score['components'] as $component)
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-gray-700">
                                    {{ $component['label'] }}
                                    <span class="text-xs text-gray-400">
                                        {{ number_format($component['score'], 2) }} × {{ rtrim(rtrim(number_format($component['weight'], 2, '.', ''), '0'), '.') }}%
                                    </span>
                                </dt>
                                <dd class="text-gray-900 font-medium">{{ number_format($component['weighted'], 2) }}</dd>
                            </div>
                        @endforeach

                        @if ($score['overtime_bonus'] > 0)
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-gray-700">
                                    Overtime bonus
                                    <span class="text-xs text-gray-400">
                                        {{ \App\Models\OvertimeRecord::minutesLabelFor($score['approved_overtime_minutes']) }} approved ·
                                        max +{{ rtrim(rtrim(number_format($score['overtime_bonus_max'], 2, '.', ''), '0'), '.') }}
                                    </span>
                                </dt>
                                <dd class="text-emerald-700 font-medium">+{{ number_format($score['overtime_bonus'], 2) }}</dd>
                            </div>
                        @endif

                        <div class="flex items-center justify-between py-2 border-t border-gray-200">
                            <dt class="text-gray-900 font-semibold">Final Score</dt>
                            <dd class="text-gray-900 font-semibold">{{ number_format($score['base_score'], 2) }}@if ($score['overtime_bonus'] > 0) + {{ number_format($score['overtime_bonus'], 2) }} = {{ number_format($score['score'], 2) }}@endif</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-gray-500">
                        Formula: {{ $score['formula'] }}{{ $score['overtime_bonus'] > 0 ? ' + Overtime bonus (capped)' : '' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{ $score['inputs']['present'] }} present · {{ $score['inputs']['half'] }} half ·
                        {{ $score['inputs']['late'] }} late · {{ $score['inputs']['absent'] }} absent ·
                        {{ $score['inputs']['leave'] }} on leave ({{ $score['inputs']['attendable'] }} of
                        {{ $score['inputs']['elapsed_scheduled'] }} scheduled days attendable) ·
                        worked {{ \App\Models\OvertimeRecord::minutesLabelFor($score['inputs']['worked_capped_minutes']) }}
                        of {{ \App\Models\OvertimeRecord::minutesLabelFor($score['inputs']['expected_minutes']) }} expected
                        (overtime excluded, capped at 100).
                    </p>
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
                            <dt class="text-gray-500">Overtime (approved)</dt>
                            <dd class="mt-1 text-gray-900 font-semibold">
                                {{ $fmtMin($stats['overtime_minutes']) }}
                                @if (($stats['overtime_pay'] ?? 0) > 0)
                                    · {{ $stats['currency'] }} {{ number_format($stats['overtime_pay'], 2) }}
                                @endif
                            </dd>
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
                                <dt class="text-gray-500">Bonus</dt>
                                <dd class="text-gray-900 font-medium">+ {{ $stats['currency'] }} {{ number_format($stats['bonus'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Overtime pay (approved)</dt>
                                <dd class="text-gray-900 font-medium">+ {{ $stats['currency'] }} {{ number_format($stats['overtime_pay'] ?? 0, 2) }}</dd>
                            </div>
                            @if (($stats['potential_overtime_pay'] ?? 0) > 0)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Pending overtime (potential)</dt>
                                    <dd class="text-amber-700 font-medium">
                                        + {{ $stats['currency'] }} {{ number_format($stats['potential_overtime_pay'], 2) }}
                                        <span class="text-xs text-gray-400">— excluded until approved</span>
                                    </dd>
                                </div>
                            @endif
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
                            Approved overtime pay is included in the estimate; pending overtime shows as potential and is excluded.
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
                                    <td class="px-6 py-3 text-gray-700 text-right">
                                        @if ($day['late_minutes'] > 0)
                                            {{ $fmtMin($day['late_minutes']) }}
                                            @if (($day['late_offset_minutes'] ?? 0) > 0)
                                                <span class="text-gray-400 text-xs">({{ $fmtMin($day['late_offset_minutes']) }} offset)</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
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
