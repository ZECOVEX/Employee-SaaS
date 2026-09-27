<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Platform · Organizations</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Slug</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Users</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Employees</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($organizations as $organization)
                            <tr>
                                <td class="px-6 py-4 text-gray-900">{{ $organization->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $organization->slug }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-2 text-xs font-semibold
                                        {{ $organization->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $organization->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $organization->users_count }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $organization->employees_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <form action="{{ route('platform.organizations.status', $organization) }}" method="POST">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-indigo-600 hover:underline text-sm">
                                            {{ $organization->status === 'active' ? 'Suspend' : 'Activate' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No organizations.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $organizations->links() }}</div>
        </div>
    </div>
</x-app-layout>
