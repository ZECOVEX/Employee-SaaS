<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Billing &amp; Subscription</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif
            @if ($errors->has('employee_limit'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ $errors->first('employee_limit') }}</div>
            @endif

            {{-- Current plan + headcount usage --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-6">
                    <div>
                        <h3 class="font-semibold text-gray-800">Current plan</h3>
                        @if ($plan)
                            <p class="mt-1 text-2xl font-semibold text-indigo-700">{{ $plan->name }}</p>
                            <p class="text-sm text-gray-500">
                                {{ $plan->price_monthly > 0 ? $plan->priceLabel().' / month' : 'Free' }}
                                &middot;
                                {{ $plan->employee_limit === null ? 'Unlimited employees' : 'Up to '.$plan->employee_limit.' employees' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                Status: <span class="capitalize">{{ $subscription?->status ?? '—' }}</span>
                                @if ($subscription)
                                    &middot; Renews {{ $subscription->current_period_end->format('M j, Y') }}
                                @endif
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-600">
                                No active subscription — headcount limits are disabled until a plan is selected.
                            </p>
                        @endif
                    </div>

                    <div class="min-w-[240px]">
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <span>Active employees</span>
                            <span class="font-medium text-gray-900">
                                {{ $used }} / {{ $limit === null ? '∞' : $limit }}
                            </span>
                        </div>
                        @php
                            $pct = $limit ? min(100, (int) round(($used / max(1, $limit)) * 100)) : 0;
                            $barClass = $pct >= 100 ? 'bg-red-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-green-500');
                        @endphp
                        <div class="mt-2 h-2 rounded-full bg-gray-100">
                            <div class="h-2 rounded-full {{ $limit === null ? 'bg-indigo-400' : $barClass }}"
                                 style="width: {{ $limit === null ? 100 : $pct }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $limit === null
                                ? 'Unlimited — your plan has no headcount cap.'
                                : ($pct >= 100 ? 'Limit reached — upgrade to add more employees.' : 'Employees counted are status "active".') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Plan catalog --}}
            <div>
                <h3 class="mb-3 font-semibold text-gray-800">Plans</h3>
                @if ($plans->isEmpty())
                    <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500">
                        No plans have been published yet. Run <code>php artisan db:seed --class=PlanSeeder</code> to load the catalog.
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach ($plans as $p)
                            <div class="flex flex-col rounded-lg border bg-white p-5
                                        {{ $plan?->id === $p->id ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200' }}">
                                <div class="font-semibold text-gray-800">{{ $p->name }}</div>
                                <div class="mt-1 text-xl font-semibold text-gray-900">
                                    {{ $p->priceLabel() }}
                                    @if ($p->price_monthly > 0)
                                        <span class="text-sm font-normal text-gray-400">/mo</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-sm text-gray-500">
                                    {{ $p->employee_limit === null ? 'Unlimited employees' : 'Up to '.$p->employee_limit.' employees' }}
                                </div>
                                <div class="mt-auto pt-4">
                                    @if ($plan?->id === $p->id)
                                        <span class="inline-block rounded bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-600">
                                            Current plan
                                        </span>
                                    @else
                                        <form action="{{ route('billing.plan') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="plan_id" value="{{ $p->id }}" />
                                            <button type="submit"
                                                    class="w-full rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                                Switch
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Invoices --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">Invoices</h3>
                    <span class="text-xs text-gray-500">Most recent first</span>
                </div>

                @if ($invoices->isEmpty())
                    <p class="px-6 py-8 text-sm text-gray-500">
                        No invoices yet — they appear when a paid plan is selected.
                    </p>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Number</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Plan</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Amount</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Period</th>
                                <th class="px-6 py-3 text-left font-medium text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="px-6 py-3 font-mono text-xs text-gray-700">{{ $invoice->number }}</td>
                                    <td class="px-6 py-3">{{ $invoice->plan?->name ?? '—' }}</td>
                                    <td class="px-6 py-3">
                                        ${{ number_format($invoice->amount, 2) }}
                                        <span class="text-xs text-gray-400">{{ $invoice->currency }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-gray-600">
                                        {{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}
                                    </td>
                                    <td class="px-6 py-3 capitalize">{{ $invoice->status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <p class="text-xs text-gray-500">
                Plan changes are recorded locally — no payment is charged in this environment.
                A payment provider (e.g. Stripe) plugs in here later (§54).
            </p>
        </div>
    </div>
</x-app-layout>
