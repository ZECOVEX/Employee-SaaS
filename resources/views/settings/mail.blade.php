<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Email (SMTP)</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->has('status'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ $errors->first('status') }}</div>
            @endif

            <div class="mb-4 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('settings.edit') }}" class="text-indigo-600 hover:underline">Company info</a>
                <a href="{{ route('sso.edit') }}" class="text-indigo-600 hover:underline">Single sign-on</a>
                <span class="font-medium text-gray-700">Email</span>
                <a href="{{ route('api-keys.index') }}" class="text-indigo-600 hover:underline">API &amp; provisioning</a>
                <a href="{{ route('webhooks.index') }}" class="text-indigo-600 hover:underline">Webhooks</a>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-500">
                    Send all outgoing email (leave decisions, payslips, security alerts, password resets…)
                    through your own mail server or webmail SMTP gateway (Gmail, Outlook/Exchange, Zoho, a
                    self-hosted relay…). When disabled, the platform default mailer is used.
                </p>

                <form action="{{ route('mail-settings.update') }}" method="POST" class="mt-4 space-y-4">
                    @csrf
                    @method('PUT')

                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="mail_enabled" value="0">
                        <input type="checkbox" name="mail_enabled" value="1" class="rounded border-gray-300 text-indigo-600"
                               @checked(old('mail_enabled', $mail['enabled']))>
                        <span class="text-sm font-medium text-gray-700">Send email through this SMTP server</span>
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <x-input-label for="mail_host" value="SMTP host" />
                            <x-text-input id="mail_host" name="mail_host" type="text" class="mt-1 block w-full"
                                          :value="old('mail_host', $mail['host'])" required placeholder="smtp.gmail.com" />
                            <x-input-error :messages="$errors->get('mail_host')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="mail_port" value="Port" />
                            <x-text-input id="mail_port" name="mail_port" type="number" min="1" max="65535" class="mt-1 block w-full"
                                          :value="old('mail_port', $mail['port'])" required />
                            <x-input-error :messages="$errors->get('mail_port')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="mail_encryption" value="Encryption" />
                        <select id="mail_encryption" name="mail_encryption" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                            <option value="tls" @selected(old('mail_encryption', $mail['encryption']) === 'tls')>TLS (STARTTLS — port 587, recommended)</option>
                            <option value="ssl" @selected(old('mail_encryption', $mail['encryption']) === 'ssl')>SSL (implicit TLS — port 465)</option>
                            <option value="none" @selected(old('mail_encryption', $mail['encryption']) === 'none')>None (plain — port 25, local relays)</option>
                        </select>
                        <x-input-error :messages="$errors->get('mail_encryption')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mail_username" value="Username (optional)" />
                            <x-text-input id="mail_username" name="mail_username" type="text" class="mt-1 block w-full"
                                          :value="old('mail_username', $mail['username'])" autocomplete="off" placeholder="noreply@acme.com" />
                            <x-input-error :messages="$errors->get('mail_username')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="mail_password" value="Password / app password" />
                            <x-text-input id="mail_password" name="mail_password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <p class="mt-1 text-xs text-gray-500">{{ $mail['has_password'] ? 'A password is stored (encrypted). Leave blank to keep it.' : 'No password stored yet.' }}</p>
                            <x-input-error :messages="$errors->get('mail_password')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="mail_from_address" value="From address" />
                            <x-text-input id="mail_from_address" name="mail_from_address" type="email" class="mt-1 block w-full"
                                          :value="old('mail_from_address', $mail['from_address'])" required placeholder="noreply@acme.com" />
                            <x-input-error :messages="$errors->get('mail_from_address')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="mail_from_name" value="From name" />
                            <x-text-input id="mail_from_name" name="mail_from_name" type="text" class="mt-1 block w-full"
                                          :value="old('mail_from_name', $mail['from_name'])" required placeholder="Acme Corp" />
                            <x-input-error :messages="$errors->get('mail_from_name')" class="mt-2" />
                        </div>
                    </div>

                    <fieldset class="border-t border-gray-200 pt-4">
                        <legend class="text-sm font-medium text-gray-700">Which emails should be sent?</legend>
                        <p class="mt-1 text-xs text-gray-500">
                            Controls the email copies only — in-app notifications always appear.
                            Untick a feature to stop its emails entirely.
                        </p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($featureLabels as $key => $label)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" name="mail_features[]" value="{{ $key }}"
                                           class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                           @checked(in_array($key, (array) old('mail_features', array_keys(array_filter($features))), true))>
                                    <span class="text-gray-700">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('mail_features')" class="mt-2" />
                        <x-input-error :messages="$errors->get('mail_features.0')" class="mt-2" />
                    </fieldset>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save email settings</x-primary-button>
                    </div>
                </form>

                <form action="{{ route('mail-settings.test') }}" method="POST" class="mt-4">
                    @csrf
                    <button class="text-sm text-indigo-600 hover:underline">Send test email to {{ auth()->user()->email }}</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
