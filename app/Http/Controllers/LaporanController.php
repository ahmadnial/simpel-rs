<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Unit;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;

class LaporanController extends Controller
{
    public function index(Request $request)
    {
        $tahun = $request->get('tahun', date('Y'));
        $unitId = $request->get('unit_id');

        $query = Document::whereYear('created_at', $tahun);

        if ($unitId) {
            $query->where('unit_id', $unitId);
        }

        $totalDokumen = (clone $query)->count();
        $totalSelesai = (clone $query)->where('status', Document::STATUS_DITANDATANGANI)->count();
        $totalProses  = (clone $query)->whereIn('status', [Document::STATUS_DIAJUKAN, Document::STATUS_VERIFIKASI, Document::STATUS_MENUNGGU_TTD])->count();
        $totalRevisi  = (clone $query)->where('status', Document::STATUS_REVISI)->count();

        $dokumenPerJenis = DocumentType::withCount(['documents' => function ($q) use ($tahun, $unitId) {
            $q->whereYear('created_at', $tahun);
            if ($unitId) $q->where('unit_id', $unitId);
        }])->get();

        $recentDocuments = $query->with(['documentType', 'unit', 'pengusul'])
            ->latest()
            ->paginate(15);

        $units = Unit::active()->get();

        return view('laporan.index', compact(
            'totalDokumen', 'totalSelesai', 'totalProses', 'totalRevisi',
            'dokumenPerJenis', 'recentDocuments', 'units', 'tahun', 'unitId'
        ));
    }

    public function ekspor(Request $request)
    {
        $tahun = $request->get('tahun', date('Y'));
        $unitId = $request->get('unit_id');

        $query = Document::whereYear('created_at', $tahun)->with(['documentType', 'unit', 'pengusul']);

        if ($unitId) {
            $query->where('unit_id', $unitId);
        }

        $documents = $query->get();

        return (new FastExcel($documents))->download('laporan-persuratan-' . $tahun . '.xlsx', function ($doc) {
            return [
                'ID'             => $doc->id,
                'Judul Dokumen'  => $doc->judul,
                'Jenis Naskah'   => $doc->documentType->nama ?? '-',
                'Unit Kerja'     => $doc->unit->nama ?? '-',
                'Pengusul'       => $doc->pengusul->name ?? '-',
                'Status'         => $doc->status_label,
                'Nomor Surat'    => $doc->nomor_surat ?? '-',
                'Tanggal Surat'  => $doc->tanggal_surat ? $doc->tanggal_surat->format('d/m/Y') : '-',
                'Tanggal Dibuat' => $doc->created_at->format('d/m/Y H:i'),
            ];
        });
    }
}
