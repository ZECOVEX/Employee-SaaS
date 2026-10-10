<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit Logs</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
                <div>
                    <label for="f-action" class="block text-xs text-gray-500 mb-1">Action</label>
                    <select id="f-action" name="action" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-resource" class="block text-xs text-gray-500 mb-1">Resource</label>
                    <select id="f-resource" name="resource_type" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All resources</option>
                        @foreach ($resourceTypes as $type)
                            <option value="{{ $type }}" @selected(request('resource_type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-actor" class="block text-xs text-gray-500 mb-1">Actor</label>
                    <select id="f-actor" name="actor" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">All actors</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor->id }}" @selected((string) request('actor') === (string) $actor->id)>{{ $actor->name }}</option>
                        @endforeach
                        <option value="0" @selected(request('actor') === '0' || request('actor') === 0)>System</option>
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
                    @if (request()->hasAny(['action', 'resource_type', 'actor', 'from', 'to']))
                        <a href="{{ route('audit-logs.index') }}" class="px-4 py-2 border border-transparent rounded-md text-sm text-gray-500 hover:text-gray-700">Reset</a>
                    @endif
                </div>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">When</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">IP / Device</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Actor</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Action</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Resource</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Changes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            @php
                                $old = is_array($log->old_value) ? $log->old_value : [];
                                $new = is_array($log->new_value) ? $log->new_value : [];

                                $sameValue = static function ($o, $n): bool {
                                    if (is_array($o) || is_array($n)) {
                                        return is_array($o) && is_array($n) && $o == $n;
                                    }
                                    if (is_numeric($o) && is_numeric($n)) {
                                        return (float) $o === (float) $n;
                                    }

                                    return $o === $n;
                                };

                                $diff = [];
                                foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
                                    $fromValue = $old[$key] ?? null;
                                    $toValue = $new[$key] ?? null;
                                    if (! $sameValue($fromValue, $toValue)) {
                                        $diff[$key] = [$fromValue, $toValue];
                                    }
                                }

                                $isSecret = fn (string $key) => (bool) preg_match('/password|token|secret|api_key|card_token/i', $key);
                                $hideSalary = str_starts_with($log->action, 'salary.') && ! auth()->user()?->canPermission('salary.view');

                                $fmt = static function ($value): string {
                                    if ($value === null || $value === '') {
                                        return '—';
                                    }
                                    if (is_bool($value)) {
                                        return $value ? 'Yes' : 'No';
                                    }
                                    if (is_array($value)) {
                                        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    }

                                    return (string) $value;
                                };
                            @endphp
                            <tr class="align-top">
                                <td class="px-6 py-4 text-gray-500 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                                <td class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap">
                                    @if ($log->ip_address)
                                        <span title="{{ $log->user_agent }}" class="underline decoration-dotted cursor-help">{{ $log->ip_address }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-900">{{ $log->actor?->name ?? 'System' }}</td>
                                <td class="px-6 py-4 text-gray-700 font-mono text-xs">{{ $log->action }}</td>
                                <td class="px-6 py-4 text-gray-500">
                                    @if ($log->resource_type)
                                        {{ $log->resource_type }} #{{ $log->resource_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs max-w-md">
                                    @if ($hideSalary && $diff !== [])
                                        <span class="text-gray-400 italic">Amounts hidden — requires salary.view</span>
                                    @elseif ($diff === [])
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <table class="w-full">
                                            <tbody>
                                                @foreach ($diff as $key => [$fromValue, $toValue])
                                                    <tr>
                                                        <td class="py-0.5 pe-2 align-top font-mono text-gray-600 whitespace-nowrap">{{ $key }}</td>
                                                        <td class="py-0.5 px-2 align-top text-gray-400 break-all">
                                                            {{ $isSecret($key) ? '••••••' : $fmt($fromValue) }}
                                                        </td>
                                                        <td class="py-0.5 ps-1 align-top text-emerald-700 font-medium break-all">
                                                            {{ $isSecret($key) ? '••••••' : $fmt($toValue) }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No audit logs.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $logs->links() }}</div>
        </div>
    </div>
</x-app-layout>
