<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $employee->user?->name }} ({{ $employee->employee_code }})
            </h2>
            @can('employees.edit')
                <a href="{{ route('employees.edit', $employee) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    Edit
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            {{-- §40 header cards: attendance score + overtime this month --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Attendance Score</p>
                    @if ($score !== null)
                        <p class="mt-1 text-3xl font-bold text-gray-900">{{ number_format($score['score'], 1) }}</p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ $scoreStatus['label'] ?? '' }}
                            <span class="text-gray-400">· base {{ number_format($score['base_score'], 1) }} + OT bonus {{ number_format($score['overtime_bonus'], 1) }}</span>
                        </p>
                        <p class="mt-1 text-xs text-gray-400">Formula: {{ $score['formula'] }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-500">Not enough elapsed days this month to score.</p>
                    @endif
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Overtime This Month</p>
                    @if (! $isSelf || $otSummary['show_hours'])
                        <p class="mt-1 text-3xl font-bold text-gray-900">
                            {{ intdiv((int) $otSummary['approved_minutes'], 60) }}h {{ (int) $otSummary['approved_minutes'] % 60 }}m
                        </p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ $otSummary['days'] }} overtime day(s)
                            @if ($otSummary['pending_minutes'] > 0)
                                · {{ $otSummary['pending_minutes'] }} min pending
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-gray-500">Hidden by your overtime policy.</p>
                    @endif
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-4">Overview</h3>
                <div class="flex items-start gap-5">
                    @if ($employee->photo_path)
                        <img src="{{ Storage::disk('public')->url($employee->photo_path) }}" alt=""
                             class="w-20 h-20 rounded-full object-cover shrink-0" />
                    @endif
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm grow">
                        <div><dt class="text-gray-500">Email</dt><dd class="text-gray-900">{{ $employee->user?->email }}</dd></div>
                        <div><dt class="text-gray-500">Status</dt><dd class="text-gray-900 capitalize">{{ $employee->status }}</dd></div>
                        <div><dt class="text-gray-500">Employment</dt><dd class="text-gray-900 capitalize">{{ str_replace('_', ' ', $employee->employment_type) }}</dd></div>
                        <div><dt class="text-gray-500">Department</dt><dd class="text-gray-900">{{ $employee->department?->name ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Position</dt><dd class="text-gray-900">{{ $employee->position?->title ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Manager</dt><dd class="text-gray-900">{{ $employee->manager?->name ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Phone</dt><dd class="text-gray-900">{{ $employee->phone ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Joined</dt><dd class="text-gray-900">{{ $employee->joining_date?->format('d M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Last login</dt><dd class="text-gray-900">{{ $employee->user?->last_login_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Work location</dt><dd class="text-gray-900">{{ $employee->work_location ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Date of birth</dt><dd class="text-gray-900">{{ $employee->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Address</dt><dd class="text-gray-900">{{ $employee->address ?? '—' }}</dd></div>
                        <div>
                            <dt class="text-gray-500">Emergency contact</dt>
                            <dd class="text-gray-900">
                                @if ($employee->emergency_contact_name)
                                    {{ $employee->emergency_contact_name }}{{ $employee->emergency_contact_phone ? ' · '.$employee->emergency_contact_phone : '' }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Overtime eligibility</dt>
                            <dd class="text-gray-900">
                                @php
                                    $otLabel = [\App\Models\Employee::OT_POLICY_DEFAULT => 'Follow policy', \App\Models\Employee::OT_ELIGIBLE => 'Always eligible', \App\Models\Employee::OT_NOT_ELIGIBLE => 'Never eligible'];
                                @endphp
                                {{ $otLabel[$employee->overtime_eligibility ?? \App\Models\Employee::OT_POLICY_DEFAULT] ?? 'Follow policy' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($canSeeSalary)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-800">Salary</h3>
                        <a href="{{ route('salary.show', $employee) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">
                            @can('salary.edit')
                                Change salary →
                            @else
                                Salary history →
                            @endcan
                        </a>
                    </div>
                    @if ($currentSalary)
                        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                            <div><dt class="text-gray-500">Basic</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->basic_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Gross</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->gross_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Net</dt>
                                <dd class="mt-1 text-gray-900 font-semibold">{{ number_format($currentSalary->net_salary, 2) }}</dd></div>
                            <div><dt class="text-gray-500">Effective from</dt>
                                <dd class="mt-1 text-gray-900">{{ $currentSalary->effective_from->format('d M Y') }}</dd></div>
                        </dl>
                    @else
                        <p class="text-sm text-gray-500">No salary record yet.</p>
                    @endif
                </div>
            @endif

            {{-- §40 tabs --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap gap-2 text-sm mb-4">
                    <a href="{{ route('attendance.employee', $employee) }}" wire:navigate
                       class="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700 hover:bg-indigo-100">Attendance</a>
                    @foreach (['leave' => 'Leave', 'monthly' => 'Monthly Status', 'nfc' => 'NFC Card', 'audit' => 'Audit History'] as $key => $label)
                        @if (in_array($key, $tabs, true))
                            <a href="{{ route('employees.show', ['employee' => $employee, 'tab' => $key]) }}" wire:navigate
                               class="rounded-full px-3 py-1 {{ $tab === $key ? 'bg-indigo-600 text-white font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                                {{ $label }}
                            </a>
                        @endif
                    @endforeach
                </div>

                <div class="text-sm">
                    @switch($tab)
                        @case('leave')
                            @if ($tabData->isEmpty())
                                <p class="text-gray-500">No leave requests yet.</p>
                            @else
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="text-left text-xs text-gray-500">
                                            <th class="py-2 pe-4">Type</th>
                                            <th class="py-2 pe-4">Dates</th>
                                            <th class="py-2 pe-4">Days</th>
                                            <th class="py-2 pe-4">Status</th>
                                            <th class="py-2">Note</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($tabData as $leave)
                                            <tr>
                                                <td class="py-2 pe-4 text-gray-900">{{ $leave->leaveType?->name ?? '—' }}</td>
                                                <td class="py-2 pe-4 text-gray-600">
                                                    {{ $leave->start_date?->format('d M Y') }} — {{ $leave->end_date?->format('d M Y') }}
                                                </td>
                                                <td class="py-2 pe-4 text-gray-600">{{ $leave->days }}</td>
                                                <td class="py-2 pe-4">
                                                    <span class="rounded px-2 py-0.5 text-xs font-semibold
                                                        {{ $leave->status === 'APPROVED' ? 'bg-green-50 text-green-700' : ($leave->status === 'REJECTED' || $leave->status === 'CANCELLED' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                                                        {{ $leave->status }}
                                                    </span>
                                                </td>
                                                <td class="py-2 text-gray-500">{{ \Illuminate\Support\Str::limit($leave->reason, 60) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            @break

                        @case('nfc')
                            @if ($tabData->isEmpty())
                                <p class="text-gray-500">No NFC card is assigned to this employee.</p>
                            @else
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="text-left text-xs text-gray-500">
                                            <th class="py-2 pe-4">Token</th>
                                            <th class="py-2 pe-4">Status</th>
                                            <th class="py-2">Last used</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($tabData as $card)
                                            <tr>
                                                <td class="py-2 pe-4 font-mono text-gray-900">{{ $card->card_token }}</td>
                                                <td class="py-2 pe-4 text-gray-600 capitalize">{{ $card->status }}</td>
                                                <td class="py-2 text-gray-500">{{ $card->last_used_at?->format('d M Y H:i') ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            @break

                        @case('audit')
                            @if ($tabData->isEmpty() && ($loginHistory ?? collect())->isEmpty())
                                <p class="text-gray-500">No profile audit entries yet.</p>
                            @else
                                @if ($tabData->isNotEmpty())
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr class="text-left text-xs text-gray-500">
                                                <th class="py-2 pe-4">When</th>
                                                <th class="py-2 pe-4">Actor</th>
                                                <th class="py-2 pe-4">Action</th>
                                                <th class="py-2">Changes</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($tabData as $log)
                                                <tr>
                                                    <td class="py-2 pe-4 text-gray-600 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                                                    <td class="py-2 pe-4 text-gray-900">{{ $log->actor?->name ?? 'System' }}</td>
                                                    <td class="py-2 pe-4 font-mono text-xs text-gray-700">{{ $log->action }}</td>
                                                    <td class="py-2 text-xs text-gray-500">
                                                        @php
                                                            $old = is_array($log->old_value) ? $log->old_value : [];
                                                            $new = is_array($log->new_value) ? $log->new_value : [];
                                                            $pairs = [];
                                                            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
                                                                $from = $old[$key] ?? null;
                                                                $to = $new[$key] ?? null;
                                                                if ($from !== $to && ! (is_numeric($from) && is_numeric($to) && (float) $from === (float) $to) && ! (is_array($from) && is_array($to) && $from == $to)) {
                                                                    $pairs[] = $key;
                                                                }
                                                            }
                                                        @endphp
                                                        {{ $pairs === [] ? '—' : implode(', ', $pairs) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif

                                @if (($loginHistory ?? collect())->isNotEmpty())
                                    <h4 class="mt-6 mb-2 text-sm font-semibold text-gray-700">Recent logins</h4>
                                    <p class="mb-2 text-xs text-gray-500">Approximate IP geolocation, security monitoring only.</p>
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr class="text-left text-xs text-gray-500">
                                                <th class="py-2 pe-4">When</th>
                                                <th class="py-2 pe-4">Status</th>
                                                <th class="py-2 pe-4">IP</th>
                                                <th class="py-2">Location</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($loginHistory as $entry)
                                                <tr>
                                                    <td class="py-2 pe-4 text-gray-600 whitespace-nowrap">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                                                    <td class="py-2 pe-4">
                                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $entry->login_status === 'SUCCESS' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $entry->login_status }}</span>
                                                    </td>
                                                    <td class="py-2 pe-4 text-gray-700">{{ $entry->ip_address ?? '—' }}</td>
                                                    <td class="py-2 text-gray-700">{{ $entry->locationLabel() }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            @endif
                            @break

                        @default {{-- monthly --}}
                            @if ($tabData->isEmpty())
                                <p class="text-gray-500">No finalized monthly status yet — snapshots appear after the month is generated.</p>
                            @else
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="text-left text-xs text-gray-500">
                                            <th class="py-2 pe-4">Month</th>
                                            <th class="py-2 pe-4">Score</th>
                                            <th class="py-2 pe-4">Present</th>
                                            <th class="py-2 pe-4">Absent</th>
                                            <th class="py-2 pe-4">Late</th>
                                            <th class="py-2 pe-4">Leave</th>
                                            <th class="py-2">Overtime</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($tabData as $status)
                                            <tr>
                                                <td class="py-2 pe-4 text-gray-900 font-medium">{{ $status->periodLabel() }}</td>
                                                <td class="py-2 pe-4 text-gray-900 font-semibold">{{ number_format($status->attendance_score, 1) }}</td>
                                                <td class="py-2 pe-4 text-gray-600">{{ $status->days_present }}</td>
                                                <td class="py-2 pe-4 text-gray-600">{{ $status->days_absent }}</td>
                                                <td class="py-2 pe-4 text-gray-600">{{ $status->days_late }}</td>
                                                <td class="py-2 pe-4 text-gray-600">{{ $status->days_on_leave }}</td>
                                                <td class="py-2 text-gray-600">{{ intdiv((int) $status->overtime_minutes_approved, 60) }}h {{ (int) $status->overtime_minutes_approved % 60 }}m</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                    @endswitch
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
