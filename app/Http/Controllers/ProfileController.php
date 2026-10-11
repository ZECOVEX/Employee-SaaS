<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §43 — self-service data export: any authenticated user downloads a JSON
 * dump of their own account data (profile, employee record, login history).
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
}
