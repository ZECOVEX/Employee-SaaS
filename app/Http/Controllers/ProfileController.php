<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * §43 — self-service data export: any authenticated user downloads a JSON
 * dump of their own account data (profile, employee record, login history).
 *
 * Post-Phase 19/20 — profile customization, role-scoped:
 *  - identity editors (platform admin or `employees.edit`): photo upload
 *    (public disk) + contact phone on their own user row;
 *  - everyone else (employees): emergency contact only, written to their
 *    employee record so admins see it on the employee profile (synced).
 */
class ProfileController extends Controller
{
    public function export(Request $request, PrivacyService $privacy): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            $privacy->exportUserData($user)
        )->withHeaders([
            'Content-Disposition' => 'attachment; filename="my-data-export.json"',
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($this->canEditIdentity($user)) {
            return $this->updateIdentity($request, $audit, $user);
        }

        return $this->updateEmergencyContact($request, $audit, $user);
    }

    /**
     * Same gate as the profile identity-lock Volt form: name/email (and now
     * photo/phone) are HR-managed for plain employee accounts (§3).
     */
    private function canEditIdentity(User $user): bool
    {
        return $user->is_platform_admin || $user->canPermission('employees.edit');
    }

    private function updateIdentity(Request $request, AuditLogger $audit, User $user): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'avatar_remove' => ['nullable', 'boolean'],
        ]);

        $old = ['phone' => $user->phone, 'avatar_path' => $user->avatar_path];

        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }

        if ($request->hasFile('avatar')) {
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');

            if ($old['avatar_path'] !== null) {
                Storage::disk('public')->delete($old['avatar_path']);
            }
        } elseif (! empty($data['avatar_remove']) && $user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        $user->save();

        $audit->log('profile.updated', $user, $old, [
            'phone' => $user->phone,
            'avatar_path' => $user->avatar_path,
        ]);

        return back()->with('status', 'profile-updated');
    }

    private function updateEmergencyContact(Request $request, AuditLogger $audit, User $user): RedirectResponse
    {
        $data = $request->validate([
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            // Employees may not touch their identity fields (Post-Phase 20).
            'phone' => ['prohibited'],
            'avatar' => ['prohibited'],
            'avatar_remove' => ['prohibited'],
        ]);

        $employee = $user->employee()->first();

        if ($employee === null) {
            throw ValidationException::withMessages([
                'emergency_contact_name' => 'No employee profile is linked to your account; contact an administrator.',
            ]);
        }

        $old = [
            'emergency_contact_name' => $employee->emergency_contact_name,
            'emergency_contact_phone' => $employee->emergency_contact_phone,
        ];

        $employee->emergency_contact_name = $data['emergency_contact_name'];
        $employee->emergency_contact_phone = $data['emergency_contact_phone'];
        $employee->save();

        $audit->log('profile.emergency_updated', $employee, $old, [
            'emergency_contact_name' => $employee->emergency_contact_name,
            'emergency_contact_phone' => $employee->emergency_contact_phone,
        ]);

        return back()->with('status', 'profile-updated');
    }
}
