<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — SCIM 2.0 provisioning endpoints. Users map to tenant User
 * accounts (default role: employee) and Groups map to Departments. All
 * queries are scoped by the authenticated SCIM token's organization.
 */
class ScimController extends Controller
{
    private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    private const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public function __construct(private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- discovery

    public function serviceProviderConfig(Request $request): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => 'https://www.rfc-editor.org/rfc/rfc7644',
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => false, 'maxResults' => 100],
            'changePassword' => ['supported' => true],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer Token',
                'description' => 'Authentication via a SCIM bearer token issued in settings.',
                'primary' => true,
            ]],
        ]);
    }

    public function resourceTypes(Request $request): JsonResponse
    {
        return response()->json([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => 2,
            'Resources' => [
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id' => 'User',
                    'name' => 'User',
                    'endpoint' => '/Users',
                    'schema' => self::USER_SCHEMA,
                ],
                [
                    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
                    'id' => 'Group',
                    'name' => 'Group',
                    'endpoint' => '/Groups',
                    'schema' => self::GROUP_SCHEMA,
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------- users

    public function indexUsers(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $perPage = min((int) $request->query('count', 100), 200);

        $query = User::query()
            ->where('organization_id', $orgId)
            ->orderBy('id');

        if ($request->query('filter') === 'active eq "true"') {
            // All provisioned users are active (deactivation = delete).
        }

        $total = (clone $query)->count();
        $startIndex = max(1, (int) $request->query('startIndex', 1));

        $users = $query->skip($startIndex - 1)->take($perPage)->get();

        return response()->json([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn (User $user) => $this->userResource($user))->values(),
        ]);
    }

    public function showUser(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($request, $id);

        if ($user === null) {
            return $this->scimError(404, 'NotFound', 'User '.$id.' not found.');
        }

        return response()->json($this->userResource($user));
    }

    public function storeUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userName' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'name.formatted' => ['nullable', 'string', 'max:120'],
            'name.givenName' => ['nullable', 'string', 'max:60'],
            'name.familyName' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $name = $this->resolveName($data)
            ?: Str::before($data['userName'], '@');

        $password = $data['password'] ?? Str::random(16);

        $user = DB::transaction(function () use ($data, $name, $password, $request) {
            $user = User::create([
                'organization_id' => $request->user()->organization_id,
                'name' => $name,
                'email' => $data['userName'],
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'must_change_password' => isset($data['password']) ? false : true,
            ]);

            $role = Role::where('organization_id', $request->user()->organization_id)
                ->where('slug', 'employee')
                ->first();

            if ($role !== null) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });

        $this->audit->log('scim.user_created', $user, null, ['email' => $user->email]);

        return response()->json($this->userResource($user), 201);
    }

    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($request, $id);

        if ($user === null) {
            return $this->scimError(404, 'NotFound', 'User '.$id.' not found.');
        }

        $data = $request->validate([
            'userName' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'name.formatted' => ['sometimes', 'string', 'max:120'],
            'name.givenName' => ['sometimes', 'string', 'max:60'],
            'name.familyName' => ['sometimes', 'string', 'max:60'],
            'password' => ['sometimes', 'string', 'min:8'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $attributes = [];

        if (isset($data['userName'])) {
            $attributes['email'] = $data['userName'];
        }

        if (($resolved = $this->resolveName($data)) !== null && $resolved !== '') {
            $attributes['name'] = $resolved;
        }

        if (isset($data['password'])) {
            $attributes['password'] = Hash::make($data['password']);
            $attributes['must_change_password'] = false;
        }

        // `active=false` maps to deletion (no deactivated state in users table).
        if (array_key_exists('active', $data) && $data['active'] === false) {
            $this->audit->log('scim.user_deleted', $user, null, ['email' => $user->email, 'via' => 'patch']);
            $user->delete();

            return response()->json(null, 204);
        }

        if ($attributes !== []) {
            $user->fill($attributes)->save();
            $this->audit->log('scim.user_updated', $user);
        }

        return response()->json($this->userResource($user->refresh()));
    }

    public function destroyUser(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($request, $id);

        if ($user === null) {
            return $this->scimError(404, 'NotFound', 'User '.$id.' not found.');
        }

        $this->audit->log('scim.user_deleted', $user, null, ['email' => $user->email]);
        $user->delete();

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------- groups

    public function indexGroups(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $departments = Department::where('organization_id', $orgId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'schemas' => [self::LIST_SCHEMA],
            'totalResults' => $departments->count(),
            'startIndex' => 1,
            'itemsPerPage' => $departments->count(),
            'Resources' => $departments->map(fn (Department $d) => $this->groupResource($d))->values(),
        ]);
    }

    public function showGroup(Request $request, string $id): JsonResponse
    {
        $department = Department::find($id);

        if ($department === null || $department->organization_id !== $request->user()->organization_id) {
            return $this->scimError(404, 'NotFound', 'Group '.$id.' not found.');
        }

        return response()->json($this->groupResource($department));
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'displayName' => ['required', 'string', 'max:120'],
        ]);

        $department = Department::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['displayName'],
            'code' => $this->uniqueDepartmentCode($request->user()->organization_id, $data['displayName']),
        ]);

        $this->audit->log('scim.group_created', $department, null, ['name' => $department->name]);

        return response()->json($this->groupResource($department), 201);
    }

    public function updateGroup(Request $request, string $id): JsonResponse
    {
        $department = Department::find($id);

        if ($department === null || $department->organization_id !== $request->user()->organization_id) {
            return $this->scimError(404, 'NotFound', 'Group '.$id.' not found.');
        }

        $data = $request->validate([
            'displayName' => ['sometimes', 'string', 'max:120'],
        ]);

        if (isset($data['displayName'])) {
            $department->update(['name' => $data['displayName']]);
            $this->audit->log('scim.group_updated', $department);
        }

        return response()->json($this->groupResource($department->refresh()));
    }

    public function destroyGroup(Request $request, string $id): JsonResponse
    {
        $department = Department::find($id);

        if ($department === null || $department->organization_id !== $request->user()->organization_id) {
            return $this->scimError(404, 'NotFound', 'Group '.$id.' not found.');
        }

        $this->audit->log('scim.group_deleted', $department, null, ['name' => $department->name]);
        $department->delete();

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * SCIM `name` sub-attributes arrive nested after validation
     * (`name.formatted` → $data['name']['formatted']). Prefer formatted,
     * then given + family.
     */
    protected function resolveName(array $data): ?string
    {
        $name = $data['name'] ?? null;

        if (! is_array($name)) {
            return null;
        }

        if (! empty($name['formatted']) && is_string($name['formatted'])) {
            return $name['formatted'];
        }

        $combined = trim(($name['givenName'] ?? '').' '.($name['familyName'] ?? ''));

        return $combined === '' ? null : $combined;
    }

    protected function findUser(Request $request, string $id): ?User
    {
        $user = User::find($id);

        return $user !== null && $user->organization_id === $request->user()->organization_id
            ? $user
            : null;
    }

    protected function userResource(User $user): array
    {
        return [
            'schemas' => [self::USER_SCHEMA],
            'id' => (string) $user->id,
            'externalId' => null,
            'userName' => $user->email,
            'name' => ['formatted' => $user->name],
            'displayName' => $user->name,
            'active' => true,
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
            ],
        ];
    }

    protected function groupResource(Department $department): array
    {
        return [
            'schemas' => [self::GROUP_SCHEMA],
            'id' => (string) $department->id,
            'displayName' => $department->name,
            'meta' => [
                'resourceType' => 'Group',
                'created' => $department->created_at?->toIso8601String(),
                'lastModified' => $department->updated_at?->toIso8601String(),
            ],
        ];
    }

    protected function uniqueDepartmentCode(int $organizationId, string $name): string
    {
        $base = strtoupper(Str::limit(Str::slug($name, ''), 3, ''));
        $base = $base === '' ? 'DEP' : $base;

        do {
            $code = $base.Str::upper(Str::random(2));
        } while (Department::where('organization_id', $organizationId)->where('code', $code)->exists());

        return $code;
    }

    protected function scimError(int $status, string $type, string $detail): JsonResponse
    {
        return response()->json([
            'schemas' => [self::ERROR_SCHEMA],
            'status' => (string) $status,
            'scimType' => $type,
            'detail' => $detail,
        ], $status);
    }
}
