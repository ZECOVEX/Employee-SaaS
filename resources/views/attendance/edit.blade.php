<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Attendance Entry</h2>
                        <a href="{{ route('attendance.index', ['date' => $event->occurred_at->toDateString()]) }}"
               class="text-sm text-indigo-600 hover:underline" wire:navigate>← Back to attendance</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-6">
                    <div>
                        <dt class="text-gray-500">Employee</dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $event->employee?->user?->name }}
                            <span class="text-xs text-gray-400">{{ $event->employee?->employee_code }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Recorded</dt>
                        <dd class="mt-1 text-gray-900">{{ $event->occurred_at->format('D, M j Y') }} <x-time :value="$event->occurred_at" /> ({{ $tz }})</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Type</dt>
                        <dd class="mt-1 font-semibold {{ str_contains($event->event_type, 'OUT') || str_contains($event->event_type, 'START') ? 'text-red-600' : 'text-green-600' }}">{{ $event->event_type }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Source</dt>
                        <dd class="mt-1 text-gray-700">{{ $event->source }}{{ $event->terminal?->name ? ' · '.$event->terminal->name : '' }}</dd>
                    </div>
                    @if ($event->notes)
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Original note</dt>
                            <dd class="mt-1 text-gray-700">{{ $event->notes }}</dd>
                        </div>
                    @endif
                </dl>

                <p class="mb-4 rounded-md bg-blue-50 p-3 text-xs text-blue-800">
                    Attendance events are never overwritten. Saving creates a replacement entry,
                    archives this one (kept for audit) and recalculates the day — including any
                    day this entry moves to.
                </p>

                <form action="{{ route('attendance.events.update', $event) }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="event_type" value="Event type" />
                        <select id="event_type" name="event_type" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach (['CHECK_IN', 'CHECK_OUT', 'MANUAL_IN', 'MANUAL_OUT', 'BREAK_START', 'BREAK_END'] as $type)
                                <option value="{{ $type }}" @selected(old('event_type', $event->event_type) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('event_type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="occurred_at" value="Date & time" />
                        <x-text-input id="occurred_at" name="occurred_at" type="datetime-local" class="mt-1 block w-full"
                                      :value="old('occurred_at', $event->occurred_at->format('Y-m-d\TH:i'))" required />
                        <x-input-error :messages="$errors->get('occurred_at')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" value="Reason for the correction (required)" />
                        <textarea id="notes" name="notes" rows="3" required
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes', $event->notes) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex space-x-3">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Save Correction
                        </button>
            <a href="{{ route('attendance.index', ['date' => $event->occurred_at->toDateString()]) }}"
                           class="px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-1">Remove entry</h3>
                <p class="mb-4 text-sm text-gray-500">
                    Use this if the entry should not exist at all (e.g. a duplicate tap).
                    The day is recalculated from the remaining events and the removal is audited.
                </p>
                <form action="{{ route('attendance.events.destroy', $event) }}" method="POST" class="space-y-4"
                      onsubmit="return confirm('Remove this attendance entry?');">
                    @csrf
                    @method('DELETE')
                    <div>
                        <x-input-label for="reason" value="Reason (required)" />
                        <textarea id="reason" name="reason" rows="2" required
                                  class="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm" placeholder="e.g. duplicate tap, wrong card"></textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500">
                        Remove Entry
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
