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

    public function steps(WorkflowTemplate $workflow)
    {
        $workflow->load('steps.verifierPool');
        $roles = \Spatie\Permission\Models\Role::all();
        $users = \App\Models\User::all();
        return view('admin.workflows.steps', compact('workflow', 'roles', 'users'));
    }

    public function storeStep(Request $request, WorkflowTemplate $workflow)
    {
        $validated = $request->validate([
            'nama_tahap'      => 'required|string',
            'tipe'            => 'required|in:verifikasi,penandatangan',
            'urutan'          => 'required|integer',
            'sla_hari_kerja'  => 'required|integer',
            'role_nama'       => 'nullable|string',
            'mode_verifikasi' => 'required|in:serial,parallel',
            'min_approval'    => 'nullable|integer',
            'verifier_users'  => 'nullable|array',
            'verifier_roles'  => 'nullable|array',
        ]);

        $step = $workflow->steps()->create($validated);

        if ($validated['mode_verifikasi'] === 'parallel') {
            if (!empty($validated['verifier_users'])) {
                foreach ($validated['verifier_users'] as $uid) {
                    $step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $uid]);
                }
            }
            if (!empty($validated['verifier_roles'])) {
                foreach ($validated['verifier_roles'] as $rName) {
                    $step->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => $rName]);
                }
            }
        }

        return back()->with('success', 'Tahapan berhasil ditambahkan.');
    }

    public function destroyStep(\App\Models\WorkflowStep $step)
    {
        $step->verifierPool()->delete();
        $step->delete();
        return back()->with('success', 'Tahapan berhasil dihapus.');
    }
}
