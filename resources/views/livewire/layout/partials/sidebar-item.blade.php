{{-- Console sidebar item: a plain link, or an expandable accordion group (recursive for sub/sub levels). --}}
@if (! isset($item['children']))
    <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate
       @class(['sidebar-link', 'is-active' => request()->routeIs($item['active'])])>
        <span>{{ $item['label'] }}</span>
    </a>
@else
    @php
        $open = request()->routeIs($item['active']);
    @endphp
    <div x-data="{ open: {{ $open ? 'true' : 'false' }} }">
        <button type="button" @click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                @class(['sidebar-link sidebar-group', 'is-active' => $open])>
            <span>{{ $item['label'] }}</span>
            <svg class="sidebar-chevron" :class="{ 'rotate-90': open }" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" style="display: none;"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="sidebar-section-items">
            @foreach ($item['children'] as $child)
                @include('livewire.layout.partials.sidebar-item', ['item' => $child])
            @endforeach
        </div>
    </div>
@endif
