<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analytics</h2>
            <div class="flex flex-wrap items-center gap-5">
                <span class="text-sm text-gray-500">{{ $analytics['active_employees'] }} active employees</span>
                @include('reports._tabs', ['active' => 'analytics'])
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @php
                $currentRate = $analytics['trend'][array_key_last($analytics['trend'])]['rate'] ?? 0;
                $lateTotal = array_sum(array_column($analytics['weekdays'], 'count'));
                $payrollTotal = array_sum(array_column($analytics['payroll'], 'total'));
                $scores = $analytics['scores'];
                $leave = $analytics['leave'];
                $workload = $analytics['workload'];
                $workforce = $analytics['workforce'];
                $wfMax = max([1, ...array_column($workforce['employment'], 'count')]);
                $toneClasses = [
                    'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'red' => 'bg-red-50 text-red-700 border-red-200',
                    'gray' => 'bg-gray-100 text-gray-600 border-gray-200',
                ];
            @endphp

            {{-- KPI strip --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Active employees</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $analytics['active_employees'] }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Attendance this month</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $currentRate }}%</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Late arrivals (month)</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $lateTotal }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Departments</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ count($analytics['departments']) }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Avg attendance score</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $scores['avg'] !== null ? $scores['avg'].'%' : '—' }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ $scores['label'] !== null ? 'Latest finalized month · '.$scores['label'] : 'No finalized month yet' }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Approved leave (month)</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $leave['approved_days_month'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">days this month</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Pending leave requests</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $leave['pending'] }}</p>
                    <p class="mt-1 text-xs text-gray-400">awaiting review</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Worked vs expected</p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $workload['rate'] }}%</p>
                    <p class="mt-1 text-xs text-gray-400">current-month workload</p>
                </div>
            </div>

            {{-- Attendance trend (6 months) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-baseline justify-between mb-6">
                    <h3 class="font-semibold text-gray-800">Attendance trend</h3>
                    <p class="text-xs text-gray-500">Last 6 months · % of scheduled days attended</p>
                </div>
                <div class="flex items-end gap-3 h-44">
                    @foreach ($analytics['trend'] as $point)
                        <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $point['attended'] }}/{{ $point['expected'] }} employee-days attended">
                            <span class="text-xs text-gray-600 mb-1">{{ $point['rate'] }}%</span>
                            <div class="w-full rounded-t bg-indigo-400 hover:bg-indigo-500 transition-colors"
                                 style="height: {{ max(2, $point['rate']) }}%"></div>
                            <span class="mt-2 text-xs text-gray-500">{{ $point['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-6">
                {{-- Overtime trend (6 months, §41 [OT]) --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Overtime trend</h3>
                        <p class="text-xs text-gray-500">Last 6 months · approved / pending hours</p>
                    </div>
                    @php($otMax = max(1, ...array_column($analytics['overtime'], 'hours')))
                    <div class="flex items-end gap-2 h-40">
                        @foreach ($analytics['overtime'] as $point)
                            <div class="flex-1 flex flex-col items-center justify-end h-full"
                                 title="{{ $point['approved'] }}h approved / {{ $point['pending'] }}h pending">
                                @if ($point['hours'] > 0)
                                    <span class="text-xs text-gray-600 mb-1">{{ rtrim(rtrim(number_format($point['hours'], 1, '.', ''), '0'), '.') }}h</span>
                                @endif
                                <div class="w-full flex flex-col rounded-t overflow-hidden {{ $point['hours'] > 0 ? '' : 'bg-gray-200' }}"
                                     style="height: {{ max(2, (int) round($point['hours'] / $otMax * 100)) }}%">
                                    @if ($point['hours'] > 0)
                                        @if ($point['pending'] > 0 && ($point['approved'] + $point['pending']) > 0)
                                            <div class="bg-amber-400"
                                                 style="height: {{ max(4, (int) round($point['pending'] / ($point['approved'] + $point['pending']) * 100)) }}%"></div>
                                        @endif
                                        <div class="flex-1 bg-emerald-500"></div>
                                    @endif
                                </div>
                                <span class="mt-2 text-xs text-gray-500">{{ $point['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-emerald-500"></span> Approved</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-amber-400"></span> Pending</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-gray-200"></span> None</span>
                    </div>
                </div>

                {{-- Department comparison --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Departments</h3>
                        <p class="text-xs text-gray-500">Current month rate</p>
                    </div>
                    @if ($analytics['departments'] === [])
                        <p class="text-sm text-gray-500">No active employees yet.</p>
                    @else
                        <div class="space-y-4">
                            @foreach ($analytics['departments'] as $dept)
                                <div>
                                    <div class="flex items-baseline justify-between text-sm mb-1">
                                        <span class="text-gray-700">{{ $dept['name'] }}</span>
                                        <span class="text-gray-500 text-xs">
                                            {{ $dept['rate'] }}% · {{ $dept['employees'] }} emp · {{ $dept['late_minutes'] }}m late
                                        </span>
                                    </div>
                                    <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-500 rounded-full" style="width: {{ max(1, $dept['rate']) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Weekday late pattern --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Late arrivals by weekday</h3>
                        <p class="text-xs text-gray-500">Current month</p>
                    </div>
                    @php($maxLate = max(1, ...array_column($analytics['weekdays'], 'count')))
                    <div class="flex items-end gap-2 h-40">
                        @foreach ($analytics['weekdays'] as $day)
                            <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $day['count'] }} late arrivals">
                                @if ($day['count'] > 0)
                                    <span class="text-xs text-gray-600 mb-1">{{ $day['count'] }}</span>
                                @endif
                                <div class="w-full rounded-t {{ $day['count'] > 0 ? 'bg-yellow-400' : 'bg-gray-200' }}"
                                     style="height: {{ max(2, (int) round($day['count'] / $maxLate * 100)) }}%"></div>
                                <span class="mt-2 text-xs text-gray-500">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Workforce mix --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Workforce mix</h3>
                        <p class="text-xs text-gray-500">{{ $workforce['total'] }} total · {{ $workforce['joiners_30d'] }} joined (30d)</p>
                    </div>
                    @if ($workforce['employment'] === [])
                        <p class="text-sm text-gray-500">No employees yet.</p>
                    @else
                        <div class="space-y-4">
                            @foreach ($workforce['employment'] as $type)
                                <div>
                                    <div class="flex items-baseline justify-between text-sm mb-1">
                                        <span class="text-gray-700">{{ $type['label'] }}</span>
                                        <span class="text-gray-500 text-xs">{{ $type['count'] }}</span>
                                    </div>
                                    <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-sky-500 rounded-full" style="width: {{ max(1, (int) round($type['count'] / $wfMax * 100)) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-5 pt-4 border-t border-gray-100 flex flex-wrap gap-2">
                            @foreach ($workforce['status'] as $state)
                                <span class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full border text-xs font-medium {{ $toneClasses[$state['tone']] ?? $toneClasses['gray'] }}">
                                    <span class="w-2 h-2 rounded-full bg-current opacity-60"></span>
                                    {{ $state['label'] }} · {{ $state['count'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Attendance status distribution + workload --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-baseline justify-between mb-5">
                    <h3 class="font-semibold text-gray-800">Attendance status distribution</h3>
                    <p class="text-xs text-gray-500">Current month · all attendance rows</p>
                </div>
                @if ($analytics['status_distribution'] === [])
                    <p class="text-sm text-gray-500">No attendance rows this month yet.</p>
                @else
                    @php($statusTotal = max(1, array_sum(array_column($analytics['status_distribution'], 'count'))))
                    <div class="w-full h-5 rounded-full overflow-hidden flex bg-gray-100">
                        @foreach ($analytics['status_distribution'] as $slice)
                            <div class="{{ $slice['color'] }} h-full"
                                 style="width: {{ round($slice['count'] / $statusTotal * 100, 1) }}%"
                                 title="{{ $slice['label'] }}: {{ $slice['count'] }}"></div>
                        @endforeach
                    </div>
                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach ($analytics['status_distribution'] as $slice)
                            <div class="flex items-center gap-2 text-sm">
                                <span class="inline-block w-3 h-3 rounded-sm {{ $slice['color'] }} shrink-0"></span>
                                <span class="text-gray-700 truncate">{{ $slice['label'] }}</span>
                                <span class="ml-auto text-xs text-gray-500">{{ $slice['count'] }} · {{ round($slice['count'] / $statusTotal * 100, 1) }}%</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="mt-6 pt-5 border-t border-gray-100">
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Worked vs expected</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $workload['rate'] }}%</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Worked hours</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($workload['worked_minutes'] / 60, 1) }} h</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Scheduled hours</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($workload['expected_minutes'] / 60, 1) }} h</p>
                        </div>
                    </div>
                    <div class="mt-4 w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-teal-500 rounded-full" style="width: {{ max(1, (int) round($workload['rate'])) }}%"></div>
                    </div>
                </div>
            </div>

            {{-- Leave analytics --}}
            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Leave requests</h3>
                        <p class="text-xs text-gray-500">Last 6 months · approved / pending / rejected</p>
                    </div>
                    @php($leaveMax = max([1, ...array_map(fn (array $m) => max($m['approved'], $m['pending'], $m['rejected']), $leave['months'])]))
                    <div class="flex items-end gap-3 h-40">
                        @foreach ($leave['months'] as $month)
                            <div class="flex-1 flex flex-col items-center justify-end h-full gap-1">
                                <div class="w-full flex items-end justify-center gap-0.5 h-full">
                                    <div class="w-1/3 rounded-t bg-emerald-500" title="{{ $month['approved'] }} approved"
                                         style="height: {{ max(2, (int) round($month['approved'] / $leaveMax * 100)) }}%"></div>
                                    <div class="w-1/3 rounded-t bg-amber-400" title="{{ $month['pending'] }} pending"
                                         style="height: {{ max(2, (int) round($month['pending'] / $leaveMax * 100)) }}%"></div>
                                    <div class="w-1/3 rounded-t bg-red-400" title="{{ $month['rejected'] }} rejected"
                                         style="height: {{ max(2, (int) round($month['rejected'] / $leaveMax * 100)) }}%"></div>
                                </div>
                                <span class="text-xs text-gray-500">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-emerald-500"></span> Approved</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-amber-400"></span> Pending</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-red-400"></span> Rejected</span>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Leave by type</h3>
                        <p class="text-xs text-gray-500">Approved days this year</p>
                    </div>
                    @if ($leave['types'] === [])
                        <p class="text-sm text-gray-500">No approved leave this year.</p>
                    @else
                        @php($typeMax = max(1, ...array_column($leave['types'], 'days')))
                        <div class="space-y-4">
                            @foreach ($leave['types'] as $type)
                                <div>
                                    <div class="flex items-baseline justify-between text-sm mb-1">
                                        <span class="text-gray-700">{{ $type['name'] }}</span>
                                        <span class="text-gray-500 text-xs">{{ $type['days'] }} {{ $type['days'] === 1 ? 'day' : 'days' }}</span>
                                    </div>
                                    <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-purple-500 rounded-full" style="width: {{ max(1, (int) round($type['days'] / $typeMax * 100)) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($leave['pending'] > 0)
                        <div class="mt-5 pt-4 border-t border-gray-100 text-sm text-gray-600">
                            {{ $leave['pending'] }} {{ $leave['pending'] === 1 ? 'request' : 'requests' }} awaiting review —
                            <a class="text-indigo-600 hover:underline" href="{{ route('leave.index') }}">open the leave queue</a>.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Scores + risk --}}
            <div class="grid lg:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Performance scores</h3>
                        <p class="text-xs text-gray-500">{{ $scores['label'] !== null ? 'Latest finalized month · '.$scores['label'] : 'Latest finalized month' }}</p>
                    </div>
                    @if ($scores['avg'] === null)
                        <p class="text-sm text-gray-500">Monthly score snapshots are generated on the 1st of each month — none yet.</p>
                    @else
                        <div class="flex items-baseline gap-3 mb-5">
                            <span class="text-3xl font-semibold text-gray-900">{{ $scores['avg'] }}%</span>
                            <span class="text-sm text-gray-500">average across {{ $scores['total'] }} {{ $scores['total'] === 1 ? 'employee' : 'employees' }}</span>
                        </div>
                        <div class="space-y-4">
                            @foreach ($scores['bands'] as $band)
                                <div>
                                    <div class="flex items-baseline justify-between text-sm mb-1">
                                        <span class="text-gray-700">{{ $band['label'] }}</span>
                                        <span class="text-gray-500 text-xs">{{ $band['count'] }}</span>
                                    </div>
                                    <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full {{ $band['color'] }} rounded-full"
                                             style="width: {{ $scores['total'] > 0 ? max(1, round($band['count'] / $scores['total'] * 100, 1)) : 0 }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-5">
                        <h3 class="font-semibold text-gray-800">Risk overview</h3>
                        <p class="text-xs text-gray-500">Open windows · still current</p>
                    </div>
                    @if ($analytics['risk']['total'] === 0)
                        <p class="text-sm text-gray-500">No open risk alerts.</p>
                    @else
                        <div class="flex flex-wrap gap-2 mb-5">
                            @foreach ($analytics['risk']['by_level'] as $level)
                                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200">
                                    {{ $level['label'] }} · {{ $level['count'] }}
                                </span>
                            @endforeach
                        </div>
                        <div class="space-y-3">
                            @foreach ($analytics['risk']['by_type'] as $type)
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="text-gray-700">{{ $type['label'] }}</span>
                                    <span class="text-gray-500 text-xs">{{ $type['count'] }} {{ $type['count'] === 1 ? 'event' : 'events' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Payroll progress (authorized users only) --}}
            @can('salary.view')
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-baseline justify-between mb-6">
                        <h3 class="font-semibold text-gray-800">Finalized payroll</h3>
                        <p class="text-xs text-gray-500">Net salary per period · last 6 months · {{ auth()->user()->organization?->currency() ?? 'BDT' }}</p>
                    </div>
                    @if ($payrollTotal <= 0)
                        <p class="text-sm text-gray-500">
                            No finalized payslips in this window yet — finalize one from
                            <a class="text-indigo-600 hover:underline" href="{{ route('salary.index') }}">Salary records</a>.
                        </p>
                    @else
                        @php($payrollMax = max(1, ...array_column($analytics['payroll'], 'total')))
                        <div class="flex items-end gap-3 h-44">
                            @foreach ($analytics['payroll'] as $point)
                                <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ number_format($point['total'], 2) }} {{ auth()->user()->organization?->currency() ?? 'BDT' }}">
                                    <span class="text-xs text-gray-600 mb-1">{{ $point['total'] > 0 ? number_format($point['total'] / 1000, 1).'k' : '—' }}</span>
                                    <div class="w-full rounded-t bg-emerald-400 hover:bg-emerald-500 transition-colors"
                                         style="height: {{ max(2, (int) round($point['total'] / $payrollMax * 100)) }}%"></div>
                                    <span class="mt-2 text-xs text-gray-500">{{ $point['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endcan

            <p class="text-xs text-gray-400 text-right">Approved overtime pay is included in the estimated net trend.</p>
        </div>
    </div>
</x-app-layout>
