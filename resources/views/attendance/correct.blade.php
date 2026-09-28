<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Correct Attendance</h2>
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

            @php($orgTz = auth()->user()->organization?->timezone ?? 'UTC')

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800">Enter check-in &amp; check-out</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Record both times for one day in a single step. Times are in your organization
                    timezone ({{ $orgTz }}) — leave a field empty if only one of the two was missed.
                </p>

                <form action="{{ route('attendance.inout') }}" method="POST" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="inout_employee_id" value="Employee" />
                        <select id="inout_employee_id" name="inout_employee_id" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Select employee…</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('inout_employee_id') == $employee->id)>
                                    {{ $employee->employee_code }} — {{ $employee->user?->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('inout_employee_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="inout_date" value="Date" />
                        <x-text-input id="inout_date" name="inout_date" type="date" class="mt-1 block w-full"
                                      :value="old('inout_date', now($orgTz)->toDateString())" required />
                        <x-input-error :messages="$errors->get('inout_date')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="inout_check_in" value="Check-in time" />
                            <x-text-input id="inout_check_in" name="inout_check_in" type="time" class="mt-1 block w-full"
                                          :value="old('inout_check_in')" />
                            <x-input-error :messages="$errors->get('inout_check_in')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="inout_check_out" value="Check-out time" />
                            <x-text-input id="inout_check_out" name="inout_check_out" type="time" class="mt-1 block w-full"
                                          :value="old('inout_check_out')" />
                            <x-input-error :messages="$errors->get('inout_check_out')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="inout_notes" value="Reason (required)" />
                        <textarea id="inout_notes" name="inout_notes" rows="3" required
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" placeholder="e.g. badge not working, forgot to tap">{{ old('inout_notes') }}</textarea>
                        <x-input-error :messages="$errors->get('inout_notes')" class="mt-2" />
                    </div>

                    <div class="flex space-x-3">
                        <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Save Attendance
                        </button>
                        <a href="{{ route('attendance.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800">Add a single event</h3>
                <p class="mt-1 text-sm text-gray-500">
                    For one-off entries such as a break start/end or a single missed tap.
                </p>

                <form action="{{ route('attendance.store') }}" method="POST" class="mt-4 space-y-4">
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
