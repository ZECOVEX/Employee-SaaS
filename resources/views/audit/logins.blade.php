<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Login History</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <a href="{{ route('audit-logs.index') }}"
                   class="rounded-full px-3 py-1 text-sm bg-gray-100 text-gray-600 hover:bg-gray-200">Action log</a>
                <a href="{{ route('audit-logs.logins') }}"
                   class="rounded-full px-3 py-1 text-sm bg-indigo-600 text-white font-semibold">Login history</a>
            </div>

            <p class="mb-4 text-sm text-gray-500">
                Locations are best-effort IP geolocation (country / division / area) resolved server-side for
                security monitoring only — approximate, and never the sole basis for blocking access.
            </p>

            <form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
                <div>
                    <label for="f-status" class="block text-xs text-gray-500 mb-1">Status</label>
                    <select id="f-status" name="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All statuses</option>
                        @foreach (['SUCCESS', 'FAILED', 'MFA_FAILED'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-division" class="block text-xs text-gray-500 mb-1">Division</label>
                    <select id="f-division" name="division" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All divisions</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division }}" @selected(request('division') === $division)>{{ $division }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-user" class="block text-xs text-gray-500 mb-1">User</label>
                    <select id="f-user" name="user" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All users</option>
                        @foreach ($users as $candidate)
                            <option value="{{ $candidate->id }}" @selected((string) request('user') === (string) $candidate->id)>{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-from" class="block text-xs text-gray-500 mb-1">From</label>
                    <input type="date" id="f-from" name="from" value="{{ request('from') }}" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                </div>
                <div>
                    <label for="f-to" class="block text-xs text-gray-500 mb-1">To</label>
                    <input type="date" id="f-to" name="to" value="{{ request('to') }}" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Filter</button>
                    @if (request()->hasAny(['status', 'division', 'user', 'from', 'to']))
                        <a href="{{ route('audit-logs.logins') }}" class="px-4 py-2 border border-transparent rounded-md text-sm text-gray-500 hover:text-gray-700">Reset</a>
                    @endif
                </div>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">When</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">User</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">IP</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Location</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Device</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logins as $login)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-700">{{ $login->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-700">
                                    @if ($login->user)
                                        {{ $login->user->name }}
                                        <span class="text-gray-400">&lt;{{ $login->user->email }}&gt;</span>
                                    @else
                                        <span class="text-gray-400">unknown</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if ($login->login_status === 'SUCCESS')
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-800">SUCCESS</span>
                                    @elseif ($login->login_status === 'MFA_FAILED')
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-red-100 text-red-800">MFA_FAILED</span>
                                    @else
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">FAILED</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-700">{{ $login->ip_address ?? '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-700">{{ $login->locationLabel() }}</td>
                                <td class="px-6 py-3 text-gray-500 max-w-xs truncate" title="{{ $login->user_agent }}">{{ $login->user_agent ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No login attempts recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $logins->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
