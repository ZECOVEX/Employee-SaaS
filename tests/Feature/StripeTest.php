<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class StripeTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function configureStripe(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_123',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);
    }

    protected function signPayload(string $payload, string $secret = 'whsec_test', ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return 't='.$timestamp.',v1='.$signature;
    }

    public function test_webhook_returns_503_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);

        $this->postJson('/api/stripe/webhook', ['type' => 'ping'])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->configureStripe();

        $this->withHeaders(['Stripe-Signature' => 't='.time().',v1=bad'])
            ->postJson('/api/stripe/webhook', ['type' => 'ping'])
            ->assertStatus(400);
    }

    public function test_checkout_session_completion_activates_the_subscription(): void
    {
        $this->configureStripe();
        ['organization' => $org] = $this->makeOrganization();
        $this->seedPlans();
        $plan = Plan::where('slug', 'business')->firstOrFail();

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'customer' => 'cus_test_1',
                'subscription' => 'sub_test_1',
                'metadata' => [
                    'organization_id' => (string) $org->id,
                    'plan_id' => (string) $plan->id,
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->withHeaders(['Stripe-Signature' => $this->signPayload($payload)])
            ->call('POST', '/api/stripe/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->signPayload($payload),
            ], $payload)
            ->assertOk()
            ->assertJson(['received' => true]);

        $subscription = Subscription::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('sub_test_1', $subscription->stripe_subscription_id);
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.stripe_checkout_completed']);
    }

    public function test_subscription_deleted_event_cancels_the_local_row(): void
    {
        $this->configureStripe();
        ['organization' => $org] = $this->makeOrganization();
        $this->seedPlans();
        $plan = Plan::where('slug', 'business')->firstOrFail();

        Subscription::create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'stripe_subscription_id' => 'sub_del',
            'stripe_customer_id' => 'cus_del',
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
        ]);

        $payload = json_encode([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_del',
                'customer' => 'cus_del',
                'status' => 'canceled',
                'metadata' => ['organization_id' => (string) $org->id],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signPayload($payload),
        ], $payload)->assertOk();

        $this->assertSame('canceled', Subscription::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->firstOrFail()
            ->status);
    }

    public function test_invoice_paid_marks_the_open_invoice_paid(): void
    {
        $this->configureStripe();
        ['organization' => $org] = $this->makeOrganization();
        $this->seedPlans();
        $plan = Plan::where('slug', 'business')->firstOrFail();

        $invoice = Invoice::create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'number' => 'INV-TEST-1',
            'amount' => 29.0,
            'currency' => 'USD',
            'status' => 'open',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'paid_at' => null,
        ]);

        $payload = json_encode([
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_test',
                'amount_paid' => 2900,
                'metadata' => ['organization_id' => (string) $org->id],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signPayload($payload),
        ], $payload)->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_expired_timestamps_are_rejected(): void
    {
        $this->configureStripe();

        $payload = json_encode(['type' => 'ping']);
        $old = time() - 3600;
        $signature = hash_hmac('sha256', $old.'.'.$payload, 'whsec_test');

        $this->withHeaders(['Stripe-Signature' => 't='.$old.',v1='.$signature])
            ->call('POST', '/api/stripe/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$old.',v1='.$signature,
            ], $payload)
            ->assertStatus(400);
    }

    public function test_checkout_redirect_creates_a_stripe_session(): void
    {
        $this->configureStripe();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->seedPlans();

        $plan = Plan::where('slug', 'business')->firstOrFail();
        $plan->forceFill(['stripe_price_id' => 'price_pro'])->save();

        // Sequence: customer then checkout session.
        Http::fakeSequence('api.stripe.com/*')
            ->push(['id' => 'cus_new'], 200)
            ->push(['id' => 'cs_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_123'], 200);

        $response = $this->actingAs($admin)->post(route('billing.stripe_checkout'), [
            'plan_id' => $plan->id,
        ]);

        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_123');
    }

    public function test_checkout_is_404_for_plans_without_a_price_id(): void
    {
        $this->configureStripe();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->seedPlans();

        $plan = Plan::where('slug', 'business')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('billing.stripe_checkout'), ['plan_id' => $plan->id])
            ->assertNotFound();
    }

    public function test_stripe_service_verifies_signatures(): void
    {
        $this->configureStripe();

        $service = app(StripeService::class);
        $payload = '{"type":"ping"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $request = Request::create('/api/stripe/webhook', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $payload);

        $this->assertTrue($service->verifyWebhookSignature($request));

        $bad = Request::create('/api/stripe/webhook', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1=wrong',
        ], $payload);

        $this->assertFalse($service->verifyWebhookSignature($bad));
    }
}
