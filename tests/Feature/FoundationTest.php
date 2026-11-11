<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function admin(): User
{
    $user = User::factory()->create();
    $user->type = 'admin';
    $user->save();

    return $user;
}

test('guests must authenticate and cannot register', function () {
    $this->get('/profile')->assertRedirect('/login');
    $this->get('/users')->assertRedirect('/login');
    $this->get('/register')->assertNotFound();
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Login'));
});

test('login validates credentials and logout ends the session', function () {
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'correct-password']);
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect('/profile');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

test('login attempts are rate limited', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])->assertTooManyRequests();
});

test('administrators can access protected pages without explicit permissions', function () {
    $this->actingAs(admin())->get('/dashboard')->assertOk();
    $this->get('/users')->assertOk();
    $this->get('/workspaces')->assertOk();
});

test('ordinary users are denied ungranted pages and administration endpoints', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertForbidden();
    $this->get('/workspaces')->assertForbidden();
    $this->get('/users')->assertForbidden();
    $this->post('/users', [])->assertForbidden();
    $this->post('/workspaces', ['name' => 'Denied'])->assertForbidden();
    $this->get('/profile')->assertOk();
    $this->get('/contacts')->assertForbidden();
    $this->get('/settings')->assertForbidden();
    $this->get('/settings/currency')->assertForbidden();
});

test('granted permissions enable the corresponding url and workspace results are isolated', function () {
    $user = User::factory()->create();
    $own = Workspace::factory()->create(['name' => 'Own workspace']);
    Workspace::factory()->create(['name' => 'Other workspace']);
    $user->workspaces()->attach($own);
    $user->permissions()->createMany([
        ['permission' => Permissions::Dashboard],
        ['permission' => Permissions::Workspaces],
        ['permission' => Permissions::Contacts],
        ['permission' => Permissions::Settings],
    ]);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('auth.user.type', 'user')
        ->where('auth.permissions', [Permissions::Contacts, Permissions::Dashboard, Permissions::Settings, Permissions::Workspaces]));
    $this->get('/workspaces')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Workspaces')->has('workspaces', 1)->where('workspaces.0.name', 'Own workspace'));
    $this->get('/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contacts'));
    $this->get('/settings')->assertOk();
    $this->get('/settings/currency')->assertOk();
    $this->get('/users')->assertForbidden();
});

test('administrator creation requires an interactive terminal', function () {
    $this->artisan('app:create-admin', ['--no-interaction' => true])->assertFailed();
    expect(User::count())->toBe(0);
});

test('administrators create users and assign access', function () {
    $workspace = Workspace::factory()->create();
    $this->actingAs(admin())->post('/users', [
        'name' => 'New User', 'email' => 'new@example.com', 'type' => 'user',
        'password' => 'strong-password', 'password_confirmation' => 'strong-password',
        'permissions' => [Permissions::Dashboard], 'workspaces' => [$workspace->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user = User::where('email', 'new@example.com')->firstOrFail();
    expect($user->type)->toBe('user');
    expect(Hash::check('strong-password', $user->password))->toBeTrue();
    expect($user->hasPermission(Permissions::Dashboard))->toBeTrue();
    expect($user->workspaces()->whereKey($workspace->id)->exists())->toBeTrue();
});

test('user assignment is validated and ordinary users cannot elevate their privileges', function () {
    $user = User::factory()->create();
    $this->actingAs(admin())->post('/users', [
        'name' => 'Invalid', 'email' => 'invalid@example.com', 'type' => 'user',
        'password' => 'strong-password', 'password_confirmation' => 'strong-password',
        'permissions' => ['users.manage'], 'workspaces' => [99999],
    ])->assertSessionHasErrors(['permissions.0', 'workspaces.0']);
    $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);

    $this->actingAs($user)->put('/users/'.$user->id, [
        'name' => $user->name, 'email' => $user->email, 'type' => 'admin',
        'permissions' => [Permissions::Dashboard], 'workspaces' => [],
    ])->assertForbidden();
    expect($user->fresh()->type)->toBe('user');
});

test('admins can change permissions and membership but cannot demote themselves', function () {
    $admin = admin();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $payload = ['name' => $user->name, 'email' => $user->email, 'type' => 'user',
        'permissions' => [Permissions::Workspaces], 'workspaces' => [$workspace->id]];
    $this->actingAs($admin)->put('/users/'.$user->id, $payload)->assertSessionHasNoErrors();
    expect($user->fresh()->hasPermission(Permissions::Workspaces))->toBeTrue();
    expect($user->workspaces()->count())->toBe(1);
    $payload['permissions'] = [];
    $payload['workspaces'] = [];
    $this->put('/users/'.$user->id, $payload)->assertSessionHasNoErrors();
    expect($user->fresh()->hasPermission(Permissions::Workspaces))->toBeFalse();
    expect($user->workspaces()->count())->toBe(0);

    $payload['name'] = $admin->name;
    $payload['email'] = $admin->email;
    $this->put('/users/'.$admin->id, $payload)->assertSessionHasErrors('type');
    expect($admin->fresh()->isAdmin())->toBeTrue();
});

test('profile changes validate identity and password without accepting account type', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $this->actingAs($user)->patch('/profile', ['name' => 'Updated', 'email' => 'updated@example.com', 'type' => 'admin'])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBe('Updated');
    expect($user->fresh()->type)->toBe('user');
    $this->put('/profile/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasErrors('current_password');
    $this->put('/profile/password', ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('admins can create workspaces and invalid names are rejected', function () {
    $this->actingAs(admin())->post('/workspaces', ['name' => ''])->assertSessionHasErrors('name');
    $this->post('/workspaces', ['name' => 'Support'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('workspaces', ['name' => 'Support']);
});
