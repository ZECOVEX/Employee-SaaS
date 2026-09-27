<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('users.view');

        $users = User::with('roles:id,name,slug')
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        Gate::authorize('users.manage');

        return view('users.create', [
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('users.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['required', Rule::exists('roles', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'organization_id' => $request->user()->organization_id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);
            $user->roles()->attach($data['role_id']);

            return $user;
        });

        $this->audit->log('user.created', $user, null, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
        ]);

        return redirect()
            ->route('users.index')
            ->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('users.manage');
        $this->assertSameOrg($user);

        return view('users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');
        $this->assertSameOrg($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['required', Rule::exists('roles', 'id')
                ->where('organization_id', $request->user()->organization_id)],
        ]);

        $old = [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('slug')->all(),
        ];

        DB::transaction(function () use ($data, $user) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);
            $user->roles()->sync([$data['role_id']]);
        });

        $user->unsetRelation('roles');

        $this->audit->log('user.updated', $user, $old, [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [$data['role_id']],
        ]);

        return redirect()
            ->route('users.index')
            ->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');
        $this->assertSameOrg($user);

        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');

        $old = ['name' => $user->name, 'email' => $user->email];
        $user->delete();

        $this->audit->log('user.deleted', null, $old, null);

        return redirect()
            ->route('users.index')
            ->with('status', 'User deleted.');
    }

    private function assertSameOrg(User $user): void
    {
        $actor = auth()->user();

        if ($actor->is_platform_admin) {
            return;
        }

        abort_unless($user->organization_id === $actor->organization_id, 404);
    }
}
