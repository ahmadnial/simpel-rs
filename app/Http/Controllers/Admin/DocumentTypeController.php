<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\DocumentType;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = DocumentType::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%")
                  ->orWhere('singkatan', 'like', "%{$search}%");
            });
        }

        $documentTypes = $query->orderBy('urutan')->orderBy('nama')->paginate(15)->withQueryString();

        return view('admin.jenis-naskah.index', compact('documentTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:20|unique:document_types,kode',
            'nama' => 'required|string|max:255',
            'singkatan' => 'required|string|max:50',
            'deskripsi' => 'nullable|string',
            'format_nomor' => 'required|string|max:255',
            'mulai_nomor' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'urutan' => 'nullable|integer',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['urutan'] = $request->urutan ?? 0;

        DocumentType::create($validated);

        return redirect()->route('admin.jenis-naskah.index')->with('success', 'Jenis Naskah / Klasifikasi berhasil ditambahkan.');
    }

    public function update(Request $request, DocumentType $jenisNaskah)
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:20|unique:document_types,kode,' . $jenisNaskah->id,
            'nama' => 'required|string|max:255',
            'singkatan' => 'required|string|max:50',
            'deskripsi' => 'nullable|string',
            'format_nomor' => 'required|string|max:255',
            'mulai_nomor' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'urutan' => 'nullable|integer',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['urutan'] = $request->urutan ?? 0;

        $jenisNaskah->update($validated);

        return redirect()->route('admin.jenis-naskah.index')->with('success', 'Jenis Naskah / Klasifikasi berhasil diperbarui.');
    }

    public function destroy(DocumentType $jenisNaskah)
    {
        if ($jenisNaskah->documents()->count() > 0) {
            return back()->with('error', 'Jenis Naskah tidak dapat dihapus karena sudah memiliki dokumen terdaftar.');
        }

        $jenisNaskah->delete();

        return redirect()->route('admin.jenis-naskah.index')->with('success', 'Jenis Naskah berhasil dihapus.');
    }

    public function resetNomor(Request $request, DocumentType $jenisNaskah)
    {
        $tahun = (int) now()->format('Y');
        
        \App\Models\NumberingSequence::where('document_type_id', $jenisNaskah->id)
            ->where('tahun', $tahun)
            ->update([
                'nomor_terakhir' => max(0, $jenisNaskah->mulai_nomor - 1)
            ]);

        return redirect()->route('admin.jenis-naskah.index')->with('success', "Penomoran untuk {$jenisNaskah->nama} berhasil direset ke nomor mulai ({$jenisNaskah->mulai_nomor}).");
    }
}
