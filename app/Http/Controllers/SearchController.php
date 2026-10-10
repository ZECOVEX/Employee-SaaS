<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\NfcCard;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search: employees (code, name, email, department, designation)
 * and NFC card tokens. Results are tenant-scoped by the organization
 * global scope and RBAC-filtered per section (employees.view for people,
 * nfc.view for cards); pure managers only see their own team, matching
 * EmployeeController::index.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        $response = ['employees' => [], 'cards' => []];

        if (mb_strlen($q) < 2) {
            return response()->json($response);
        }

        $user = $request->user();

        if ($user->canPermission('employees.view')) {
            $response['employees'] = $this->employees($user, $q);
        }

        if ($user->canPermission('nfc.view')) {
            $response['cards'] = $this->cards($q);
        }

        return response()->json($response);
    }

    /**
     * @return list<array{id: int, code: string, name: ?string, email: ?string, department: ?string, designation: ?string, url: string}>
     */
    private function employees(User $user, string $q): array
    {
        $term = '%'.$q.'%';

        return Employee::with(['user:id,name,email', 'department:id,name', 'position:id,title'])
            ->when(! $user->is_platform_admin && $user->hasRole('manager') && ! $user->hasAnyRole(['company_admin', 'hr']),
                fn ($query) => $query->where('manager_id', $user->id))
            ->where(function ($w) use ($term) {
                $w->where('employee_code', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term))
                    ->orWhereHas('department', fn ($d) => $d->where('name', 'like', $term))
                    ->orWhereHas('position', fn ($p) => $p->where('title', 'like', $term));
            })
            ->orderBy('employee_code')
            ->take(8)
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'code' => $e->employee_code,
                'name' => $e->user?->name,
                'email' => $e->user?->email,
                'department' => $e->department?->name,
                'designation' => $e->position?->title,
                'url' => route('employees.show', $e),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{token: string, status: string, employee: ?string, url: string}>
     */
    private function cards(string $q): array
    {
        return NfcCard::with('employee.user:id,name')
            ->where('card_token', 'like', '%'.$q.'%')
            ->take(5)
            ->get()
            ->map(fn (NfcCard $card) => [
                'token' => $card->card_token,
                'status' => $card->status,
                'employee' => $card->employee?->user?->name,
                'url' => route('nfc-cards.index', ['q' => $card->card_token]),
            ])
            ->values()
            ->all();
    }
}
