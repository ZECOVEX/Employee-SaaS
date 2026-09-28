<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Card History</h2>
            <a href="{{ route('nfc-cards.index') }}" class="text-sm text-indigo-600 hover:underline" wire:navigate>← Back to cards</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Token</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $nfcCard->card_token }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1"><span class="px-2 py-1 rounded text-xs font-semibold
                            @switch($nfcCard->status)
                                @case('active') bg-green-100 text-green-800 @break
                                @case('blocked') bg-red-100 text-red-800 @break
                                @case('revoked') bg-gray-200 text-gray-500 @break
                                @default bg-gray-100 text-gray-600
                            @endswitch">{{ $nfcCard->status }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Employee</dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $nfcCard->employee?->user?->name ?? '—' }}
                            @if ($nfcCard->employee) <span class="text-xs text-gray-400">{{ $nfcCard->employee->employee_code }}</span> @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Issued</dt>
                        <dd class="mt-1 text-gray-900">{{ $nfcCard->issued_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Revoked</dt>
                        <dd class="mt-1 text-gray-900">{{ $nfcCard->revoked_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Last used</dt>
                        <dd class="mt-1 text-gray-900">{{ $nfcCard->last_used_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($nfcCard->notes)
                        <div class="sm:col-span-3">
                            <dt class="text-gray-500">Notes</dt>
                            <dd class="mt-1 text-gray-900">{{ $nfcCard->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">Punch history ({{ $events->count() }} shown, latest 200)</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">When</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employee</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Event</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Terminal</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($events as $event)
                            <tr>
                                <td class="px-6 py-3 text-gray-900">{{ $event->occurred_at->format('Y-m-d') }} <x-time :value="$event->occurred_at" seconds /></td>
                                <td class="px-6 py-3 text-gray-500">
                                    {{ $event->employee?->user?->name ?? '—' }}
                                    <span class="text-xs text-gray-400">{{ $event->employee?->employee_code }}</span>
                                </td>
                                <td class="px-6 py-3 text-gray-700 font-medium">{{ $event->event_type }}</td>
                                <td class="px-6 py-3 text-gray-500">{{ $event->terminal?->name ?? '—' }}</td>
                                <td class="px-6 py-3 text-gray-500">{{ $event->source }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No punches recorded with this card.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
