<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function unreadNotificationCount(): int
    {
        $user = auth()->user();

        return $user ? $user->notifications()->whereNull('read_at')->count() : 0;
    }

    /**
     * Non-active employment status ('inactive' | 'terminated') for the badge
     * next to the user's name; null for active employees and non-employees.
     */
    public function employmentStatus(): ?string
    {
        $status = auth()->user()?->employee?->status;

        return ($status !== null && $status !== 'active') ? $status : null;
    }

    public function recentNotifications(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        return $user->notifications()->latest()->take(5)->get()->all();
    }

    /**
     * Navigation tree (all roles): the left console sidebar renders every
     * visible destination — Dashboard, the section groups (People,
     * Attendance, Payroll, Reports, Settings) with their children, Leave
     * and Notifications — while the slim top bar holds search, the theme
     * toggle, the notification bell and the user menu. Groups without
     * visible children are dropped; groups with a single visible child
     * collapse to that plain link (see prune()).
     */
    public function links(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }
        if ($user->is_platform_admin && ! $user->organization_id) {
            return [
                ['label' => 'Platform', 'route' => 'platform.organizations', 'active' => 'platform.*', 'can' => true],
            ];
        }

        $can = fn (string $p) => $user->canPermission($p);

        // Employees without attendance.view still need their own history.
        $myAttendance = ($user->employee && ! $can('attendance.view'))
            ? [[
                'label' => 'My Attendance',
                'route' => 'attendance.employee',
                'params' => ['employee' => $user->employee->id],
                'active' => 'attendance.employee',
                'can' => true,
            ]]
            : [];

        // Self-service salary view (salary.view_own, no salary.view).
        $mySalary = ($user->employee && ! $can('salary.view') && $can('salary.view_own'))
            ? [[
                'label' => 'My Salary',
                'route' => 'salary.show',
                'params' => ['employee' => $user->employee->id],
                'active' => 'salary.show',
                'can' => true,
            ]]
            : [];

        $links = [
            [
                'label' => 'Dashboard',
                'route' => $user->canPermission('dashboard.admin') ? 'dashboard.admin' : 'dashboard.employee',
                'active' => 'dashboard*',
                'can' => true,
            ],
            [
                'label' => 'People',
                'active' => ['employees.*', 'departments.*', 'positions.*', 'users.*'],
                'children' => [
                    ['label' => 'Employees', 'route' => 'employees.index', 'active' => 'employees.*', 'can' => $can('employees.view')],
                    ['label' => 'Departments', 'route' => 'departments.index', 'active' => 'departments.*', 'can' => $can('departments.view')],
                    ['label' => 'Positions', 'route' => 'positions.index', 'active' => 'positions.*', 'can' => $can('positions.manage')],
                    ['label' => 'Users', 'route' => 'users.index', 'active' => 'users.*', 'can' => $can('users.view')],
                ],
            ],
            [
                'label' => 'Attendance',
                'active' => ['attendance.index', 'attendance.create', 'attendance.employee', 'attendance.events.*', 'attendance.live*', 'nfc-cards.*', 'terminals.*'],
                'children' => array_merge([
                    ['label' => 'Attendance', 'route' => 'attendance.index', 'active' => ['attendance.index', 'attendance.create', 'attendance.employee', 'attendance.events.*'], 'can' => $can('attendance.view')],
                    ['label' => 'Live board', 'route' => 'attendance.live.index', 'active' => 'attendance.live*', 'can' => $can('attendance.view')],
                    [
                        'label' => 'Devices',
                        'active' => ['nfc-cards.*', 'terminals.*'],
                        'children' => [
                            ['label' => 'NFC Cards', 'route' => 'nfc-cards.index', 'active' => ['nfc-cards.index', 'nfc-cards.history'], 'can' => $can('nfc.view')],
                            ['label' => 'Terminals', 'route' => 'terminals.index', 'active' => 'terminals.*', 'can' => $can('attendance.terminal')],
                        ],
                    ],
                ], $myAttendance),
            ],
            ['label' => 'Leave', 'route' => 'leave.index', 'active' => 'leave*', 'can' => $can('leave.view')],
            // Personal inbox — the bell covers the top bar; the sidebar
            // still lists it for every role (§64).
            ['label' => 'Notifications', 'route' => 'notifications.index', 'active' => 'notifications.*', 'can' => true, 'mobile_only' => true],
            // Time & pay: self-service overtime, approvals and salary.
            [
                'label' => 'Payroll',
                'active' => ['salary.*', 'overtime.index', 'overtime.queue'],
                'children' => array_merge([
                    ['label' => 'Overtime', 'route' => 'overtime.index', 'active' => 'overtime.index', 'can' => true],
                    ['label' => 'OT approvals', 'route' => 'overtime.queue', 'active' => 'overtime.queue', 'can' => $can('overtime.manage')],
                    ['label' => 'Salary', 'route' => 'salary.index', 'active' => 'salary.*', 'can' => $can('salary.view')],
                ], $mySalary),
            ],
            // Personal monthly statistics sit with the org reports; without
            // reports.view the group collapses to a plain "Statistics" link.
            [
                'label' => 'Reports',
                'active' => ['reports.attendance', 'reports.monthly', 'reports.overtime', 'reports.salary', 'analytics.*', 'statistics.*'],
                'children' => [
                    ['label' => 'Statistics', 'route' => 'statistics.index', 'active' => 'statistics.*', 'can' => (bool) $user->employee],
                    ['label' => 'Attendance Report', 'route' => 'reports.attendance', 'active' => 'reports.attendance', 'can' => $can('reports.view')],
                    ['label' => 'Monthly Report', 'route' => 'reports.monthly', 'active' => 'reports.monthly', 'can' => $can('reports.view')],
                    ['label' => 'Overtime Report', 'route' => 'reports.overtime', 'active' => 'reports.overtime', 'can' => $can('reports.view')],
                    ['label' => 'Salary Report', 'route' => 'reports.salary', 'active' => 'reports.salary', 'can' => $can('reports.view') && $can('salary.view')],
                    ['label' => 'Analytics', 'route' => 'analytics.index', 'active' => 'analytics.*', 'can' => $can('reports.view')],
                ],
            ],
            [
                'label' => 'Settings',
                'active' => ['settings.*', 'schedule.*', 'audit-logs.*', 'billing.*', 'roles.*', 'sso.*', 'mail-settings.*', 'webhooks.*', 'api-keys.*', 'support.*', 'platform.*'],
                'children' => [
                    ['label' => 'Settings', 'route' => 'settings.edit', 'active' => ['settings.edit', 'settings.update', 'schedule.*'], 'can' => $can('settings.manage')],
                    ['label' => 'Single Sign-On', 'route' => 'sso.edit', 'active' => 'sso.*', 'can' => $can('settings.manage')],
                    ['label' => 'Email', 'route' => 'mail-settings.edit', 'active' => 'mail-settings.*', 'can' => $can('settings.manage')],
                    ['label' => 'API & Provisioning', 'route' => 'api-keys.index', 'active' => ['api-keys.*', 'scim-tokens.*'], 'can' => $can('settings.manage')],
                    ['label' => 'Webhooks', 'route' => 'webhooks.index', 'active' => 'webhooks.*', 'can' => $can('settings.manage')],
                    ['label' => 'Roles', 'route' => 'roles.index', 'active' => 'roles.*', 'can' => $can('users.manage')],
                    ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'active' => 'audit-logs.*', 'can' => $can('audit.view')],
                    ['label' => 'Billing', 'route' => 'billing.index', 'active' => 'billing.*', 'can' => $can('settings.manage')],
                    ['label' => 'Support', 'route' => 'support.index', 'active' => 'support.*', 'can' => $user->can('support.view')],
                    ['label' => 'Platform', 'route' => 'platform.organizations', 'active' => 'platform.*', 'can' => $user->is_platform_admin],
                ],
            ],
        ];

        return $this->prune($links);
    }

    /**
     * Drop items the user cannot see, collapse a group whose only visible
     * child is a plain link into that link, and remove empty groups so the
     * sidebar stays flat where there is nothing to nest.
     */
    private function prune(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! isset($item['children'])) {
                if ($item['can']) {
                    $out[] = $item;
                }

                continue;
            }

            $children = $this->prune($item['children']);

            if ($children === []) {
                continue;
            }

            if (count($children) === 1 && ! isset($children[0]['children'])) {
                $out[] = $children[0];

                continue;
            }

            $item['children'] = $children;
            $out[] = $item;
        }

        return $out;
    }
}; ?>

<div class="contents" x-data="{ sidebarOpen: false }">
    {{-- Mobile backdrop (the sidebar slides over the page below xl). --}}
    <div x-show="sidebarOpen" style="display: none;" x-transition.opacity
         class="fixed inset-0 z-[45] bg-slate-950/50 xl:hidden"
         @click="sidebarOpen = false" aria-hidden="true"></div>

    {{-- Left console sidebar --}}
    <aside id="app-sidebar"
           class="app-sidebar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col transition-transform duration-200 ease-out xl:translate-x-0 xl:transition-none"
           :class="sidebarOpen ? '!translate-x-0' : ''">
        <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-gray-100 px-5">
            @if (auth()->user()?->organization?->logo_path)
                <img src="{{ asset('storage/'.auth()->user()->organization->logo_path) }}"
                     alt="" aria-hidden="true" width="26" height="26" class="app-brand-logo" />
            @else
                <span class="app-brand-mark" aria-hidden="true"></span>
            @endif
            <a id="app-brand" href="{{ route('dashboard') }}" wire:navigate
               class="font-semibold text-indigo-600 whitespace-nowrap">{{ auth()->user()?->organization?->name ?? config('app.name') }}</a>
            <button type="button" class="app-sidebar-close ms-auto xl:hidden" @click="sidebarOpen = false"
                    aria-label="Close navigation" title="Close">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav class="app-sidebar-nav" aria-label="Primary">
            @foreach ($this->links() as $link)
                @include('livewire.layout.partials.sidebar-item', ['item' => $link])
            @endforeach
        </nav>
    </aside>

    {{-- Slim top bar --}}
    <header class="app-topbar fixed top-0 right-0 left-0 z-30 xl:left-64">
        <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
            <button type="button" class="app-icon-btn xl:hidden" @click="sidebarOpen = ! sidebarOpen"
                    aria-label="Open navigation" title="Menu">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            @if (auth()->user()?->canPermission('employees.view') || auth()->user()?->canPermission('nfc.view'))
                <div class="relative hidden sm:block"
                     x-data="{
                         q: '',
                         results: null,
                         open: false,
                         async search() {
                             if (this.q.trim().length < 2) {
                                 this.results = null;
                                 this.open = false;

                                 return;
                             }
                             const response = await fetch('{{ route('search.index') }}?q=' + encodeURIComponent(this.q), { headers: { 'Accept': 'application/json' } });
                             if (response.ok) {
                                 this.results = await response.json();
                                 this.open = true;
                             }
                         }
                     }"
                     @click.outside="open = false">
                    <input type="search" x-model="q" @input.debounce.300ms="search()" @focus="open = !!results"
                           placeholder="Search&hellip;" aria-label="Search employees and cards"
                           class="app-search-input w-44 lg:w-72 rounded-md text-sm" />
                    <div x-show="open && results" style="display: none;"
                         class="absolute end-0 top-full z-50 mt-2 w-80 max-h-96 overflow-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
                        <template x-if="results && (results.employees.length > 0 || results.cards.length > 0)">
                            <div>
                                <template x-for="employee in results.employees" :key="'employee-' + employee.id">
                                    <a :href="employee.url" wire:navigate @click="open = false"
                                       class="block px-4 py-2 hover:bg-gray-50">
                                        <span class="text-sm font-medium text-gray-800" x-text="employee.name"></span>
                                        <span class="ms-2 text-xs text-gray-400" x-text="employee.code"></span>
                                        <span class="block text-xs text-gray-500">
                                            <span x-text="employee.department || ''"></span><template x-if="employee.designation"><span> &middot; <span x-text="employee.designation"></span></span></template>
                                        </span>
                                    </a>
                                </template>
                                <template x-for="card in results.cards" :key="'card-' + card.token">
                                    <a :href="card.url" wire:navigate @click="open = false"
                                       class="block px-4 py-2 hover:bg-gray-50 border-t border-gray-100">
                                        <span class="text-sm font-medium text-gray-800" x-text="card.token"></span>
                                        <span class="ms-2 text-xs uppercase text-gray-400" x-text="card.status"></span>
                                        <span class="block text-xs text-gray-500" x-text="card.employee || 'Unassigned'"></span>
                                    </a>
                                </template>
                            </div>
                        </template>
                        <template x-if="results && results.employees.length === 0 && results.cards.length === 0">
                            <div class="px-4 py-3 text-sm text-gray-500">No matches.</div>
                        </template>
                    </div>
                </div>
            @endif

            <div class="ms-auto flex items-center gap-1 sm:gap-2">
                <button type="button" class="app-theme-toggle inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition duration-150 ease-in-out"
                        title="Toggle dark mode" aria-label="Toggle dark mode">
                    <svg class="h-5 w-5 dark:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                    </svg>
                    <svg class="h-5 w-5 hidden dark:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                    </svg>
                </button>

                <x-dropdown align="right" width="80">
                    <x-slot name="trigger">
                        <button class="relative me-1 inline-flex items-center justify-center p-2 border border-transparent rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition ease-in-out duration-150"
                                title="Notifications">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                            @if ($this->unreadNotificationCount() > 0)
                                <span class="absolute -top-1 -right-1 inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-violet-500 text-white text-[10px] font-bold leading-none">
                                    {{ $this->unreadNotificationCount() > 99 ? '99+' : $this->unreadNotificationCount() }}
                                </span>
                            @endif
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                            Notifications
                        </div>
                        @forelse ($this->recentNotifications() as $notification)
                            <a href="{{ route('notifications.read', $notification->id) }}" wire:navigate
                               class="block px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                <p class="text-sm font-medium text-gray-800 {{ $notification->read_at ? '' : 'text-indigo-700' }}">
                                    {{ $notification->data['title'] ?? 'Notification' }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 line-clamp-2">{{ $notification->data['body'] ?? '' }}</p>
                            </a>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">No notifications yet.</p>
                        @endforelse
                        <x-dropdown-link :href="route('notifications.index')" wire:navigate>
                            View all notifications
                        </x-dropdown-link>
                    </x-slot>
                </x-dropdown>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-2 sm:px-3 py-2 border border-transparent rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none transition ease-in-out duration-150">
                            <x-avatar size="sm" class="me-2" />
                            <div class="hidden sm:block" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                            @if ($status = $this->employmentStatus())
                                <span class="ms-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $status === 'terminated' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ ucfirst($status) }}
                                </span>
                            @endif
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </header>
</div>
