<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">NFC Cards</h2>
            @can('nfc.manage')
                <form action="{{ route('nfc-cards.store') }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <input type="number" name="count" value="1" min="1" max="50" required
                           class="w-20 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm" />
                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Issue Cards
                    </button>
                </form>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 break-all">{{ session('status') }}</div>
            @endif

            <form method="GET" class="flex gap-2">
                <select name="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach (['available', 'active', 'blocked', 'revoked'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search token…"
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm" />
                <button type="submit" class="px-3 py-2 bg-gray-800 rounded-md text-xs font-semibold text-white uppercase">Filter</button>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Token</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employee</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Last used</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($cards as $card)
                            <tr>
                                <td class="px-6 py-4 text-gray-900 font-mono text-xs">{{ $card->card_token }}</td>
                                <td class="px-6 py-4 text-gray-500">
                                    {{ $card->employee?->user?->name ?? '—' }}
                                    @if ($card->employee)
                                        <span class="text-xs text-gray-400">{{ $card->employee->employee_code }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @php($badge = ['available' => 'bg-gray-100 text-gray-600', 'active' => 'bg-green-100 text-green-800', 'blocked' => 'bg-red-100 text-red-800', 'revoked' => 'bg-gray-200 text-gray-500'])
                                    <span class="px-2 py-1 rounded text-xs font-semibold {{ $badge[$card->status] ?? '' }}">{{ $card->status }}</span>
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $card->last_used_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                    <a href="{{ route('nfc-cards.history', $card) }}" class="text-indigo-600 hover:underline" wire:navigate>History</a>
                                    @can('nfc.manage')
                                        @if ($card->status === 'available')
                                            <form action="{{ route('nfc-cards.assign', $card) }}" method="POST" class="inline">
                                                @csrf
                                                <select name="employee_id" required class="text-xs border-gray-300 rounded"
                                                        onchange="this.form.submit()">
                                                    <option value="">Assign to…</option>
                                                    @foreach (\App\Models\Employee::where('status', 'active')->with('user:id,name')->orderBy('employee_code')->get() as $emp)
                                                        <option value="{{ $emp->id }}">{{ $emp->employee_code }} — {{ $emp->user?->name }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @endif
                                        @if ($card->status === 'active')
                                            <form action="{{ route('nfc-cards.unassign', $card) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="text-gray-500 hover:underline">Unassign</button>
                                            </form>
                                            <form action="{{ route('nfc-cards.block', $card) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="text-yellow-600 hover:underline">Block</button>
                                            </form>
                                        @endif
                                        @if ($card->employee_id && $card->status !== 'revoked')
                                            <form action="{{ route('nfc-cards.replace', $card) }}" method="POST" class="inline"
                                                  onsubmit="return confirm('Replace this card? A new token will be issued and this one revoked. Attendance history is preserved.')">
                                                @csrf
                                                <button type="submit" class="text-indigo-600 hover:underline">Replace</button>
                                            </form>
                                        @endif
                                        @if ($card->status !== 'revoked')
                                            <form action="{{ route('nfc-cards.revoke', $card) }}" method="POST" class="inline"
                                                  onsubmit="return confirm('Revoke this card?')">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:underline">Revoke</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No cards found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $cards->links() }}</div>
        </div>
    </div>
</x-app-layout>
