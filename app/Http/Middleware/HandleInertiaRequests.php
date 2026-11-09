<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only('id', 'name', 'email', 'type'),
                'permissions' => $user?->isAdmin() ? Permissions::All : ($user?->permissions()->pluck('permission')->all() ?? []),
            ],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
