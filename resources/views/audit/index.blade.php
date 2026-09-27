<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit Logs</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form method="GET" class="mb-4 flex flex-wrap gap-3">
                <select name="action" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Filter</button>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">When</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Actor</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Action</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Resource</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Changes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            <tr class="align-top">
                                <td class="px-6 py-4 text-gray-500 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                                <td class="px-6 py-4 text-gray-900">{{ $log->actor?->name ?? 'System' }}</td>
                                <td class="px-6 py-4 text-gray-700 font-mono text-xs">{{ $log->action }}</td>
                                <td class="px-6 py-4 text-gray-500">
                                    @if ($log->resource_type)
                                        {{ $log->resource_type }} #{{ $log->resource_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-500 max-w-md">
                                    @if ($log->old_value || $log->new_value)
                                        <details>
                                            <summary class="cursor-pointer text-indigo-600">View</summary>
                                            <pre class="mt-2 whitespace-pre-wrap bg-gray-50 p-2 rounded">{{ json_encode(['old' => $log->old_value, 'new' => $log->new_value], JSON_PRETTY_PRINT) }}</pre>
                                        </details>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No audit logs.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $logs->links() }}</div>
        </div>
    </div>
</x-app-layout>
