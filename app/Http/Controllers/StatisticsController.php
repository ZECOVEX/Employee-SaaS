<?php

namespace App\Http\Controllers;

use App\Services\SalaryCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    /**
     * Employee's own monthly salary statistics & daily breakdown (§28).
     *
     * No permission middleware: every employee may see their own numbers.
     * Users without an employee profile get 403.
     */
    public function index(Request $request, SalaryCalculator $calculator)
    {
        $user = $request->user();
        abort_unless((bool) $user->employee, 403);

        $validated = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'month.regex' => 'The month must be in YYYY-MM format.',
        ]);

        $organization = $user->organization;
        $tz = $organization?->timezone ?? config('app.timezone');
        $currentMonth = Carbon::now($tz)->format('Y-m');
        $month = $validated['month'] ?? $currentMonth;

        $previousMonth = Carbon::createFromFormat('Y-m-d', $month.'-01', $tz)
            ->subMonthNoOverflow()
            ->format('Y-m');
        $nextMonth = Carbon::createFromFormat('Y-m-d', $month.'-01', $tz)
            ->addMonthNoOverflow()
            ->format('Y-m');

        $stats = $calculator->estimate($user->employee, $month);

        return view('statistics.index', [
            'month' => $month,
            'currentMonth' => $currentMonth,
            'previousMonth' => $previousMonth,
            'nextMonth' => $nextMonth,
            'stats' => $stats,
            'currency' => $organization?->currency() ?? 'BDT',
            'employee' => $user->employee,
        ]);
    }
}
