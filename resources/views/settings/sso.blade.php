<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Single Sign-On (OIDC)</h2>
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
                <span class="font-medium text-gray-700">Single sign-on</span>
                <a href="{{ route('mail-settings.edit') }}" class="text-indigo-600 hover:underline">Email</a>
                <a href="{{ route('api-keys.index') }}" class="text-indigo-600 hover:underline">API &amp; provisioning</a>
                <a href="{{ route('webhooks.index') }}" class="text-indigo-600 hover:underline">Webhooks</a>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-500">
                    Let users sign in through your identity provider (Okta, Entra ID, Keycloak, Google Workspace…).
                    When enabled, a "Sign in with SSO" button appears on the login page.
                    Users whose email domain is listed are auto-provisioned on first login (role: employee).
                </p>

                <form action="{{ route('sso.update') }}" method="POST" class="mt-4 space-y-4">
                    @csrf
                    @method('PUT')

                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="sso_enabled" value="0">
                        <input type="checkbox" name="sso_enabled" value="1" class="rounded border-gray-300 text-indigo-600"
                               @checked(old('sso_enabled', $sso['enabled']))>
                        <span class="text-sm font-medium text-gray-700">Enable SSO</span>
                    </label>

                    <div>
                        <x-input-label for="sso_name" value="Button label" />
                        <x-text-input id="sso_name" name="sso_name" type="text" class="mt-1 block w-full" :value="old('sso_name', $sso['name'])" required placeholder="Acme Corp" />
                        <x-input-error :messages="$errors->get('sso_name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="sso_discovery_url" value="Discovery URL (issuer base)" />
                        <x-text-input id="sso_discovery_url" name="sso_discovery_url" type="url" class="mt-1 block w-full"
                                      :value="old('sso_discovery_url', $sso['discovery_url'])" required placeholder="https://login.example.com/realms/acme" />
                        <p class="mt-1 text-xs text-gray-500">We fetch <code>/.well-known/openid-configuration</code> from this base URL.</p>
                        <x-input-error :messages="$errors->get('sso_discovery_url')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="sso_client_id" value="Client ID" />
                            <x-text-input id="sso_client_id" name="sso_client_id" type="text" class="mt-1 block w-full" :value="old('sso_client_id', $sso['client_id'])" required />
                            <x-input-error :messages="$errors->get('sso_client_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="sso_client_secret" value="Client secret" />
                            <x-text-input id="sso_client_secret" name="sso_client_secret" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <p class="mt-1 text-xs text-gray-500">{{ $sso['has_secret'] ? 'A secret is stored (encrypted). Leave blank to keep it.' : 'No secret stored yet.' }}</p>
                            <x-input-error :messages="$errors->get('sso_client_secret')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="sso_allowed_domains" value="Allowed email domains (comma-separated, optional)" />
                        <x-text-input id="sso_allowed_domains" name="sso_allowed_domains" type="text" class="mt-1 block w-full"
                                      :value="old('sso_allowed_domains', implode(', ', (array) ($organization->settings['sso_allowed_domains'] ?? [])))" placeholder="acme.com, acme.co.uk" />
                        <p class="mt-1 text-xs text-gray-500">Leave blank to allow any domain. New users are auto-provisioned with the employee role.</p>
                        <x-input-error :messages="$errors->get('sso_allowed_domains')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save SSO settings</x-primary-button>
                    </div>
                </form>

                <form action="{{ route('sso.test') }}" method="POST" class="mt-4">
                    @csrf
                    <button class="text-sm text-indigo-600 hover:underline">Test discovery document</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
