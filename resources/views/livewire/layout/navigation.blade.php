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

    public function recentNotifications(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        return $user->notifications()->latest()->take(5)->get()->all();
    }

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

        $links = [
            [
                'label' => 'Dashboard',
                'route' => $user->canPermission('dashboard.admin') ? 'dashboard.admin' : 'dashboard.employee',
                'active' => 'dashboard*',
                'can' => true,
            ],
            ['label' => 'Employees', 'route' => 'employees.index', 'active' => 'employees.*', 'can' => $can('employees.view')],
            ['label' => 'Departments', 'route' => 'departments.index', 'active' => 'departments.*', 'can' => $can('departments.view')],
            [
                'label' => 'Attendance',
                'route' => 'attendance.index',
                'active' => ['attendance.index', 'attendance.create', 'attendance.employee', 'attendance.events.*'],
                'can' => $can('attendance.view'),
            ],
            [
                'label' => 'Live board',
                'route' => 'attendance.live.index',
                'active' => 'attendance.live*',
                'can' => $can('attendance.view'),
            ],
            ['label' => 'Leave', 'route' => 'leave.index', 'active' => 'leave*', 'can' => $can('leave.view')],
            // Phase 5 — self-service overtime (no permission key) + approvals (§73-E/I).
            ['label' => 'Overtime', 'route' => 'overtime.index', 'active' => 'overtime.index', 'can' => true],
            ['label' => 'OT approvals', 'route' => 'overtime.queue', 'active' => 'overtime.queue', 'can' => $can('overtime.manage')],
            ['label' => 'Salary', 'route' => 'salary.index', 'active' => 'salary.*', 'can' => $can('salary.view')],
            ['label' => 'NFC Cards', 'route' => 'nfc-cards.index', 'active' => 'nfc-cards.*', 'can' => $can('nfc.view')],
            ['label' => 'Users', 'route' => 'users.index', 'active' => 'users.*', 'can' => $can('users.view')],
            ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'active' => 'audit-logs.*', 'can' => $can('audit.view')],
            ['label' => 'Reports', 'route' => 'reports.attendance', 'active' => 'reports.*', 'can' => $can('reports.view')],
            ['label' => 'Analytics', 'route' => 'analytics.index', 'active' => 'analytics.*', 'can' => $can('reports.view')],
            ['label' => 'Settings', 'route' => 'settings.edit', 'active' => ['settings.*', 'schedule.*'], 'can' => $can('settings.manage')],
            ['label' => 'Billing', 'route' => 'billing.index', 'active' => 'billing.*', 'can' => $can('settings.manage')],
            ['label' => 'Platform', 'route' => 'platform.organizations', 'active' => 'platform.*', 'can' => $user->is_platform_admin],
        ];

        // Employees without attendance.view still need their own history (§4).
        if ($user->employee && ! $can('attendance.view')) {
            $links[] = [
                'label' => 'My Attendance',
                'route' => 'attendance.employee',
                'params' => ['employee' => $user->employee->id],
                'active' => 'attendance.employee',
                'can' => true,
            ];
        }

        // Self-service salary view (salary.view_own, no salary.view).
        if ($user->employee && ! $can('salary.view') && $can('salary.view_own')) {
            $links[] = [
                'label' => 'My Salary',
                'route' => 'salary.show',
                'params' => ['employee' => $user->employee->id],
                'active' => 'salary.show',
                'can' => true,
            ];
        }

        // Self-service monthly statistics (§28) — own numbers only.
        if ($user->employee) {
            $links[] = [
                'label' => 'Statistics',
                'route' => 'statistics.index',
                'active' => 'statistics.*',
                'can' => true,
            ];
        }

        return $links;
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate class="font-semibold text-indigo-600">
                        EmployeeSaaS
                    </a>
                </div>

                <div class="hidden space-x-6 sm:-my-px sm:ms-10 sm:flex">
                    @foreach ($this->links() as $link)
                        @if ($link['can'])
                            <x-nav-link :href="route($link['route'], $link['params'] ?? [])" :active="request()->routeIs($link['active'])" wire:navigate>
                                {{ $link['label'] }}
                            </x-nav-link>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                @if (! empty($currentOrganization ?? null))
                    <span class="me-3 text-xs text-gray-500 uppercase tracking-wide">
                        {{ $currentOrganization->name }}
                    </span>
                @endif

                <x-dropdown align="right" width="80">
                    <x-slot name="trigger">
                        <button class="relative me-2 inline-flex items-center px-2 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150"
                                title="Notifications">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                            @if ($this->unreadNotificationCount() > 0)
                                <span class="absolute -top-1 -right-1 inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold leading-none">
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
                            <a href="{{ route('notifications.read', $notification->id) }}"
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
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
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

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @foreach ($this->links() as $link)
                @if ($link['can'])
                    <x-responsive-nav-link :href="route($link['route'], $link['params'] ?? [])" :active="request()->routeIs($link['active'])" wire:navigate>
                        {{ $link['label'] }}
                    </x-responsive-nav-link>
                @endif
            @endforeach
        </div>

        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ auth()->user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
