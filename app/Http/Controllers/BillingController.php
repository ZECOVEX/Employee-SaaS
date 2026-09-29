<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Services\AuditLogger;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('settings.manage');

        $organization = $request->user()->organization;

        return view('billing.index', [
            'organization' => $organization,
            'subscription' => $this->billing->subscriptionFor($organization),
            'plan' => $this->billing->planFor($organization),
            'plans' => Plan::where('is_active', true)->orderBy('price_monthly')->get(),
            'used' => $this->billing->activeEmployeeCount($organization),
            'limit' => $this->billing->employeeLimit($organization),
            'invoices' => Invoice::where('organization_id', $organization->id)
                ->with('plan:id,name,slug')
                ->latest()
                ->limit(12)
                ->get(),
        ]);
    }

    public function updatePlan(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $organization = $request->user()->organization;

        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        $current = $this->billing->planFor($organization);

        if ($current?->id === $plan->id) {
            return redirect()
                ->route('billing.index')
                ->with('status', 'Already on the '.$plan->name.' plan.');
        }

        $old = $current
            ? ['plan' => $current->slug, 'price_monthly' => $current->price_monthly]
            : ['plan' => null];

        $subscription = $this->billing->switchPlan($organization, $plan);

        if ($plan->price_monthly > 0) {
            $this->billing->issueInvoice($organization, $plan);
        }

        $this->audit->log('subscription.plan_changed', $subscription, $old, [
            'plan' => $plan->slug,
            'price_monthly' => $plan->price_monthly,
        ]);

        return redirect()
            ->route('billing.index')
            ->with('status', 'Plan changed to '.$plan->name.'.');
    }
}
