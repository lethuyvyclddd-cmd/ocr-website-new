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

        // FIX: ràng buộc {applicant} chỉ nhận số, tránh "nuốt" mất các path
        // chữ như /applicants/export (đăng ký sau, ở nhóm admin bên dưới)
        // khiến Laravel hiểu nhầm "export" là id hồ sơ -> 404.
        Route::get('/{applicant}', [ApplicantController::class, 'workspace'])
            ->where('applicant', '[0-9]+')
            ->name('workspace');

        Route::put('/{applicant}', [ApplicantController::class, 'update'])
            ->where('applicant', '[0-9]+')
            ->name('update');

        Route::post('/{applicant}/documents', [ApplicantController::class, 'uploadDocument'])
            ->where('applicant', '[0-9]+')
            ->name('documents.upload');
    });

    // === Chức năng CHỈ ADMIN ===
    Route::middleware('admin')->group(function () {

        // === Quản lý người dùng ===
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::patch('/users/{id}/role', [UserManagementController::class, 'updateRole'])->name('users.updateRole');
        Route::delete('/users/{id}', [UserManagementController::class, 'destroy'])->name('users.destroy');

        // === Quản lý hồ sơ: CHỈ ADMIN ===

        // Xóa hồ sơ
        Route::delete('/applicants/{applicant}', [ApplicantController::class, 'destroy'])
            ->where('applicant', '[0-9]+')
            ->name('applicants.destroy');

        // Gộp hồ sơ
        Route::post('/applicants/{applicant}/merge-into/{target}', [ApplicantController::class, 'mergeInto'])
            ->where(['applicant' => '[0-9]+', 'target' => '[0-9]+'])
            ->name('applicants.merge');

        // === Xuất file: CHỈ ADMIN ===
        Route::get('/applicants/export', [ApplicantController::class, 'exportBatch'])
            ->name('applicants.export.batch');

        Route::get('/applicants/{applicant}/export', [ApplicantController::class, 'exportOne'])
            ->where('applicant', '[0-9]+')
            ->name('applicants.export.one');
    });

});