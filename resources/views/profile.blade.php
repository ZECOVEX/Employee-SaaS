<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.mfa-form />
                </div>
            </div>

            @php
                $profileUser = auth()->user();
                $recentLogins = $profileUser->loginHistory()->limit(5)->get();
            @endphp
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900 mb-1">Security information</h3>
                <p class="text-sm text-gray-600 mb-4">
                    Last successful login and your most recent attempts. Locations are approximate
                    IP geolocation, stored only for security monitoring (see the privacy policy).
                </p>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 text-sm">
                    <div>
                        <dt class="text-gray-500">Last login</dt>
                        <dd class="text-gray-900">{{ $profileUser->last_login_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Last IP</dt>
                        <dd class="text-gray-900">{{ $profileUser->last_login_ip ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Last location</dt>
                        <dd class="text-gray-900">
                            @if ($profileUser->last_login_geo_division || $profileUser->last_login_geo_area)
                                {{ collect([$profileUser->last_login_geo_area, $profileUser->last_login_geo_division])->filter()->implode(', ') }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">When</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">IP</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">Location</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentLogins as $entry)
                            <tr>
                                <td class="px-4 py-2 text-gray-700">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $entry->login_status === 'SUCCESS' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $entry->login_status }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-700">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-700">{{ $entry->locationLabel() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-4 text-center text-gray-500">No recent logins recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- §43 — self-service deletion is prohibited; this section holds
                 the admin-contact note + the self data-export download. --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
