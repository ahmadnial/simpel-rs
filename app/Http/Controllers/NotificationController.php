<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Dapatkan daftar notifikasi user saat ini.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $notifications = $user->notifications()
            // Relasi notifiable pada koneksi SQL Server sudah membawa urutan
            // created_at DESC. Jangan menambahkan latest() lagi karena SQL Server
            // menolak kolom yang sama dua kali di ORDER BY.
            ->take(15)
            ->get()
            ->map(function ($n) {
                return [
                    'id'         => $n->id,
                    'type'       => $n->data['tipe_event'] ?? 'info',
                    'title'      => $n->data['title'] ?? 'Notifikasi Naskah',
                    'message'    => $n->data['message'] ?? '',
                    'url'        => $n->data['url'] ?? '#',
                    'read_at'    => $n->read_at,
                    'created_at' => $n->created_at->diffForHumans(),
                ];
            });

        // JSON_THROW_ON_ERROR (default di Laravel) gagal jika data lama di
        // database mengandung byte non-UTF-8. Ganti byte tersebut dengan
        // karakter pengganti agar satu notifikasi rusak tidak mematikan API.
        return response()->json([
            'unread_count'  => $user->unreadNotifications()->count(),
            'notifications' => $notifications,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Tandai satu notifikasi telah dibaca.
     */
    public function markAsRead(string $id): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'url'     => $notification->data['url'] ?? route('dokumen.index'),
        ]);
    }

    /**
     * Tandai semua notifikasi telah dibaca.
     */
    public function markAllRead(): JsonResponse
    {
        auth()->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
