<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'onlyoffice/callback/*',
            'onlyoffice/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Token CSRF dapat menjadi tidak valid ketika sesi browser kedaluwarsa
        // atau pengguna masih membuka formulir lama. Jangan tampilkan halaman
        // teknis "419 Page Expired". Selalu muat ulang halaman asal agar token
        // baru dibuat; bila sesi memang telah hilang, middleware auth pada GET
        // berikutnya akan mengarahkan ke login. Ini tidak mengeluarkan pengguna
        // yang hanya memiliki formulir/token basi pada tab lama. Request JSON
        // tetap memakai 419 agar klien programatik dapat menanganinya eksplisit.
        // Handler Laravel menormalisasi TokenMismatchException menjadi
        // HttpException(419) sebelum callback render ini dipanggil.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sesi atau token keamanan telah kedaluwarsa. Silakan muat ulang halaman dan masuk kembali bila diperlukan.',
                ], 419);
            }

            return redirect()->back()->with('error', 'Formulir telah diperbarui untuk keamanan. Silakan periksa kembali lalu kirim ulang.');
        });
    })->create();
