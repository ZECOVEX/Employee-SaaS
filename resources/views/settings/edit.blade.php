<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Company Settings</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-4 flex gap-3 text-sm">
                <span class="font-medium text-gray-700">Company info</span>
                <a href="{{ route('schedule.edit') }}" class="text-indigo-600 hover:underline">Working hours</a>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('settings.update') }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="name" value="Company name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $organization->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="timezone" value="Timezone" />
                        <x-text-input id="timezone" name="timezone" type="text" class="mt-1 block w-full" :value="old('timezone', $organization->timezone)" required placeholder="Asia/Dhaka" />
                        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="debounce_seconds" value="Double-tap debounce (seconds)" />
                        <x-text-input id="debounce_seconds" name="debounce_seconds" type="number" min="5" max="300" class="mt-1 block w-full"
                                      :value="old('debounce_seconds', $organization->debounceSeconds())" required />
                        <p class="mt-1 text-xs text-gray-500">
                            Repeat taps on the same card and terminal within this window return the original result instead of a new event (10–15s recommended).
                        </p>
                        <x-input-error :messages="$errors->get('debounce_seconds')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="time_format" value="Time format" />
                        <select id="time_format" name="time_format" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="24h" @selected(old('time_format', $organization->timeFormat()) === '24h')>24-hour — 16:00 (international)</option>
                            <option value="12h" @selected(old('time_format', $organization->timeFormat()) === '12h')>12-hour — 4:00 PM (am/pm)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            How clock times are shown across attendance, dashboards and history (check-in/out, punch events, averages).
                        </p>
                        <x-input-error :messages="$errors->get('time_format')" class="mt-2" />
                    </div>
                    <div class="text-sm text-gray-500">
                        Slug: <code>{{ $organization->slug }}</code> ·
                        Status: <span class="capitalize">{{ $organization->status }}</span>
                    </div>

                    <hr class="border-gray-200" />

                    <div>
                        <h4 class="text-sm font-semibold text-gray-800">Salary &amp; deductions</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            Rules used for the monthly salary estimate, late/absence deductions and payslips (§28).
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="currency" value="Currency code" />
                            <x-text-input id="currency" name="currency" type="text" class="mt-1 block w-full" maxlength="8"
                                          :value="old('currency', $organization->currency())" required placeholder="BDT" />
                            <x-input-error :messages="$errors->get('currency')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="deduction_grace_minutes" value="Late deduction grace (minutes)" />
                            <x-text-input id="deduction_grace_minutes" name="deduction_grace_minutes" type="number" min="0" max="240"
                                          class="mt-1 block w-full" :value="old('deduction_grace_minutes', $organization->salaryRules()['deduction_grace_minutes'])" required />
                            <p class="mt-1 text-xs text-gray-500">Late minutes within this grace are not deducted.</p>
                            <x-input-error :messages="$errors->get('deduction_grace_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="late_deduction" value="Late deduction method" />
                            <select id="late_deduction" name="late_deduction" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="per_minute" @selected(old('late_deduction', $organization->salaryRules()['late_deduction']) === 'per_minute')>Per minute (late minutes × hourly rate)</option>
                                <option value="none" @selected(old('late_deduction', $organization->salaryRules()['late_deduction']) === 'none')>No late deduction</option>
                            </select>
                            <x-input-error :messages="$errors->get('late_deduction')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="deduction_rounding" value="Deduction rounding" />
                            <select id="deduction_rounding" name="deduction_rounding" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="exact" @selected(old('deduction_rounding', $organization->salaryRules()['deduction_rounding']) === 'exact')>Exact (2 decimals)</option>
                                <option value="nearest" @selected(old('deduction_rounding', $organization->salaryRules()['deduction_rounding']) === 'nearest')>Nearest whole unit</option>
                                <option value="whole" @selected(old('deduction_rounding', $organization->salaryRules()['deduction_rounding']) === 'whole')>Round down to whole unit</option>
                            </select>
                            <x-input-error :messages="$errors->get('deduction_rounding')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="absence_deduction" value="Absence deduction (ABSENT day)" />
                            <select id="absence_deduction" name="absence_deduction" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="full_day" @selected(old('absence_deduction', $organization->salaryRules()['absence_deduction']) === 'full_day')>Full day rate</option>
                                <option value="half_day" @selected(old('absence_deduction', $organization->salaryRules()['absence_deduction']) === 'half_day')>Half day rate</option>
                                <option value="none" @selected(old('absence_deduction', $organization->salaryRules()['absence_deduction']) === 'none')>No deduction</option>
                            </select>
                            <x-input-error :messages="$errors->get('absence_deduction')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="half_day_deduction" value="Half-day deduction (HALF_DAY)" />
                            <select id="half_day_deduction" name="half_day_deduction" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="half_day" @selected(old('half_day_deduction', $organization->salaryRules()['half_day_deduction']) === 'half_day')>Half day rate</option>
                                <option value="full_day" @selected(old('half_day_deduction', $organization->salaryRules()['half_day_deduction']) === 'full_day')>Full day rate</option>
                                <option value="none" @selected(old('half_day_deduction', $organization->salaryRules()['half_day_deduction']) === 'none')>No deduction</option>
                            </select>
                            <x-input-error :messages="$errors->get('half_day_deduction')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="max_deduction_percent" value="Monthly deduction cap (% of base)" />
                            <x-text-input id="max_deduction_percent" name="max_deduction_percent" type="number" min="0" max="100"
                                          class="mt-1 block w-full" :value="old('max_deduction_percent', $organization->salaryRules()['max_deduction_percent'])" required />
                            <p class="mt-1 text-xs text-gray-500">Total attendance deductions cannot exceed this share of the monthly base salary.</p>
                            <x-input-error :messages="$errors->get('max_deduction_percent')" class="mt-2" />
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="hidden" name="unpaid_leave_deduction" value="0">
                                <input type="checkbox" name="unpaid_leave_deduction" value="1"
                                       @checked(old('unpaid_leave_deduction', $organization->salaryRules()['unpaid_leave_deduction'] ? '1' : '0') === '1') />
                                Deduct unpaid leave at the daily rate
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
