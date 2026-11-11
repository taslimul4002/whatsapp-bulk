<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\ContactClassificationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactTransferController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppSettingsController;
use App\Http\Controllers\WhatsAppTemplateController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureWorkspaceAccess;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');
Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.webhook');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('whatsapp.webhook.receive');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->middleware('permission:'.Permissions::Dashboard)->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/settings', fn () => Inertia::render('Settings'))->middleware('permission:'.Permissions::Settings)->name('settings');
    Route::get('/settings/currency', [CurrencyController::class, 'index'])->middleware('permission:'.Permissions::Settings)->name('settings.currency');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->middleware('permission:'.Permissions::Workspaces)->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::get('/contacts', [ContactController::class, 'index'])->middleware('permission:'.Permissions::Contacts)->name('contacts.index');
    Route::prefix('/workspaces/{workspace}/contacts')->middleware(['permission:'.Permissions::Contacts, EnsureWorkspaceAccess::class])->group(function (): void {
        Route::get('/', [ContactController::class, 'show'])->name('contacts.workspace');
        Route::post('/', [ContactController::class, 'store'])->name('contacts.store');
        Route::post('/import', [ContactTransferController::class, 'import'])->name('contacts.import');
        Route::get('/export/{format}', [ContactTransferController::class, 'export'])->name('contacts.export');
        Route::post('/classifications/{kind}', [ContactClassificationController::class, 'store'])->name('contacts.classifications.store');
        Route::put('/classifications/{kind}/{classification}', [ContactClassificationController::class, 'update'])->name('contacts.classifications.update');
        Route::delete('/classifications/{kind}/{classification}', [ContactClassificationController::class, 'destroy'])->name('contacts.classifications.destroy');
        Route::put('/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('contacts.update');
        Route::delete('/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact')->name('contacts.destroy');
    });

    Route::middleware('admin')->group(function (): void {
        Route::get('/templates', [WhatsAppTemplateController::class, 'index'])->name('templates.index');
        Route::post('/templates', [WhatsAppTemplateController::class, 'store'])->name('templates.store');
        Route::post('/templates/sync', [WhatsAppTemplateController::class, 'sync'])->name('templates.sync');
        Route::put('/templates/{template}', [WhatsAppTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [WhatsAppTemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('/templates/{template}/submit', [WhatsAppTemplateController::class, 'submit'])->name('templates.submit');
        Route::post('/templates/{template}/recover', [WhatsAppTemplateController::class, 'recover'])->name('templates.recover');
        Route::get('/settings/whatsapp', [WhatsAppSettingsController::class, 'edit'])->name('settings.whatsapp');
        Route::put('/settings/whatsapp', [WhatsAppSettingsController::class, 'update'])->name('settings.whatsapp.update');
        Route::post('/settings/whatsapp/test', [WhatsAppSettingsController::class, 'test'])->name('settings.whatsapp.test');
        Route::patch('/settings/currency/preference', [CurrencyController::class, 'updatePreference'])->name('settings.currency.preference');
        Route::post('/settings/currency', [CurrencyController::class, 'store'])->name('settings.currency.store');
        Route::delete('/settings/currency/{currency}', [CurrencyController::class, 'destroy'])->name('settings.currency.destroy');
        Route::get('/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::post('/translations', [TranslationController::class, 'store'])->name('translations.store');
        Route::put('/translations/{key}', [TranslationController::class, 'update'])
            ->where('key', '[a-z0-9._-]+')
            ->name('translations.update');
        Route::get('/translations/export', [TranslationController::class, 'export'])->name('translations.export');
    });
});
