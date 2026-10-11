<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OvertimeApproval;
use App\Models\OvertimePolicy;
use App\Models\OvertimeRecord;
use App\Services\AuditLogger;
use App\Services\OvertimeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * §73-E expiry sweep: a pending record not reviewed within
 * pending_expiry_days is resolved per pending_expiry_action —
 * AUTO_APPROVE / AUTO_REJECT decide it (approver null = system, with the
 * employee notified), ESCALATE marks it EXPIRED so the queue shows it as
 * awaiting a late review. Runs daily; also safe to run by hand.
 */
class ExpirePendingOvertime extends Command
{
    protected $signature = 'overtime:expire-pending';

    protected $description = 'Expire pending overtime records after the policy window (auto-approve, auto-reject or escalate).';

    public function handle(OvertimeService $overtime, AuditLogger $audit): int
    {
        $processed = 0;

        OvertimeRecord::withoutGlobalScopes()
            ->where('status', OvertimeRecord::STATUS_PENDING)
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($overtime, $audit, &$processed): void {
                foreach ($records as $record) {
                    if ($this->expire($record, $overtime, $audit)) {
                        $processed++;
                    }
                }
            });

        $this->info("{$processed} pending overtime record(s) expired.");

        return self::SUCCESS;
    }

    private function expire(OvertimeRecord $record, OvertimeService $overtime, AuditLogger $audit): bool
    {
        $policy = $record->policy_id !== null
            ? OvertimePolicy::withoutGlobalScopes()->find($record->policy_id)
            : $overtime->policyFor((int) $record->organization_id, $record->work_date->toDateString());

        if ($policy === null || (int) $policy->pending_expiry_days <= 0) {
            return false;
        }

        $deadline = $record->created_at?->copy()->addDays((int) $policy->pending_expiry_days);

        if ($deadline === null || Carbon::now()->lt($deadline)) {
            return false;
        }

        $reason = sprintf('Expired after %d day(s) without review.', (int) $policy->pending_expiry_days);

        return (bool) match ($policy->pending_expiry_action) {
            OvertimePolicy::EXPIRE_AUTO_APPROVE => $overtime->approve($record, null, $reason),
            OvertimePolicy::EXPIRE_AUTO_REJECT => $overtime->reject($record, null, $reason),
            default => $this->escalate($record, $audit, $reason),
        };
    }

    /**
     * §73-E terminal mark: EXPIRED keeps the record out of re-derivation
     * (isLocked) while the queue still offers a late approve/reject.
     * One marker per record, so the next sweep leaves it alone.
     */
    private function escalate(OvertimeRecord $record, AuditLogger $audit, string $reason): bool
    {
        $already = OvertimeApproval::withoutGlobalScopes()
            ->where('overtime_record_id', $record->id)
            ->where('decision', OvertimeApproval::ESCALATED)
            ->exists();

        if ($already) {
            return false;
        }

        OvertimeApproval::withoutGlobalScopes()->create([
            'organization_id' => $record->organization_id,
            'overtime_record_id' => $record->id,
            'approver_user_id' => null,
            'decision' => OvertimeApproval::ESCALATED,
            'minutes_before' => (int) $record->countable_minutes,
            'minutes_after' => (int) $record->countable_minutes,
            'reason' => $reason,
            'decided_at' => now(),
        ]);

        $record->status = OvertimeRecord::STATUS_EXPIRED;
        $record->calculated_at = now();
        $record->save();

        $audit->log('overtime.escalated', $record, ['status' => OvertimeRecord::STATUS_PENDING], [
            'status' => OvertimeRecord::STATUS_EXPIRED,
            'reason' => $reason,
        ]);

        return true;
    }
}
