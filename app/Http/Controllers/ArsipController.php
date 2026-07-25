<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Unit;
use Illuminate\Http\Request;

class ArsipController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::whereIn('status', [Document::STATUS_DITANDATANGANI, Document::STATUS_DIPUBLIKASIKAN, Document::STATUS_DIARSIPKAN])
            ->with(['documentType', 'unit', 'pengusul', 'signature']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                  ->orWhere('nomor_surat', 'like', "%{$search}%")
                  ->orWhere('perihal', 'like', "%{$search}%");
            });
        }

        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->document_type_id);
        }

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('tahun')) {
            $query->whereYear('tanggal_surat', $request->tahun);
        }

        $documents = $query->latest('tanggal_surat')->paginate(15);
        $documentTypes = DocumentType::active()->get();
        $units = Unit::active()->get();

        return view('arsip.index', compact('documents', 'documentTypes', 'units'));
    }

    public function show(Document $document)
    {
        $document->load([
            'documentType', 'unit', 'pengusul',
            'versions.uploader', 'verifications.verifikator',
            'signature.penandatangan', 'auditLogs'
        ]);

        return view('arsip.show', compact('document'));
    }
}
