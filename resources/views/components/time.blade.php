@props(['value' => null, 'seconds' => false])

{{-- Clock time rendered in the org's admin-selected format (12h AM/PM or 24h). --}}
@if ($value === null)
    —
@elseif (auth()->user()?->organization)
    {{ auth()->user()->organization->formatTime($value, $seconds) }}
@else
    {{ \Carbon\Carbon::parse($value)->format($seconds ? 'H:i:s' : 'H:i') }}
@endif
