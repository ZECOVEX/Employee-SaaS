<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserLoginHistory;
use App\Services\AuditLogger;
use App\Services\Geo\LoginHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 8 — guest OIDC login: /sso/{organization} redirects to the IdP,
 * /sso/callback completes the code exchange (userinfo-based identity, no
 * local JWT verification) and auto-provisions employee accounts.
 */
class SsoLoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function redirectToProvider(Request $request, Organization $organization): RedirectResponse
    {
        if (! $organization->isActive() || ! ($organization->settings['sso_enabled'] ?? false)) {
            abort(404);
        }

        $discovery = $this->discover($organization);

        $state = Str::random(40);
        $nonce = Str::random(40);

        session([
            'sso.state' => $state,
            'sso.nonce' => $nonce,
            'sso.organization_id' => $organization->id,
        ]);

        $url = $discovery['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $organization->settings['sso_client_id'],
            'redirect_uri' => route('sso.callback'),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');

        if ($state === null || $state !== session('sso.state')) {
            return redirect()->route('login')->withErrors(['email' => 'SSO state mismatch. Please try again.']);
        }

        $organization = Organization::find(session('sso.organization_id'));
        session()->forget(['sso.state', 'sso.nonce', 'sso.organization_id']);

        if ($organization === null || ! $organization->isActive() || ! ($organization->settings['sso_enabled'] ?? false)) {
            return redirect()->route('login')->withErrors(['email' => 'SSO is not enabled for this organization.']);
        }

        if (($request->query('error')) !== null) {
            return redirect()->route('login')->withErrors(['email' => 'SSO error: '.Str::limit((string) $request->query('error_description', $request->query('error')), 120)]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('login')->withErrors(['email' => 'SSO callback missing authorization code.']);
        }

        try {
            $discovery = $this->discover($organization);

            $tokenResponse = Http::asForm()->timeout(15)->post($discovery['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('sso.callback'),
                'client_id' => $organization->settings['sso_client_id'],
                'client_secret' => decrypt($organization->settings['sso_client_secret']),
            ])->throw()->json();

            $accessToken = $tokenResponse['access_token'] ?? null;

            if (! is_string($accessToken)) {
                throw new \RuntimeException('Token response missing access_token.');
            }

            $identity = Http::withToken($accessToken)
                ->timeout(15)
                ->get($discovery['userinfo_endpoint'])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            $this->audit->log('sso.login_failed', $organization, null, ['error' => substr($e->getMessage(), 0, 200)]);

            return redirect()->route('login')->withErrors(['email' => 'SSO login failed: '.Str::limit($e->getMessage(), 120)]);
        }

        $email = strtolower((string) ($identity['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return redirect()->route('login')->withErrors(['email' => 'SSO identity has no valid email.']);
        }

        $allowed = $organization->settings['sso_allowed_domains'] ?? [];

        if ($allowed !== [] && ! in_array(Str::after($email, '@'), $allowed, true)) {
            return redirect()->route('login')->withErrors(['email' => 'Your email domain is not allowed for SSO.']);
        }

        $user = User::where('email', $email)->first();

        if ($user !== null && $user->organization_id !== $organization->id) {
            return redirect()->route('login')->withErrors(['email' => 'This email belongs to a different organization.']);
        }

        if ($user === null) {
            $user = User::create([
                'organization_id' => $organization->id,
                'name' => (string) ($identity['name'] ?? Str::before($email, '@')),
                'email' => $email,
                'password' => Str::password(32, true, true, true, false),
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);

            $role = Role::where('organization_id', $organization->id)
                ->where('slug', 'employee')
                ->first();

            if ($role !== null) {
                $user->roles()->attach($role->id);
            }

            $this->audit->log('sso.user_provisioned', $user, null, ['email' => $email]);
        }

        $this->audit->log('sso.login', $user, null, ['email' => $email]);

        // §43 — admin-controlled deactivation blocks SSO logins too.
        if ($user->isDeactivated()) {
            return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        app(LoginHistoryService::class)->recordFromRequest($user, UserLoginHistory::STATUS_SUCCESS);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    protected function discover(Organization $organization): array
    {
        $baseUrl = rtrim((string) ($organization->settings['sso_discovery_url'] ?? ''), '/');

        if ($baseUrl === '') {
            abort(404, 'SSO discovery URL is not configured.');
        }

        $discovery = Http::timeout(10)
            ->get($baseUrl.'/.well-known/openid-configuration')
            ->throw()
            ->json();

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            abort_unless(! empty($discovery[$key]), 404, 'Discovery document missing '.$key.'.');
        }

        return $discovery;
    }
}
