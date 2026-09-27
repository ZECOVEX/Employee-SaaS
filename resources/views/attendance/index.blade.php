<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance</h2>
            <div class="space-x-2">
                @can('attendance.manage')
                    <a href="{{ route('attendance.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Correct Attendance
                    </a>
                @endcan
                @can('attendance.terminal')
                    <a href="{{ route('terminals.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        Terminals
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="date" value="Date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block" :value="$date" />
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="mt-1 block border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">All</option>
                        @foreach (['PRESENT', 'LATE', 'ABSENT', 'LEAVE', 'HOLIDAY', 'WEEKEND'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="text" class="mt-1 block" :value="request('q')" placeholder="Code or name" />
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">Filter</button>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employee</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Check-in</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Check-out</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Worked</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Late</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($records as $record)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">
                                    {{ $record->employee?->user?->name }}
                                    <span class="text-gray-400 text-xs">{{ $record->employee?->employee_code }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    @php($colors = ['PRESENT' => 'bg-green-100 text-green-800', 'LATE' => 'bg-yellow-100 text-yellow-800', 'ABSENT' => 'bg-red-100 text-red-800', 'LEAVE' => 'bg-blue-100 text-blue-800', 'HOLIDAY' => 'bg-purple-100 text-purple-800', 'WEEKEND' => 'bg-gray-100 text-gray-600'])
                                    <span class="px-2 py-1 rounded text-xs font-semibold {{ $colors[$record->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $record->status }}</span>
                                    @if ($record->is_manual)
                                        <span class="text-xs text-gray-400">(manual)</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->first_check_in?->timezone($tz)->format('H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->last_check_out?->timezone($tz)->format('H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ intdiv($record->total_work_minutes, 60) }}h {{ $record->total_work_minutes % 60 }}m</td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->late_minutes > 0 ? $record->late_minutes.' min' : '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    @if ($record->employee)
                                        <a href="{{ route('attendance.employee', $record->employee) }}" class="text-indigo-600 hover:underline">History</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-500">No attendance records for this date.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $records->links() }}</div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-100 font-medium text-gray-700">Recent events</div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($events as $event)
                            <tr>
                                <td class="px-6 py-3 text-gray-900">{{ $event->employee?->user?->name }}</td>
                                <td class="px-6 py-3">
                                    <span class="text-xs font-semibold {{ $event->event_type === 'CHECK_OUT' || $event->event_type === 'MANUAL_OUT' ? 'text-red-600' : 'text-green-600' }}">{{ $event->event_type }}</span>
                                </td>
                                <td class="px-6 py-3 text-gray-500">{{ $event->occurred_at->timezone($tz)->format('H:i:s') }}</td>
                                <td class="px-6 py-3 text-gray-400 text-xs">{{ $event->source }} {{ $event->terminal?->name ? '· '.$event->terminal->name : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-6 text-center text-gray-500">No events.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
