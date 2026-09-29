<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">My Overtime</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500">
                Extra time is detected from your punches. Records appear here once recorded;
                pay is shown only when your company policy allows it.
            </p>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Day</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Overtime</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-right font-medium text-gray-500">Est. pay</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($records as $row)
                            @php($record = $row['record'])
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $record->work_date->format('D, M j, Y') }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ $dayLabels[$record->day_type] ?? $record->day_type }}</td>
                                <td class="px-6 py-4 text-gray-900 font-medium">
                                    {{ $record->minutesLabel() }}
                                    @if ((int) $record->late_offset_minutes > 0)
                                        <span class="text-xs text-gray-400">(includes {{ $record->late_offset_minutes }}m late offset)</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-700">
                                    {{ $statusLabels[$record->status] ?? $record->status }}
                                    @if ($record->flag_reason)
                                        <span class="block text-xs text-amber-600">{{ $flagLabels[$record->flag_reason] ?? $record->flag_reason }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-gray-900">
                                    @if ($row['show_pay'])
                                        @if ($record->estimated_pay > 0)
                                            {{ auth()->user()->organization?->currency() ?? 'BDT' }} {{ number_format($record->estimated_pay, 2) }}
                                        @else
                                            —
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-400">Hidden by policy</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No overtime recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $records->links() }}
        </div>
    </div>
</x-app-layout>
