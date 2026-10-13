<?php
    // GitHub-style contribution calendar (§39): one cell per day, coloured by status.
    $colorFor = function (?string $status): string {
        return match ($status) {
            'PRESENT', 'REMOTE' => 'bg-green-100 text-green-800',
            'LATE' => 'bg-amber-100 text-amber-800',
            'HALF_DAY' => 'bg-orange-100 text-orange-800',
            'ABSENT' => 'bg-red-100 text-red-800',
            'LEAVE' => 'bg-blue-100 text-blue-800',
            'HOLIDAY' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-500',
        };
    };

    $monthTitle = $month->format('F Y');
    $prevMonth = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $nextMonth = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $prevMonthDisabled = $month->copy()->startOfMonth()->lte(now()->startOfMonth()->subYear());
    $nextMonthDisabled = $month->copy()->startOfMonth()->gte(now()->startOfMonth());

    $firstCell = $month->copy()->startOfMonth()->startOfWeek();
    $lastCell = $month->copy()->endOfMonth()->endOfWeek();
    $workDayNumbers = $schedule?->workDayNumbers() ?? [1, 2, 3, 4, 5];

    $cells = [];
    $cursor = $firstCell->copy();
    while ($cursor->lte($lastCell)) {
        $cells[] = $cursor->copy();
        $cursor->addDay();
    }
    $weeks = array_chunk($cells, 7);

    $fmtMinutes = fn (int $minutes) => intdiv($minutes, 60).'h '.($minutes % 60).'m';
    $org = $user->organization;
    $plainClock = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $fmtClock = fn (int $minutes) => $org ? $org->formatTime($plainClock($minutes)) : $plainClock($minutes);

    $dayTitle = function ($record, $day) use ($workDayNumbers, $fmtMinutes, $org, $otByDay, $dayRows, $showOt, $showOtPay, $currency) {
        $label = $day->format('D, d M Y');

        $ot = $showOt ? ($otByDay->get($day->toDateString()) ?? null) : null;
        $otPart = '';

        if ($ot && (int) $ot->countable_minutes > 0) {
            $statusText = match ($ot->status) {
                \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED => 'auto-approved',
                \App\Models\OvertimeRecord::STATUS_PENDING => 'pending',
                \App\Models\OvertimeRecord::STATUS_REJECTED => 'rejected',
                \App\Models\OvertimeRecord::STATUS_FLAGGED => 'flagged',
                default => 'approved',
            };
            $otPart = ' · +'.$fmtMinutes((int) $ot->countable_minutes).' overtime ('.$statusText.')';

            if ($ot->status === \App\Models\OvertimeRecord::STATUS_REJECTED) {
                $reason = $ot->approvals
                    ->where('decision', \App\Models\OvertimeApproval::REJECTED)
                    ->last()?->reason;

                if ($reason) {
                    $otPart .= ': '.$reason;
                }
            }

            if ($showOtPay
                && $ot->estimated_pay > 0
                && in_array($ot->status, [
                    \App\Models\OvertimeRecord::STATUS_APPROVED,
                    \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED,
                ], true)) {
                $otPart .= ' · OT pay '.$currency.' '.number_format((float) $ot->estimated_pay, 2);
            }
        }

        $deductionPart = '';
        $salaryRow = $dayRows[$day->toDateString()] ?? null;

        if ($salaryRow && $salaryRow['deduction'] > 0) {
            $deductionPart = ' · deduction '.$currency.' '.number_format((float) $salaryRow['deduction'], 2);
        }

        if ($record) {
            $parts = [$record->status];
            if ($record->first_check_in || $record->last_check_out) {
                $in = $org?->formatTime($record->first_check_in) ?? '—';
                $out = $org?->formatTime($record->last_check_out) ?? '—';
                $parts[] = $in.'–'.$out;
            }
            $parts[] = $fmtMinutes((int) $record->total_work_minutes);
            if ($record->late_minutes > 0) {
                $parts[] = $record->late_minutes.'m late';
            }
            if ($record->review_flag) {
                $parts[] = 'needs review';
            }

            return $label.' — '.implode(' · ', $parts).$otPart.$deductionPart;
        }

        if (! in_array((int) $day->dayOfWeekIso, $workDayNumbers, true)) {
            return $label.' — day off'.$otPart;
        }

        if ($day->isFuture()) {
            return $label.$otPart;
        }

        return $label.' — no record'.$otPart.$deductionPart;
    };
?>

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                My Dashboard
            </h2>
            @if ($employee)
                <a href="{{ route('attendance.employee', $employee) }}"
                   class="text-sm text-indigo-600 hover:underline" wire:navigate>My attendance history →</a>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($employee?->status === 'terminated')
                <div class="rounded-md bg-red-50 border border-red-200 p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ms-3">
                            <h3 class="text-sm font-bold text-red-800">You have been terminated</h3>
                            <div class="mt-1 text-sm text-red-700">
                                Your employment with {{ $user->organization?->name ?? 'this company' }} has been terminated.
                                If you have questions about your final settlement or employment record, please contact HR.
                            </div>
                        </div>
                    </div>
                </div>
            @elseif ($employee?->status === 'inactive')
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-amber-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ms-3">
                            <h3 class="text-sm font-bold text-amber-800">Your employment status is inactive</h3>
                            <div class="mt-1 text-sm text-amber-700">
                                Your employment record is currently marked as inactive.
                                If you have questions, please contact HR.
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @foreach ($riskEvents ?? [] as $riskEvent)
                @if ($riskEvent->type === \App\Models\RiskEvent::TYPE_LATE_WEEK)
                    <div class="rounded-md bg-red-50 border border-red-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-red-800">{{ $riskEvent->level }}</h3>
                                <div class="mt-1 text-sm text-red-700">
                                    {{ $riskEvent->detail }}<br />
                                    Your attendance pattern has reached the configured late-arrival
                                    risk threshold ({{ $riskEvent->threshold }} day(s) per
                                    {{ $riskEvent->period_end->diffInDays($riskEvent->period_start) > 6 ? 'rolling 7 days' : 'calendar week' }}).
                                </div>
                            </div>
                            <a href="{{ route('statistics.index') }}"
                               class="text-sm font-medium text-red-800 whitespace-nowrap hover:underline">View statistics →</a>
                        </div>
                    </div>
                @else
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-amber-800">{{ $riskEvent->level }}</h3>
                                <div class="mt-1 text-sm text-amber-700">
                                    {{ $riskEvent->detail }} — exceeds the configured limit.<br />
                                    This is an informational compliance signal; overtime never lowers your score.
                                </div>
                            </div>
                            <a href="{{ route('overtime.index') }}"
                               class="text-sm font-medium text-amber-800 whitespace-nowrap hover:underline">View overtime →</a>
                        </div>
                    </div>
                @endif
            @endforeach

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 flex items-center gap-4 {{ $employee ? 'sm:col-span-3' : 'sm:col-span-4' }}">
                    @if ($employee?->photo_path)
                        <img src="{{ Storage::disk('public')->url($employee->photo_path) }}" alt=""
                             class="w-12 h-12 rounded-full object-cover" />
                    @endif
                    <div>
                        <p class="text-lg text-gray-800">Good day, {{ $user->name }}</p>
                        <p class="text-sm text-gray-500 mt-1">{{ $monthTitle }}{{ $schedule && $org ? ' · Office '.$org->formatTime($schedule->start_time).'–'.$org->formatTime($schedule->end_time) : '' }}</p>
                    </div>
                </div>
                @if ($employee)
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 flex flex-col items-center justify-center text-center">
                        <div class="text-4xl font-bold text-indigo-600 leading-none">{{ $scoreData !== null ? (int) round($scoreData['score']) : '—' }}</div>
                        <div class="text-sm text-gray-500 mt-2">Attendance Score</div>
                        @if ($scoreBand)
                            <span class="mt-2 inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-indigo-800">
                                {{ $scoreBand['label'] }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($employee)
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <x-hover-panel :drill="$drilldowns['present'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-5">
                        <x-slot name="trigger">
                            <div class="text-sm text-gray-500">Present ({{ $month->format('M') }})</div>
                            <div class="text-2xl font-semibold text-green-700 mt-1">{{ $monthStats['present'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['late'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-5">
                        <x-slot name="trigger">
                            <div class="text-sm text-gray-500">Late</div>
                            <div class="text-2xl font-semibold text-amber-600 mt-1">{{ $monthStats['late'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['absent'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-5">
                        <x-slot name="trigger">
                            <div class="text-sm text-gray-500">Absent</div>
                            <div class="text-2xl font-semibold text-red-600 mt-1">{{ $monthStats['absent'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['leave'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-5">
                        <x-slot name="trigger">
                            <div class="text-sm text-gray-500">On leave</div>
                            <div class="text-2xl font-semibold text-blue-600 mt-1">{{ $monthStats['leave'] }}</div>
                        </x-slot>
                    </x-hover-panel>
                    <x-hover-panel :drill="$drilldowns['balance'] ?? null" class="bg-white shadow-sm sm:rounded-lg p-5">
                        <x-slot name="trigger">
                            <div class="text-sm text-gray-500">Leave balance</div>
                            <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $leaveBalance['remaining'] }}<span class="text-sm font-normal text-gray-400"> / {{ $leaveBalance['total'] }}d</span></div>
                        </x-slot>
                    </x-hover-panel>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Worked this month</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $fmtMinutes($monthStats['worked_display_minutes'] ?? $monthStats['worked_minutes']) }}</div>
                        @if (isset($monthStats['expected_minutes']))
                            <div class="text-xs text-gray-400 mt-1">of {{ $fmtMinutes($monthStats['expected_minutes']) }} expected</div>
                        @endif
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Avg arrival / departure</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-1">
                            {{ $avgArrival !== null ? $fmtClock($avgArrival) : '—' }}
                            <span class="text-gray-400 font-normal">/</span>
                            {{ $avgDeparture !== null ? $fmtClock($avgDeparture) : '—' }}
                        </div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Worked this week</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $fmtMinutes($weekMinutes) }}</div>
                    </div>
                </div>

                @if ($monthOt && $monthOt['eligible'])
                    {{-- §39 [OT] Overtime / Extra Time card — REQUIRED for eligible employees --}}
                    <div class="bg-white shadow-sm sm:rounded-lg p-6" data-test="overtime-card">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold text-gray-800">Overtime / Extra Time</h3>
                            <a href="{{ route('statistics.index') }}#overtime"
                               class="text-sm text-indigo-600 hover:underline" wire:navigate>Statistics →</a>
                        </div>
                        @if (! $monthOt['show_hours'])
                            <p class="text-sm text-gray-500">Overtime details are restricted by company policy.</p>
                        @else
                            @php
                            $otMonthTotal = $monthOt['approved_minutes'] + $monthOt['pending_minutes'] + $monthOt['rejected_minutes'];
                        @endphp
                            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div>
                                    <dt class="text-xs text-gray-500">This Month</dt>
                                    <dd class="text-lg font-semibold text-gray-900 mt-0.5">{{ $fmtMinutes($otMonthTotal) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500">Approved</dt>
                                    <dd class="text-lg font-semibold text-green-700 mt-0.5">{{ $fmtMinutes($monthOt['approved_minutes']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500">Pending</dt>
                                    <dd class="text-lg font-semibold text-amber-600 mt-0.5">{{ $fmtMinutes($monthOt['pending_minutes']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500">Rejected</dt>
                                    <dd class="text-lg font-semibold text-red-600 mt-0.5">{{ $fmtMinutes($monthOt['rejected_minutes']) }}</dd>
                                </div>
                            </dl>
                            <div class="mt-4 flex items-center gap-2 text-sm text-gray-700">
                                <span class="text-gray-500">Today:</span>
                                @if ($todayOt && $todayOt->countable_minutes > 0)
                                    <span class="font-medium">{{ $fmtMinutes((int) $todayOt->countable_minutes) }}</span>
                                    @php
                                        $todayChip = match ($todayOt->status) {
                                            \App\Models\OvertimeRecord::STATUS_PENDING => 'bg-amber-100 text-amber-800',
                                            \App\Models\OvertimeRecord::STATUS_REJECTED => 'bg-red-100 text-red-800',
                                            \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED => 'bg-indigo-100 text-indigo-800',
                                            default => 'bg-green-100 text-green-800',
                                        };
                                        $todayLabel = match ($todayOt->status) {
                                            \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED => 'AUTO-APPROVED',
                                            default => $todayOt->status,
                                        };
                                    @endphp
                                    <span class="px-2 py-0.5 rounded text-xs font-medium {{ $todayChip }}">{{ $todayLabel }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </div>
                            @if ($monthOt['show_pay'])
                                <div class="mt-3 text-sm">
                                    <span class="text-gray-500">Est. Overtime Pay:</span>
                                    <span class="font-semibold text-gray-900">{{ $currency }} {{ number_format($monthOt['pay_approved'], 2) }}</span>
                                    @if ($monthOt['pay_pending'] > 0)
                                        <span class="text-xs text-amber-600 ml-1">+ {{ $currency }} {{ number_format($monthOt['pay_pending'], 2) }} pending</span>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>
                @endif

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-gray-800">{{ $monthTitle }}</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <a href="{{ route('dashboard.employee', ['month' => $prevMonth]) }}"
                               class="{{ $prevMonthDisabled ? 'text-gray-300 pointer-events-none' : 'text-indigo-600 hover:underline' }}" wire:navigate>← Prev</a>
                            <a href="{{ route('dashboard.employee', ['month' => $nextMonth]) }}"
                               class="{{ $nextMonthDisabled ? 'text-gray-300 pointer-events-none' : 'text-indigo-600 hover:underline' }}" wire:navigate>Next →</a>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-400 mb-1">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
                            <div>{{ $dow }}</div>
                        @endforeach
                    </div>

                    <div class="space-y-1">
                        @foreach ($weeks as $week)
                            <div class="grid grid-cols-7 gap-1">
                                @foreach ($week as $day)
                                    @php
                                        $record = $day->format('Y-m') === $month->format('Y-m') ? ($records[$day->toDateString()] ?? null) : null;
                                        $inMonth = $day->format('Y-m') === $month->format('Y-m');
                                        $isWorkDay = in_array((int) $day->dayOfWeekIso, $workDayNumbers, true);

                                        if ($record) {
                                            $classes = $colorFor($record->status);
                                        } elseif (! $inMonth || ! $isWorkDay || $day->isFuture()) {
                                            $classes = 'bg-gray-50 text-gray-300';
                                        } else {
                                            $classes = 'bg-white border border-gray-200 text-gray-400';
                                        }

                                        if ($record?->review_flag) {
                                            $classes .= ' ring-2 ring-red-400';
                                        }

                                        $otDay = $inMonth ? ($otByDay->get($day->toDateString()) ?? null) : null;
                                    @endphp
                                    <div class="rounded {{ $classes }} p-1.5 text-xs text-center"
                                         title="{{ $dayTitle($record, $day) }}">
                                        <div class="font-semibold {{ $inMonth ? '' : 'opacity-40' }} flex items-center justify-center gap-1">
                                            {{ $day->day }}
                                            @if ($otDay && (int) $otDay->countable_minutes > 0)
                                                @if (in_array($otDay->status, [\App\Models\OvertimeRecord::STATUS_APPROVED, \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED], true))
                                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-600" title="Overtime approved"></span>
                                                @elseif ($otDay->status === \App\Models\OvertimeRecord::STATUS_PENDING)
                                                    <span class="inline-block w-1.5 h-1.5 rounded-full ring-1 ring-amber-500 bg-transparent" title="Overtime pending"></span>
                                                @elseif ($otDay->status === \App\Models\OvertimeRecord::STATUS_REJECTED)
                                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500" title="Overtime rejected"></span>
                                                @endif
                                            @endif
                                        </div>
                                        @if ($record?->first_check_in)
                                            <div class="hidden sm:block text-[10px] leading-tight opacity-80"><x-time :value="$record->first_check_in" /></div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-3 mt-4 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-green-100"></span> Present</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-amber-100"></span> Late</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-orange-100"></span> Half day</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-red-100"></span> Absent</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-blue-100"></span> Leave</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-purple-100"></span> Holiday</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-gray-50 border border-gray-200"></span> Off / no record</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-white ring-2 ring-red-400"></span> Needs review</span>
                        @if ($showOt && $monthOt && $monthOt['show_hours'])
                            <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-full bg-emerald-600"></span> Overtime (approved)</span>
                            <span class="flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-full ring-1 ring-amber-500"></span> Overtime (pending)</span>
                        @endif
                    </div>
                </div>

                @if ($weekTotals)
                    {{-- §39 weekly summary below the calendar --}}
                    <div class="bg-white shadow-sm sm:rounded-lg p-6" data-test="week-summary">
                        <div class="flex items-baseline justify-between mb-4">
                            <h3 class="font-semibold text-gray-800">This Week</h3>
                            <span class="text-xs text-gray-500">{{ $weekStart->format('D d M') }} – {{ $weekEnd->format('D d M Y') }}</span>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div>
                                <div class="text-xs text-gray-500">Present</div>
                                <div class="text-xl font-semibold text-green-700 mt-0.5">{{ $weekTotals['present'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Late</div>
                                <div class="text-xl font-semibold text-amber-600 mt-0.5">{{ $weekTotals['late'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Absent</div>
                                <div class="text-xl font-semibold text-red-600 mt-0.5">{{ $weekTotals['absent'] }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Leave</div>
                                <div class="text-xl font-semibold text-blue-600 mt-0.5">{{ $weekTotals['leave'] }}</div>
                            </div>
                        </div>
                        <dl class="mt-4 space-y-1.5 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-gray-500">Worked</dt>
                                <dd class="text-gray-900 font-medium">
                                    {{ $fmtMinutes($weekTotals['worked_capped_minutes']) }} / {{ $fmtMinutes($weekTotals['expected_minutes']) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-gray-500">Late</dt>
                                <dd class="text-gray-900 font-medium">{{ $weekTotals['late_minutes'] }}m</dd>
                            </div>
                            @if ($weekLateDeduction > 0)
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500">Late Salary Deduction</dt>
                                    <dd class="text-red-600 font-medium">{{ $currency }} {{ number_format($weekLateDeduction, 2) }}</dd>
                                </div>
                            @endif
                            @if ($monthOt && $monthOt['eligible'] && $monthOt['show_hours'])
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500">Overtime</dt>
                                    <dd class="text-gray-900 font-medium">
                                        {{ $fmtMinutes($weekOt['approved_minutes'] + $weekOt['pending_minutes'] + $weekOt['rejected_minutes']) }}
                                        <span class="text-gray-500 font-normal">
                                            (Approved {{ $fmtMinutes($weekOt['approved_minutes']) }}, Pending {{ $fmtMinutes($weekOt['pending_minutes']) }})
                                        </span>
                                    </dd>
                                </div>
                                @if ($monthOt['show_pay'])
                                    <div class="flex items-center justify-between">
                                        <dt class="text-gray-500">Overtime Pay</dt>
                                        <dd class="text-gray-900 font-medium">
                                            {{ $currency }} {{ number_format($weekOt['pay_approved'], 2) }}
                                            @if ($weekOt['pay_pending'] > 0)
                                                <span class="text-xs text-amber-600 font-normal">+ {{ $currency }} {{ number_format($weekOt['pay_pending'], 2) }} pending</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            @endif
                        </dl>
                    </div>
                @endif

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-800">Recent attendance</h3>
                        <a href="{{ route('attendance.employee', $employee) }}" class="text-sm text-indigo-600 hover:underline" wire:navigate>View all</a>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Date</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">In</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Out</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Worked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recent as $record)
                                <tr>
                                    <td class="px-6 py-3 text-gray-900">{{ $record->date->format('D, M j') }}</td>
                                    <td class="px-6 py-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium {{ $colorFor($record->status) }}">{{ $record->status }}</span>
                                        @if ($record->review_flag) <span class="ml-1 text-xs text-red-500" title="Needs HR review">⚠</span> @endif
                                    </td>
                                    <td class="px-6 py-3 text-gray-500"><x-time :value="$record->first_check_in" /></td>
                                    <td class="px-6 py-3 text-gray-500"><x-time :value="$record->last_check_out" /></td>
                                    <td class="px-6 py-3 text-gray-500">
                                        {{ $fmtMinutes((int) $record->total_work_minutes) }}
                                        @php
                                            $recentRow = $recentOt->get($record->date->toDateString());
                                        @endphp
                                        @if ($recentRow && (int) $recentRow->countable_minutes > 0)
                                            <div class="text-xs {{ $recentRow->status === \App\Models\OvertimeRecord::STATUS_PENDING ? 'text-amber-600' : 'text-emerald-600' }}">
                                                +{{ $fmtMinutes((int) $recentRow->countable_minutes) }} OT ({{ $recentRow->status === \App\Models\OvertimeRecord::STATUS_AUTO_APPROVED ? 'auto-approved' : strtolower($recentRow->status) }})
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">No attendance records yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

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
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-600">
                    No employee profile linked to this account yet.
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
