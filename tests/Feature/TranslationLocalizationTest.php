<?php

use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('users can switch locales and receive translated inertia props with rtl direction', function () {
    $user = User::factory()->create();
    Translation::create(['locale' => 'ar', 'key' => 'profile.title', 'value' => 'الملف الشخصي']);

    $this->actingAs($user)
        ->post('/locale/fr')
        ->assertNotFound()
        ->assertSessionMissing('locale');

    $this->post('/locale/ar')
        ->assertRedirect()
        ->assertSessionHas('locale', 'ar');

    $this->get('/profile')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('locale', 'ar')
            ->where('translations', ['profile.title' => 'الملف الشخصي']));
});

test('administrators can create and update english and arabic translations', function () {
    $admin = User::factory()->create();
    $admin->type = 'admin';
    $admin->save();
    Translation::create(['locale' => 'en', 'key' => 'editor.greeting', 'value' => 'Hello']);
    Translation::create(['locale' => 'ar', 'key' => 'editor.greeting', 'value' => 'مرحبًا']);
    Translation::create(['locale' => 'en', 'key' => 'translations.title', 'value' => 'Translations']);
    Translation::create(['locale' => 'ar', 'key' => 'translations.title', 'value' => 'الترجمات']);

    $this->actingAs($admin)
        ->get('/translations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Translations')
            ->where('translationEntries.0.key', 'editor.greeting')
            ->where('translationEntries.0.en', 'Hello')
            ->where('translationEntries.0.ar', 'مرحبًا')
            ->where('translations', ['editor.greeting' => 'Hello', 'translations.title' => 'Translations']));

    $this->withSession(['locale' => 'ar'])
        ->get('/translations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Translations')
            ->where('translationEntries.0.en', 'Hello')
            ->where('translationEntries.0.ar', 'مرحبًا')
            ->where('translations', ['editor.greeting' => 'مرحبًا', 'translations.title' => 'الترجمات']));

    $this->put('/translations/editor.greeting', ['en' => 'Welcome', 'ar' => 'أهلًا'])
        ->assertRedirect()
        ->assertSessionHas('success', 'flash.translations_updated');

    $this->assertDatabaseHas('translations', ['locale' => 'en', 'key' => 'editor.greeting', 'value' => 'Welcome']);
    $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => 'editor.greeting', 'value' => 'أهلًا']);

    $this->post('/translations', ['key' => 'editor.new', 'en' => 'New text', 'ar' => 'نص جديد'])
        ->assertRedirect()
        ->assertSessionHas('success', 'flash.translations_created');

    $this->assertDatabaseHas('translations', ['locale' => 'en', 'key' => 'editor.new', 'value' => 'New text']);
    $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => 'editor.new', 'value' => 'نص جديد']);
});

test('non-administrators cannot view edit or export translations', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/translations')->assertForbidden();
    $this->get('/translations/export')->assertForbidden();
    $this->post('/translations', ['key' => 'unauthorized.entry', 'en' => 'Text', 'ar' => 'نص'])->assertForbidden();

    $this->assertDatabaseCount('translations', 0);
});

test('administrators can export unicode translations as a locale-specific json download', function () {
    $admin = User::factory()->create();
    $admin->type = 'admin';
    $admin->save();
    Translation::create(['locale' => 'ar', 'key' => 'export.greeting', 'value' => 'مرحبًا']);

    $response = $this->actingAs($admin)->get('/translations/export?locale=ar');

    $response->assertOk()
        ->assertDownload('translations-ar.json')
        ->assertHeader('content-type', 'application/json; charset=utf-8');
    expect(json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['export.greeting' => 'مرحبًا']);
    $this->get('/translations/export?locale=fr')->assertNotFound();
});

test('translation validation rejects malformed and duplicate keys', function () {
    $admin = User::factory()->create();
    $admin->type = 'admin';
    $admin->save();
    Translation::create(['locale' => 'en', 'key' => 'editor.existing', 'value' => 'Existing']);

    $this->actingAs($admin)
        ->post('/translations', ['key' => 'Bad key', 'en' => 'Text', 'ar' => 'نص'])
        ->assertSessionHasErrors(['key' => 'The key field format is invalid.']);

    $this->post('/translations', ['key' => 'editor.existing', 'en' => 'Changed', 'ar' => 'تغيير'])
        ->assertSessionHasErrors(['key' => 'A translation with this key already exists.']);

    $this->assertDatabaseMissing('translations', ['locale' => 'ar', 'key' => 'editor.existing']);
});

test('database seeding adds both locales and preserves edited translations', function () {
    $this->seed();
    Translation::query()->where('locale', 'en')->where('key', 'nav.dashboard')->update(['value' => 'Custom dashboard']);

    $this->seed();

    $this->assertDatabaseHas('translations', ['locale' => 'en', 'key' => 'nav.dashboard', 'value' => 'Custom dashboard']);
    $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => 'nav.dashboard', 'value' => 'لوحة التحكم']);
});
