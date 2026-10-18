<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="landing scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ config('app.name') }} — enterprise employee management: attendance, leave, payroll, payslips, reports and analytics for growing companies.">
        <meta name="color-scheme" content="light dark">

        <title>{{ config('app.name') }} — Employee management for modern companies</title>

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
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="icon" type="image/png" href="{{ asset('images/zecovex-icon.png') }}">

        <style>
            body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }
            .reveal { opacity: 0; transform: translateY(18px); transition: opacity .6s ease, transform .6s ease; }
            .reveal.is-visible { opacity: 1; transform: none; }
            @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } }
            .hero-grid {
                background-image: linear-gradient(rgba(15,23,42,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(15,23,42,.06) 1px, transparent 1px);
                background-size: 44px 44px;
            }
            .dark .hero-grid {
                background-image: linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px);
            }
            .glow { background: radial-gradient(ellipse 60% 50% at 50% 0%, rgba(99,102,241,.14), transparent 70%); }
            .dark .glow { background: radial-gradient(ellipse 60% 50% at 50% 0%, rgba(99,102,241,.28), transparent 70%); }
        </style>
    </head>
    <body class="antialiased font-sans bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        {{-- Announcement bar --}}
        <div class="bg-slate-950 text-center text-xs font-medium text-slate-300 dark:bg-slate-900">
            <div class="mx-auto flex max-w-7xl items-center justify-center gap-2 px-4 py-2">
                <span class="inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                All systems operational —
                <a href="{{ route('register') }}" class="text-indigo-300 underline-offset-2 hover:underline">start your free workspace</a>
            </div>
        </div>

        {{-- Header --}}
        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-white/10 dark:bg-slate-950/90">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <a href="/" class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white shadow-lg shadow-indigo-900/40">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    </span>
                    <span class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-8 md:flex">
                    <a href="#features" class="text-sm font-medium text-slate-600 transition hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Product</a>
                    <a href="#security" class="text-sm font-medium text-slate-600 transition hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Security</a>
                    <a href="#customers" class="text-sm font-medium text-slate-600 transition hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Customers</a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    <button type="button" id="theme-toggle" aria-label="Toggle dark mode" class="rounded-lg border border-slate-200 p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white">
                        <svg class="h-5 w-5 dark:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                        <svg class="hidden h-5 w-5 dark:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                    </button>
                    @auth
                        <a href="{{ url('/dashboard') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:text-slate-900 dark:text-slate-200 dark:hover:text-white">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:text-slate-900 dark:text-slate-200 dark:hover:text-white">
                            Log in
                        </a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                            Start free
                        </a>
                    @endauth
                </div>
            </div>
        </header>

        {{-- Hero --}}
        <section class="relative overflow-hidden bg-white dark:bg-slate-950">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 hero-grid"></div>
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 glow"></div>

            <div class="relative mx-auto max-w-7xl px-4 pb-24 pt-16 sm:px-6 sm:pt-24 lg:px-8 lg:pb-32">
                <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-16">
                    <div class="max-w-2xl">
                        <p class="mb-6 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-4 py-1.5 text-xs font-semibold tracking-wide text-slate-600 dark:border-white/15 dark:bg-white/5 dark:text-slate-300">
                            <span class="inline-flex h-1.5 w-1.5 rounded-full bg-indigo-400"></span>
                            ENTERPRISE EMPLOYEE MANAGEMENT PLATFORM
                        </p>

                        <h1 class="text-4xl font-extrabold leading-[1.08] tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem] dark:text-white">
                            Run your entire team from
                            <span class="bg-gradient-to-r from-indigo-600 via-indigo-500 to-sky-500 bg-clip-text text-transparent dark:from-indigo-400 dark:via-indigo-300 dark:to-sky-300">one place</span>
                        </h1>

                        <p class="mt-6 max-w-xl text-lg leading-8 text-slate-600 dark:text-slate-400">
                            {{ config('app.name') }} unifies attendance, leave, payroll, and people analytics — with enterprise-grade security, granular permissions, and complete data isolation for every company.
                        </p>

                        <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-8 py-3.5 text-base font-semibold text-white shadow-lg shadow-indigo-900/50 transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                                Create your workspace
                                <svg class="ml-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </a>
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-8 py-3.5 text-base font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:text-white dark:hover:bg-white/10">
                                Log in
                            </a>
                        </div>

                        <div class="mt-10 flex items-center gap-6 text-sm text-slate-500">
                            <div class="flex -space-x-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-950">AK</span>
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-950">MR</span>
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-950">JL</span>
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-500 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-950">+</span>
                            </div>
                            <p>Trusted by people teams at <span class="font-medium text-slate-700 dark:text-slate-300">2,400+</span> companies worldwide</p>
                        </div>
                    </div>

                    {{-- Product mockup --}}
                    <div class="relative mx-auto w-full max-w-lg lg:max-w-none">
                        <div class="rounded-2xl border border-slate-200 bg-slate-100 p-2 shadow-2xl shadow-indigo-950/20 dark:border-white/10 dark:bg-slate-900 dark:shadow-indigo-950/60">
                            <div class="overflow-hidden rounded-xl bg-white dark:bg-slate-900">
                                <div class="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-800 dark:bg-slate-950/60">
                                    <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                                    <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                    <span class="ml-3 text-[11px] font-medium text-slate-400">{{ config('app.name') }} · Dashboard</span>
                                </div>

                                <div class="grid grid-cols-3 gap-3 p-4">
                                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Present</p>
                                        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">142</p>
                                        <p class="text-[10px] font-medium text-emerald-600">+6 vs last week</p>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">On leave</p>
                                        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">9</p>
                                        <p class="text-[10px] font-medium text-slate-400">3 pending</p>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Payroll</p>
                                        <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">Ready</p>
                                        <p class="text-[10px] font-medium text-indigo-600">Oct · draft</p>
                                    </div>

                                    <div class="col-span-3 rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Attendance this week</p>
                                            <span class="text-[10px] font-medium text-slate-400">Live</span>
                                        </div>
                                        <div class="mt-3 flex h-16 items-end gap-1.5">
                                            <div class="w-full rounded-t bg-indigo-500/80" style="height: 62%"></div>
                                            <div class="w-full rounded-t bg-indigo-500/80" style="height: 78%"></div>
                                            <div class="w-full rounded-t bg-indigo-500/80" style="height: 71%"></div>
                                            <div class="w-full rounded-t bg-indigo-500/80" style="height: 88%"></div>
                                            <div class="w-full rounded-t bg-indigo-600" style="height: 95%"></div>
                                            <div class="w-full rounded-t bg-slate-200 dark:bg-slate-800" style="height: 24%"></div>
                                            <div class="w-full rounded-t bg-slate-200 dark:bg-slate-800" style="height: 18%"></div>
                                        </div>
                                    </div>

                                    <div class="col-span-3 divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                                        <div class="flex items-center justify-between px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-[9px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">SM</span>
                                                <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Sarah M.</span>
                                            </div>
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400">PRESENT</span>
                                        </div>
                                        <div class="flex items-center justify-between px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-[9px] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">DK</span>
                                                <span class="text-xs font-medium text-slate-700 dark:text-slate-300">David K.</span>
                                            </div>
                                            <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-400">ON LEAVE</span>
                                        </div>
                                        <div class="flex items-center justify-between px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-rose-100 text-[9px] font-bold text-rose-700 dark:bg-rose-950 dark:text-rose-300">LP</span>
                                                <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Lisa P.</span>
                                            </div>
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-400">LATE</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="absolute -bottom-5 -left-5 hidden rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xl dark:border-white/10 dark:bg-slate-900 sm:block">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Payslip generated</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">October · 142 employees</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Logo cloud --}}
        <section class="border-t border-slate-200 bg-slate-50 py-12 dark:border-slate-800 dark:bg-slate-950">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p class="text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Trusted by teams worldwide</p>
                <div class="mt-8 grid grid-cols-2 gap-8 sm:grid-cols-3 lg:grid-cols-6">
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Northwind</p>
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Vertex Labs</p>
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Halcyon</p>
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Meridian</p>
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Pinnacle HR</p>
                    <p class="text-center text-lg font-bold tracking-tight text-slate-400 dark:text-slate-600">Solstice</p>
                </div>
            </div>
        </section>

        {{-- Stats --}}
        <section class="border-t border-slate-200 bg-white py-14 dark:border-slate-800 dark:bg-slate-950">
            <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-4 sm:px-6 lg:grid-cols-4 lg:px-8">
                <div class="reveal text-center">
                    <p class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl dark:text-white">2,400+</p>
                    <p class="mt-2 text-sm font-medium text-slate-500">Companies onboard</p>
                </div>
                <div class="reveal text-center">
                    <p class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl dark:text-white">180k+</p>
                    <p class="mt-2 text-sm font-medium text-slate-500">Employees managed</p>
                </div>
                <div class="reveal text-center">
                    <p class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl dark:text-white">99.95%</p>
                    <p class="mt-2 text-sm font-medium text-slate-500">Platform uptime</p>
                </div>
                <div class="reveal text-center">
                    <p class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl dark:text-white">14 days</p>
                    <p class="mt-2 text-sm font-medium text-slate-500">Free trial, no card</p>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="border-t border-slate-200 bg-slate-50 py-24 dark:border-slate-800 dark:bg-slate-900/40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Platform</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Everything HR needs, nothing it doesn't</h2>
                    <p class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-400">
                        From clock-in to payslip — one platform, one login, per-company data isolation built in from day one.
                    </p>
                </div>

                <div class="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Employee directory</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Profiles, departments, positions and documents — with a clean org-wide directory your managers can actually navigate.
                        </p>
                    </div>

                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Attendance &amp; live board</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Clock-ins with geo-IP context, overtime detection, NFC terminal support, and a live attendance board that updates in real time.
                        </p>
                    </div>

                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Leave management</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Leave requests with attachments, two-stage approvals, expiry tracking and balance reports — no more spreadsheet chaos.
                        </p>
                    </div>

                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Payroll &amp; payslips</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Salary records, a built-in calculator, revision history and downloadable payslips — delivered to employees automatically.
                        </p>
                    </div>

                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Reports &amp; analytics</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Headcount trends, attendance scores, risk indicators and CSV exports — dashboards that answer questions at a glance.
                        </p>
                    </div>

                    <div class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold">Security first</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                            Role-based permissions, two-factor auth (TOTP or email codes), SSO, API keys, audit trails and per-company data isolation.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Security --}}
        <section id="security" class="border-t border-slate-200 bg-white py-24 dark:border-slate-800 dark:bg-slate-950">
            <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:gap-20 lg:px-8">
                <div class="reveal">
                    <p class="text-sm font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Security &amp; compliance</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Built the way security teams expect</h2>
                    <p class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-400">
                        People data deserves more than a shared password. {{ config('app.name') }} ships with the controls auditors ask about — on by default.
                    </p>
                    <ul class="mt-8 space-y-4">
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <div>
                                <p class="font-semibold">Two-factor authentication</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">TOTP authenticator apps or email codes, with recovery codes and login alerts.</p>
                            </div>
                        </li>
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <div>
                                <p class="font-semibold">Role-based access control</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">Granular permissions per role — HR, managers and employees see only what they should.</p>
                            </div>
                        </li>
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <div>
                                <p class="font-semibold">Complete audit trails</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">Every sensitive action is logged with actor, IP and device — exportable for reviews.</p>
                            </div>
                        </li>
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <div>
                                <p class="font-semibold">SSO &amp; API keys</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">OIDC single sign-on for enterprise identity providers, plus scoped API keys for integrations.</p>
                            </div>
                        </li>
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <div>
                                <p class="font-semibold">Data isolation &amp; privacy</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">Strict multi-tenant isolation, data export on request, and configurable retention windows.</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="reveal relative">
                    <div class="rounded-2xl border border-slate-200 bg-slate-950 p-8 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/15 text-indigo-400">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-white">Security at a glance</p>
                                <p class="text-xs text-slate-400">Enterprise defaults, zero configuration</p>
                            </div>
                        </div>
                        <div class="mt-6 space-y-3">
                            <div class="flex items-center justify-between rounded-lg bg-white/5 px-4 py-3">
                                <span class="text-sm text-slate-300">Encryption in transit</span>
                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">TLS 1.2+</span>
                            </div>
                            <div class="flex items-center justify-between rounded-lg bg-white/5 px-4 py-3">
                                <span class="text-sm text-slate-300">Password hashing</span>
                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">Bcrypt</span>
                            </div>
                            <div class="flex items-center justify-between rounded-lg bg-white/5 px-4 py-3">
                                <span class="text-sm text-slate-300">Login alerts</span>
                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">Built-in</span>
                            </div>
                            <div class="flex items-center justify-between rounded-lg bg-white/5 px-4 py-3">
                                <span class="text-sm text-slate-300">Audit log retention</span>
                                <span class="rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-xs font-semibold text-indigo-300">Configurable</span>
                            </div>
                            <div class="flex items-center justify-between rounded-lg bg-white/5 px-4 py-3">
                                <span class="text-sm text-slate-300">Tenant data isolation</span>
                                <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">Enforced</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Testimonials --}}
        <section id="customers" class="border-t border-slate-200 bg-slate-50 py-24 dark:border-slate-800 dark:bg-slate-900/40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Customers</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">People teams ship faster with {{ config('app.name') }}</h2>
                </div>

                <div class="mt-14 grid gap-6 lg:grid-cols-3">
                    <figure class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <blockquote class="text-sm leading-6 text-slate-600 dark:text-slate-300">
                            “We replaced three spreadsheets and a shared inbox with {{ config('app.name') }}. Payroll day went from a full day of panic to an hour of review.”
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">SR</span>
                            <div>
                                <p class="text-sm font-semibold">Sara Rahman</p>
                                <p class="text-xs text-slate-500">Head of People, Meridian</p>
                            </div>
                        </figcaption>
                    </figure>

                    <figure class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <blockquote class="text-sm leading-6 text-slate-600 dark:text-slate-300">
                            “The live attendance board alone paid for itself. Managers spot no-shows in minutes instead of finding out at month end.”
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-600 text-xs font-bold text-white">JT</span>
                            <div>
                                <p class="text-sm font-semibold">James Turner</p>
                                <p class="text-xs text-slate-500">Operations Director, Vertex Labs</p>
                            </div>
                        </figcaption>
                    </figure>

                    <figure class="reveal rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <blockquote class="text-sm leading-6 text-slate-600 dark:text-slate-300">
                            “SSO, audit logs and granular roles checked every box our security review asked for. Setup took an afternoon.”
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">AN</span>
                            <div>
                                <p class="text-sm font-semibold">Aisha Noor</p>
                                <p class="text-xs text-slate-500">IT Manager, Halcyon Group</p>
                            </div>
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>

        {{-- How it works --}}
        <section class="border-t border-slate-200 bg-white py-24 dark:border-slate-800 dark:bg-slate-950">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Onboarding</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Up and running in an afternoon</h2>
                </div>

                <ol class="mx-auto mt-14 grid max-w-5xl gap-10 sm:grid-cols-3">
                    <li class="reveal relative text-center">
                        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-900 text-sm font-bold text-white dark:bg-indigo-600">1</span>
                        <h3 class="mt-5 font-semibold">Create your workspace</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Register your company — you become the company admin with full control from day one.</p>
                    </li>
                    <li class="reveal relative text-center">
                        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-900 text-sm font-bold text-white dark:bg-indigo-600">2</span>
                        <h3 class="mt-5 font-semibold">Invite your team</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Add employees, assign roles, and configure email, SSO, and attendance rules to match your policies.</p>
                    </li>
                    <li class="reveal relative text-center">
                        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-900 text-sm font-bold text-white dark:bg-indigo-600">3</span>
                        <h3 class="mt-5 font-semibold">Run payroll &amp; track</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Attendance, leave and payroll flow into reports and payslips automatically — every month.</p>
                    </li>
                </ol>
            </div>
        </section>

        {{-- CTA --}}
        <section class="relative overflow-hidden border-t border-slate-800 bg-slate-950 py-24">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 glow"></div>
            <div class="relative mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Ready to leave spreadsheets behind?</h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-400">
                    Join thousands of companies running attendance, leave and payroll on {{ config('app.name') }}. Free for 14 days — no credit card required.
                </p>
                <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-white px-8 py-3.5 text-base font-semibold text-slate-900 shadow transition hover:bg-slate-100">
                        Start your free trial
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl border border-white/15 px-8 py-3.5 text-base font-semibold text-white transition hover:bg-white/5">
                        Log in
                    </a>
                </div>
            </div>
        </section>

        {{-- Footer --}}
        <footer class="border-t border-slate-800 bg-slate-950">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="grid gap-10 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <a href="/" class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-white">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                            </span>
                            <span class="text-base font-bold text-white">{{ config('app.name') }}</span>
                        </a>
                        <p class="mt-4 max-w-sm text-sm leading-6 text-slate-500">
                            The employee management platform for companies that take people operations seriously.
                        </p>
                        <div class="mt-6 flex items-center gap-2 text-xs text-slate-500">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
                            </span>
                            All systems operational
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-white">Product</p>
                        <ul class="mt-4 space-y-3 text-sm text-slate-500">
                            <li><a href="#features" class="transition hover:text-white">Features</a></li>
                            <li><a href="#security" class="transition hover:text-white">Security</a></li>
                            <li><a href="#customers" class="transition hover:text-white">Customers</a></li>
                            <li><a href="{{ route('register') }}" class="transition hover:text-white">Start free</a></li>
                        </ul>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-white">Company</p>
                        <ul class="mt-4 space-y-3 text-sm text-slate-500">
                            <li><a href="{{ route('privacy') }}" class="transition hover:text-white">Privacy policy</a></li>
                            <li><a href="{{ route('login') }}" class="transition hover:text-white">Log in</a></li>
                            <li><a href="{{ route('register') }}" class="transition hover:text-white">Create account</a></li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-slate-800 pt-8 sm:flex-row">
                    <p class="text-xs text-slate-600">© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                    <p class="flex items-center gap-2 text-xs font-medium text-slate-400">
                        Powered by
                        <img src="{{ asset('images/zecovex-lockup.png') }}" alt="Zecovex" class="h-8 w-auto mix-blend-lighten" width="124" height="26">
                    </p>
                </div>
            </div>
        </footer>

        <script>
            document.getElementById('theme-toggle').addEventListener('click', function () {
                var dark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
            });

            if ('IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    for (const e of entries) {
                        if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
                    }
                }, { threshold: 0.12 });
                document.querySelectorAll('.reveal').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('.reveal').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
