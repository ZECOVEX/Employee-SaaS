<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    /**
     * Organization-wide analytics: attendance trends, department comparison,
     * weekday lateness and payroll progress (charts are pure CSS — no JS
     * chart library needed).
     */
    public function index(ReportService $reports): View
    {
        Gate::authorize('reports.view');

        return view('analytics.index', [
            'analytics' => $reports->analytics((int) request()->user()->organization_id),
        ]);
    }
}
