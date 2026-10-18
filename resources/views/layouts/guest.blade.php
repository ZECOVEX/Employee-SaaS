<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (dark) {
                        document.documentElement.classList.add('dark');
                    }
                } catch (e) {}
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 app-canvas px-4">
            <div class="flex items-center gap-3">
                <span class="app-brand-mark" aria-hidden="true"></span>
                <a href="/" wire:navigate class="text-lg font-bold tracking-tight text-gray-900">
                    {{ config('app.name', 'EmployeeSaaS') }}
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-8 px-6 py-6 bg-white border border-gray-200 shadow-md sm:rounded-2xl overflow-hidden">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
