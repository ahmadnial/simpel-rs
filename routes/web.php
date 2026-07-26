<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\VerifikasiController;
use App\Http\Controllers\TandaTanganController;
use App\Http\Controllers\PublikasiController;
use App\Http\Controllers\ArsipController;
use App\Http\Controllers\DelegasiController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\PublicVerifyController;
use App\Http\Controllers\OnlyOfficeController;

// ==========================================
// Public Routes
// ==========================================
Route::get('/validasi-qr/{token}', [PublicVerifyController::class, 'show'])->name('public.verify');

// ==========================================
// Auth Routes
// ==========================================
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// ==========================================
// Authenticated Routes
// ==========================================
Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Dokumen
    Route::prefix('dokumen')->name('dokumen.')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('index');
        Route::get('/buat', [DocumentController::class, 'create'])->name('create');
        Route::post('/', [DocumentController::class, 'store'])->name('store');
        Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
        Route::get('/{document}/edit', [DocumentController::class, 'edit'])->name('edit');
        Route::post('/{document}/edit', [DocumentController::class, 'updateEditor'])->name('update-editor');
        Route::get('/{document}/preview/{version?}', [DocumentController::class, 'preview'])->name('preview');
        Route::get('/{document}/preview-pdf/{version?}', [DocumentController::class, 'previewPdf'])->name('preview-pdf');
        Route::post('/{document}/upload-versi', [DocumentController::class, 'uploadVersi'])->name('upload-versi');
        Route::post('/{document}/ajukan', [DocumentController::class, 'ajukan'])->name('ajukan');
        Route::get('/{document}/download/{version}', [DocumentController::class, 'download'])->name('download');
        Route::get('/{document}/download-pdf/{version?}', [DocumentController::class, 'downloadPdf'])->name('download-pdf');
    });

    // Verifikasi
    Route::prefix('verifikasi')->name('verifikasi.')->group(function () {
        Route::get('/', [VerifikasiController::class, 'index'])->name('index');
        Route::get('/{verification}', [VerifikasiController::class, 'show'])->name('show');
        Route::post('/{verification}/setujui', [VerifikasiController::class, 'setujui'])->name('setujui');
        Route::post('/{verification}/revisi', [VerifikasiController::class, 'mintaRevisi'])->name('revisi');
    });

    // Tanda Tangan
    Route::prefix('tanda-tangan')->name('ttd.')->group(function () {
        Route::get('/', [TandaTanganController::class, 'index'])->name('index');
        Route::get('/{document}', [TandaTanganController::class, 'show'])->name('show');
        Route::post('/kirim-otp', [TandaTanganController::class, 'kirimOtp'])->name('kirim-otp');
        Route::post('/{document}/tandatangani', [TandaTanganController::class, 'tandatangani'])->name('tandatangani');
    });

    // Publikasi
    Route::prefix('publikasi')->name('publikasi.')->group(function () {
        Route::get('/', [PublikasiController::class, 'index'])->name('index');
        Route::post('/{document}/publikasi', [PublikasiController::class, 'publikasi'])->name('publikasi');
    });

    // Arsip
    Route::prefix('arsip')->name('arsip.')->group(function () {
        Route::get('/', [ArsipController::class, 'index'])->name('index');
        Route::get('/{document}', [ArsipController::class, 'show'])->name('show');
    });

    // Delegasi
    Route::prefix('delegasi')->name('delegasi.')->group(function () {
        Route::get('/', [DelegasiController::class, 'index'])->name('index');
        Route::post('/', [DelegasiController::class, 'store'])->name('store');
        Route::delete('/{delegation}', [DelegasiController::class, 'destroy'])->name('destroy');
    });

    // Laporan
    Route::prefix('laporan')->name('laporan.')->group(function () {
        Route::get('/', [LaporanController::class, 'index'])->name('index');
        Route::get('/ekspor', [LaporanController::class, 'ekspor'])->name('ekspor');
    });

    // OnlyOffice Docs Web Application
    Route::prefix('onlyoffice')->name('onlyoffice.')->group(function () {
        Route::get('/editor/{document}', [OnlyOfficeController::class, 'editor'])->name('editor');
        Route::get('/download/{document}/{version}', [OnlyOfficeController::class, 'download'])->name('download');
        Route::post('/callback/{document}', [OnlyOfficeController::class, 'callback'])->name('callback');
    });

    // Admin
    Route::prefix('admin')->name('admin.')->middleware('role:super_admin|admin_unit')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::resource('units', \App\Http\Controllers\Admin\UnitController::class);
        Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
        Route::resource('jenis-naskah', \App\Http\Controllers\Admin\DocumentTypeController::class);
        Route::resource('workflows', \App\Http\Controllers\Admin\WorkflowController::class);
    });

});
