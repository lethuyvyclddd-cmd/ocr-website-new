<?php

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

// === Trang chủ công khai (không cần đăng nhập) ===
Route::get('/', function () {
    return view('home');
});

Route::middleware('auth')->group(function () {

    // === Route profile của Breeze ===
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // === Dashboard ===
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // === Hồ sơ thí sinh ===
    Route::prefix('applicants')->name('applicants.')->group(function () {
        Route::get('/', [ApplicantController::class, 'index'])->name('index');
        Route::get('/create', [ApplicantController::class, 'create'])->name('create');
        Route::post('/', [ApplicantController::class, 'store'])->name('store');

        Route::get('/export', [ApplicantController::class, 'exportBatch'])->name('export.batch');

        Route::get('/{applicant}', [ApplicantController::class, 'workspace'])->name('workspace');
        Route::put('/{applicant}', [ApplicantController::class, 'update'])->name('update');
        Route::delete('/{applicant}', [ApplicantController::class, 'destroy'])->name('destroy');

        Route::post('/{applicant}/documents', [ApplicantController::class, 'uploadDocument'])->name('documents.upload');
        Route::get('/{applicant}/export', [ApplicantController::class, 'exportOne'])->name('export.one');
    });

    // === Chức năng CHỈ ADMIN ===
    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::patch('/users/{id}/role', [UserManagementController::class, 'updateRole'])->name('users.updateRole');
        Route::delete('/users/{id}', [UserManagementController::class, 'destroy'])->name('users.destroy');
    });

});