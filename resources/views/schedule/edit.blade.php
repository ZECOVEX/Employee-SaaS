<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Working Hours</h2>
            <a href="{{ route('settings.edit') }}" class="text-sm text-indigo-600 hover:underline">Company settings</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('schedule.update') }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <p class="text-sm text-gray-500">
                        Current schedule effective since
                        <span class="font-medium text-gray-700">{{ $schedule->effective_from?->format('d M Y') ?? 'the beginning' }}</span>.
                        Changes apply from today onward — past days keep the schedule they were derived with.
                    </p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_time" value="Office start" />
                            <x-text-input id="start_time" name="start_time" type="time" class="mt-1 block w-full"
                                          :value="old('start_time', substr($schedule->start_time, 0, 5))" required />
                            <x-input-error :messages="$errors->get('start_time')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_time" value="Office end" />
                            <x-text-input id="end_time" name="end_time" type="time" class="mt-1 block w-full"
                                          :value="old('end_time', substr($schedule->end_time, 0, 5))" required />
                            <x-input-error :messages="$errors->get('end_time')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="grace_minutes" value="Grace period (minutes)" />
                        <x-text-input id="grace_minutes" name="grace_minutes" type="number" min="0" max="120" class="mt-1 block w-full"
                                      :value="old('grace_minutes', $schedule->grace_minutes)" required />
                        <x-input-error :messages="$errors->get('grace_minutes')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="break_start" value="Break start (optional)" />
                            <x-text-input id="break_start" name="break_start" type="time" class="mt-1 block w-full"
                                          :value="old('break_start', $schedule->break_start ? substr($schedule->break_start, 0, 5) : '')" />
                            <x-input-error :messages="$errors->get('break_start')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="break_end" value="Break end" />
                            <x-text-input id="break_end" name="break_end" type="time" class="mt-1 block w-full"
                                          :value="old('break_end', $schedule->break_end ? substr($schedule->break_end, 0, 5) : '')" />
                            <x-input-error :messages="$errors->get('break_end')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Working days" />
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                                <label class="inline-flex items-center gap-1 text-sm text-gray-700">
                                    <input type="checkbox" name="work_days[]" value="{{ $day }}"
                                           @checked(in_array($day, old('work_days', $schedule->workDayNumbers())))
                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('work_days')" class="mt-2" />
                    </div>

                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Save Working Hours
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
