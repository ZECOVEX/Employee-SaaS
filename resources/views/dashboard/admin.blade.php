<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Dashboard
        </h2>
    </x-slot>

    @php
        $presentOnTime = max(0, $stats['present_today'] - $stats['late_today']);
        $split = [
            ['label' => 'Present today', 'value' => $presentOnTime, 'color' => '#22c55e', 'drill' => $drilldowns['present_today'] ?? null],
            ['label' => 'Late today', 'value' => $stats['late_today'], 'color' => '#f59e0b', 'drill' => $drilldowns['late_today'] ?? null],
            ['label' => 'Absent today', 'value' => $stats['absent_today'], 'color' => '#ef4444', 'drill' => $drilldowns['absent_today'] ?? null],
            ['label' => 'On leave', 'value' => $stats['on_leave_today'], 'color' => '#3b82f6', 'drill' => $drilldowns['on_leave_today'] ?? null],
        ];
        $splitCovered = $presentOnTime + $stats['late_today'] + $stats['absent_today'] + $stats['on_leave_today'];
        $unrecorded = max(0, $stats['total_employees'] - $splitCovered);

        if ($unrecorded > 0) {
            $split[] = ['label' => 'No record yet', 'value' => $unrecorded, 'color' => '#c4bfd8', 'drill' => null];
        }

        $splitSum = max(1, array_sum(array_column($split, 'value')));
        $donutRadius = 46;
        $donutCirc = 2 * pi() * $donutRadius;
        $donutOffset = 0.0;

        $ringCirc = 2 * pi() * 22;
        $presentRingPct = $stats['total_employees'] > 0
            ? min(100, (int) round($stats['present_today'] / max(1, $stats['total_employees']) * 100))
            : 0;
        $leaveRingPct = $stats['total_employees'] > 0
            ? min(100, (int) round($stats['on_leave_today'] / max(1, $stats['total_employees']) * 100))
            : 0;
        $activeShare = $stats['total_employees'] > 0
            ? (int) round($stats['active_employees'] / max(1, $stats['total_employees']) * 100)
            : 0;
        $avgHeadcount = $stats['departments'] > 0
            ? round($stats['total_employees'] / $stats['departments'], 1)
            : null;
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Performance chart + today's split. --}}
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <section class="dash-card lg:col-span-8">
                    <div class="dash-card-head">
                        <div>
                            <h3 class="dash-title">Performance</h3>
                            <p class="dash-sub">Employees checked in per day · last 14 days</p>
                        </div>
                        <span class="dash-chip">{{ array_sum($trend['values']) }} check-ins</span>
                    </div>

                    <div class="dash-chart">
                        <svg viewBox="0 0 {{ $trend['width'] }} {{ $trend['height'] }}" role="img"
                             aria-label="Employees checked in per day over the last 14 days">
                            <defs>
                                <linearGradient id="dashArea" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#6366f1" stop-opacity="0.26" />
                                    <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0" />
                                </linearGradient>
                                <linearGradient id="dashLine" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="#6366f1" />
                                    <stop offset="100%" stop-color="#8b5cf6" />
                                </linearGradient>
                            </defs>

                            <line class="dash-grid" x1="0" y1="16" x2="{{ $trend['width'] }}" y2="16" />
                            <line class="dash-grid" x1="0" y1="{{ $trend['height'] / 2 }}" x2="{{ $trend['width'] }}" y2="{{ $trend['height'] / 2 }}" />
                            <line class="dash-grid" x1="0" y1="{{ $trend['height'] - 16 }}" x2="{{ $trend['width'] }}" y2="{{ $trend['height'] - 16 }}" />

                            <path d="{{ $trend['area'] }}" fill="url(#dashArea)" />
                            <path d="{{ $trend['path'] }}" fill="none" stroke="url(#dashLine)" stroke-width="2.5"
                                  stroke-linecap="round" stroke-linejoin="round" />

                            <circle cx="{{ $trend['last'][0] }}" cy="{{ $trend['last'][1] }}" r="5.5"
                                    fill="#ffffff" stroke="#6366f1" stroke-width="3" />
                        </svg>

                        <div class="dash-x" aria-hidden="true">
                            @foreach ($trend['labels'] as $trendIndex => $trendLabel)
                                <span class="{{ $loop->last ? 'is-today' : '' }}">{{ $trendLabel }}</span>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="dash-card lg:col-span-4">
                    <div class="dash-card-head">
                        <div>
                            <h3 class="dash-title">Today's split</h3>
                            <p class="dash-sub">{{ now()->format('l, d M Y') }}</p>
                        </div>
                    </div>

                    <div class="dash-donut">
                        <svg viewBox="0 0 120 120" role="img" aria-label="Today's attendance split">
                            <circle cx="60" cy="60" r="{{ $donutRadius }}" fill="none" class="dash-donut-track"
                                    stroke-width="13" />

                            @foreach ($split as $seg)
                                @php
                                    $segLen = $seg['value'] / $splitSum * $donutCirc;
                                    $segDash = max(0, $segLen - 2.5);
                                @endphp
                                <circle cx="60" cy="60" r="{{ $donutRadius }}" fill="none"
                                        stroke="{{ $seg['color'] }}" stroke-width="13"
                                        stroke-dasharray="{{ $segDash }} {{ $donutCirc - $segDash }}"
                                        stroke-dashoffset="{{ -$donutOffset }}"
                                        transform="rotate(-90 60 60)" />
                                @php $donutOffset += $segLen; @endphp
                            @endforeach

                            <text class="donut-num" x="60" y="57" text-anchor="middle">{{ $stats['present_today'] }}</text>
                            <text class="donut-cap" x="60" y="73" text-anchor="middle">CHECKED IN</text>
                        </svg>
                    </div>

                    <div class="dash-legend">
                        @foreach ($split as $seg)
                            <x-hover-panel :drill="$seg['drill']" class="relative">
                                <x-slot name="trigger">
                                    <div class="dash-legend-row">
                                        <span class="dash-dot" style="background: {{ $seg['color'] }}"></span>
                                        <span class="dash-legend-label">{{ $seg['label'] }}</span>
                                        <span class="dash-legend-val">{{ $seg['value'] }}</span>
                                        <span class="dash-legend-pct">{{ (int) round($seg['value'] / $splitSum * 100) }}%</span>
                                    </div>
                                </x-slot>
                            </x-hover-panel>
                        @endforeach
                    </div>

                    <x-hover-panel :drill="$drilldowns['avg_attendance'] ?? null" class="relative">
                        <x-slot name="trigger">
                            <div class="dash-avg">
                                <span>Avg attendance (30d)</span>
                                <strong>{{ $stats['avg_attendance_hours'] }}h</strong>
                            </div>
                        </x-slot>
                    </x-hover-panel>
                </section>
            </div>

            {{-- KPI cards. --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                <x-hover-panel :drill="$drilldowns['total_employees'] ?? null" class="dash-card dash-kpi">
                    <x-slot name="trigger">
                        <div class="dash-icon bg-indigo-50 text-indigo-600">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H4v-1a4 4 0 0 1 3-3.87m6-1.13a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm6-4a3 3 0 1 1-3-3" />
                            </svg>
                        </div>
                        <div class="dash-kpi-label">Total Employees</div>
                        <div class="dash-kpi-num">{{ $stats['total_employees'] }}</div>
                        <div class="dash-kpi-sub">across {{ $stats['departments'] }} {{ str('department')->plural((int) $stats['departments']) }}</div>
                    </x-slot>
                </x-hover-panel>

                <x-hover-panel :drill="$drilldowns['active_employees'] ?? null" class="dash-card dash-kpi">
                    <x-slot name="trigger">
                        <div class="dash-icon bg-emerald-50 text-emerald-600">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <div class="dash-kpi-label">Active Employees</div>
                        <div class="dash-kpi-num">{{ $stats['active_employees'] }}</div>
                        <div class="dash-kpi-sub is-up">{{ $activeShare }}% of roster</div>
                    </x-slot>
                </x-hover-panel>

                <x-hover-panel :drill="$drilldowns['departments'] ?? null" class="dash-card dash-kpi">
                    <x-slot name="trigger">
                        <div class="dash-icon bg-amber-50 text-amber-600">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15" />
                            </svg>
                        </div>
                        <div class="dash-kpi-label">Departments</div>
                        <div class="dash-kpi-num">{{ $stats['departments'] }}</div>
                        <div class="dash-kpi-sub">{{ $avgHeadcount !== null ? $avgHeadcount.' avg headcount' : 'no teams yet' }}</div>
                    </x-slot>
                </x-hover-panel>

                <x-hover-panel :drill="$drilldowns['users'] ?? null" class="dash-card dash-kpi">
                    <x-slot name="trigger">
                        <div class="dash-icon bg-violet-50 text-violet-600">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                        </div>
                        <div class="dash-kpi-label">Users</div>
                        <div class="dash-kpi-num">{{ $stats['users'] }}</div>
                        <div class="dash-kpi-sub">sign-in accounts</div>
                    </x-slot>
                </x-hover-panel>
            </div>

            {{-- Daily overview rings + headcount bars. --}}
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <section class="dash-card lg:col-span-7">
                    <div class="dash-card-head">
                        <div>
                            <h3 class="dash-title">Daily Overview</h3>
                            <p class="dash-sub">Today's coverage against expected headcount</p>
                        </div>
                        @can('reports.view')
                            <a href="{{ route('reports.attendance') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-lg font-semibold text-xs text-white hover:bg-gray-700">
                                Export
                            </a>
                        @endcan
                    </div>

                    <div class="dash-rings">
                        <div class="dash-ring-group">
                            <div class="dash-ring-stat">
                                <strong>{{ $stats['present_today'] }}</strong>
                                <span>Today</span>
                            </div>
                            <div class="dash-ring">
                                <svg viewBox="0 0 56 56" aria-hidden="true">
                                    <circle cx="28" cy="28" r="22" fill="none" class="dash-ring-track" stroke-width="6" />
                                    <circle cx="28" cy="28" r="22" fill="none" stroke="#6366f1" stroke-width="6"
                                            stroke-linecap="round" stroke-dasharray="{{ $ringCirc * $presentRingPct / 100 }} {{ $ringCirc }}"
                                            transform="rotate(-90 28 28)" />
                                </svg>
                                <span class="dash-ring-cap">PRESENT</span>
                            </div>
                            <div class="dash-ring-stat is-right">
                                <strong>{{ $stats['total_employees'] }}</strong>
                                <span>Expected</span>
                            </div>
                        </div>

                        <div class="dash-ring-group">
                            <div class="dash-ring-stat">
                                <strong>{{ $stats['on_leave_today'] }}</strong>
                                <span>Today</span>
                            </div>
                            <div class="dash-ring">
                                <svg viewBox="0 0 56 56" aria-hidden="true">
                                    <circle cx="28" cy="28" r="22" fill="none" class="dash-ring-track" stroke-width="6" />
                                    <circle cx="28" cy="28" r="22" fill="none" stroke="#3b82f6" stroke-width="6"
                                            stroke-linecap="round" stroke-dasharray="{{ $ringCirc * $leaveRingPct / 100 }} {{ $ringCirc }}"
                                            transform="rotate(-90 28 28)" />
                                </svg>
                                <span class="dash-ring-cap">LEAVE</span>
                            </div>
                            <div class="dash-ring-stat is-right">
                                <strong>{{ $stats['total_employees'] }}</strong>
                                <span>Expected</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="dash-card lg:col-span-5">
                    <div class="dash-card-head">
                        <div>
                            <h3 class="dash-title">Headcount by department</h3>
                            <p class="dash-sub">Top teams · share of {{ $stats['total_employees'] }} employees</p>
                        </div>
                    </div>

                    <div class="dash-bars">
                        @forelse ($deptBars as $bar)
                            <div>
                                <div class="dash-bar-top">
                                    <span class="dash-bar-name">{{ $bar['name'] }}</span>
                                    <span class="dash-bar-meta">{{ $bar['count'] }} · {{ $bar['pct'] }}%</span>
                                </div>
                                <div class="dash-bar">
                                    <i style="width: {{ max(2, $bar['pct']) }}%"></i>
                                </div>
                            </div>
                        @empty
                            <p class="dash-empty">No departments yet — headcount bars appear once employees are assigned to teams.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            {{-- §18 score, overtime & risk widgets (§15 admin visibility). --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-6">
                <x-hover-panel :drill="$drilldowns['avg_score'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">Average Score</div>
                        <div class="dash-widget-num">
                            @if ($stats['avg_score'] !== null)
                                {{ number_format($stats['avg_score'], 1) }}
                            @else
                                —
                            @endif
                        </div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['at_risk'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">At Risk Employees</div>
                        <div class="dash-widget-num">{{ $stats['at_risk'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['high_risk_alerts'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">High-Risk Alerts</div>
                        <div class="dash-widget-num">{{ $stats['high_risk_alerts'] }}</div>
                        <div class="dash-widget-sub">this week</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['overtime_today'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">Overtime Today</div>
                        <div class="dash-widget-num">
                            {{ number_format($stats['overtime_today'] / 60, 1) }}<span class="dash-widget-unit">h</span>
                        </div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['pending_overtime'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">Pending Overtime Approvals</div>
                        <div class="dash-widget-num">{{ $stats['pending_overtime'] }}</div>
                    </x-slot>
                </x-hover-panel>
                <x-hover-panel :drill="$drilldowns['overtime_month'] ?? null" class="dash-card dash-widget">
                    <x-slot name="trigger">
                        <div class="dash-widget-label">Overtime Hours This Month</div>
                        <div class="dash-widget-num">
                            {{ number_format($stats['overtime_month'] / 60, 1) }}<span class="dash-widget-unit">h</span>
                        </div>
                    </x-slot>
                </x-hover-panel>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                @can('audit.view')
                    <section class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <h3 class="dash-title">Recent activity</h3>
                                <p class="dash-sub">Latest audit events in your organization</p>
                            </div>
                            <a href="{{ route('audit-logs.index') }}" class="dash-chip">View all</a>
                        </div>
                        @if ($recentAudit->isEmpty())
                            <p class="dash-empty">No recent audit events.</p>
                        @else
                            <ul>
                                @foreach ($recentAudit as $log)
                                    <li class="dash-act-row">
                                        <span class="dash-act-text">
                                            <span class="dash-act-actor">{{ $log->actor?->name ?? 'System' }}</span>
                                            {{ str_replace('.', ' ', $log->action) }}
                                            @if ($log->resource_type)
                                                <span class="dash-act-res">#{{ $log->resource_id }}</span>
                                            @endif
                                        </span>
                                        <span class="dash-act-time">{{ $log->created_at->diffForHumans() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endcan

                @can('reports.view')
                    <section class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <h3 class="dash-title">Analytics &amp; exports</h3>
                                <p class="dash-sub">Workforce trends, attendance, overtime and salary reports — each with CSV export.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3 pt-1">
                            <a href="{{ route('analytics.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-lg font-semibold text-xs text-white hover:bg-gray-700">
                                Open analytics
                            </a>
                            <a href="{{ route('reports.attendance') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg font-semibold text-xs text-gray-700 hover:bg-gray-50">
                                Reports &amp; exports
                            </a>
                        </div>
                    </section>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
