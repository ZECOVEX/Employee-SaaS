<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Salary</h2>
            <span class="text-sm text-gray-500">Current salary per employee · effective-dated (§28)</span>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-6 py-3">Code</th>
                            <th class="px-6 py-3">Employee</th>
                            <th class="px-6 py-3">Department</th>
                            <th class="px-6 py-3 text-right">Basic</th>
                            <th class="px-6 py-3 text-right">Gross</th>
                            <th class="px-6 py-3 text-right">Net</th>
                            <th class="px-6 py-3">Effective from</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($employees as $employee)
                            @php($record = $current[$employee->id])
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-500">{{ $employee->employee_code }}</td>
                                <td class="px-6 py-4 text-gray-900">{{ $employee->user?->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $employee->department?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-900 text-right">
                                    {{ $record ? number_format($record->basic_salary, 2) : '—' }}
                                </td>
                                <td class="px-6 py-4 text-gray-900 text-right">
                                    {{ $record ? number_format($record->gross_salary, 2) : '—' }}
                                </td>
                                <td class="px-6 py-4 text-gray-900 text-right">
                                    {{ $record ? number_format($record->net_salary, 2) : '—' }}
                                </td>
                                <td class="px-6 py-4 text-gray-500">
                                    {{ $record?->effective_from?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('salary.show', $employee) }}" wire:navigate class="text-indigo-600 hover:underline">
                                        {{ $record ? 'History' : 'Add' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500">No active employees.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
