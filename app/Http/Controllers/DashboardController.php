<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVerification;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Dokumen milik saya (pengusul)
        $dokumenSaya = Document::where('pengusul_id', $user->id)
            ->with(['documentType', 'unit'])
            ->latest()
            ->take(5)
            ->get();

        // Antrian verifikasi saya
        $antrianVerifikasi = DocumentVerification::where('verifikator_id', $user->id)
            ->where('status', DocumentVerification::STATUS_MENUNGGU)
            ->with(['document.documentType', 'document.pengusul'])
            ->latest()
            ->take(5)
            ->get();

        // Antrian TTD saya
        $antrianTtd = Document::where('status', Document::STATUS_MENUNGGU_TTD)
            ->whereHas('workflowTemplate.steps', fn($q) => $q->where('tipe', 'penandatangan')->where('role_nama', $user->getRoleNames()->first()))
            ->with(['documentType', 'pengusul'])
            ->latest()
            ->take(5)
            ->get();

        // Statistik
        $stats = [
            'total_dokumen'     => Document::where('pengusul_id', $user->id)->count(),
            'menunggu_verifikasi'=> DocumentVerification::where('verifikator_id', $user->id)->where('status', 'menunggu')->count(),
            'draft'             => Document::where('pengusul_id', $user->id)->where('status', Document::STATUS_DRAFT)->count(),
            'selesai_bulan_ini' => Document::where('status', Document::STATUS_DITANDATANGANI)
                ->whereMonth('ditandatangani_at', now()->month)->count(),
        ];

        return view('dashboard', compact('dokumenSaya', 'antrianVerifikasi', 'antrianTtd', 'stats'));
    }
}
