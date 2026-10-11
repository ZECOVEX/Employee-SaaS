<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Phase 8 — per-org OIDC SSO settings (stored in organization.settings with
 * an encrypted client secret). Discovery URL is used to locate the
 * authorization/token/userinfo endpoints at login time.
 */
class SsoSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $settings = $organization->settings ?? [];

        return view('settings.sso', [
            'organization' => $organization,
            'sso' => [
                'enabled' => (bool) ($settings['sso_enabled'] ?? false),
                'name' => (string) ($settings['sso_name'] ?? ''),
                'discovery_url' => (string) ($settings['sso_discovery_url'] ?? ''),
                'client_id' => (string) ($settings['sso_client_id'] ?? ''),
                'allowed_domains' => (string) ($settings['sso_allowed_domains'] ?? ''),
                'has_secret' => ! empty($settings['sso_client_secret']),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $data = $request->validate([
            'sso_enabled' => ['nullable', 'in:0,1'],
            'sso_name' => ['required', 'string', 'max:80'],
            'sso_discovery_url' => ['required', 'url', 'max:500'],
            'sso_client_id' => ['required', 'string', 'max:190'],
            'sso_client_secret' => ['nullable', 'string', 'max:190'],
            'sso_allowed_domains' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = $organization->settings ?? [];
        $old = [
            'sso_enabled' => (bool) ($settings['sso_enabled'] ?? false),
            'sso_name' => $settings['sso_name'] ?? null,
            'sso_client_id' => isset($settings['sso_client_id']) ? '[set]' : null,
        ];

        $settings['sso_enabled'] = $data['sso_enabled'] === '1';
        $settings['sso_name'] = $data['sso_name'];
        $settings['sso_discovery_url'] = rtrim($data['sso_discovery_url'], '/');
        $settings['sso_client_id'] = $data['sso_client_id'];

        if ($request->filled('sso_client_secret')) {
            $settings['sso_client_secret'] = encrypt($data['sso_client_secret']);
        }

        $settings['sso_allowed_domains'] = array_values(array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) $data['sso_allowed_domains'])),
        )));

        $organization->update(['settings' => $settings]);

        $this->audit->log('sso.settings_updated', $organization, $old, [
            'sso_enabled' => $settings['sso_enabled'],
            'sso_name' => $settings['sso_name'],
            'sso_client_id' => '[set]',
            'sso_client_secret' => $request->filled('sso_client_secret') ? '[rotated]' : '[unchanged]',
            'sso_allowed_domains' => $settings['sso_allowed_domains'],
        ]);

        return redirect()
            ->route('sso.edit')
            ->with('status', 'SSO settings saved.');
    }

    /**
     * Validate the discovery document + credentials by exchanging a dummy
     * authorization request (token endpoint reachability only).
     */
    public function test(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $settings = $organization->settings ?? [];

        if (empty($settings['sso_discovery_url'])) {
            throw ValidationException::withMessages(['status' => 'No discovery URL configured.']);
        }

        try {
            $discovery = Http::timeout(10)
                ->get(rtrim($settings['sso_discovery_url'], '/').'/.well-known/openid-configuration')
                ->throw()
                ->json();

            $endpoints = collect(['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'])
                ->filter(fn ($key) => empty($discovery[$key]))
                ->values();

            if ($endpoints->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'Discovery document is missing: '.implode(', ', $endpoints),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'status' => 'Discovery failed: '.substr($e->getMessage(), 0, 120),
            ]);
        }

        return back()->with('status', 'Discovery OK — '.$discovery['issuer']);
    }
}
