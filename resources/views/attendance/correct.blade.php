<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Correct Attendance</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('attendance.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="employee_id" value="Employee" />
                        <select id="employee_id" name="employee_id" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Select employee…</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                    {{ $employee->employee_code }} — {{ $employee->user?->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="event_type" value="Event type" />
                        <select id="event_type" name="event_type" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="MANUAL_IN" @selected(old('event_type') === 'MANUAL_IN')>Manual check-in</option>
                            <option value="MANUAL_OUT" @selected(old('event_type') === 'MANUAL_OUT')>Manual check-out</option>
                        </select>
                        <x-input-error :messages="$errors->get('event_type')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="occurred_at" value="Date & time" />
                        <x-text-input id="occurred_at" name="occurred_at" type="datetime-local" class="mt-1 block w-full"
                                      :value="old('occurred_at')" required />
                        <x-input-error :messages="$errors->get('occurred_at')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notes" value="Reason (required)" />
                        <textarea id="notes" name="notes" rows="3" required
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex space-x-3">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Record Correction
                        </button>
                        <a href="{{ route('attendance.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
