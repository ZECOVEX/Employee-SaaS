{{-- Mobile nav item: a plain link, or an accordion group (recursive for nested levels). --}}
@if (! isset($item['children']))
    <x-responsive-nav-link :href="route($item['route'], $item['params'] ?? [])" :active="request()->routeIs($item['active'])" wire:navigate>
        {{ $item['label'] }}
    </x-responsive-nav-link>
@else
    @php
        $active = request()->routeIs($item['active']);
    @endphp
    <div x-data="{ open: false }">
        <button type="button" @click="open = ! open"
                class="flex w-full items-center justify-between ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium transition duration-150 ease-in-out focus:outline-none {{ $active ? 'border-indigo-400 text-indigo-700 bg-indigo-50' : 'border-transparent text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none' }}">
            <span>{{ $item['label'] }}</span>
            <svg class="h-4 w-4 shrink-0 transform transition-transform duration-150 {{ $active ? 'text-indigo-400' : 'text-gray-400' }}" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" style="display: none;" class="ms-4 mt-1 space-y-1 border-s border-gray-100">
            @foreach ($item['children'] as $child)
                @include('livewire.layout.partials.mobile-item', ['item' => $child])
            @endforeach
        </div>
    </div>
@endif
