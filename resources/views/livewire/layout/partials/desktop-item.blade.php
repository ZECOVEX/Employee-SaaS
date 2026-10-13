{{-- Top-level desktop nav item: a plain link, or a hover/click dropdown group. --}}
@if (! isset($item['children']))
    <x-nav-link :href="route($item['route'], $item['params'] ?? [])" :active="request()->routeIs($item['active'])" wire:navigate>
        {{ $item['label'] }}
    </x-nav-link>
@else
    @php
        $active = request()->routeIs($item['active']);
    @endphp
    <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" @click.outside="open = false">
        <button type="button" @click="open = true"
                class="h-full inline-flex items-center gap-1 px-1 border-b-2 text-sm font-medium leading-5 whitespace-nowrap transition duration-150 ease-in-out focus:outline-none {{ $active ? 'border-indigo-400 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:text-gray-700 focus:border-gray-300' }}">
            {{ $item['label'] }}
            <svg class="h-3 w-3 transform transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" style="display: none;"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="absolute start-0 top-full z-50 min-w-52 rounded-b-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
            @foreach ($item['children'] as $child)
                @include('livewire.layout.partials.desktop-menu', ['item' => $child])
            @endforeach
        </div>
    </div>
@endif
