<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('audit.view');

        $logs = AuditLog::with('actor:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
            ->when($request->filled('resource_type'), fn ($q) => $q->where('resource_type', $request->string('resource_type')->toString()))
            ->when($request->filled('actor'), function ($q) use ($request) {
                // actor=0 selects system/actorless entries.
                $request->integer('actor') === 0
                    ? $q->whereNull('actor_user_id')
                    : $q->where('actor_user_id', $request->integer('actor'));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $actions = AuditLog::select('action')->distinct()->orderBy('action')->pluck('action');
        $resourceTypes = AuditLog::select('resource_type')->distinct()
            ->whereNotNull('resource_type')->orderBy('resource_type')->pluck('resource_type');

        $actors = User::whereIn('id', AuditLog::select('actor_user_id')->distinct()->whereNotNull('actor_user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('audit.index', compact('logs', 'actions', 'resourceTypes', 'actors'));
    }

    /**
     * §71 — login history: filterable by status / division / user / date.
     * Surfaces the GeoIP-resolved location next to each attempt.
     */
    public function logins(Request $request): View
    {
        Gate::authorize('audit.view');

        $logins = UserLoginHistory::with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('login_status', $request->string('status')->toString()))
            ->when($request->filled('division'), fn ($q) => $q->where('geo_division', $request->string('division')->toString()))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $divisions = UserLoginHistory::select('geo_division')->distinct()
            ->whereNotNull('geo_division')->orderBy('geo_division')->pluck('geo_division');

        $users = User::whereIn('id', UserLoginHistory::select('user_id')->distinct()->whereNotNull('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('audit.logins', compact('logins', 'divisions', 'users'));
    }

    /**
     * Phase 8 — advanced audit: CSV export honouring the same filters as the
     * index screen.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('audit.view');

        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'actor', 'action', 'resource_type', 'resource_id', 'old_value', 'new_value', 'ip_address']);

            AuditLog::query()
                ->with('actor:id,name,email')
                ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
                ->when($request->filled('resource_type'), fn ($q) => $q->where('resource_type', $request->string('resource_type')->toString()))
                ->when($request->filled('actor'), function ($q) use ($request) {
                    $request->integer('actor') === 0
                        ? $q->whereNull('actor_user_id')
                        : $q->where('actor_user_id', $request->integer('actor'));
                })
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
                ->orderByDesc('id')
                ->chunk(500, function ($logs) use ($out) {
                    foreach ($logs as $log) {
                        fputcsv($out, [
                            $log->id,
                            $log->created_at?->toIso8601String(),
                            $log->actor?->email ?? 'system',
                            $log->action,
                            $log->resource_type,
                            $log->resource_id,
                            json_encode($log->old_value, JSON_UNESCAPED_UNICODE),
                            json_encode($log->new_value, JSON_UNESCAPED_UNICODE),
                            $log->ip_address,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
