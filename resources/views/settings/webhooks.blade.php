<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Webhooks</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('new_webhook_secret'))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
                    <p class="text-sm font-medium text-amber-800">Signing secret (copy it now — it will not be shown again). Verify the <code>X-Webhook-Signature: sha256=&lt;hmac&gt;</code> header (HMAC-SHA256 of the raw body) with it:</p>
                    <code class="mt-1 block break-all text-sm text-amber-900 select-all">{{ session('new_webhook_secret') }}</code>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Add subscription</h3>
                <p class="mt-1 text-sm text-gray-500">
                    We POST a signed JSON body to your URL. Choose the events you want, or subscribe to all.
                </p>

                <form action="{{ route('webhooks.store') }}" method="POST" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="url" value="Endpoint URL" />
                        <x-text-input id="url" name="url" type="url" class="mt-1 block w-full" :value="old('url')" required placeholder="https://example.com/hooks/employee" />
                        <x-input-error :messages="$errors->get('url')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label value="Events" />
                        <div class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-1 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="events[]" value="*" class="rounded border-gray-300 text-indigo-600"
                                       @checked(old('events') === null || in_array('*', (array) old('events', []), true))>
                                <span class="font-medium">* — all events</span>
                            </label>
                            @foreach ($events as $event)
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" class="rounded border-gray-300 text-indigo-600"
                                           @checked(in_array($event, (array) old('events', []), true))>
                                    <code>{{ $event }}</code>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('events')" class="mt-2" />
                    </div>

                    <x-primary-button>Create webhook</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800 mb-3">Subscriptions</h3>
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-100">
                        <tr>
                            <th class="py-2 pr-4">URL</th>
                            <th class="py-2 pr-4">Events</th>
                            <th class="py-2 pr-4">Last delivery</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($subscriptions as $subscription)
                            <tr class="border-b border-gray-50 @unless ($subscription->is_active) opacity-50 @endunless">
                                <td class="py-2 pr-4 break-all">{{ $subscription->url }}</td>
                                <td class="py-2 pr-4 text-gray-600">
                                    @if (in_array('*', $subscription->events ?? [], true)) * @else <code>{{ implode(', ', $subscription->events ?? []) }}</code> @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    @if ($subscription->last_delivery_at)
                                        {{ $subscription->last_delivery_at->diffForHumans() }}
                                        <span class="text-xs {{ str_starts_with((string) $subscription->last_status, 'ok') ? 'text-green-600' : 'text-red-600' }}">({{ $subscription->last_status }})</span>
                                    @else
                                        never
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    <form action="{{ route('webhooks.destroy', $subscription) }}" method="POST" onsubmit="return confirm('Delete this webhook?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-gray-500">No webhooks yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
