<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Employees</h2>
            @can('employees.create')
                <a href="{{ route('employees.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    New Employee
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <form method="GET" class="mb-4 flex flex-wrap gap-3">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search code, name, email..."
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full sm:w-64" />
                <select name="department_id" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected((int) request('department_id') === $department->id)>
                            {{ $department->name }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Filter</button>
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Department</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($employees as $employee)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $employee->employee_code }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-gray-900">{{ $employee->user?->name }}</div>
                                    <div class="text-gray-400 text-xs">{{ $employee->user?->email }}</div>
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $employee->department?->name ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-2 text-xs font-semibold
                                        {{ $employee->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $employee->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <a href="{{ route('employees.show', $employee) }}" class="text-indigo-600 hover:underline">View</a>
                                    @can('employees.edit')
                                        <a href="{{ route('employees.edit', $employee) }}" class="text-indigo-600 hover:underline">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No employees found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $employees->links() }}</div>
        </div>
    </div>
</x-app-layout>
