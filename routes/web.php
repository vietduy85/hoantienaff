<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/google', [App\Http\Controllers\Auth\GoogleController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [App\Http\Controllers\Auth\GoogleController::class, 'callback'])->name('google.callback');

Route::get('/debug/provider', [App\Http\Controllers\Debug\ProviderController::class, 'index']);
Route::post('/debug/provider', [App\Http\Controllers\Debug\ProviderController::class, 'test']);

Route::get('/debug/worker', [App\Http\Controllers\Debug\WorkerController::class, 'index']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/link-requests', [DashboardController::class, 'store'])->name('link-requests.store');
    Route::post('/link-requests/{linkRequest}/toggle-pin', [DashboardController::class, 'togglePin'])->name('link-requests.toggle-pin');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
