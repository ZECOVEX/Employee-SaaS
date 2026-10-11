<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Log;

/**
 * Fan out an event to every matching active subscription in the org.
 * Delivery itself is queued (DeliverWebhook) so HTTP latency never blocks
 * the request that triggered the event.
 */
class WebhookService
{
    public function dispatch(string $event, int $organizationId, array $payload = []): int
    {
        $subscriptions = WebhookSubscription::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookSubscription $subscription) => $subscription->matches($event));

        foreach ($subscriptions as $subscription) {
            DeliverWebhook::dispatch($subscription->id, $event, [
                'event' => $event,
                'organization_id' => $organizationId,
                'data' => $payload,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        Log::info('webhooks.dispatched', [
            'event' => $event,
            'organization_id' => $organizationId,
            'matched' => $subscriptions->count(),
        ]);

        return $subscriptions->count();
    }
}
