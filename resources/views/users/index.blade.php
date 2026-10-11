<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Users</h2>
            @can('users.manage')
                <a href="{{ route('users.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    New User
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('temp_password'))
                <div class="mb-4 rounded-md bg-amber-50 p-4 text-sm text-amber-900 border border-amber-300">
                    <p class="font-semibold">Temporary password</p>
                    <p class="mt-1">
                        Share this with the user over a trusted channel — it is shown only once:
                        <code class="mx-1 rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-900">{{ session('temp_password') }}</code>
                    </p>
                    <p class="mt-1">They must change it at next login.</p>
                </div>
            @endif

            <form method="GET" class="mb-4">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search users..."
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full sm:w-64" />
            </form>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Email</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500">Roles</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($users as $user)
                            <tr class="{{ $user->deactivated_at ? 'bg-red-50' : '' }}">
                                <td class="px-6 py-4 text-gray-900">
                                    {{ $user->name }}
                                    @if ($user->deactivated_at)
                                        <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 ms-1">Deactivated</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ $user->email }}</td>
                                <td class="px-6 py-4 text-gray-500">
                                    @foreach ($user->roles as $role)
                                        <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700 me-1">{{ $role->name }}</span>
                                    @endforeach
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    @can('users.manage')
                                        <a href="{{ route('users.edit', $user) }}" class="text-indigo-600 hover:underline">Edit</a>
                                        @if ($user->id !== auth()->id())
                                            <a href="{{ route('users.export', $user) }}" class="text-gray-500 hover:underline">Export</a>
                                            <form action="{{ route('users.reset_password', $user) }}" method="POST" class="inline"
                                                  onsubmit="return confirm('Reset this user&apos;s password to a temporary one?')">
                                                @csrf
                                                <button type="submit" class="text-amber-600 hover:underline">Reset password</button>
                                            </form>
                                            @if ($user->deactivated_at)
                                                <form action="{{ route('users.activate', $user) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-green-600 hover:underline">Reactivate</button>
                                                </form>
                                            @else
                                                <form action="{{ route('users.deactivate', $user) }}" method="POST" class="inline"
                                                      onsubmit="return confirm('Deactivate this user? They will no longer be able to sign in.')">
                                                    @csrf
                                                    <button type="submit" class="text-amber-700 hover:underline">Deactivate</button>
                                                </form>
                                            @endif
                                            <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline"
                                                  onsubmit="return confirm('Delete this user?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-500">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $users->links() }}</div>
        </div>
    </div>
</x-app-layout>
