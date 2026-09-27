<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Request Leave</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 grid gap-6 md:grid-cols-2">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                    </div>
                @endif

                <form action="{{ route('leave.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <x-input-label for="leave_type_id" value="Leave type" />
                        <select id="leave_type_id" name="leave_type_id" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Select type…</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}" @selected(old('leave_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('leave_type_id')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" value="Start date" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full"
                                          :value="old('start_date')" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" value="End date" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full"
                                          :value="old('end_date')" required />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="reason" value="Reason" />
                        <textarea id="reason" name="reason" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>

                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Submit Request
                    </button>
                    <a href="{{ route('leave.index') }}" class="ms-3 text-sm text-gray-500 hover:underline">Back</a>
                </form>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 h-fit">
                <h3 class="font-medium text-gray-700 mb-3">Your balances ({{ now()->year }})</h3>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($balances as $balance)
                            <tr>
                                <td class="py-2 text-gray-700">{{ $balance->leaveType?->name }}</td>
                                <td class="py-2 text-right text-gray-500">
                                    {{ $balance->remainingDays() }} / {{ $balance->total_days }} days left
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-3 text-gray-500">No balances yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
