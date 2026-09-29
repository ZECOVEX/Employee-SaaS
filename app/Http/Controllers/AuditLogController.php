<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('audit.view');

        $logs = AuditLog::with('actor:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
            ->when($request->filled('resource_type'), fn ($q) => $q->where('resource_type', $request->string('resource_type')->toString()))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $actions = AuditLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return view('audit.index', compact('logs', 'actions'));
    }
}
