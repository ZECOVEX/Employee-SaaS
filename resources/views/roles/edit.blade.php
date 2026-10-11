<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit {{ $role->name }} permissions</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-gray-500">
                    Check every permission members of this role should hold. Changes apply immediately to all users with the role.
                </p>

                <form action="{{ route('roles.update', $role) }}" method="POST" class="mt-4 space-y-4">
                    @csrf
                    @method('PUT')

                    @php
                        $selected = $role->permissions->pluck('key')->all();
                        $groups = collect($catalog)->groupBy(fn ($meta) => $meta[1]);
                    @endphp

                    @foreach ($groups as $group => $entries)
                        <div>
                            <h4 class="text-sm font-semibold text-gray-800 capitalize border-b border-gray-100 pb-1">{{ $group }}</h4>
                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1 text-sm">
                                @foreach ($entries as $key => $meta)
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                               class="rounded border-gray-300 text-indigo-600"
                                               @checked(in_array($key, old('permissions', $selected), true))>
                                        <span>{{ $meta[0] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <x-input-error :messages="$errors->get('permissions')" class="mt-2" />

                    <div class="flex items-center gap-3">
                        <x-primary-button>Save permissions</x-primary-button>
                        <a href="{{ route('roles.index') }}" class="text-sm text-gray-600 hover:underline">Back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
