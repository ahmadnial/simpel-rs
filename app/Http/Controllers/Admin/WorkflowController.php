<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\WorkflowTemplate;
use App\Models\DocumentType;
use App\Models\Unit;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {
        $query = WorkflowTemplate::with(['documentType', 'unit', 'steps']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('nama', 'like', "%{$search}%");
        }

        $workflows = $query->latest()->paginate(15)->withQueryString();
        $documentTypes = DocumentType::active()->get();
        $units = Unit::active()->get();

        return view('admin.workflows.index', compact('workflows', 'documentTypes', 'units'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id',
            'unit_id' => 'nullable|exists:units,id',
            'deskripsi' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['is_default'] = $request->has('is_default');
        $validated['is_active'] = $request->has('is_active');

        if ($validated['is_default']) {
            WorkflowTemplate::where('document_type_id', $validated['document_type_id'])->update(['is_default' => false]);
        }

        WorkflowTemplate::create($validated);

        return redirect()->route('admin.workflows.index')->with('success', 'Template Workflow berhasil ditambahkan.');
    }

    public function update(Request $request, WorkflowTemplate $workflow)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id',
            'unit_id' => 'nullable|exists:units,id',
            'deskripsi' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['is_default'] = $request->has('is_default');
        $validated['is_active'] = $request->has('is_active');

        if ($validated['is_default']) {
            WorkflowTemplate::where('document_type_id', $validated['document_type_id'])
                ->where('id', '!=', $workflow->id)
                ->update(['is_default' => false]);
        }

        $workflow->update($validated);

        return redirect()->route('admin.workflows.index')->with('success', 'Template Workflow berhasil diperbarui.');
    }

    public function destroy(WorkflowTemplate $workflow)
    {
        if ($workflow->documents()->count() > 0) {
            return back()->with('error', 'Workflow tidak dapat dihapus karena sudah digunakan oleh dokumen.');
        }

        $workflow->steps()->delete();
        $workflow->delete();

        return redirect()->route('admin.workflows.index')->with('success', 'Template Workflow berhasil dihapus.');
    }
}
