<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\NfcCard;
use App\Notifications\NfcCardStatusChanged;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NfcCardController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('nfc.view');

        $cards = NfcCard::with('employee.user:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('card_token', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('nfc.index', compact('cards'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $tokens = [];
        for ($i = 0; $i < $data['count']; $i++) {
            $token = 'NFC-'.Str::upper(Str::random(12));
            NfcCard::create([
                'organization_id' => $request->user()->organization_id,
                'card_token' => $token,
                'status' => 'available',
                'notes' => $data['notes'] ?? null,
            ]);
            $tokens[] = $token;
        }

        $this->audit->log('nfc.issued', null, null, ['count' => $data['count'], 'tokens' => $tokens]);

        return redirect()
            ->route('nfc-cards.index')
            ->with('status', $data['count'].' card(s) created: '.implode(', ', $tokens));
    }

    public function assign(Request $request, NfcCard $nfcCard): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $old = $nfcCard->only(['employee_id', 'status']);

        $nfcCard->update([
            'employee_id' => $employee->id,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->audit->log('nfc.assigned', $nfcCard, $old, $nfcCard->fresh()->only(['employee_id', 'status']));

        return back()->with('status', 'Card assigned to '.$employee->employee_code.'.');
    }

    public function unassign(NfcCard $nfcCard): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $old = $nfcCard->only(['employee_id', 'status']);

        $nfcCard->update([
            'employee_id' => null,
            'status' => 'available',
            'issued_at' => null,
        ]);

        $this->audit->log('nfc.unassigned', $nfcCard, $old, $nfcCard->fresh()->only(['employee_id', 'status']));

        return back()->with('status', 'Card unassigned.');
    }

    public function block(NfcCard $nfcCard): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $old = ['status' => $nfcCard->status];
        $nfcCard->update(['status' => 'blocked']);

        $this->audit->log('nfc.blocked', $nfcCard, $old, ['status' => 'blocked']);
        $nfcCard->employee?->user?->notify(new NfcCardStatusChanged('blocked', substr($nfcCard->card_token, -4)));

        return back()->with('status', 'Card blocked.');
    }

    public function revoke(NfcCard $nfcCard): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $old = $nfcCard->only(['employee_id', 'status']);
        $nfcCard->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->audit->log('nfc.revoked', $nfcCard, $old, ['status' => 'revoked', 'revoked_at' => now()]);
        $nfcCard->employee?->user?->notify(new NfcCardStatusChanged('revoked', substr($nfcCard->card_token, -4)));

        return back()->with('status', 'Card revoked.');
    }

    /**
     * Replace a lost/damaged assigned card with a fresh token (§20).
     * The old card is revoked but kept — attendance history is preserved.
     */
    public function replace(Request $request, NfcCard $nfcCard): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        if (! $nfcCard->employee_id) {
            return back()->with('status', 'Unassigned cards can be re-issued instead — nothing to replace.');
        }

        if ($nfcCard->status === 'revoked') {
            return back()->with('status', 'This card has already been replaced or revoked.');
        }

        $oldToken = $nfcCard->card_token;

        $newToken = DB::transaction(function () use ($nfcCard) {
            $token = 'NFC-'.Str::upper(Str::random(12));

            NfcCard::withoutGlobalScopes()->create([
                'organization_id' => $nfcCard->organization_id,
                'card_token' => $token,
                'employee_id' => $nfcCard->employee_id,
                'status' => 'active',
                'issued_at' => now(),
                'notes' => 'Replacement for card #'.$nfcCard->id,
            ]);

            $nfcCard->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

            return $token;
        });

        $this->audit->log('nfc.replaced', $nfcCard, ['card_token' => $oldToken, 'status' => $nfcCard->status], [
            'new_card_token' => $newToken,
            'employee_id' => $nfcCard->employee_id,
        ]);

        $nfcCard->employee?->user?->notify(new NfcCardStatusChanged('replaced', substr($oldToken, -4)));

        return redirect()
            ->route('nfc-cards.index')
            ->with('status', 'Card replaced. New token: '.$newToken.' (recorded once — attendance history is preserved).');
    }

    /**
     * Attendance history recorded by a card (§20).
     */
    public function history(Request $request, NfcCard $nfcCard): View
    {
        Gate::authorize('nfc.view');

        $events = AttendanceEvent::with(['employee.user:id,name', 'terminal:id,name'])
            ->where('nfc_card_id', $nfcCard->id)
            ->whereNull('superseded_by')
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return view('nfc.history', compact('nfcCard', 'events'));
    }
}
