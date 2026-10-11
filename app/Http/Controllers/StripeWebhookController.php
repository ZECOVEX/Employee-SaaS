<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\Billing\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 — Stripe webhook endpoint (POST /api/stripe/webhook). The raw
 * body is signature-verified with the shared secret; handlers reconcile
 * checkout, subscription lifecycle and invoice payments into local rows.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->stripe->isConfigured() || $this->stripe->webhookSecret() === '') {
            return response()->json(['error' => 'Stripe is not configured.'], 503);
        }

        if (! $this->stripe->verifyWebhookSignature($request)) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! isset($event['type'])) {
            return response()->json(['error' => 'Malformed payload.'], 400);
        }

        $type = $event['type'];
        $object = $event['data']['object'] ?? [];

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.updated', 'customer.subscription.deleted' => $this->stripe->syncSubscription($object),
            'invoice.paid' => $this->stripe->markInvoicePaid($object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    protected function handleCheckoutCompleted(array $session): void
    {
        $organizationId = (int) ($session['metadata']['organization_id'] ?? 0);

        $this->stripe->activateFromCheckout($session);

        if ($organizationId !== 0) {
            $organization = Organization::find($organizationId);

            if ($organization !== null) {
                $this->audit->log('subscription.stripe_checkout_completed', $organization, null, [
                    'subscription' => (string) ($session['subscription'] ?? ''),
                ]);
            }
        }
    }
}
