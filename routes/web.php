<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');
Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');

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
    Route::get('/settings', fn () => Inertia::render('Settings'))->name('settings');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->middleware('permission:'.Permissions::Workspaces)->name('workspaces.index');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::middleware('admin')->group(function (): void {
        Route::get('/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::post('/translations', [TranslationController::class, 'store'])->name('translations.store');
        Route::put('/translations/{key}', [TranslationController::class, 'update'])
            ->where('key', '[a-z0-9._-]+')
            ->name('translations.update');
        Route::get('/translations/export', [TranslationController::class, 'export'])->name('translations.export');
    });
});
