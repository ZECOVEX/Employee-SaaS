{{--
    Post-Phase 19 — user avatar (uploaded photo on the public disk) with an
    initials fallback.

    props:
      - user: App\Models\User|null (defaults to the current user)
      - size: 'sm' (h-6 w-6) | 'md' (h-10 w-10) | 'lg' (h-20 w-20)
--}}
@props(['user' => null, 'size' => 'sm'])

@php
    $avatarUser = $user ?? auth()->user();
    $sizeClasses = match ($size) {
        'lg' => 'h-20 w-20 text-2xl',
        'md' => 'h-10 w-10 text-sm',
        default => 'h-6 w-6 text-xs',
    };

    $parts = preg_split('/\s+/', trim((string) ($avatarUser?->name ?? ''))) ?: [];
    $initials = '';

    if ($parts !== []) {
        $initials = strtoupper(mb_substr($parts[0], 0, 1));

        if (count($parts) > 1) {
            $initials .= strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
        }
    }
@endphp

@if ($avatarUser?->avatar_path)
    <img src="{{ asset('storage/'.$avatarUser->avatar_path) }}"
         alt="{{ $avatarUser->name }}"
         {{ $attributes->merge(['class' => $sizeClasses.' rounded-full object-cover']) }} />
@elseif ($avatarUser)
    <span {{ $attributes->merge(['class' => $sizeClasses.' inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700']) }}
          >{{ $initials }}</span>
@endif
