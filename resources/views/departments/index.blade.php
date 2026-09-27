<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Departments</h2>
            @can('departments.manage')
                <a href="{{ route('departments.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    New Department
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <form method="GET" class="mb-4">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search departments..."
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full sm:w-64" />
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Manager</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employees</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($departments as $department)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $department->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $department->code ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $department->manager?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $department->employees_count }}</td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    @can('departments.manage')
                                        <a href="{{ route('departments.edit', $department) }}" class="text-indigo-600 hover:underline">Edit</a>
                                        <form action="{{ route('departments.destroy', $department) }}" method="POST" class="inline"
                                              onsubmit="return confirm('Delete this department?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">Delete</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No departments found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $departments->links() }}</div>
        </div>
    </div>
</x-app-layout>
