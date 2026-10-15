<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\NfcCard;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Post-Phase 24 — NFC issue, merged into the NFC Cards page: the inline
 * "Issue a card" panel hands a card to an employee in one step. The admin
 * captures the card's code three ways — phone NFC (Web NFC API), a USB
 * reader machine (keyboard wedge), or typed by hand — picks the employee,
 * and issues it: a fresh active card is created, or a pre-generated
 * "available" card is claimed. Only the POST endpoint is routed; the form
 * renders on `nfc-cards.index` behind `@can('nfc.manage')`.
 */
class NfcIssueController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('nfc.manage');

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'card_token' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9:.\/\-\s]+$/'],
            'method' => ['nullable', Rule::in(['phone', 'reader', 'manual'])],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        $token = NfcCard::normalizeToken((string) $data['card_token']);
        if (strlen($token) < 4) {
            throw ValidationException::withMessages([
                'card_token' => 'The card code must be at least 4 characters.',
            ]);
        }

        $employee = Employee::findOrFail($data['employee_id']);
        $method = (string) ($data['method'] ?? 'manual');

        $existing = NfcCard::query()->where('card_token', $token)->first();

        if ($existing === null) {
            $card = NfcCard::create([
                'organization_id' => $request->user()->organization_id,
                'card_token' => $token,
                'employee_id' => $employee->id,
                'status' => 'active',
                'issued_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->log('nfc.issued', $card, null, [
                'card_token' => $token,
                'employee_id' => $employee->id,
                'method' => $method,
            ]);

            return $this->backToCards('Card '.$token.' issued to '.$employee->employee_code.'.');
        }

        if ($existing->status === 'available') {
            $old = $existing->only(['employee_id', 'status']);
            $existing->update([
                'employee_id' => $employee->id,
                'status' => 'active',
                'issued_at' => now(),
                'notes' => $data['notes'] ?? $existing->notes,
            ]);

            $this->audit->log('nfc.assigned', $existing, $old, array_merge(
                $existing->only(['employee_id', 'status']),
                ['method' => $method],
            ));

            return $this->backToCards('Card '.$token.' issued to '.$employee->employee_code.'.');
        }

        throw ValidationException::withMessages([
            'card_token' => $existing->status === 'active'
                ? 'This code is already issued — manage it from this list.'
                : 'This code exists with status "'.$existing->status.'" — restore or reuse it from this list first.',
        ]);
    }

    /** Land back on the merged NFC Cards page (where the form lives). */
    private function backToCards(string $status): RedirectResponse
    {
        return redirect()
            ->route('nfc-cards.index')
            ->with('status', $status);
    }
}
