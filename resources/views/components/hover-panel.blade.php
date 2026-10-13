{{--
    Post-Phase 19 — hover drill-down behind dashboard stat cards.

    props:
      - drill: array{title: string, rows: list<array{name: string, detail?: string}>, total?: int}|null
                Rows are rendered server-side; Alpine only toggles visibility and
                keeps the panel inside the viewport (horizontal clamp).

    The root carries the card's own classes (merged via $attributes). When no
    rows are available the component renders the trigger as-is (no hover shell).
--}}
@props(['drill' => null])

@php
    $rows = is_array($drill) ? array_values((array) ($drill['rows'] ?? [])) : [];
    $total = max(count($rows), (int) ($drill['total'] ?? 0));
    $more = $total - count($rows);
    $drillTitle = (string) ($drill['title'] ?? '');
@endphp

@if ($rows !== [] && $drillTitle !== '')
    <div {{ $attributes->merge(['class' => 'relative']) }}
         x-data="{
             show: false,
             shift: 0,
             open() {
                 this.show = true;
                 this.$nextTick(() => {
                     const panel = this.$refs.panel;
                     if (! panel) return;
                     const rect = panel.getBoundingClientRect();
                     let adjust = 0;
                     if (rect.right > window.innerWidth - 8) {
                         adjust = window.innerWidth - 8 - rect.right;
                     }
                     if (rect.left + adjust < 8) {
                         adjust = 8 - rect.left;
                     }
                     this.shift = adjust;
                 });
             },
             close() { this.show = false; }
         }"
         @mouseenter="open()" @mouseleave="close()"
         @focusin="open()" @focusout="close()"
         data-test="hover-panel">
        {{ $trigger }}

        <div x-show="show"
             style="display: none;"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="absolute left-1/2 top-full z-40 mt-2 w-72 max-w-[calc(100vw-2rem)]">
            <div x-ref="panel"
                 :style="'transform: translateX(calc(-50% + ' + shift + 'px))'"
                 class="rounded-lg bg-white p-3 shadow-lg ring-1 ring-black ring-opacity-5">
                <div class="mb-2 flex items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                    <span>{{ $drillTitle }}</span>
                    <span class="text-gray-300">{{ $total }}</span>
                </div>
                <ul class="max-h-64 space-y-1.5 overflow-y-auto pe-1">
                    @foreach ($rows as $row)
                        <li class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate font-medium text-gray-800">{{ $row['name'] ?? '' }}</span>
                            <span class="shrink-0 text-right text-xs text-gray-500">{{ $row['detail'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($more > 0)
                    <div class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-400">
                        + {{ $more }} more
                    </div>
                @endif
            </div>
        </div>
    </div>
@else
    <div {{ $attributes }}>{{ $trigger }}</div>
@endif
