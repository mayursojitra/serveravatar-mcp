<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    Route::get('token', [TokenController::class, 'show'])->name('token.show');
    Route::middleware('throttle:token-writes')->group(function () {
        Route::post('token', [TokenController::class, 'store'])->name('token.store');
        Route::post('token/test', [TokenController::class, 'test'])->name('token.test');
        Route::delete('token', [TokenController::class, 'destroy'])->name('token.destroy');
    });

    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::middleware('throttle:30,1')->group(function () {
        Route::patch('connections/{grant}', [ConnectionController::class, 'update'])->whereNumber('grant')->name('connections.update');
        Route::delete('connections/{grant}', [ConnectionController::class, 'destroy'])->whereNumber('grant')->name('connections.destroy');
    });

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('users/{user}/reset-two-factor', [AdminUserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        });
        Route::get('activity', [AdminActivityController::class, 'index'])->name('activity.index');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
