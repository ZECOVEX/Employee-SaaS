<?php

namespace App\Console\Commands;

use App\Services\MonthlyStatusService;
use Illuminate\Console\Command;

/**
 * §17 monthly status generation. Runs on the 1st at 00:30 — after the
 * 00:10 pending-overtime expiry sweep, so the finalized record stores the
 * resulting approved/rejected values. Existing rows are never rewritten.
 */
class GenerateMonthlyStatus extends Command
{
    protected $signature = 'status:generate {--month= : Month to snapshot in YYYY-MM (default: last month)}';

    protected $description = 'Generate finalized employee monthly status records (§17).';

    public function handle(MonthlyStatusService $service): int
    {
        $month = $this->option('month') ?: now()->subMonth()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('The --month option must be YYYY-MM.');

            return self::FAILURE;
        }

        $created = $service->generate($month);

        $this->info("Generated {$created} monthly status records for {$month}.");

        return self::SUCCESS;
    }
}
