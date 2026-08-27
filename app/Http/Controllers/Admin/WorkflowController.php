<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\WorkflowTemplate;
use App\Models\DocumentType;
use App\Models\Unit;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {
        $query = WorkflowTemplate::with(['documentType', 'units', 'steps']);

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
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'exists:units,id',
            'deskripsi' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['is_default'] = $request->has('is_default');
        $validated['is_active'] = $request->has('is_active');
        $unitIds = $validated['unit_ids'] ?? [];
        unset($validated['unit_ids']);

        // Isolasi tetap per jenis naskah (document_type), bukan per unit: satu template
        // default berlaku untuk semua unit yang tidak dibatasi cakupannya.
        if ($validated['is_default']) {
            WorkflowTemplate::where('document_type_id', $validated['document_type_id'])->update(['is_default' => false]);
        }

        $workflow = WorkflowTemplate::create($validated);
        $workflow->units()->sync($unitIds);

        return redirect()->route('admin.workflows.index')->with('success', 'Template Workflow berhasil ditambahkan.');
    }

    public function update(Request $request, WorkflowTemplate $workflow)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'exists:units,id',
            'deskripsi' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['is_default'] = $request->has('is_default');
        $validated['is_active'] = $request->has('is_active');
        $unitIds = $validated['unit_ids'] ?? [];
        unset($validated['unit_ids']);

        if ($validated['is_default']) {
            WorkflowTemplate::where('document_type_id', $validated['document_type_id'])
                ->where('id', '!=', $workflow->id)
                ->update(['is_default' => false]);
        }

        $workflow->update($validated);
        $workflow->units()->sync($unitIds);

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
            'role_nama'       => [
                Rule::requiredIf(function () use ($request) {
                    // Tahap 1 mode serial boleh kosong role_nama-nya (pengusul yang pilih manual
                    // verifikator saat mengajukan dokumen). Tahap serial di atas level 1 dan tahap
                    // penandatangan WAJIB role_nama — kalau kosong, proses dokumen akan macet di
                    // tengah jalan (tidak ada verifikator/penandatangan yang bisa ditugaskan)
                    // alih-alih dicegah lebih awal saat admin menyusun template.
                    // Mode parallel tidak butuh role_nama di sini — pool-nya divalidasi terpisah
                    // di bawah lewat verifier_users/verifier_roles.
                    if ($request->input('tipe') === 'penandatangan') {
                        return true;
                    }

                    return $request->input('mode_verifikasi') === 'serial' && (int) $request->input('urutan') > 1;
                }),
                'nullable', 'string',
            ],
            'mode_verifikasi' => 'required|in:serial,parallel',
            'min_approval'    => 'nullable|integer',
            'verifier_users'  => 'nullable|array',
            'verifier_roles'  => 'nullable|array',
        ], [
            'role_nama.required' => 'Nama Role wajib diisi untuk tahap ini — tidak ada mekanisme pemilihan verifikator manual selain di tahap pertama.',
        ]);

        // Mode parallel-quorum wajib punya minimal satu anggota pool (user atau role), kalau
        // kosong DocumentService::createVerificationsForStep() akan gagal total saat dokumen
        // nyata mencoba masuk ke tahap ini.
        if ($validated['mode_verifikasi'] === 'parallel'
            && empty($validated['verifier_users'])
            && empty($validated['verifier_roles'])
        ) {
            return back()->withErrors([
                'verifier_users' => 'Mode Parallel/Quorum wajib punya minimal 1 anggota pool (pilih user atau role).',
            ])->withInput();
        }

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
