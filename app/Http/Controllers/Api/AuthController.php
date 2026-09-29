<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Issue a personal access token (§29 auth). The token carries the
     * tenant's identity — org scoping happens on every request via the
     * organization global scope.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->organization_id === null) {
            return response()->json(['message' => 'This account is not linked to an organization.'], 403);
        }

        $organization = $user->organization;

        if ($organization === null || ! $organization->isActive()) {
            return response()->json(['message' => 'This organization is suspended.'], 403);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($user->load('roles')),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles.permissions'));
    }
}
