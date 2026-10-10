<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Positions</h2>
            <a href="{{ route('positions.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                New Position
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <p class="mb-4 text-sm text-gray-500">
                Designations employees pick on the New/Edit Employee form. Deleting a position clears it from the employees holding it.
            </p>

            <form method="GET" class="mb-4">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search positions..."
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full sm:w-64" />
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Title</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Department</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employees</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($positions as $position)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $position->title }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $position->department?->name ?? 'All departments' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $position->employees_count }}</td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('positions.edit', $position) }}" class="text-indigo-600 hover:underline">Edit</a>
                                    <form action="{{ route('positions.destroy', $position) }}" method="POST" class="inline"
                                          onsubmit="return confirm('Delete this position? Employees keep working without a designation.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-500">No positions found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $positions->links() }}</div>
        </div>
    </div>
</x-app-layout>
