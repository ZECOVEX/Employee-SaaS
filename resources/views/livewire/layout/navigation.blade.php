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

        return [
            [
                'label' => 'Dashboard',
                'route' => $user->canPermission('dashboard.admin') ? 'dashboard.admin' : 'dashboard.employee',
                'active' => 'dashboard*',
                'can' => true,
            ],
            ['label' => 'Employees', 'route' => 'employees.index', 'active' => 'employees.*', 'can' => $can('employees.view')],
            ['label' => 'Departments', 'route' => 'departments.index', 'active' => 'departments.*', 'can' => $can('departments.view')],
            ['label' => 'Attendance', 'route' => 'attendance.index', 'active' => 'attendance*', 'can' => $can('attendance.view')],
            ['label' => 'Leave', 'route' => 'leave.index', 'active' => 'leave*', 'can' => $can('leave.view')],
            ['label' => 'NFC Cards', 'route' => 'nfc-cards.index', 'active' => 'nfc-cards.*', 'can' => $can('nfc.view')],
            ['label' => 'Users', 'route' => 'users.index', 'active' => 'users.*', 'can' => $can('users.view')],
            ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'active' => 'audit-logs.*', 'can' => $can('audit.view')],
            ['label' => 'Settings', 'route' => 'settings.edit', 'active' => ['settings.*', 'schedule.*'], 'can' => $can('settings.manage')],
            ['label' => 'Platform', 'route' => 'platform.organizations', 'active' => 'platform.*', 'can' => $user->is_platform_admin],
        ];
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
                            <x-nav-link :href="route($link['route'])" :active="request()->routeIs($link['active'])" wire:navigate>
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
                    <x-responsive-nav-link :href="route($link['route'])" :active="request()->routeIs($link['active'])" wire:navigate>
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
