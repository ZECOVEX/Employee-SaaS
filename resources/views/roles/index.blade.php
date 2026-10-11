<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Roles &amp; Permissions</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-500">
                    Each role holds a subset of the permission catalog. Edit HR, Manager and Employee;
                    Company Admin is locked so the tenant can never lose access to its own settings.
                </p>

                <table class="mt-4 w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-100">
                        <tr>
                            <th class="py-2 pr-4">Role</th>
                            <th class="py-2 pr-4">Permissions</th>
                            <th class="py-2 pr-4">Users</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roles as $role)
                            <tr class="border-b border-gray-50">
                                <td class="py-2 pr-4 font-medium">
                                    {{ $role->name }}
                                    @if ($role->slug === 'company_admin')
                                        <span class="ml-1 text-xs text-gray-400">(locked)</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600">{{ $role->permissions->count() }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $role->users_count }}</td>
                                <td class="py-2 text-right">
                                    @unless ($role->slug === 'company_admin')
                                        <a href="{{ route('roles.edit', $role) }}" class="text-indigo-600 hover:underline">Edit</a>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
