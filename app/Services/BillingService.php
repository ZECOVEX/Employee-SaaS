<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Subscription billing (§54). Deliberately decoupled from payment providers:
 * there are no charges in this environment — plan changes and invoices are
 * recorded here, and a provider (Stripe etc.) plugs in later without
 * touching callers.
 */
class BillingService
{
    public function subscriptionFor(Organization|int $organization): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('organization_id', $this->organizationId($organization))
            ->where('status', '!=', 'canceled')
            ->latest('id')
            ->first();
    }

    public function planFor(Organization|int $organization): ?Plan
    {
        return $this->subscriptionFor($organization)?->plan;
    }

    /** Active-employee cap for the org's plan; null = unlimited or no plan (never blocks). */
    public function employeeLimit(Organization|int $organization): ?int
    {
        return $this->planFor($organization)?->employee_limit;
    }

    public function activeEmployeeCount(Organization|int $organization): int
    {
        return Employee::withoutGlobalScopes()
            ->where('organization_id', $this->organizationId($organization))
            ->where('status', 'active')
            ->count();
    }

    public function canAddEmployee(Organization|int $organization): bool
    {
        $limit = $this->employeeLimit($organization);

        return $limit === null || $this->activeEmployeeCount($organization) < $limit;
    }

    /**
     * Blocks employee creation once the plan's headcount cap is reached.
     * Organizations without a subscription/plan are never blocked.
     */
    public function ensureCanAddEmployee(Organization|int $organization): void
    {
        if ($this->canAddEmployee($organization)) {
            return;
        }

        throw ValidationException::withMessages([
            'employee_limit' => 'Your plan allows '.$this->employeeLimit($organization)
                .' active employees. Switch to a larger plan on the Billing page to add more.',
        ]);
    }

    /** Give a newly provisioned organization the free plan when the catalog is seeded. */
    public function provisionDefault(Organization $organization): ?Subscription
    {
        $plan = Plan::where('slug', 'free')->where('is_active', true)->first();

        if (! $plan) {
            return null;
        }

        return Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addYear()->toDateString(),
        ]);
    }

    public function switchPlan(Organization|int $organization, Plan $plan): Subscription
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->firstOrNew(['organization_id' => $this->organizationId($organization)]);

        $subscription->plan_id = $plan->id;

        if (! $subscription->exists || $subscription->status === 'canceled') {
            $subscription->status = 'active';
        }

        // A plan change starts a fresh billing period.
        $subscription->current_period_start = now()->toDateString();
        $subscription->current_period_end = now()->addMonth()->toDateString();

        $subscription->save();

        return $subscription;
    }

    public function issueInvoice(Organization|int $organization, Plan $plan): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->organizationId($organization),
            'plan_id' => $plan->id,
            'number' => 'INV-'.now()->format('Ym').'-'.Str::upper(Str::random(6)),
            'amount' => $plan->price_monthly,
            'currency' => 'USD',
            'status' => 'open',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'paid_at' => null,
        ]);
    }

    private function organizationId(Organization|int $organization): int
    {
        return $organization instanceof Organization ? (int) $organization->id : (int) $organization;
    }
}
