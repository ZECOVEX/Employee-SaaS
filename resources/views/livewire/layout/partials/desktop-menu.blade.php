{{-- Row inside a desktop dropdown panel: a menu link, or a hover flyout submenu (sub-sub-navlink). --}}
@php
    $active = request()->routeIs($item['active']);
@endphp

@if (! isset($item['children']))
    <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate
       class="block px-4 py-2 text-sm {{ $active ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-700' }}">
        {{ $item['label'] }}
    </a>
@else
    <div class="relative" x-data="{ sub: false }" @mouseenter="sub = true" @mouseleave="sub = false" @click.outside="sub = false">
        <button type="button" @click="sub = true"
                class="flex w-full items-center justify-between gap-3 px-4 py-2 text-sm text-start {{ $active ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-700' }}">
            <span>{{ $item['label'] }}</span>
            <svg class="h-3.5 w-3.5 shrink-0 transform transition-transform duration-150 {{ $active ? 'text-indigo-400' : 'text-gray-400' }}" :class="{ 'rotate-90': sub }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7-7" />
            </svg>
        </button>

        <div x-show="sub" style="display: none;"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 -translate-x-1"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="absolute start-full top-0 z-50 min-w-44 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
            @foreach ($item['children'] as $grandchild)
                @include('livewire.layout.partials.desktop-menu', ['item' => $grandchild])
            @endforeach
        </div>
    </div>
@endif
