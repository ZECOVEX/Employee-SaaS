<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\UserLoginHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §43 — data retention: prune audit logs and login history older than the
 * per-org retention windows (organizations.settings.audit_retention_days /
 * login_retention_days, defaults 365 / 180 days). Platform-owned tables
 * without an org fall back to the defaults. Scheduled daily.
 */
class PrunePersonalData extends Command
{
    protected $signature = 'privacy:prune';

    protected $description = 'Prune audit logs and login history past the configured retention windows (§43).';

    public function handle(): int
    {
        $auditDays = (int) config('privacy.audit_retention_days', 365);
        $loginDays = (int) config('privacy.login_retention_days', 180);

        $auditCutoff = now()->subDays($auditDays);
        $loginCutoff = now()->subDays($loginDays);

        // Per-org overrides: organizations may shorten (never lengthen past
        // the default window) via settings.
        $orgs = DB::table('organizations')
            ->whereNotNull('settings')
            ->get(['id', 'settings']);

        $auditDeletes = 0;
        $loginDeletes = 0;

        foreach ($orgs as $org) {
            $settings = is_string($org->settings) ? (array) json_decode($org->settings, true) : (array) $org->settings;
            $orgAuditDays = isset($settings['audit_retention_days']) ? (int) $settings['audit_retention_days'] : null;
            $orgLoginDays = isset($settings['login_retention_days']) ? (int) $settings['login_retention_days'] : null;

            if ($orgAuditDays !== null && $orgAuditDays > 0 && $orgAuditDays < $auditDays) {
                $auditDeletes += AuditLog::withoutGlobalScopes()
                    ->where('organization_id', $org->id)
                    ->where('created_at', '<', now()->subDays($orgAuditDays))
                    ->delete();
            }

            if ($orgLoginDays !== null && $orgLoginDays > 0 && $orgLoginDays < $loginDays) {
                $loginDeletes += UserLoginHistory::withoutGlobalScopes()
                    ->where('organization_id', $org->id)
                    ->where('created_at', '<', now()->subDays($orgLoginDays))
                    ->delete();
            }
        }

        // Default window for every row (console context has no session user,
        // so the organization global scope must be bypassed).
        $auditDeletes += AuditLog::withoutGlobalScopes()
            ->where('created_at', '<', $auditCutoff)
            ->delete();
        $loginDeletes += UserLoginHistory::withoutGlobalScopes()
            ->where('created_at', '<', $loginCutoff)
            ->delete();

        $this->info("Pruned {$auditDeletes} audit log rows and {$loginDeletes} login history rows.");

        return self::SUCCESS;
    }
}
