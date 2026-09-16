<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkerController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\RunController;
use Illuminate\Support\Facades\Route;

// Language switching and the how-to guide are available signed in or out.
Route::post('locale/{locale}', LocaleController::class)->name('locale');
Route::get('help', HelpController::class)->name('help');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::post('runs', [RunController::class, 'store'])->middleware('throttle:runs')->name('runs.store');
    Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
    Route::get('runs/{run}/progress', [RunController::class, 'progress'])->name('runs.progress');
    Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    Route::post('runs/{run}/reprocess', [RunController::class, 'reprocess'])->middleware('throttle:runs')->name('runs.reprocess');
    Route::delete('runs/{run}', [RunController::class, 'destroy'])->name('runs.destroy');
    Route::get('runs/{run}/export/{type}', [RunController::class, 'export'])
        ->whereIn('type', ['leads', 'unreachable', 'inactive'])->name('runs.export');

    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/export', [LeadController::class, 'export'])->name('leads.export');
    Route::get('insights', InsightController::class)->name('insights');

    Route::get('import', [ImportController::class, 'create'])->name('import.create');
    Route::post('import', [ImportController::class, 'store'])->middleware('throttle:uploads')->name('import.store');

    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('workers', [WorkerController::class, 'index'])->name('workers.index');
        Route::post('workers', [WorkerController::class, 'store'])->name('workers.store');
        Route::delete('workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
        Route::get('workers/setup', [WorkerController::class, 'setup'])->name('workers.setup');
    });
});
