<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Leave Requests</h2>
            <a href="{{ route('leave.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                Request Leave
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <form method="GET" class="flex gap-2">
                <select name="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach (['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-3 py-2 bg-gray-800 rounded-md text-xs font-semibold text-white uppercase">Filter</button>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employee</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Type</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Dates</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Days</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($requests as $leaveRequest)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $leaveRequest->employee?->user?->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $leaveRequest->leaveType?->name }}</td>
                                <td class="px-6 py-4 text-gray-500">
                                    {{ $leaveRequest->start_date->format('M j') }} – {{ $leaveRequest->end_date->format('M j, Y') }}
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $leaveRequest->days }}</td>
                                <td class="px-6 py-4">
                                    @php($badge = ['PENDING' => 'bg-yellow-100 text-yellow-800', 'APPROVED' => 'bg-green-100 text-green-800', 'REJECTED' => 'bg-red-100 text-red-800', 'CANCELLED' => 'bg-gray-100 text-gray-600'])
                                    <span class="px-2 py-1 rounded text-xs font-semibold {{ $badge[$leaveRequest->status] ?? '' }}">{{ $leaveRequest->status }}</span>
                                    @if ($leaveRequest->review_note)
                                        <div class="text-xs text-gray-400 mt-1">{{ $leaveRequest->review_note }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                    @if ($leaveRequest->status === 'PENDING')
                                        @can('leave.approve')
                                            <form action="{{ route('leave.approve', $leaveRequest) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="text-green-600 hover:underline">Approve</button>
                                            </form>
                                            <form action="{{ route('leave.reject', $leaveRequest) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:underline">Reject</button>
                                            </form>
                                        @endcan
                                        <form action="{{ route('leave.cancel', $leaveRequest) }}" method="POST" class="inline"
                                              onsubmit="return confirm('Cancel this request?')">
                                            @csrf
                                            <button type="submit" class="text-gray-500 hover:underline">Cancel</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No leave requests.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $requests->links() }}</div>
        </div>
    </div>
</x-app-layout>
