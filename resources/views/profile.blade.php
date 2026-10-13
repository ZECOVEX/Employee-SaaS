<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @php
                // Post-Phase 20 — role-scoped profile editing, one @php block:
                // identity editors manage photo/phone; employees manage their
                // emergency contact (synced to the employee record admins see).
                $profileUser = auth()->user();
                $canEditIdentity = $profileUser->is_platform_admin || $profileUser->canPermission('employees.edit');
                $employeeProfile = $profileUser->employee;
                $recentLogins = $profileUser->loginHistory()->limit(5)->get();
            @endphp
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            @if ($canEditIdentity)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl" x-data="{ preview: null }">
                        <h3 class="text-lg font-medium text-gray-900">Photo &amp; phone</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Upload a profile photo (JPG or PNG, max 2 MB) and a contact phone number.
                            The photo appears next to your name across the app.
                        </p>

                        @if (session('status') === 'profile-updated')
                            <p class="mt-3 rounded-md bg-green-50 p-3 text-sm text-green-700">
                                Profile updated.
                            </p>
                        @endif

                        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="mt-6 space-y-6">
                            @csrf
                            @method('PUT')

                            <div class="flex items-center gap-4">
                                <template x-if="preview">
                                    <img :src="preview" alt="Avatar preview" class="h-20 w-20 rounded-full object-cover" />
                                </template>
                                <template x-if="! preview">
                                    <x-avatar size="lg" />
                                </template>

                                <div class="flex-1 space-y-2">
                                    <input type="file" name="avatar" accept="image/*" x-ref="avatarInput"
                                           @@change="preview = $refs.avatarInput.files.length ? URL.createObjectURL($refs.avatarInput.files[0]) : null"
                                           class="block w-full text-sm text-gray-600" data-test="avatar-input" />
                                    <x-input-error class="mt-0" :messages="$errors->get('avatar')" />

                                    @if ($profileUser->avatar_path)
                                        <label class="flex items-center gap-2 text-sm text-gray-600">
                                            <input type="checkbox" name="avatar_remove" value="1"
                                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                            Remove current photo
                                        </label>
                                    @endif
                                </div>
                            </div>

                            <div>
                                <x-input-label for="phone" value="Phone" />
                                <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                              :value="old('phone', $profileUser->phone)" placeholder="+1 555 0100"
                                              autocomplete="tel" data-test="phone-input" />
                                <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                            </div>

                            <x-primary-button data-test="profile-save">Save</x-primary-button>
                        </form>
                    </div>
                </div>
            @else
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        <h3 class="text-lg font-medium text-gray-900">Emergency contact</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Your name, email, photo and phone are managed by HR. You can keep your
                            emergency contact up to date here — it is stored on your employee record,
                            so administrators see it on your employee profile.
                        </p>

                        @if (session('status') === 'profile-updated')
                            <p class="mt-3 rounded-md bg-green-50 p-3 text-sm text-green-700">
                                Emergency contact updated.
                            </p>
                        @endif

                        @if ($employeeProfile)
                            <form method="POST" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
                                @csrf
                                @method('PUT')

                                <div>
                                    <x-input-label for="emergency_contact_name" value="Contact name" />
                                    <x-text-input id="emergency_contact_name" name="emergency_contact_name" type="text"
                                                  class="mt-1 block w-full"
                                                  :value="old('emergency_contact_name', $employeeProfile->emergency_contact_name)"
                                                  data-test="ec-name" />
                                    <x-input-error class="mt-2" :messages="$errors->get('emergency_contact_name')" />
                                </div>

                                <div>
                                    <x-input-label for="emergency_contact_phone" value="Contact phone" />
                                    <x-text-input id="emergency_contact_phone" name="emergency_contact_phone" type="text"
                                                  class="mt-1 block w-full"
                                                  :value="old('emergency_contact_phone', $employeeProfile->emergency_contact_phone)"
                                                  autocomplete="tel" data-test="ec-phone" />
                                    <x-input-error class="mt-2" :messages="$errors->get('emergency_contact_phone')" />
                                </div>

                                <x-primary-button data-test="ec-save">Save</x-primary-button>
                            </form>
                        @else
                            <p class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-600" data-test="ec-none">
                                No employee profile is linked to your account, so there is no emergency contact to edit.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

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

            {{-- Post-Phase 21 — opt-in login-alert emails (IP, location, device). --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" data-test="login-alerts-card">
                <form method="POST" action="{{ route('profile.login-alerts') }}">
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Login alerts</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Email me when someone signs in to my account — including the IP address,
                        approximate location and device of the sign-in.
                    </p>

                    <label class="flex items-center gap-3 text-sm text-gray-700">
                        <input type="checkbox" name="enabled" value="1"
                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                               {{ $profileUser->login_alerts_enabled ? 'checked' : '' }}
                               data-test="login-alerts-toggle" data-test-state="{{ $profileUser->login_alerts_enabled ? 'on' : 'off' }}">
                        <span>Email me about new sign-ins</span>
                    </label>

                    <x-primary-button class="mt-4" data-test="login-alerts-save">Save</x-primary-button>
                </form>
            </div>

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
