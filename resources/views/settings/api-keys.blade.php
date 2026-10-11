<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">API &amp; Provisioning</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('new_api_key'))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                    <p class="text-sm font-medium text-amber-800">New API key (copy it now — it will not be shown again):</p>
                    <code class="mt-1 block break-all text-sm text-amber-900 select-all">{{ session('new_api_key') }}</code>
                </div>
            @endif

            @if (session('new_scim_token'))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                    <p class="text-sm font-medium text-amber-800">New SCIM token (copy it now — it will not be shown again):</p>
                    <code class="mt-1 block break-all text-sm text-amber-900 select-all">{{ session('new_scim_token') }}</code>
                </div>
            @endif

            {{-- API keys --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">API keys</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Keys authenticate against <code>/api/v1</code> via the <code>X-API-Key</code> header (or bearer token).
                    Restrict each key to the permission keys it needs.
                </p>

                <form action="{{ route('api-keys.store') }}" method="POST" class="mt-4 space-y-3 border-b border-gray-100 pb-6">
                    @csrf
                    <div>
                        <x-input-label for="key_name" value="Key name" />
                        <x-text-input id="key_name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required placeholder="e.g. Payroll integration" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label value="Abilities (leave all unchecked for full org access)" />
                        <div class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-1 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="abilities[]" value="*" class="rounded border-gray-300 text-indigo-600"
                                       @checked(old('abilities') === null || in_array('*', (array) old('abilities', []), true))>
                                <span class="font-medium">* — all org abilities</span>
                            </label>
                            @foreach ($catalog as $key => [$label, $group])
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="abilities[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600"
                                           @checked(in_array($key, (array) old('abilities', []), true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('abilities')" class="mt-2" />
                    </div>

                    <x-primary-button>Create key</x-primary-button>
                </form>

                <table class="mt-4 w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-100">
                        <tr>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Abilities</th>
                            <th class="py-2 pr-4">Last used</th>
                            <th class="py-2 pr-4">Created by</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($keys as $apiKey)
                            <tr class="border-b border-gray-50 @if ($apiKey->revoked_at) opacity-50 @endif">
                                <td class="py-2 pr-4 font-medium">
                                    {{ $apiKey->name }}
                                    @if ($apiKey->revoked_at)
                                        <span class="text-xs text-red-600">(revoked)</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600">{{ $apiKey->abilities === null || in_array('*', $apiKey->abilities ?? [], true) ? '*' : implode(', ', $apiKey->abilities) }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $apiKey->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $apiKey->creator?->name ?? '—' }}</td>
                                <td class="py-2 text-right">
                                    @unless ($apiKey->revoked_at)
                                        <form action="{{ route('api-keys.destroy', $apiKey) }}" method="POST" onsubmit="return confirm('Revoke this key?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-600 hover:underline">Revoke</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-gray-500">No API keys yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- SCIM tokens --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">SCIM 2.0 tokens</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Bearer tokens for <code>/api/scim/v2</code> user &amp; group provisioning (Users map to accounts, Groups to departments).
                </p>

                <form action="{{ route('scim-tokens.store') }}" method="POST" class="mt-4 flex items-end gap-3 border-b border-gray-100 pb-6">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="token_name" value="Token name" />
                        <x-text-input id="token_name" name="token_name" type="text" class="mt-1 block w-full" :value="old('token_name')" required placeholder="e.g. Okta SCIM" />
                        <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
                    </div>
                    <x-primary-button>Create token</x-primary-button>
                </form>

                <table class="mt-4 w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-100">
                        <tr>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Last used</th>
                            <th class="py-2 pr-4">Created by</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tokens as $token)
                            <tr class="border-b border-gray-50 @if ($token->revoked_at) opacity-50 @endif">
                                <td class="py-2 pr-4 font-medium">
                                    {{ $token->name }}
                                    @if ($token->revoked_at)
                                        <span class="text-xs text-red-600">(revoked)</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $token->creator?->name ?? '—' }}</td>
                                <td class="py-2 text-right">
                                    @unless ($token->revoked_at)
                                        <form action="{{ route('scim-tokens.destroy', $token) }}" method="POST" onsubmit="return confirm('Revoke this token?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-red-600 hover:underline">Revoke</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-gray-500">No SCIM tokens yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
