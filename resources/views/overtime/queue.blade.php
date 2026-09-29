<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Overtime approvals</h2>
            <a href="{{ route('overtime.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">My overtime</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-md text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-md text-sm">
                    <ul class="list-disc ms-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-sm text-gray-500">
                Records waiting for a decision.
                {{ $scoped ? 'As a manager you only see your direct reports.' : 'You can decide for the whole company.' }}
                Rejections require a reason; every decision is kept in the audit trail.
            </p>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Employee</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Overtime</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($records as $record)
                            <tr>
                                <td class="px-4 py-4 text-gray-900">
                                    {{ $record->employee?->user?->name ?? '—' }}
                                    <span class="block text-xs text-gray-400">{{ $record->employee?->employee_code }}</span>
                                </td>
                                <td class="px-4 py-4 text-gray-700">{{ $record->work_date->format('D, M j, Y') }}</td>
                                <td class="px-4 py-4 text-gray-900 font-medium">
                                    {{ $record->minutesLabel() }}
                                    <span class="block text-xs text-gray-400">×{{ rtrim(rtrim(number_format($record->multiplier, 2), '0'), '.') }}</span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <form method="POST" action="{{ route('overtime.approve', $record) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-2 bg-emerald-600 rounded-md text-xs font-semibold text-white uppercase hover:bg-emerald-700">Approve</button>
                                        </form>

                                        <form method="POST" action="{{ route('overtime.reject', $record) }}" class="flex items-end gap-2">
                                            @csrf
                                            <label class="block">
                                                <span class="block text-xs text-gray-500">Reason (required)</span>
                                                <input name="reason" value="{{ old('reason') }}" maxlength="500" required
                                                       class="mt-1 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm w-44" />
                                            </label>
                                            <button type="submit" class="px-3 py-2 bg-red-600 rounded-md text-xs font-semibold text-white uppercase hover:bg-red-700">Reject</button>
                                        </form>

                                        <form method="POST" action="{{ route('overtime.adjust', $record) }}" class="flex items-end gap-2">
                                            @csrf
                                            <label class="block">
                                                <span class="block text-xs text-gray-500">Adjust minutes</span>
                                                <input type="number" name="minutes" min="0" max="1440"
                                                       value="{{ old('minutes', $record->countable_minutes) }}"
                                                       class="mt-1 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm w-24" />
                                            </label>
                                            <input name="reason" value="{{ old('reason') }}" maxlength="500" placeholder="Reason (optional)"
                                                   class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm w-36" />
                                            <button type="submit" class="px-3 py-2 bg-gray-800 rounded-md text-xs font-semibold text-white uppercase hover:bg-gray-900">Set</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-500">No overtime waiting for approval.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $records->links() }}
        </div>
    </div>
</x-app-layout>
