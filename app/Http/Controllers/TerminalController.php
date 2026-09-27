<?php

namespace App\Http\Controllers;

use App\Models\AttendanceTerminal;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('attendance.terminal');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('attendance_terminals', 'name')
                ->where('organization_id', $request->user()->organization_id)],
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        $plainKey = 'term_'.Str::random(40);

        AttendanceTerminal::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'key_hash' => hash('sha256', $plainKey),
            'status' => 'active',
        ]);

        $this->audit->log('terminal.created', null, null, ['name' => $data['name'], 'location' => $data['location'] ?? null]);

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
