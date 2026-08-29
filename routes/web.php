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

    // Halaman editor/form dapat dibiarkan terbuka cukup lama tanpa request lain.
    // Endpoint ringan ini dipanggil periodik oleh layout untuk mempertahankan
    // sesi dan token CSRF selama pengguna masih membuka aplikasi.
    Route::get('/session/keep-alive', function (\Illuminate\Http\Request $request) {
        $request->session()->put('_last_keep_alive_at', now()->timestamp);

        return response()->json(['ok' => true]);
    })->name('session.keep-alive');

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
        Route::post('/{verification}/teruskan-bawah', [VerifikasiController::class, 'teruskanBawah'])->name('teruskan-bawah');
    });

    // Tanda Tangan
    Route::prefix('tanda-tangan')->name('ttd.')->group(function () {
        Route::get('/', [TandaTanganController::class, 'index'])->name('index');
        Route::get('/{document}', [TandaTanganController::class, 'show'])->name('show');
        // Dibatasi (throttle) karena OTP 6 digit rentan brute force bila tidak dibatasi jumlah percobaan.
        Route::post('/{document}/kirim-otp', [TandaTanganController::class, 'kirimOtp'])->name('kirim-otp')->middleware('throttle:5,1');
        Route::post('/{document}/tandatangani', [TandaTanganController::class, 'tandatangani'])->name('tandatangani')->middleware('throttle:10,1');
        Route::post('/{document}/tolak', [TandaTanganController::class, 'tolak'])->name('tolak');
    });

    // Publikasi
    Route::prefix('publikasi')->name('publikasi.')->group(function () {
        Route::get('/', [PublikasiController::class, 'index'])->name('index');
        Route::post('/{document}/publikasi', [PublikasiController::class, 'publikasi'])->name('publikasi');
        Route::post('/{document}/unpublish', [PublikasiController::class, 'unpublish'])->name('unpublish');
        Route::post('/{document}/republish', [PublikasiController::class, 'republish'])->name('republish');
    });

    // Arsip
    Route::prefix('arsip')->name('arsip.')->group(function () {
        Route::get('/', [ArsipController::class, 'index'])->name('index');
        Route::get('/{document}', [ArsipController::class, 'show'])->name('show');
    });

    // Delegasi
    Route::prefix('delegasi')->name('delegasi.')->middleware('role:super_admin|penandatangan|verifikator')->group(function () {
        Route::get('/', [DelegasiController::class, 'index'])->name('index');
        Route::post('/', [DelegasiController::class, 'store'])->name('store');
        Route::delete('/{delegation}', [DelegasiController::class, 'destroy'])->name('destroy');
    });

    // Laporan
    Route::prefix('laporan')->name('laporan.')->group(function () {
        Route::get('/', [LaporanController::class, 'index'])->name('index');
        Route::get('/ekspor', [LaporanController::class, 'ekspor'])->name('ekspor');
    });

    // OnlyOffice Docs Web Application — halaman editor butuh sesi user login.
    // Route 'download' & 'callback' SENGAJA tidak diletakkan di sini (lihat bawah file):
    // keduanya dipanggil langsung oleh OnlyOffice Document Server (server-to-server, tanpa
    // cookie sesi browser), sehingga tidak akan pernah lolos middleware 'auth'.
    Route::prefix('onlyoffice')->name('onlyoffice.')->group(function () {
        Route::get('/editor/{document}', [OnlyOfficeController::class, 'editor'])->name('editor');
    });

    // Admin
    Route::prefix('admin')->name('admin.')->middleware('role:super_admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AdminController::class, 'index'])->name('index');
        Route::resource('units', \App\Http\Controllers\Admin\UnitController::class);
        Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
        
        Route::post('jenis-naskah/{jenis_naskah}/reset-nomor', [\App\Http\Controllers\Admin\DocumentTypeController::class, 'resetNomor'])->name('jenis-naskah.reset-nomor');
        Route::resource('jenis-naskah', \App\Http\Controllers\Admin\DocumentTypeController::class);
        
        Route::resource('workflows', \App\Http\Controllers\Admin\WorkflowController::class);
        
        Route::get('workflows/{workflow}/steps', [\App\Http\Controllers\Admin\WorkflowController::class, 'steps'])->name('workflows.steps');
        Route::post('workflows/{workflow}/steps', [\App\Http\Controllers\Admin\WorkflowController::class, 'storeStep'])->name('workflows.steps.store');
        Route::put('workflows/steps/{step}', [\App\Http\Controllers\Admin\WorkflowController::class, 'updateStep'])->name('workflows.steps.update');
        Route::delete('workflows/steps/{step}', [\App\Http\Controllers\Admin\WorkflowController::class, 'destroyStep'])->name('workflows.steps.destroy');
    });

    // Notifikasi API
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index'])->name('index');
        Route::post('/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('read');
    });

});

// ==========================================
// OnlyOffice Document Server callbacks (server-to-server, tanpa sesi browser)
// ==========================================
// 'download' diamankan dengan signed URL (dibuat oleh editor() untuk user yang sudah auth),
// bukan session auth, karena yang memanggil route ini adalah Document Server, bukan browser.
Route::prefix('onlyoffice')->name('onlyoffice.')->group(function () {
    Route::get('/download/{document}/{version}', [OnlyOfficeController::class, 'download'])
        ->name('download')
        ->middleware('signed');

    // 'callback' diamankan dengan verifikasi JWT OnlyOffice (lihat OnlyOfficeController::callback),
    // bukan signed URL, karena URL callback statis sedangkan JWT-nya berbeda tiap request.
    Route::post('/callback/{document}', [OnlyOfficeController::class, 'callback'])->name('callback');
});
