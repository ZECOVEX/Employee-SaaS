<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Company Settings</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-4 flex flex-wrap gap-3 text-sm">
                <span class="font-medium text-gray-700">Company info</span>
                <a href="{{ route('schedule.edit') }}" class="text-indigo-600 hover:underline">Working hours</a>
                <a href="{{ route('overtime-policy.edit') }}" class="text-indigo-600 hover:underline">Overtime policy</a>
                <a href="{{ route('scoring-settings.edit') }}" class="text-indigo-600 hover:underline">Scoring &amp; risk</a>
                <a href="{{ route('mail-settings.edit') }}" class="text-indigo-600 hover:underline">Email</a>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="name" value="Company name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $organization->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-sm font-semibold text-gray-800">Company logo</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            Shown beside the company name in the sidebar. JPEG, PNG, WebP or SVG up to 2 MB.
                        </p>
                        <div class="mt-3 flex items-center gap-4">
                            @if ($organization->logo_path)
                                <img src="{{ asset('storage/'.$organization->logo_path) }}" alt=""
                                     class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-gray-200" />
                            @endif
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml"
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200" />
                        </div>
                        @if ($organization->logo_path)
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="remove_logo" value="1" @checked(old('remove_logo') === '1') />
                                Remove logo
                            </label>
                        @endif
                        <x-input-error :messages="$errors->get('logo')" class="mt-2" />
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
                    <div>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="self_attendance_enabled" value="0">
                            <input type="checkbox" name="self_attendance_enabled" value="1"
                                   @checked(old('self_attendance_enabled', $organization->selfAttendanceEnabled() ? '1' : '0') === '1') />
                            Allow employees to self-report attendance
                        </label>
                        <p class="mt-1 text-xs text-gray-500">
                            Fallback for when an NFC terminal is down: employees submit their own check-in and check-out
                            time once each per day from My Attendance. Submissions cannot be edited afterwards.
                        </p>
                    </div>
                    <div class="text-sm text-gray-500">
                        Slug: <code>{{ $organization->slug }}</code> ·
                        Status: <span class="capitalize">{{ $organization->status }}</span>
                    </div>

                    <hr class="border-gray-200" />

                    <div>
                        <h4 class="text-sm font-semibold text-gray-800">Salary &amp; deductions</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            Rules used for the monthly salary estimate, late/absence deductions and payslips.
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

                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="text-sm font-semibold text-gray-800 mb-3">Data retention (privacy)</h3>
                        <p class="mb-3 text-xs text-gray-500">
                            Windows used by the daily <code>privacy:prune</code> job. Enter 0 to use the
                            platform defaults (audit 365 days, login history 180 days). See the
                            <a href="{{ route('privacy') }}" class="text-indigo-600 hover:underline" target="_blank">privacy policy</a>.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="audit_retention_days" value="Audit log retention (days)" />
                                <x-text-input id="audit_retention_days" name="audit_retention_days" type="number" min="0" max="3650"
                                    class="mt-1 block w-full"
                                    :value="old('audit_retention_days', $organization->settings['audit_retention_days'] ?? 0)" />
                                <x-input-error :messages="$errors->get('audit_retention_days')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="login_retention_days" value="Login history retention (days)" />
                                <x-text-input id="login_retention_days" name="login_retention_days" type="number" min="0" max="3650"
                                    class="mt-1 block w-full"
                                    :value="old('login_retention_days', $organization->settings['login_retention_days'] ?? 0)" />
                                <x-input-error :messages="$errors->get('login_retention_days')" class="mt-2" />
                            </div>
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
