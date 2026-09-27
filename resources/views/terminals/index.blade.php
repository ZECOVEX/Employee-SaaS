<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance Terminals</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800 break-all">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-3">Register terminal</h3>
                <form action="{{ route('terminals.store') }}" method="POST" class="flex flex-wrap gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="name" value="Terminal ID / name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block" :value="old('name')" placeholder="TERM-DHK-001" required />
                    </div>
                    <div>
                        <x-input-label for="location" value="Location" />
                        <x-text-input id="location" name="location" type="text" class="mt-1 block" :value="old('location')" placeholder="Dhaka Office" />
                    </div>
                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Create
                    </button>
                </form>
                <p class="mt-3 text-xs text-gray-500">
                    Punch via <code>POST /api/attendance/nfc/punch</code> with header <code>X-Terminal-Key</code> and body <code>{"card_token":"..."}</code>.
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Location</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Last seen</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($terminals as $terminal)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $terminal->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $terminal->location ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 rounded text-xs font-semibold {{ $terminal->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $terminal->status }}</span>
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $terminal->last_seen_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    @if ($terminal->status === 'active')
                                        <form action="{{ route('terminals.destroy', $terminal) }}" method="POST" class="inline"
                                              onsubmit="return confirm('Revoke this terminal?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">Revoke</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No terminals registered.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
