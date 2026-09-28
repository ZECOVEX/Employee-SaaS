<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Attendance — {{ $employee->user?->name }} ({{ $employee->employee_code }})
            </h2>
            <form method="GET" class="flex items-center gap-2">
                <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm" />
                <button type="submit" class="px-3 py-2 bg-gray-800 rounded-md text-xs font-semibold text-white uppercase">View</button>
            </form>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">In</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Out</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Worked</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Late</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($records as $record)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $record->date->format('D, M j') }}</td>
                                <td class="px-6 py-4 text-gray-700">
                                    {{ $record->status }}
                                    @if ($record->is_manual) <span class="text-xs text-gray-400">(adjusted)</span> @endif
                                </td>
                                <td class="px-6 py-4 text-gray-500"><x-time :value="$record->first_check_in" /></td>
                                <td class="px-6 py-4 text-gray-500"><x-time :value="$record->last_check_out" /></td>
                                <td class="px-6 py-4 text-gray-500">{{ intdiv($record->total_work_minutes, 60) }}h {{ $record->total_work_minutes % 60 }}m</td>
                                <td class="px-6 py-4 text-gray-500">{{ $record->late_minutes > 0 ? $record->late_minutes.' min' : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No records this month.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
