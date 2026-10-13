<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Notifications</h2>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-gray-500">{{ $unreadCount }} unread</span>
                @if ($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.readAll') }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 border border-gray-300 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Mark all read
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <ul class="divide-y divide-gray-200">
                    @forelse ($notifications as $notification)
                        <li class="{{ $notification->read_at ? 'bg-white' : 'bg-indigo-50/40' }}">
                            <a href="{{ route('notifications.read', $notification->id) }}"
                               class="flex items-start gap-4 px-6 py-4 hover:bg-gray-50">
                                <span class="mt-1 inline-flex h-2.5 w-2.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-gray-300' : 'bg-indigo-500' }}"
                                      title="{{ $notification->read_at ? 'Read' : 'Unread' }}"></span>
                                <span class="min-w-0 flex-1 max-w-3xl">
                                    <span class="block text-sm font-medium text-gray-900">
                                        {{ $notification->data['title'] ?? 'Notification' }}
                                        <span class="ms-2 align-middle text-xs font-normal text-gray-400 uppercase">
                                            {{ $notification->data['group'] ?? '' }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-sm text-gray-600">{{ $notification->data['body'] ?? '' }}</span>
                                    <span class="mt-1 block text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</span>
                                </span>
                                <span class="mt-1 ms-auto shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">
                                    {{ $notification->read_at ? 'Open' : 'Mark read & open' }}
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-10 text-center text-sm text-gray-500">
                            No notifications yet — leave decisions, attendance corrections and salary updates land here.
                        </li>
                    @endforelse
                </ul>
            </div>

            <div class="mt-6">
                {{ $notifications->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
