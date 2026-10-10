<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Scoring &amp; Risk</h2>
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

            <form action="{{ route('scoring-settings.update') }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-1">Score weights</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        The attendance score formula (§16): component × weight, summed. Weights should total 100 —
                        the final score shows the full breakdown either way.
                    </p>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <x-input-label for="score_weight_attendance" value="Attendance Rate (%)" />
                            <x-text-input id="score_weight_attendance" name="score_weight_attendance" type="number" min="0" max="100"
                                          class="mt-1 block w-full" :value="old('score_weight_attendance', $config['score_weight_attendance'])" required />
                            <x-input-error :messages="$errors->get('score_weight_attendance')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="score_weight_punctuality" value="Punctuality (%)" />
                            <x-text-input id="score_weight_punctuality" name="score_weight_punctuality" type="number" min="0" max="100"
                                          class="mt-1 block w-full" :value="old('score_weight_punctuality', $config['score_weight_punctuality'])" required />
                            <x-input-error :messages="$errors->get('score_weight_punctuality')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="score_weight_working_hours" value="Working Hours (%)" />
                            <x-text-input id="score_weight_working_hours" name="score_weight_working_hours" type="number" min="0" max="100"
                                          class="mt-1 block w-full" :value="old('score_weight_working_hours', $config['score_weight_working_hours'])" required />
                            <x-input-error :messages="$errors->get('score_weight_working_hours')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="score_weight_leave" value="Leave Compliance (%)" />
                            <x-text-input id="score_weight_leave" name="score_weight_leave" type="number" min="0" max="100"
                                          class="mt-1 block w-full" :value="old('score_weight_leave', $config['score_weight_leave'])" required />
                            <x-input-error :messages="$errors->get('score_weight_leave')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-1">Status labels &amp; thresholds</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Score bands (§15). The first band whose minimum the score meets wins; thresholds must descend
                        and the lowest band starts at 0.
                    </p>

                    <div class="space-y-3">
                        @foreach ($config['risk_bands'] as $index => $band)
                            <div class="grid grid-cols-5 gap-3 items-center">
                                <div class="col-span-2">
                                    <x-input-label :for="'risk_bands_'.$index.'_label'" value="Status label" />
                                    <x-text-input :id="'risk_bands_'.$index.'_label'" :name="'risk_bands['.$index.'][label]'" type="text" class="mt-1 block w-full" maxlength="40"
                                                  :value="old('risk_bands.'.$index.'.label', $band['label'])" required />
                                    <x-input-error :messages="$errors->get('risk_bands.'.$index.'.label')" class="mt-2" />
                                </div>
                                <div class="col-span-2">
                                    <x-input-label :for="'risk_bands_'.$index.'_min'" value="Score ≥" />
                                    <x-text-input :id="'risk_bands_'.$index.'_min'" :name="'risk_bands['.$index.'][min]'" type="number" min="0" max="100"
                                                  class="mt-1 block w-full" :value="old('risk_bands.'.$index.'.min', $band['min'])" required
                                                  :readonly="$index === count($config['risk_bands']) - 1" />
                                    <x-input-error :messages="$errors->get('risk_bands.'.$index.'.min')" class="mt-2" />
                                </div>
                                <div class="text-xs text-gray-500">
                                    @if ($index === count($config['risk_bands']) - 1)
                                        catch-all
                                    @else
                                        else ↓
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-start justify-between mb-1">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Weekly late-risk rule</h3>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="late_risk_enabled" value="0">
                            <input type="checkbox" name="late_risk_enabled" value="1"
                                   @checked(old('late_risk_enabled', $config['late_risk_enabled'] ? '1' : '0') === '1')
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Enabled
                        </label>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">
                        When an employee reaches the late-day threshold within the evaluation window the system
                        creates a high-risk attendance event and notifies the selected recipients (§15).
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="late_risk_threshold" value="Late-day threshold" />
                            <x-text-input id="late_risk_threshold" name="late_risk_threshold" type="number" min="1" max="30"
                                          class="mt-1 block w-full" :value="old('late_risk_threshold', $config['late_risk_threshold'])" required />
                            <p class="mt-1 text-xs text-gray-500">Late days ≥ this value trigger the rule.</p>
                            <x-input-error :messages="$errors->get('late_risk_threshold')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="late_risk_period" value="Evaluation period" />
                            <x-select-input id="late_risk_period" name="late_risk_period" class="mt-1 block w-full">
                                <option value="calendar_week" @selected(old('late_risk_period', $config['late_risk_period']) === 'calendar_week')>Calendar week (Mon–Sun)</option>
                                <option value="rolling_7" @selected(old('late_risk_period', $config['late_risk_period']) === 'rolling_7')>Rolling 7 days</option>
                            </x-select-input>
                            <x-input-error :messages="$errors->get('late_risk_period')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="late_risk_level" value="Risk level label" />
                            <x-text-input id="late_risk_level" name="late_risk_level" type="text" maxlength="40"
                                          class="mt-1 block w-full" :value="old('late_risk_level', $config['late_risk_level'])" required />
                            <x-input-error :messages="$errors->get('late_risk_level')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div>
                            <span class="text-sm font-medium text-gray-700">Notification recipients</span>
                            <div class="mt-2 space-y-1">
                                @foreach (['employee' => 'The employee', 'manager' => 'Their manager', 'hr' => 'HR users'] as $key => $label)
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="late_risk_recipients[]" value="{{ $key }}"
                                               @checked(in_array($key, old('late_risk_recipients', $config['late_risk_recipients']), true))
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('late_risk_recipients')" class="mt-2" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Repeats</span>
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="hidden" name="late_risk_suppress" value="0">
                                <input type="checkbox" name="late_risk_suppress" value="1"
                                       @checked(old('late_risk_suppress', $config['late_risk_suppress'] ? '1' : '0') === '1')
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                Suppress repeated notifications for the same window
                            </label>
                            <x-input-error :messages="$errors->get('late_risk_suppress')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-start justify-between mb-1">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Overtime overwork notice <span class="text-gray-400 font-normal">[OT]</span></h3>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="ot_risk_enabled" value="0">
                            <input type="checkbox" name="ot_risk_enabled" value="1"
                                   @checked(old('ot_risk_enabled', $config['ot_risk_enabled'] ? '1' : '0') === '1')
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Enabled (default off)
                        </label>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">
                        Informational compliance signal when overtime exceeds a configured limit (§15 [OT]).
                        It never reduces the employee's score.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-input-label for="ot_risk_weekly_minutes" value="Weekly limit (minutes)" />
                            <x-text-input id="ot_risk_weekly_minutes" name="ot_risk_weekly_minutes" type="number" min="60" max="10080"
                                          class="mt-1 block w-full" :value="old('ot_risk_weekly_minutes', $config['ot_risk_weekly_minutes'])" required />
                            <p class="mt-1 text-xs text-gray-500">720 = 12h, the spec example.</p>
                            <x-input-error :messages="$errors->get('ot_risk_weekly_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="ot_risk_monthly_minutes" value="Monthly limit (minutes, optional)" />
                            <x-text-input id="ot_risk_monthly_minutes" name="ot_risk_monthly_minutes" type="number" min="60" max="43200"
                                          class="mt-1 block w-full" :value="old('ot_risk_monthly_minutes', $config['ot_risk_monthly_minutes'])" placeholder="off" />
                            <x-input-error :messages="$errors->get('ot_risk_monthly_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="ot_risk_streak_days" value="Consecutive overtime days (optional)" />
                            <x-text-input id="ot_risk_streak_days" name="ot_risk_streak_days" type="number" min="2" max="31"
                                          class="mt-1 block w-full" :value="old('ot_risk_streak_days', $config['ot_risk_streak_days'])" placeholder="off" />
                            <x-input-error :messages="$errors->get('ot_risk_streak_days')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                        <div>
                            <x-input-label for="ot_risk_level" value="Notice label" />
                            <x-text-input id="ot_risk_level" name="ot_risk_level" type="text" maxlength="40"
                                          class="mt-1 block w-full" :value="old('ot_risk_level', $config['ot_risk_level'])" required />
                            <x-input-error :messages="$errors->get('ot_risk_level')" class="mt-2" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Notification recipients</span>
                            <div class="mt-2 space-y-1">
                                @foreach (['employee' => 'The employee', 'manager' => 'Their manager', 'hr' => 'HR users'] as $key => $label)
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="ot_risk_recipients[]" value="{{ $key }}"
                                               @checked(in_array($key, old('ot_risk_recipients', $config['ot_risk_recipients']), true))
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('ot_risk_recipients')" class="mt-2" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Repeats</span>
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="hidden" name="ot_risk_suppress" value="0">
                                <input type="checkbox" name="ot_risk_suppress" value="1"
                                       @checked(old('ot_risk_suppress', $config['ot_risk_suppress'] ? '1' : '0') === '1')
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                Suppress repeated notices for the same window
                            </label>
                            <x-input-error :messages="$errors->get('ot_risk_suppress')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    Save Scoring &amp; Risk Settings
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
