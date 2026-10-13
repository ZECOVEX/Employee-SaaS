<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\ScimToken;
use App\Models\User;
use App\Services\MailSettings;
use App\Services\RoleSeederService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Post-Phase 22 — brand Laravel's default password-reset email with
        // the recipient's organization name instead of config('app.name')
        // ("Regards, EmployeeSaaS" → "Regards, Zecovex"). The message is a
        // copy of ResetPassword::buildMailMessage with a salutation set.
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : (string) data_get($notifiable, 'email');

            return (new MailMessage)
                ->subject('Reset your password')
                ->line('You are receiving this email because we received a password reset request for your account.')
                ->action('Reset Password', url(route('password.reset', [
                    'token' => $token,
                    'email' => $email,
                ], false)))
                ->line(__('This password reset link will expire in :count minutes.', ['count' => $expire]))
                ->line('If you did not request a password reset, no further action is required.')
                ->salutation('Regards, '.MailSettings::brandFor($notifiable));
        });

        // Phase 8 — API keys and SCIM tokens authenticate through dedicated
        // request guards so they can share middleware stacks with sanctum.
        Auth::viaRequest('apikey', fn ($request) => ApiKey::resolveFromRequest($request));
        Auth::viaRequest('scim', fn ($request) => ScimToken::resolveFromRequest($request));
        Gate::before(function ($user, string $ability): ?bool {
            if ($user instanceof User && $user->is_platform_admin) {
                return true;
            }

            return null;
        });

        // Map permission keys to gates dynamically. The subject can be a
        // User (role-derived permissions) or an ApiKey/ScimToken (ability
        // list) — both expose canPermission().
        foreach (array_keys(RoleSeederService::CATALOG) as $key) {
            Gate::define($key, fn ($user) => method_exists($user, 'canPermission') && $user->canPermission($key));
        }

        Gate::define('platform.organizations', fn ($user) => $user instanceof User && $user->is_platform_admin);

        // Password-recovery support tickets: org admins, the manager role,
        // and anyone who actually manages a department (not a catalog key —
        // visibility follows departments.manager_id, so @can/middleware both
        // resolve through this gate).
        Gate::define('support.view', fn ($user) => $user instanceof User && ($user->hasRole('company_admin')
            || $user->hasRole('manager')
            || $user->managedDepartments()->exists()));

        View::composer('*', function ($view) {
            $user = auth()->user();
            if ($user && ! $user->is_platform_admin && $user->organization_id) {
                $view->with('currentOrganization', $user->organization);
            }
        });

        // Post-Phase 17 — apply the organization's SMTP settings to every
        // mail-channel notification just before it sends (covers queued
        // sends and Laravel's default ResetPassword notification too).
        // Post-Phase 18 — returning false cancels just the mail channel
        // when the feature is toggled off in Settings → Email (the in-app
        // database notification still goes out).
        Event::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            if ($event->channel !== 'mail') {
                return null;
            }

            $organization = $event->notifiable->organization ?? null;

            if (! $organization instanceof Organization) {
                return null;
            }

            $mail = app(MailSettings::class);
            $feature = $mail->featureKeyFor($event->notification);

            if ($feature !== null && ! $mail->featureEnabled($organization, $feature)) {
                return false;
            }

            $mail->apply($organization);

            return null;
        });
    }
}
