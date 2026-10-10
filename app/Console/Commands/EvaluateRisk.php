<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\RiskService;
use Illuminate\Console\Command;

/**
 * §15 daily sweep: evaluate the configured risk rules for every active
 * employee so windows that ended without a punch (rolling periods,
 * week-end) still raise their events. Derivation hooks cover real time.
 */
class EvaluateRisk extends Command
{
    protected $signature = 'risk:evaluate {--date= : Evaluate as of this YYYY-MM-DD date}';

    protected $description = 'Evaluate configured risk rules (§15): weekly late-risk and [OT] overwork notices.';

    public function handle(RiskService $risk): int
    {
        $date = $this->option('date') ?: now()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('The --date option must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $evaluated = 0;

        Employee::withoutGlobalScopes()
            ->where('status', 'active')
            ->chunkById(100, function ($employees) use ($risk, $date, &$evaluated) {
                foreach ($employees as $employee) {
                    $risk->evaluate($employee, $date);
                    $evaluated++;
                }
            });

        $this->info("Evaluated risk rules for {$evaluated} employees.");

        return self::SUCCESS;
    }
}
