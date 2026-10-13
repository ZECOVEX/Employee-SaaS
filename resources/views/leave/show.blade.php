<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Leave Request — {{ $leaveRequest->leaveType?->name }}
            </h2>
            <a href="{{ route('leave.index') }}" class="text-sm text-gray-600 hover:underline">Back to leave requests</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6 text-sm">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <dt class="text-gray-500">Employee</dt>
                        <dd class="mt-1 text-gray-900">{{ $leaveRequest->employee?->user?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Type</dt>
                        <dd class="mt-1 text-gray-900">{{ $leaveRequest->leaveType?->name }} ({{ $leaveRequest->leaveType?->code }})</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1">
                            @php($badge = ['PENDING' => 'bg-yellow-100 text-yellow-800', 'APPROVED' => 'bg-green-100 text-green-800', 'REJECTED' => 'bg-red-100 text-red-800', 'CANCELLED' => 'bg-gray-100 text-gray-600'])
                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $badge[$leaveRequest->status] ?? '' }}">{{ $leaveRequest->status }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Dates</dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $leaveRequest->start_date->format('M j, Y') }} – {{ $leaveRequest->end_date->format('M j, Y') }}
                            ({{ $leaveRequest->days }} day{{ $leaveRequest->days === 1 ? '' : 's' }})
                        </dd>
                    </div>
                    @if ($leaveRequest->reviewed_at)
                        <div>
                            <dt class="text-gray-500">Last reviewed</dt>
                            <dd class="mt-1 text-gray-900">
                                {{ $leaveRequest->reviewed_at->format('Y-m-d H:i') }} by {{ $leaveRequest->reviewer?->name }}
                            </dd>
                        </div>
                    @endif
                    @if ($leaveRequest->attachment_path)
                        <div>
                            <dt class="text-gray-500">Attachment</dt>
                            <dd class="mt-1">
                                <a href="{{ route('leave.attachment', $leaveRequest) }}" class="text-indigo-600 hover:underline">Download</a>
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($leaveRequest->reason)
                    <div class="mt-4 border-t pt-4">
                        <p class="text-gray-500">Reason</p>
                        <p class="mt-1 text-gray-900 whitespace-pre-line">{{ $leaveRequest->reason }}</p>
                    </div>
                @endif

                @if ($leaveRequest->review_note)
                    <div class="mt-4 border-t pt-4">
                        <p class="text-gray-500">Reviewer note</p>
                        <p class="mt-1 text-gray-900 whitespace-pre-line">{{ $leaveRequest->review_note }}</p>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900">Notices</h3>
                <p class="mt-1 text-xs text-gray-500">Notes from admin / HR / your manager — visible to you.</p>

                @forelse ($leaveRequest->notices as $notice)
                    <div class="mt-4 border-t pt-4">
                        <p class="text-xs text-gray-500">
                            {{ $notice->author?->name }} · {{ $notice->created_at->format('Y-m-d H:i') }}
                        </p>
                        <p class="mt-1 text-gray-900 whitespace-pre-line">{{ $notice->body }}</p>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-gray-400">No notices yet.</p>
                @endforelse

                @if ($canReview)
                    <form action="{{ route('leave.notice', $leaveRequest) }}" method="POST" class="mt-6 border-t pt-4 space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="body" value="Add a notice" />
                            <textarea id="body" name="body" rows="3" required
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                      placeholder="Explain a change, ask for clarification, or leave instructions…">{{ old('body') }}</textarea>
                            <x-input-error :messages="$errors->get('body')" class="mt-2" />
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Post notice
                        </button>
                    </form>
                @endif
            </div>

            @if ($canReview && $leaveRequest->status !== \App\Models\LeaveRequest::CANCELLED)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Review</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                You can change this decision as often as needed — the employee is notified each time the status changes.
                            </p>
                        </div>
                        <a href="{{ route('leave.edit', $leaveRequest) }}" class="shrink-0 px-3 py-2 border border-gray-300 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Edit request
                        </a>
                    </div>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <form action="{{ route('leave.approve', $leaveRequest) }}" method="POST" class="space-y-2">
                            @csrf
                            <div>
                                <x-input-label for="approve_note" value="Note (optional)" />
                                <textarea id="approve_note" name="review_note" rows="2"
                                          class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('review_note') }}</textarea>
                            </div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700">
                                {{ $leaveRequest->status === \App\Models\LeaveRequest::APPROVED ? 'Re-approve' : 'Approve' }}
                            </button>
                        </form>

                        <form action="{{ route('leave.reject', $leaveRequest) }}" method="POST" class="space-y-2">
                            @csrf
                            <div>
                                <x-input-label for="reject_note" value="Note (optional)" />
                                <textarea id="reject_note" name="review_note" rows="2"
                                          class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('review_note') }}</textarea>
                            </div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700">
                                {{ $leaveRequest->status === \App\Models\LeaveRequest::REJECTED ? 'Re-reject' : 'Reject' }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($isOwner && $leaveRequest->status === \App\Models\LeaveRequest::PENDING)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <form action="{{ route('leave.cancel', $leaveRequest) }}" method="POST" onsubmit="return confirm('Cancel this request?')">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 hover:underline">Cancel this request</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
