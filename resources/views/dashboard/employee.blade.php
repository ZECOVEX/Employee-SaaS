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

    $dayTitle = function ($record, $day) use ($workDayNumbers, $fmtMinutes, $org) {
        $label = $day->format('D, d M Y');

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

            return $label.' — '.implode(' · ', $parts);
        }

        if (! in_array((int) $day->dayOfWeekIso, $workDayNumbers, true)) {
            return $label.' — day off';
        }

        if ($day->isFuture()) {
            return $label;
        }

        return $label.' — no record';
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
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 flex items-center gap-4">
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
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Present ({{ $month->format('M') }})</div>
                        <div class="text-2xl font-semibold text-green-700 mt-1">{{ $monthStats['present'] }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Late</div>
                        <div class="text-2xl font-semibold text-amber-600 mt-1">{{ $monthStats['late'] }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Absent</div>
                        <div class="text-2xl font-semibold text-red-600 mt-1">{{ $monthStats['absent'] }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">On leave</div>
                        <div class="text-2xl font-semibold text-blue-600 mt-1">{{ $monthStats['leave'] }}</div>
                    </div>
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Leave balance</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $leaveBalance['remaining'] }}<span class="text-sm font-normal text-gray-400"> / {{ $leaveBalance['total'] }}d</span></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <div class="text-sm text-gray-500">Worked this month</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $fmtMinutes($monthStats['worked_minutes']) }}</div>
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
                                    @endphp
                                    <div class="rounded {{ $classes }} p-1.5 text-xs text-center"
                                         title="{{ $dayTitle($record, $day) }}">
                                        <div class="font-semibold {{ $inMonth ? '' : 'opacity-40' }}">{{ $day->day }}</div>
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
                    </div>
                </div>

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
                                    <td class="px-6 py-3 text-gray-500">{{ $fmtMinutes((int) $record->total_work_minutes) }}</td>
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
