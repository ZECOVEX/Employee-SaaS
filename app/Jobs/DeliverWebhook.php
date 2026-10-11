<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deliver a single webhook: JSON POST signed with HMAC-SHA256 over the raw
 * body. Records last delivery timestamp/status on the subscription so the
 * UI can surface failures.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $subscriptionId,
        public string $event,
        public array $body,
    ) {}

    public function handle(): void
    {
        $subscription = WebhookSubscription::find($this->subscriptionId);

        if ($subscription === null || ! $subscription->is_active) {
            return;
        }

        $payload = json_encode($this->body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $subscription->secret);

        try {
            $response = Http::withHeaders([
                'X-Webhook-Event' => $this->event,
                'X-Webhook-Signature' => 'sha256='.$signature,
            ])->withBody($payload, 'application/json')->timeout(10)->post($subscription->url);

            $status = ($response->successful() ? 'ok' : 'http_'.$response->status());
        } catch (\Throwable $e) {
            Log::warning('webhook.delivery_failed', [
                'subscription_id' => $subscription->id,
                'event' => $this->event,
                'error' => $e->getMessage(),
            ]);

            $status = 'error:'.substr($e->getMessage(), 0, 60);
        }

        $subscription->forceFill([
            'last_delivery_at' => now(),
            'last_status' => $status,
        ])->saveQuietly();
    }
}
