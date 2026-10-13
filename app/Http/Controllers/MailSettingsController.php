<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Notifications\MailTest;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Post-Phase 17 — per-org outgoing email (SMTP) settings. Admins point
 * the app at their own mail/webmail server (host, port, SSL/TLS,
 * username/password, from identity); every mail-channel notification is
 * then sent through it (see App\Services\MailSettings).
 */
class MailSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $settings = $organization->settingsArray();

        return view('settings.mail', [
            'organization' => $organization,
            'mail' => [
                'enabled' => (bool) ($settings['mail_enabled'] ?? false),
                'host' => (string) ($settings['mail_host'] ?? ''),
                'port' => (int) ($settings['mail_port'] ?? 587),
                'encryption' => (string) ($settings['mail_encryption'] ?? 'tls'),
                'username' => (string) ($settings['mail_username'] ?? ''),
                'has_password' => ! empty($settings['mail_password']),
                'from_address' => (string) ($settings['mail_from_address'] ?? config('mail.from.address')),
                'from_name' => (string) ($settings['mail_from_name'] ?? config('mail.from.name')),
            ],
            'features' => app(MailSettings::class)->resolvedFeatures($organization),
            'featureLabels' => MailSettings::FEATURES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $data = $request->validate([
            'mail_enabled' => ['nullable', 'in:0,1'],
            'mail_host' => ['required', 'string', 'max:190'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['required', Rule::in(['none', 'tls', 'ssl'])],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:190'],
            'mail_from_address' => ['required', 'email', 'max:190'],
            'mail_from_name' => ['required', 'string', 'max:80'],

            // Post-Phase 18 — per-feature email allow/deny (absent = none).
            'mail_features' => ['nullable', 'array'],
            'mail_features.*' => ['string', Rule::in(array_keys(MailSettings::FEATURES))],
        ]);

        $settings = $organization->settingsArray();
        $old = [
            'mail_enabled' => (bool) ($settings['mail_enabled'] ?? false),
            'mail_host' => $settings['mail_host'] ?? null,
            'mail_port' => isset($settings['mail_port']) ? (int) $settings['mail_port'] : null,
            'mail_encryption' => $settings['mail_encryption'] ?? null,
            'mail_username' => $settings['mail_username'] ?? null,
            'mail_from_address' => $settings['mail_from_address'] ?? null,
            'mail_from_name' => $settings['mail_from_name'] ?? null,
            'mail_features' => $settings['mail_features'] ?? null,
        ];

        $settings['mail_enabled'] = $data['mail_enabled'] === '1';
        $settings['mail_host'] = $data['mail_host'];
        $settings['mail_port'] = (int) $data['mail_port'];
        $settings['mail_encryption'] = $data['mail_encryption'];
        $settings['mail_username'] = (string) $data['mail_username'];

        if ($request->filled('mail_password')) {
            $settings['mail_password'] = encrypt($data['mail_password']);
        }

        $settings['mail_from_address'] = $data['mail_from_address'];
        $settings['mail_from_name'] = $data['mail_from_name'];
        $settings['mail_features'] = array_values($data['mail_features'] ?? []);

        $organization->update(['settings' => $settings]);

        $this->audit->log('mail.settings_updated', $organization, $old, [
            'mail_enabled' => $settings['mail_enabled'],
            'mail_host' => $settings['mail_host'],
            'mail_port' => $settings['mail_port'],
            'mail_encryption' => $settings['mail_encryption'],
            'mail_username' => $settings['mail_username'],
            'mail_password' => $request->filled('mail_password') ? '[rotated]' : '[unchanged]',
            'mail_from_address' => $settings['mail_from_address'],
            'mail_from_name' => $settings['mail_from_name'],
            'mail_features' => $settings['mail_features'],
        ]);

        return redirect()
            ->route('mail-settings.edit')
            ->with('status', 'Email settings saved.');
    }

    /** Send a test email to the acting admin through the saved settings. */
    public function test(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        if (empty($organization->settingsArray()['mail_host'])) {
            throw ValidationException::withMessages(['status' => 'No SMTP host configured yet.']);
        }

        try {
            $request->user()->notify(new MailTest);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'status' => 'SMTP test failed: '.Str::limit(strip_tags($e->getMessage()), 160),
            ]);
        }

        return back()->with('status', 'Test email sent to '.$request->user()->email.' — check the inbox (and spam).');
    }
}
