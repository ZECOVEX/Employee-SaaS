<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 9 — Stripe via the REST API over Laravel's HTTP client (testable
 * with Http::fake). Plans without a `stripe_price_id` keep the legacy
 * direct switch on the Billing page; checkout only handles paid prices.
 *
 * Credentials come from config('services.stripe.*') — when the secret key
 * is absent every entry point degrades to the legacy path.
 */
class StripeService
{
    private const BASE = 'https://api.stripe.com/v1';

    public function isConfigured(): bool
    {
        return is_string(config('services.stripe.secret')) && config('services.stripe.secret') !== '';
    }

    public function webhookSecret(): string
    {
        return (string) config('services.stripe.webhook_secret');
    }

    /**
     * Create (or reuse) the org's Stripe customer and a Checkout Session for
     * the plan's recurring price. Returns the session URL.
     */
    public function checkoutUrl(Organization $organization, Plan $plan): string
    {
        $customerId = $this->ensureCustomer($organization);

        $session = $this->request('POST', '/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items[0][price]' => $plan->stripe_price_id,
            'line_items[0][quantity]' => 1,
            'success_url' => route('billing.index', ['checkout' => 'success']).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.index', ['checkout' => 'canceled']),
            'metadata[organization_id]' => (string) $organization->id,
            'metadata[plan_id]' => (string) $plan->id,
            'subscription_data[metadata][organization_id]' => (string) $organization->id,
            'subscription_data[metadata][plan_id]' => (string) $plan->id,
        ]);

        return $session['url'];
    }

    /**
     * Create a Stripe Billing Portal session so admins can manage payment
     * methods, view invoices and cancel the subscription on Stripe's side.
     */
    public function customerPortalUrl(Organization $organization): string
    {
        $customerId = $this->ensureCustomer($organization);

        $session = $this->request('POST', '/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => route('billing.index'),
        ]);

        return $session['url'];
    }

    /**
     * Verify a Stripe-Signature header against the raw payload (t=,v1=).
     */
    public function verifyWebhookSignature(Request $request, ?int $tolerance = 300): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '') {
            return false;
        }

        $header = (string) $request->header('Stripe-Signature');
        $payload = $request->getContent();

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, null);

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconcile a checkout.session.completed event: activate the org's
     * subscription with the Stripe ids.
     */
    public function activateFromCheckout(array $session): void
    {
        $organizationId = (int) ($session['metadata']['organization_id'] ?? 0);
        $planId = (int) ($session['metadata']['plan_id'] ?? 0);

        if ($organizationId === 0 || $planId === 0) {
            return;
        }

        $plan = Plan::find($planId);

        if ($plan === null) {
            return;
        }

        $subscriptionId = (string) ($session['subscription'] ?? '');
        $customerId = (string) ($session['customer'] ?? '');

        $this->activate($organizationId, $plan, $customerId, $subscriptionId);
    }

    /**
     * Reconcile customer.subscription.updated/deleted events.
     */
    public function syncSubscription(array $stripeSubscription): void
    {
        $organizationId = (int) ($stripeSubscription['metadata']['organization_id'] ?? 0);

        if ($organizationId === 0) {
            $subscriptionId = (string) ($stripeSubscription['id'] ?? '');

            if ($subscriptionId === '') {
                return;
            }

            $local = Subscription::withoutGlobalScopes()
                ->where('stripe_subscription_id', $subscriptionId)
                ->first();

            $organizationId = $local?->organization_id ?? 0;
        }

        if ($organizationId === 0) {
            return;
        }

        $status = match ($stripeSubscription['status'] ?? '') {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default => null,
        };

        $local = Subscription::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->first();

        if ($local === null || $status === null) {
            return;
        }

        $local->forceFill([
            'status' => $status,
            'stripe_customer_id' => (string) ($stripeSubscription['customer'] ?? $local->stripe_customer_id),
            'stripe_subscription_id' => (string) ($stripeSubscription['id'] ?? $local->stripe_subscription_id),
        ])->save();
    }

    /**
     * Mark the org's invoice record paid when an invoice.paid event arrives.
     */
    public function markInvoicePaid(array $invoice): void
    {
        $organizationId = (int) ($invoice['metadata']['organization_id'] ?? 0);

        if ($organizationId === 0) {
            return;
        }

        $amountPaid = ((int) ($invoice['amount_paid'] ?? 0)) / 100;

        $record = Invoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', '!=', 'paid')
            ->latest('id')
            ->first();

        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => 'paid',
            'amount' => $amountPaid > 0 ? $amountPaid : $record->amount,
            'paid_at' => now(),
        ])->save();
    }

    protected function activate(int $organizationId, Plan $plan, string $customerId, string $subscriptionId): void
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->firstOrNew(['organization_id' => $organizationId]);

        $subscription->plan_id = $plan->id;
        $subscription->status = 'active';
        $subscription->stripe_customer_id = $customerId !== '' ? $customerId : $subscription->stripe_customer_id;
        $subscription->stripe_subscription_id = $subscriptionId !== '' ? $subscriptionId : $subscription->stripe_subscription_id;
        $subscription->current_period_start = now()->toDateString();
        $subscription->current_period_end = now()->addMonth()->toDateString();
        $subscription->save();
    }

    protected function ensureCustomer(Organization $organization): string
    {
        $existing = Subscription::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNotNull('stripe_customer_id')
            ->value('stripe_customer_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $customer = $this->request('POST', '/customers', [
            'name' => $organization->name,
            'metadata[organization_id]' => (string) $organization->id,
            'description' => 'Employee SaaS tenant #'.$organization->id,
        ]);

        return $customer['id'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $parameters): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.stripe.secret'),
            'Idempotency-Key' => Str::uuid()->toString(),
        ])->timeout(20)->asForm();

        $response = match ($method) {
            'POST' => $response->post(self::BASE.$path, $parameters),
            default => $response->get(self::BASE.$path),
        };

        return $response->throw()->json();
    }
}
