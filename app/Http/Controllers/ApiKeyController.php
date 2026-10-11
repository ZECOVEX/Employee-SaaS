<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\ScimToken;
use App\Services\AuditLogger;
use App\Services\RoleSeederService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Phase 8 — issue/revoke org API keys (`/api/v1`) and SCIM tokens
 * (`/api/scim/v2`). Secrets are displayed exactly once at creation.
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('settings.manage');

        $organizationId = $request->user()->organization_id;

        return view('settings.api-keys', [
            'keys' => ApiKey::where('organization_id', $organizationId)
                ->with('creator:id,name')
                ->orderByDesc('id')
                ->get(),
            'tokens' => ScimToken::where('organization_id', $organizationId)
                ->with('creator:id,name')
                ->orderByDesc('id')
                ->get(),
            'catalog' => RoleSeederService::CATALOG,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', array_keys(RoleSeederService::CATALOG)).',*'],
        ]);

        $abilities = $data['abilities'] ?? null;

        $secret = ApiKey::issue(
            $request->user()->organization,
            $data['name'],
            $abilities === [] ? null : $abilities,
            $request->user(),
        );

        $this->audit->log('apikey.created', null, null, [
            'name' => $data['name'],
            'abilities' => $abilities === [] ? ['*'] : $abilities,
        ]);

        return redirect()
            ->route('api-keys.index')
            ->with('new_api_key', $secret)
            ->with('status', 'API key created. Copy it now â€” it will not be shown again.');
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        Gate::authorize('settings.manage');
        abort_unless($apiKey->organization_id === $request->user()->organization_id, 404);

        $apiKey->forceFill(['revoked_at' => now()])->save();

        $this->audit->log('apikey.revoked', null, null, ['name' => $apiKey->name]);

        return redirect()
            ->route('api-keys.index')
            ->with('status', 'API key revoked.');
    }

    public function storeScimToken(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $data = $request->validate([
            'token_name' => ['required', 'string', 'max:80'],
        ]);

        $token = ScimToken::issue(
            $request->user()->organization,
            $data['token_name'],
            $request->user(),
        );

        $this->audit->log('scim.token_created', null, null, ['name' => $data['token_name']]);

        return redirect()
            ->route('api-keys.index')
            ->with('new_scim_token', $token)
            ->with('status', 'SCIM token created. Copy it now â€” it will not be shown again.');
    }

    public function destroyScimToken(Request $request, ScimToken $scimToken): RedirectResponse
    {
        Gate::authorize('settings.manage');
        abort_unless($scimToken->organization_id === $request->user()->organization_id, 404);

        $scimToken->forceFill(['revoked_at' => now()])->save();

        $this->audit->log('scim.token_revoked', null, null, ['name' => $scimToken->name]);

        return redirect()
            ->route('api-keys.index')
            ->with('status', 'SCIM token revoked.');
    }
}
