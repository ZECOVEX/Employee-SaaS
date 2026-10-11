<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WebhookSubscription;
use App\Services\AuditLogger;
use App\Services\Webhooks\WebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Phase 8 — webhook subscription management. Secrets shown once; deliveries
 * are signed HMAC-SHA256 (see DeliverWebhook).
 */
class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('settings.manage');

        return view('settings.webhooks', [
            'subscriptions' => WebhookSubscription::where('organization_id', $request->user()->organization_id)
                ->orderByDesc('id')
                ->get(),
            'events' => WebhookEvent::ALL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_merge(WebhookEvent::ALL, ['*']))],
        ]);

        $this->guardAgainstPrivateUrl($data['url']);

        $secret = Str::random(48);

        $subscription = WebhookSubscription::create([
            'organization_id' => $request->user()->organization_id,
            'url' => $data['url'],
            'events' => array_values($data['events']),
            'secret' => $secret,
            'is_active' => true,
        ]);

        $this->audit->log('webhook.created', $subscription, null, [
            'url' => $data['url'],
            'events' => $data['events'],
        ]);

        return redirect()
            ->route('webhooks.index')
            ->with('new_webhook_secret', $secret)
            ->with('status', 'Webhook created. Copy the signing secret now — it will not be shown again.');
    }

    public function destroy(Request $request, WebhookSubscription $webhook): RedirectResponse
    {
        Gate::authorize('settings.manage');
        abort_unless($webhook->organization_id === $request->user()->organization_id, 404);

        $this->audit->log('webhook.deleted', $webhook, null, ['url' => $webhook->url]);
        $webhook->delete();

        return redirect()
            ->route('webhooks.index')
            ->with('status', 'Webhook deleted.');
    }

    /**
     * Block obviously-internal targets (basic SSRF hygiene).
     */
    protected function guardAgainstPrivateUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            throw ValidationException::withMessages(['url' => 'Invalid URL.']);
        }

        $blocked = [
            'localhost', '127.0.0.1', '0.0.0.0', '::1',
            'metadata.google.internal', '169.254.169.254',
        ];

        if (in_array(strtolower($host), $blocked, true) || str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            throw ValidationException::withMessages([
                'url' => 'Webhook URLs cannot point at private or local addresses.',
            ]);
        }
    }
}
