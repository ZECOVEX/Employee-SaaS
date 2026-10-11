<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Ticket #{{ $ticket->id }} — {{ $ticket->subject }}
            </h2>
            <a href="{{ route('support.index') }}" class="text-sm text-gray-600 hover:underline">Back to tickets</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('temp_password'))
                <div class="mb-4 rounded-md bg-amber-50 p-4 text-sm text-amber-900 border border-amber-300">
                    <p class="font-semibold">Temporary password issued</p>
                    <p class="mt-1">
                        Share this with the requester over a trusted channel — it is shown only once:
                        <code class="mx-1 rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-900">{{ session('temp_password') }}</code>
                    </p>
                    <p class="mt-1">They must change it at next login.</p>
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6 text-sm">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <dt class="text-gray-500">Requester email</dt>
                        <dd class="mt-1 text-gray-900">{{ $ticket->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Account</dt>
                        <dd class="mt-1 text-gray-900">{{ $ticket->requester?->name ?? 'No user account' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1">
                            @if ($ticket->status === \App\Models\SupportTicket::STATUS_OPEN)
                                <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700">Open</span>
                            @else
                                <span class="inline-flex rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">Resolved</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Filed</dt>
                        <dd class="mt-1 text-gray-900">{{ $ticket->created_at->format('Y-m-d H:i') }}</dd>
                    </div>
                    @if ($ticket->resolved_at)
                        <div>
                            <dt class="text-gray-500">Resolved</dt>
                            <dd class="mt-1 text-gray-900">{{ $ticket->resolved_at->format('Y-m-d H:i') }} by {{ $ticket->resolver?->name }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($ticket->message)
                    <div class="mt-4 border-t pt-4">
                        <p class="text-gray-500">Requester's note</p>
                        <p class="mt-1 text-gray-900 whitespace-pre-line">{{ $ticket->message }}</p>
                    </div>
                @endif

                @if ($ticket->reply)
                    <div class="mt-4 border-t pt-4">
                        <p class="text-gray-500">Your reply</p>
                        <p class="mt-1 text-gray-900 whitespace-pre-line">{{ $ticket->reply }}</p>
                    </div>
                @endif
            </div>

            @if ($ticket->status === \App\Models\SupportTicket::STATUS_OPEN)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <form action="{{ route('support.reply', $ticket) }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="reply" value="Reply" />
                            <textarea id="reply" name="reply" rows="3"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                      placeholder="Tell the requester what happens next…">{{ old('reply', $ticket->reply) }}</textarea>
                            <x-input-error :messages="$errors->get('reply')" class="mt-2" />
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Post reply
                        </button>
                    </form>

                    <div class="mt-6 border-t pt-4 flex flex-wrap items-center gap-3">
                        @if ($ticket->user_id)
                            <form action="{{ route('support.reset_password', $ticket) }}" method="POST" class="inline"
                                  onsubmit="return confirm('Reset this user&apos;s password to a temporary one and resolve the ticket?')">
                                @csrf
                                <button type="submit" class="inline-flex items-center px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-700">
                                    Reset password &amp; resolve
                                </button>
                            </form>
                        @endif
                        <form action="{{ route('support.resolve', $ticket) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                                Mark resolved
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
