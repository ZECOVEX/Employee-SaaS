<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookEvent;
use App\Services\Webhooks\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_webhooks_page_requires_settings_manage(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $hr = $this->makeUser($org, 'hr', $roles);
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($hr)->get(route('webhooks.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('webhooks.index'))->assertOk();
    }

    public function test_creating_a_webhook_shows_the_secret_once_and_audits(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->post(route('webhooks.store'), [
                'url' => 'https://hooks.example.test/employee',
                'events' => ['*'],
            ])
            ->assertRedirect(route('webhooks.index'))
            ->assertSessionHas('new_webhook_secret');

        $this->assertDatabaseHas('webhook_subscriptions', [
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.test/employee',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.created']);

        $secret = session('new_webhook_secret');
        $this->actingAs($admin)
            ->get(route('webhooks.index'))
            ->assertSee($secret);

        $this->actingAs($admin)
            ->get(route('webhooks.index'))
            ->assertDontSee($secret);
    }

    public function test_private_urls_are_rejected(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->from(route('webhooks.index'))
            ->post(route('webhooks.store'), [
                'url' => 'http://localhost/hook',
                'events' => ['*'],
            ])
            ->assertSessionHasErrors('url');

        $this->actingAs($admin)
            ->from(route('webhooks.index'))
            ->post(route('webhooks.store'), [
                'url' => 'http://169.254.169.254/hook',
                'events' => ['*'],
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_dispatch_signs_and_delivers_to_matching_subscriptions(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $secret = 's3cret';
        $subscription = WebhookSubscription::create([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.test/employee',
            'events' => [WebhookEvent::EMPLOYEE_CREATED],
            'secret' => $secret,
            'is_active' => true,
        ]);

        Http::fake([
            'hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $matched = app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_CREATED,
            (int) $org->id,
            ['employee_id' => 1],
        );

        $this->assertSame(1, $matched);

        Http::assertSent(function ($request) use ($secret) {
            $signature = hash_hmac('sha256', $request->body(), $secret);

            return $request->url() === 'https://hooks.example.test/employee'
                && $request->header('X-Webhook-Event')[0] === WebhookEvent::EMPLOYEE_CREATED
                && $request->header('X-Webhook-Signature')[0] === 'sha256='.$signature
                && json_decode($request->body(), true)['event'] === WebhookEvent::EMPLOYEE_CREATED;
        });

        $subscription->refresh();
        $this->assertNotNull($subscription->last_delivery_at);
        $this->assertSame('ok', $subscription->last_status);
    }

    public function test_event_filter_and_inactive_flag_skip_delivery(): void
    {
        ['organization' => $org] = $this->makeOrganization();

        WebhookSubscription::create([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.test/leave-only',
            'events' => [WebhookEvent::LEAVE_APPROVED],
            'secret' => 'abc',
            'is_active' => true,
        ]);

        WebhookSubscription::create([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.test/inactive',
            'events' => ['*'],
            'secret' => 'def',
            'is_active' => false,
        ]);

        Bus::fake();

        $matched = app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_CREATED,
            (int) $org->id,
        );

        $this->assertSame(0, $matched);
        Bus::assertNotDispatched(DeliverWebhook::class);
    }

    public function test_dispatch_is_scoped_to_the_organization(): void
    {
        ['organization' => $org] = $this->makeOrganization();
        ['organization' => $other] = $this->makeOrganization('Other Co');

        WebhookSubscription::create([
            'organization_id' => $other->id,
            'url' => 'https://hooks.example.test/other',
            'events' => ['*'],
            'secret' => 'xyz',
            'is_active' => true,
        ]);

        Bus::fake();

        $matched = app(WebhookService::class)->dispatch(
            WebhookEvent::EMPLOYEE_CREATED,
            (int) $org->id,
        );

        $this->assertSame(0, $matched);
        Bus::assertNotDispatched(DeliverWebhook::class);
    }

    public function test_admin_can_delete_a_subscription(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $subscription = WebhookSubscription::create([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.test/gone',
            'events' => ['*'],
            'secret' => 'abc',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('webhooks.destroy', $subscription))
            ->assertRedirect(route('webhooks.index'));

        $this->assertDatabaseMissing('webhook_subscriptions', ['id' => $subscription->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.deleted']);
    }
}
