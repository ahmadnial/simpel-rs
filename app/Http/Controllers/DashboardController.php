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

        // Pejabat ID untuk verifikasi (termasuk delegasi Plt/Plh)
        $pejabatIds = [$user->id];
        if ($user->activeDelegation()) {
            $pejabatIds[] = $user->activeDelegation()->pejabat_id;
        }

        // Roles yang dimiliki (termasuk delegasi Plt/Plh)
        $signerRoles = $user->getRoleNames()->toArray();
        if ($delegated = $user->activeDelegation()) {
            if ($delegated->pejabat) {
                $signerRoles = array_unique(array_merge($signerRoles, $delegated->pejabat->getRoleNames()->toArray()));
            }
        }

        // Dokumen milik saya (pengusul)
        $dokumenSaya = Document::where('pengusul_id', $user->id)
            ->with(['documentType', 'unit'])
            ->latest()
            ->take(5)
            ->get();

        // Antrian verifikasi saya
        $antrianVerifikasiQuery = DocumentVerification::where('status', DocumentVerification::STATUS_MENUNGGU)
            ->with(['document.documentType', 'document.pengusul']);
        if (!$user->hasRole('super_admin')) {
            $antrianVerifikasiQuery->whereIn('verifikator_id', $pejabatIds);
        }
        $antrianVerifikasi = $antrianVerifikasiQuery->latest()->take(5)->get();

        // Antrian TTD saya
        $antrianTtdQuery = Document::where('status', Document::STATUS_MENUNGGU_TTD)
            ->with(['documentType', 'pengusul']);
        if (!$user->hasRole('super_admin')) {
            $antrianTtdQuery->whereHas('workflowTemplate.steps', function ($q) use ($signerRoles) {
                $q->where('tipe', 'penandatangan')->whereIn('role_nama', $signerRoles);
            });
        }
        $antrianTtd = $antrianTtdQuery->latest()->take(5)->get();

        // Hitung total menunggu verifikasi & menunggu TTD secara akurat
        $totalVerifikasiMenunggu = DocumentVerification::where('status', DocumentVerification::STATUS_MENUNGGU)
            ->when(!$user->hasRole('super_admin'), fn($q) => $q->whereIn('verifikator_id', $pejabatIds))
            ->count();

        $totalTtdMenunggu = Document::where('status', Document::STATUS_MENUNGGU_TTD)
            ->when(!$user->hasRole('super_admin'), function ($q) use ($signerRoles) {
                $q->whereHas('workflowTemplate.steps', fn($sq) => $sq->where('tipe', 'penandatangan')->whereIn('role_nama', $signerRoles));
            })
            ->count();

        // Statistik Dashboard
        $stats = [
            'total_dokumen'      => Document::where('pengusul_id', $user->id)->count(),
            'menunggu_tindakan'  => $totalVerifikasiMenunggu + $totalTtdMenunggu,
            'menunggu_verifikasi'=> $totalVerifikasiMenunggu,
            'menunggu_ttd'       => $totalTtdMenunggu,
            'draft'              => Document::where('pengusul_id', $user->id)->where('status', Document::STATUS_DRAFT)->count(),
            'selesai_bulan_ini'  => Document::where('pengusul_id', $user->id)
                ->where('status', Document::STATUS_DITANDATANGANI)
                ->whereMonth('ditandatangani_at', now()->month)
                ->whereYear('ditandatangani_at', now()->year)
                ->count(),
        ];

        return view('dashboard', compact('dokumenSaya', 'antrianVerifikasi', 'antrianTtd', 'stats'));
    }
}
