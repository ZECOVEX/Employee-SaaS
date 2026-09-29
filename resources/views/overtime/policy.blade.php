<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Overtime Policy</h2>
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

            <form action="{{ route('overtime-policy.update') }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Detection</h3>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $policy->exists ? $policy->enabled : true))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Overtime enabled
                        </label>
                    </div>

                    <p class="text-sm text-gray-500 mb-4">
                        Effective since
                        <span class="font-medium text-gray-700">{{ $policy->effective_from?->format('d M Y') ?? 'now (defaults)' }}</span>.
                        Each date is calculated with the policy version in force on that date — history is never rewritten.
                    </p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mode" value="Overtime mode" />
                            <x-select-input id="mode" name="mode" class="mt-1 block w-full">
                                <option value="ABOVE_EXPECTED_HOURS" @selected(old('mode', $policy->mode ?? 'ABOVE_EXPECTED_HOURS') === 'ABOVE_EXPECTED_HOURS')>
                                    Above expected hours — worked minus scheduled
                                </option>
                                <option value="AFTER_OFFICE_END" @selected(old('mode', $policy->mode ?? '') === 'AFTER_OFFICE_END')>
                                    After office end — only time after the shift
                                </option>
                            </x-select-input>
                            <x-input-error :messages="$errors->get('mode')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="max_shift_minutes" value="Max shift guard (minutes)" />
                            <x-text-input id="max_shift_minutes" name="max_shift_minutes" type="number" min="60" max="2880" class="mt-1 block w-full"
                                          :value="old('max_shift_minutes', $policy->max_shift_minutes ?? 960)" required />
                            <x-input-error :messages="$errors->get('max_shift_minutes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <div>
                            <x-input-label for="start_threshold_minutes" value="Start threshold (min)" />
                            <x-text-input id="start_threshold_minutes" name="start_threshold_minutes" type="number" min="0" max="720" class="mt-1 block w-full"
                                          :value="old('start_threshold_minutes', $policy->start_threshold_minutes ?? 15)" required />
                            <x-input-error :messages="$errors->get('start_threshold_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="rounding_minutes" value="Rounding (min)" />
                            <x-text-input id="rounding_minutes" name="rounding_minutes" type="number" min="1" max="720" class="mt-1 block w-full"
                                          :value="old('rounding_minutes', $policy->rounding_minutes ?? 15)" required />
                            <x-input-error :messages="$errors->get('rounding_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="rounding_method" value="Rounding method" />
                            <x-select-input id="rounding_method" name="rounding_method" class="mt-1 block w-full">
                                @foreach (['NEAREST' => 'Nearest', 'UP' => 'Up', 'DOWN' => 'Down'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rounding_method', $policy->rounding_method ?? 'NEAREST') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('rounding_method')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <div>
                            <x-input-label for="daily_cap_minutes" value="Daily cap (min, 0 = none)" />
                            <x-text-input id="daily_cap_minutes" name="daily_cap_minutes" type="number" min="0" max="1440" class="mt-1 block w-full"
                                          :value="old('daily_cap_minutes', $policy->daily_cap_minutes ?? 120)" required />
                            <x-input-error :messages="$errors->get('daily_cap_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="weekly_cap_minutes" value="Weekly cap (min, 0 = none)" />
                            <x-text-input id="weekly_cap_minutes" name="weekly_cap_minutes" type="number" min="0" max="10080" class="mt-1 block w-full"
                                          :value="old('weekly_cap_minutes', $policy->weekly_cap_minutes ?? 720)" required />
                            <x-input-error :messages="$errors->get('weekly_cap_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="monthly_cap_minutes" value="Monthly cap (min, optional)" />
                            <x-text-input id="monthly_cap_minutes" name="monthly_cap_minutes" type="number" min="0" max="43200" class="mt-1 block w-full"
                                          :value="old('monthly_cap_minutes', $policy->monthly_cap_minutes)" />
                            <x-input-error :messages="$errors->get('monthly_cap_minutes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="offset_late_with_overtime" value="0">
                            <input type="checkbox" name="offset_late_with_overtime" value="1" @checked(old('offset_late_with_overtime', $policy->exists ? $policy->offset_late_with_overtime : false))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Offset late minutes with overtime first
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="weekend_all_overtime" value="0">
                            <input type="checkbox" name="weekend_all_overtime" value="1" @checked(old('weekend_all_overtime', $policy->exists ? $policy->weekend_all_overtime : true))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Weekend/holiday worked = all overtime
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="require_pre_approval" value="0">
                            <input type="checkbox" name="require_pre_approval" value="1" @checked(old('require_pre_approval', $policy->exists ? $policy->require_pre_approval : false))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Require pre-approval
                        </label>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Approval</h3>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="approval_mode" value="Approval mode" />
                            <x-select-input id="approval_mode" name="approval_mode" class="mt-1 block w-full">
                                @foreach ([
                                    'MANAGER' => 'Manager approves (recommended)',
                                    'MANAGER_HR' => 'Manager then HR',
                                    'AUTO' => 'Auto-approve',
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('approval_mode', $policy->approval_mode ?? 'MANAGER') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('approval_mode')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="pending_expiry_days" value="Pending expiry (days)" />
                            <x-text-input id="pending_expiry_days" name="pending_expiry_days" type="number" min="1" max="60" class="mt-1 block w-full"
                                          :value="old('pending_expiry_days', $policy->pending_expiry_days ?? 7)" required />
                            <x-input-error :messages="$errors->get('pending_expiry_days')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="pending_expiry_action" value="On expiry" />
                            <x-select-input id="pending_expiry_action" name="pending_expiry_action" class="mt-1 block w-full">
                                @foreach ([
                                    'ESCALATE' => 'Escalate to admins',
                                    'AUTO_APPROVE' => 'Auto-approve',
                                    'AUTO_REJECT' => 'Auto-reject',
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('pending_expiry_action', $policy->pending_expiry_action ?? 'ESCALATE') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('pending_expiry_action')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Pay &amp; visibility</h3>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="weekday_multiplier" value="Weekday multiplier" />
                            <x-text-input id="weekday_multiplier" name="weekday_multiplier" type="number" step="0.05" min="1" max="5" class="mt-1 block w-full"
                                          :value="old('weekday_multiplier', $policy->weekday_multiplier ?? 1.5)" required />
                            <x-input-error :messages="$errors->get('weekday_multiplier')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="weekend_multiplier" value="Weekend multiplier" />
                            <x-text-input id="weekend_multiplier" name="weekend_multiplier" type="number" step="0.05" min="1" max="5" class="mt-1 block w-full"
                                          :value="old('weekend_multiplier', $policy->weekend_multiplier ?? 2)" required />
                            <x-input-error :messages="$errors->get('weekend_multiplier')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="holiday_multiplier" value="Holiday multiplier" />
                            <x-text-input id="holiday_multiplier" name="holiday_multiplier" type="number" step="0.05" min="1" max="5" class="mt-1 block w-full"
                                          :value="old('holiday_multiplier', $policy->holiday_multiplier ?? 2)" required />
                            <x-input-error :messages="$errors->get('holiday_multiplier')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-4 gap-4 mt-4">
                        <div>
                            <x-input-label for="night_window_start" value="Night window start" />
                            <x-text-input id="night_window_start" name="night_window_start" type="time" class="mt-1 block w-full"
                                          :value="old('night_window_start', $policy->night_window_start ? substr($policy->night_window_start, 0, 5) : '')" />
                            <x-input-error :messages="$errors->get('night_window_start')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="night_window_end" value="Night window end" />
                            <x-text-input id="night_window_end" name="night_window_end" type="time" class="mt-1 block w-full"
                                          :value="old('night_window_end', $policy->night_window_end ? substr($policy->night_window_end, 0, 5) : '')" />
                            <x-input-error :messages="$errors->get('night_window_end')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="night_multiplier" value="Night multiplier" />
                            <x-text-input id="night_multiplier" name="night_multiplier" type="number" step="0.05" min="1" max="5" class="mt-1 block w-full"
                                          :value="old('night_multiplier', $policy->night_multiplier)" />
                            <x-input-error :messages="$errors->get('night_multiplier')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="rate_base" value="Rate base" />
                            <x-select-input id="rate_base" name="rate_base" class="mt-1 block w-full">
                                @foreach ([
                                    'GROSS' => 'Gross salary',
                                    'BASIC' => 'Basic salary',
                                    'CUSTOM' => 'Basic + allowances',
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('rate_base', $policy->rate_base ?? 'GROSS') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('rate_base')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <div>
                            <x-input-label for="compensation_type" value="Compensation" />
                            <x-select-input id="compensation_type" name="compensation_type" class="mt-1 block w-full">
                                @foreach (['PAID' => 'Paid (overtime pay)', 'COMP_TIME' => 'Comp time (time off instead)'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('compensation_type', $policy->compensation_type ?? 'PAID') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('compensation_type')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="overtime_score_bonus_max" value="Score bonus cap (+points)" />
                            <x-text-input id="overtime_score_bonus_max" name="overtime_score_bonus_max" type="number" min="0" max="10" class="mt-1 block w-full"
                                          :value="old('overtime_score_bonus_max', $policy->overtime_score_bonus_max ?? 0)" required />
                            <x-input-error :messages="$errors->get('overtime_score_bonus_max')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="show_pay_to_employee" value="0">
                            <input type="checkbox" name="show_pay_to_employee" value="1" @checked(old('show_pay_to_employee', $policy->exists ? $policy->show_pay_to_employee : true))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Show pay to employees
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="show_hours_to_employee" value="0">
                            <input type="checkbox" name="show_hours_to_employee" value="1" @checked(old('show_hours_to_employee', $policy->exists ? $policy->show_hours_to_employee : true))
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Show hours to employees
                        </label>
                    </div>
                </div>

                <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    Save Overtime Policy
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
