<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">NFC Cards</h2>
            @can('nfc.manage')
                <form action="{{ route('nfc-cards.store') }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <input type="number" name="count" value="1" min="1" max="50" required placeholder="Qty"
                           class="w-20 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm" />
                    <input type="text" name="notes" value="" maxlength="200" placeholder="Notes (optional)"
                           class="w-44 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm" />
                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Issue Cards
                    </button>
                </form>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4"
             x-data="{
                 open: {{ ($errors->any() || request()->boolean('issue')) ? 'true' : 'false' }},
                 tab: 'phone',
                 msg: '',
                 err: '',
                 async scanPhone() {
                     this.msg = '';
                     this.err = '';
                     if (! ('NDEFReader' in window)) {
                         this.err = 'Web NFC is not available in this browser — open this page on an Android phone in Chrome, or use the Reader machine / Manual code method.';
                         return;
                     }
                     this.msg = 'Ready — hold the card against the back of the phone…';
                     try {
                         const reader = new NDEFReader();
                         await reader.scan();
                         reader.onreading = (event) => {
                             const code = (event.serialNumber || '').replace(/[\s:]+/g, '').toUpperCase();
                             if (! code) {
                                 this.err = 'The card was read but returned no serial number.';
                                 this.msg = '';
                                 return;
                             }
                             this.$refs.code.value = code;
                             this.msg = 'Captured: ' + code;
                             this.err = '';
                         };
                         reader.onerror = (event) => {
                             this.err = 'NFC read failed: ' + (event.message || 'unknown error');
                             this.msg = '';
                         };
                     } catch (error) {
                         this.err = 'Could not start the NFC scan: ' + (error && error.message ? error.message : error);
                         this.msg = '';
                     }
                 },
                 capture(event) {
                     if (event.key !== 'Enter') return;
                     event.preventDefault();
                     const code = event.target.value.replace(/[\s:]+/g, '').toUpperCase();
                     if (! code) return;
                     this.$refs.code.value = code;
                     this.msg = 'Captured: ' + code;
                     this.err = '';
                     event.target.value = '';
                 },
             }">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 break-all">{{ session('status') }}</div>
            @endif

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="font-medium text-gray-700">NFC Cards</span>
                @can('attendance.terminal')
                    <a href="{{ route('terminals.index') }}" class="text-indigo-600 hover:underline">Terminals</a>
                @endcan
                @can('nfc.manage')
                    <button type="button" @click="open = ! open" x-text="open ? 'Close' : 'Issue a card'"
                            class="ml-auto rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-500">
                        Issue a card
                    </button>
                @endcan
            </div>

            @can('nfc.manage')
                <div x-show="open" style="display: none" class="bg-white shadow-sm sm:rounded-lg p-6">
                    <p class="text-sm text-gray-500">
                        Hand an NFC card to an employee in one step: capture the card's code with your phone,
                        a USB reader machine, or type it by hand — then pick who it belongs to. An existing
                        unused ("available") card is claimed; otherwise a new active card is created and issued.
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" @click="tab = 'phone'"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition"
                                :class="tab === 'phone' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Phone NFC
                        </button>
                        <button type="button" @click="tab = 'reader'; $nextTick(() => $refs.reader.focus())"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition"
                                :class="tab === 'reader' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Reader machine
                        </button>
                        <button type="button" @click="tab = 'manual'"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition"
                                :class="tab === 'manual' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Manual code
                        </button>
                    </div>

                    <div class="mt-3 space-y-3">
                        <div x-show="tab === 'phone'" class="rounded-md bg-gray-50 p-4 space-y-2">
                            <p class="text-sm text-gray-600">
                                Open this page on an Android phone in Chrome (HTTPS or localhost), tap
                                <span class="font-semibold">Start NFC scan</span>, then hold the card against
                                the back of the phone.
                            </p>
                            <button type="button" @click="scanPhone()"
                                    class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-semibold text-white hover:bg-indigo-500">
                                Start NFC scan
                            </button>
                            <p x-show="! ('NDEFReader' in window)" style="display: none" class="text-sm text-amber-700">
                                Web NFC is not available in this browser — switch to the Reader machine or
                                Manual code method.
                            </p>
                        </div>

                        <div x-show="tab === 'reader'" style="display: none" class="rounded-md bg-gray-50 p-4 space-y-2">
                            <p class="text-sm text-gray-600">
                                Click the field below, then tap the card on the USB reader — most readers type
                                the code and press Enter. The value is copied into the Card code field.
                            </p>
                            <input type="text" x-ref="reader" @keydown.enter.prevent="capture($event)"
                                   autocomplete="off" spellcheck="false" placeholder="Reader types here, then Enter…"
                                   class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm font-mono" />
                        </div>

                        <div x-show="tab === 'manual'" style="display: none" class="rounded-md bg-gray-50 p-4">
                            <p class="text-sm text-gray-600">
                                Type the code exactly as it appears on the card or in your card-writer tool.
                                Colons and spaces are removed automatically.
                            </p>
                        </div>
                    </div>

                    <div x-show="msg" class="mt-3 text-sm font-medium text-indigo-600" x-text="msg"></div>
                    <div x-show="err" class="mt-3 text-sm font-medium text-red-600" x-text="err"></div>

                    <form action="{{ route('nfc-cards.issue.store') }}" method="POST" class="mt-5 space-y-4">
                        @csrf
                        <input type="hidden" name="method" :value="tab">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <x-input-label for="card_token" value="Card code" />
                                <x-text-input id="card_token" name="card_token" type="text" class="mt-1 block w-full font-mono"
                                              :value="old('card_token')" x-ref="code" required maxlength="64"
                                              autocomplete="off" spellcheck="false" placeholder="NFC-… or 046E3A…" />
                                <x-input-error :messages="$errors->get('card_token')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="notes" value="Notes (optional)" />
                                <x-text-input id="notes" name="notes" type="text" class="mt-1 block w-full"
                                              :value="old('notes')" maxlength="200" placeholder="Front desk card…" />
                                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex-1 min-w-56">
                                <x-input-label for="employee_id" value="Issue to employee" />
                                <select id="employee_id" name="employee_id" required
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                    <option value="">Select an employee…</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>
                                            {{ $employee->employee_code }} — {{ $employee->user?->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @if ($employees->isEmpty())
                                    <p class="mt-1 text-xs text-gray-500">No active employees yet — add an employee first.</p>
                                @endif
                                <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
                            </div>
                            <x-primary-button>Issue card</x-primary-button>
                        </div>
                    </form>
                </div>
            @endcan

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
                                                    @foreach ($employees as $emp)
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
