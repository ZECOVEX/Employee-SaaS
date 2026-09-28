<div style="font-family: sans-serif; font-size: 14px; color: #1f2937;">
    <p>Hello {{ $name }},</p>

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @isset($actionText)
        <p>
            <a href="{{ $actionUrl }}" style="display: inline-block; padding: 8px 16px; background: #4f46e5; color: #ffffff; text-decoration: none; border-radius: 6px;">
                {{ $actionText }}
            </a>
        </p>
    @endisset

    <p style="color: #6b7280;">— {{ $footer }}</p>
</div>
