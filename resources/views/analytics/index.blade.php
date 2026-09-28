<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analytics</h2>
            <span class="text-sm text-gray-500">{{ $analytics['active_employees'] }} active employees</span>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @php
                $currentRate = $analytics['trend'][array_key_last($analytics['trend'])]['rate'] ?? 0;
                $lateTotal = array_sum(array_column($analytics['weekdays'], 'count'));
                $payrollTotal = array_sum(array_column($analytics['payroll'], 'total'));
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

            <p class="text-xs text-gray-400 text-right">Overtime pay trends arrive with the overtime phase [OT].</p>
        </div>
    </div>
</x-app-layout>
