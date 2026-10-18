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
    <body class="font-sans antialiased">
        <div class="min-h-screen app-canvas">
            <livewire:layout.navigation />

            <div class="xl:pl-64 pt-16">
                {{-- Must-change-password banner: stays on every page until the
                     user picks their own password (admin/manager reset or
                     admin-created account). --}}
                @auth
                    @if (auth()->user()->must_change_password)
                        <div class="bg-amber-50 border-b-2 border-amber-400">
                            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <p class="text-sm text-amber-800">
                                    <span class="font-semibold">Please change your password.</span>
                                    Your account is using a temporary password — change it now to keep your account secure.
                                </p>
                                <a href="{{ route('profile') }}#password"
                                   class="shrink-0 inline-flex items-center px-3 py-1.5 bg-amber-600 rounded-md text-xs font-semibold text-white uppercase tracking-wider hover:bg-amber-700">
                                    Change password
                                </a>
                            </div>
                        </div>
                    @endif
                @endauth

                <!-- Page Heading -->
                @if (isset($header))
                    <div class="app-page-header">
                        <div class="app-page-header-inner">
                            {{ $header }}
                        </div>
                    </div>
                @endif

                <!-- Page Content -->
                <main class="app-main">
                    {{ $slot }}
                </main>

                <!-- Footer -->
                <footer class="py-6">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-xs text-gray-400">
                        Powered by Zecovex{{ isset($currentOrganization) ? ' | '.$currentOrganization->name : '' }}
                    </div>
                </footer>
            </div>
        </div>

        <script>
            if (!window.__zecovexThemeToggleBound) {
                window.__zecovexThemeToggleBound = true;
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.app-theme-toggle');
                    if (!btn) return;
                    var dark = document.documentElement.classList.toggle('dark');
                    try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (err) {}
                });
            }
        </script>
    </body>
</html>
