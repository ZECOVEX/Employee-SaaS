<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('audit.view');

        $query = AuditLog::with('actor:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
            ->orderByDesc('created_at');

        return AuditLogResource::collection(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))->withQueryString(),
        );
    }
}
