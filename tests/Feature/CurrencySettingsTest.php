<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('currency settings and changes require authentication', function () {
    $this->get('/settings/currency')->assertRedirect('/login');
    $this->patch('/settings/currency/preference', ['currency' => 'SAR'])->assertRedirect('/login');
    $this->post('/settings/currency', ['code' => 'USD'])->assertRedirect('/login');
    $this->delete('/settings/currency/USD')->assertRedirect('/login');
});

test('users see SAR by default and can persist an available currency', function () {
    $user = User::factory()->create();
    DB::table('currencies')->insert(['code' => 'USD']);

    $this->actingAs($user)->get('/settings/currency')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('CurrencySettings')
            ->where('currency.selected', 'SAR')->where('currency.available', ['SAR', 'USD']));

    $this->patch('/settings/currency/preference', ['currency' => 'USD'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'currency_code' => 'USD']);
    $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('currency.selected', 'USD'));
});

test('a user cannot select an unavailable currency or manage currencies', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/currency/preference', ['currency' => 'USD'])
        ->assertSessionHasErrors('currency');
    $this->patch('/settings/currency/preference', ['currency' => 'sar'])->assertSessionHasErrors('currency');
    $this->post('/settings/currency', ['code' => 'USD'])->assertForbidden();
    $this->delete('/settings/currency/SAR')->assertForbidden();
    $this->assertDatabaseMissing('currencies', ['code' => 'USD']);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'currency_code' => null]);
});

test('administrators can add currencies and invalid or duplicate codes are rejected', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['type' => 'admin'])->save();

    $this->actingAs($admin)->post('/settings/currency', ['code' => 'USD'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('currencies', ['code' => 'USD']);
    $this->post('/settings/currency', ['code' => 'USD'])->assertSessionHasErrors('code');
    $this->post('/settings/currency', ['code' => 'usd'])->assertSessionHasErrors('code');
    $this->post('/settings/currency', ['code' => 'US'])->assertSessionHasErrors('code');
    $this->assertDatabaseCount('currencies', 2);
});

test('removing a currency resets affected users and the default cannot be removed', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['type' => 'admin'])->save();
    DB::table('currencies')->insert(['code' => 'USD']);
    $user = User::factory()->create();
    $user->forceFill(['currency_code' => 'USD'])->save();

    $this->actingAs($admin)->delete('/settings/currency/USD')->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('currencies', ['code' => 'USD']);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'currency_code' => null]);
    $this->actingAs($user->fresh())->get('/profile')->assertInertia(fn (Assert $page) => $page->where('currency.selected', 'SAR'));
    $this->actingAs($admin)->delete('/settings/currency/SAR')->assertForbidden();
    $this->delete('/settings/currency/sar')->assertNotFound();
    $this->delete('/settings/currency/USD')->assertNotFound();
    $this->assertDatabaseHas('currencies', ['code' => 'SAR']);
});

test('currency and theme labels are available in both application languages', function () {
    $this->seed();

    $this->assertDatabaseHas('translations', ['locale' => 'en', 'key' => 'currency.title', 'value' => 'Currency']);
    $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => 'currency.title', 'value' => 'العملة']);
    $this->assertDatabaseHas('translations', ['locale' => 'en', 'key' => 'settings.switch_to_dark', 'value' => 'Switch to dark mode']);
    $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => 'settings.switch_to_light', 'value' => 'التبديل إلى الوضع الفاتح']);
});
