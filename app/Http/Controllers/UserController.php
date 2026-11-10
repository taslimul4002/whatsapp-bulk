<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('Users', [
            'users' => User::query()->with(['permissions:id,user_id,permission', 'workspaces:id,name'])->orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'type']),
            'workspaces' => Workspace::query()->orderBy('name')->get(['id', 'name']),
            'availablePermissions' => Permissions::All,
        ]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            $user = new User(array_intersect_key($data, array_flip(['name', 'email', 'password'])));
            $user->type = $data['type'];
            $user->save();
            $this->syncAccess($user, $data);
        });

        return back()->with('success', 'flash.user_created');
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        if ($request->user()->is($user) && $data['type'] !== 'admin') {
            return back()->withErrors(['type' => 'You cannot remove your own administrator access.']);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->type = $data['type'];
            $user->save();
            $this->syncAccess($user, $data);
        });

        return back()->with('success', 'flash.user_updated');
    }

    private function syncAccess(User $user, array $data): void
    {
        $user->workspaces()->sync($data['workspaces']);
        $user->permissions()->delete();
        $user->permissions()->createMany(array_map(fn (string $permission) => ['permission' => $permission], $data['permissions']));
    }
}
