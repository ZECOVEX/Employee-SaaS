<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTerminalRequest;
use App\Models\AttendanceTerminal;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TerminalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('attendance.terminal');

        $terminals = AttendanceTerminal::orderBy('name')->get();

        return view('terminals.index', compact('terminals'));
    }

    public function store(StoreTerminalRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $plainKey = 'term_'.Str::random(40);

        AttendanceTerminal::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'radius_meters' => $data['radius_meters'] ?? 100,
            'require_location' => (bool) ($data['require_location'] ?? false),
            'allowed_ips' => array_values(array_filter(array_map('trim', $data['allowed_ips'] ?? []))),
            'key_hash' => hash('sha256', $plainKey),
            'status' => 'active',
        ]);

        $this->audit->log('terminal.created', null, null, [
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'radius_meters' => $data['radius_meters'] ?? 100,
            'require_location' => (bool) ($data['require_location'] ?? false),
            'allowed_ips' => $data['allowed_ips'] ?? [],
        ]);

        return redirect()
            ->route('terminals.index')
            ->with('status', 'Terminal created. Copy this API key now — it will not be shown again: '.$plainKey);
    }

    public function destroy(AttendanceTerminal $terminal): RedirectResponse
    {
        Gate::authorize('attendance.terminal');

        $old = $terminal->only(['name', 'status']);
        $terminal->update(['status' => 'revoked']);

        $this->audit->log('terminal.revoked', $terminal, $old, ['status' => 'revoked']);

        return back()->with('status', 'Terminal revoked.');
    }
}
