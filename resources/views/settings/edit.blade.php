<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Company Settings</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-4 flex gap-3 text-sm">
                <span class="font-medium text-gray-700">Company info</span>
                <a href="{{ route('schedule.edit') }}" class="text-indigo-600 hover:underline">Working hours</a>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('settings.update') }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div>
                        <x-input-label for="name" value="Company name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $organization->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="timezone" value="Timezone" />
                        <x-text-input id="timezone" name="timezone" type="text" class="mt-1 block w-full" :value="old('timezone', $organization->timezone)" required placeholder="Asia/Dhaka" />
                        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                    </div>
                    <div class="text-sm text-gray-500">
                        Slug: <code>{{ $organization->slug }}</code> ·
                        Status: <span class="capitalize">{{ $organization->status }}</span>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
