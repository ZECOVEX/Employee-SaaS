<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Support Tickets</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <p class="mb-4 text-sm text-gray-500">
                Password-reset requests filed from the forgot-password page. Reply, resolve, or issue a temporary password.
            </p>

            <div class="mb-4 flex items-center gap-4 text-sm">
                @php($filters = ['', 'open', 'resolved'])
                @foreach ($filters as $filter)
                    <a href="{{ route('support.index', $filter !== '' ? ['status' => $filter] : []) }}"
                       class="{{ ($status ?? '') === $filter ? 'font-semibold text-indigo-700 underline' : 'text-gray-500 hover:text-gray-700' }}">
                        {{ $filter === '' ? 'All' : ucfirst($filter) }}
                    </a>
                @endforeach
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">#</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Email</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Requester</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Subject</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Filed</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tickets as $ticket)
                            <tr>
                                <td class="px-6 py-4 text-gray-500">{{ $ticket->id }}</td>
                                <td class="px-6 py-4 text-gray-900">{{ $ticket->email }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $ticket->requester?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $ticket->subject }}</td>
                                <td class="px-6 py-4">
                                    @if ($ticket->status === \App\Models\SupportTicket::STATUS_OPEN)
                                        <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700">Open</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">Resolved</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $ticket->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('support.show', $ticket) }}" class="text-indigo-600 hover:underline">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-500">No support tickets found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $tickets->links() }}</div>
        </div>
    </div>
</x-app-layout>
