<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\WorkflowTemplate;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Unit;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            $this->clearCompetingDefaults($validated['document_type_id'], $unitIds);
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
            $this->clearCompetingDefaults($validated['document_type_id'], $unitIds, $workflow->id);
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
        $validated = $this->validateStep($request, $workflow);

        $step = $workflow->steps()->create($this->stepAttributes($validated));
        $this->syncVerifierPool($step, $validated);

        return back()->with('success', 'Tahapan berhasil ditambahkan.');
    }

    public function updateStep(Request $request, \App\Models\WorkflowStep $step)
    {
        $workflow = $step->template;
        abort_unless($workflow, 404);

        // Step tidak menyimpan snapshot konfigurasi untuk dokumen yang sedang berjalan.
        // Mengubahnya saat ada dokumen aktif bisa mengubah penerima/tata cara tahap
        // berikutnya di tengah proses. Batasi perubahan sampai tidak ada proses aktif.
        if ($this->workflowHasActiveDocuments($workflow)) {
            return back()->with('error', 'Tahapan tidak dapat diubah selama masih ada dokumen aktif pada workflow ini. Selesaikan atau batalkan proses aktif terlebih dahulu.');
        }

        $validated = $this->validateStep($request, $workflow, $step);

        $step->update($this->stepAttributes($validated));
        $this->syncVerifierPool($step, $validated);

        return redirect()->route('admin.workflows.steps', $workflow)
            ->with('success', 'Tahapan berhasil diperbarui. Perubahan berlaku untuk pengajuan berikutnya.');
    }

    private function validateStep(Request $request, WorkflowTemplate $workflow, ?\App\Models\WorkflowStep $editingStep = null): array
    {
        $otherSteps = $workflow->steps()
            ->when($editingStep, fn ($query) => $query->whereKeyNot($editingStep->id));

        $validated = $request->validate([
            'nama_tahap'      => 'required|string',
            'tipe'            => 'required|in:verifikasi,penandatangan',
            'urutan'          => [
                'required', 'integer', 'min:1',
                Rule::unique('workflow_steps', 'urutan')
                    ->where('workflow_template_id', $workflow->id)
                    ->ignore($editingStep?->id),
            ],
            'sla_hari_kerja'  => 'required|integer|min:1|max:365',
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
                'nullable', 'string', Rule::exists('roles', 'name'),
            ],
            'mode_verifikasi' => 'required|in:serial,parallel',
            'min_approval'    => 'nullable|integer|min:1',
            'verifier_users'  => 'nullable|array',
            'verifier_users.*'=> ['integer', Rule::exists('users', 'id')->where('is_active', true)],
            'verifier_roles'  => 'nullable|array',
            'verifier_roles.*'=> 'string|exists:roles,name',
        ], [
            'role_nama.required' => 'Nama Role wajib diisi untuk tahap ini — tidak ada mekanisme pemilihan verifikator manual selain di tahap pertama.',
        ]);

        if ($validated['tipe'] === 'penandatangan' && $validated['mode_verifikasi'] !== 'serial') {
            throw ValidationException::withMessages(['mode_verifikasi' => 'Tahap penandatangan harus menggunakan mode Serial/Tunggal.']);
        }

        if ($validated['tipe'] === 'penandatangan' && (clone $otherSteps)->where('tipe', 'penandatangan')->exists()) {
            throw ValidationException::withMessages(['tipe' => 'Satu workflow hanya boleh memiliki satu tahap penandatangan.']);
        }

        if ($validated['tipe'] === 'verifikasi' && (clone $otherSteps)->where('tipe', 'penandatangan')->where('urutan', '<', $validated['urutan'])->exists()) {
            throw ValidationException::withMessages(['urutan' => 'Tahap verifikasi tidak boleh ditempatkan setelah penandatangan.']);
        }

        if ($validated['tipe'] === 'penandatangan' && (clone $otherSteps)->where('urutan', '>', $validated['urutan'])->exists()) {
            throw ValidationException::withMessages(['urutan' => 'Penandatangan wajib menjadi tahap terakhir.']);
        }

        // Mode parallel-quorum wajib punya minimal satu anggota pool (user atau role), kalau
        // kosong DocumentService::createVerificationsForStep() akan gagal total saat dokumen
        // nyata mencoba masuk ke tahap ini.
        if ($validated['mode_verifikasi'] === 'parallel'
            && empty($validated['verifier_users'])
            && empty($validated['verifier_roles'])
        ) {
            throw ValidationException::withMessages([
                'verifier_users' => 'Mode Parallel/Quorum wajib punya minimal 1 anggota pool (pilih user atau role).',
            ]);
        }

        if ($validated['mode_verifikasi'] === 'parallel') {
            // User yang dipilih langsung dan juga berada dalam role pool hanya mendapat
            // satu tiket. Hitung penerima unik agar quorum tidak bisa disimpan lebih
            // besar dari jumlah orang yang benar-benar akan menerima tiket.
            $poolSize = $this->resolvedPoolUserCount($validated);
            if (($validated['min_approval'] ?? 1) > $poolSize) {
                throw ValidationException::withMessages(['min_approval' => "Minimum persetujuan tidak boleh melebihi jumlah pool aktif ({$poolSize})."]);
            }
        }

        return $validated;
    }

    private function stepAttributes(array $validated): array
    {
        return [
            'nama_tahap' => $validated['nama_tahap'],
            'tipe' => $validated['tipe'],
            'urutan' => $validated['urutan'],
            'sla_hari_kerja' => $validated['sla_hari_kerja'],
            'mode_verifikasi' => $validated['mode_verifikasi'],
            'min_approval' => $validated['mode_verifikasi'] === 'parallel' ? ($validated['min_approval'] ?? 1) : 1,
            'role_nama' => $validated['mode_verifikasi'] === 'serial' ? ($validated['role_nama'] ?? null) : null,
        ];
    }

    private function syncVerifierPool(\App\Models\WorkflowStep $step, array $validated): void
    {
        $step->verifierPool()->delete();
        if ($validated['mode_verifikasi'] !== 'parallel') {
            return;
        }

        foreach (collect($validated['verifier_users'] ?? [])->unique() as $userId) {
            $step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $userId]);
        }
        foreach (collect($validated['verifier_roles'] ?? [])->unique() as $roleName) {
            $step->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => $roleName]);
        }
    }

    private function resolvedPoolUserCount(array $validated): int
    {
        $userIds = collect($validated['verifier_users'] ?? [])->unique()->values();
        $roleNames = collect($validated['verifier_roles'] ?? [])->unique()->values();

        return \App\Models\User::where('is_active', true)
            ->where(function ($query) use ($userIds, $roleNames) {
                if ($userIds->isNotEmpty()) {
                    $query->whereIn('id', $userIds);
                }
                if ($roleNames->isNotEmpty()) {
                    $query->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', $roleNames));
                }
            })
            ->count();
    }

    public function destroyStep(\App\Models\WorkflowStep $step)
    {
        if ($step->verifications()->exists()) {
            return back()->with('error', 'Tahapan tidak dapat dihapus karena sudah memiliki riwayat verifikasi dokumen.');
        }

        if ($this->workflowHasActiveDocuments($step->template)) {
            return back()->with('error', 'Tahapan tidak dapat dihapus selama masih ada dokumen aktif pada workflow ini.');
        }

        $step->verifierPool()->delete();
        $step->delete();
        return back()->with('success', 'Tahapan berhasil dihapus.');
    }

    private function workflowHasActiveDocuments(?WorkflowTemplate $workflow): bool
    {
        if (!$workflow) {
            return false;
        }

        return $workflow->documents()->whereIn('status', [
            Document::STATUS_DIAJUKAN,
            Document::STATUS_VERIFIKASI,
            Document::STATUS_REVISI,
            Document::STATUS_MENUNGGU_TTD,
            Document::STATUS_DITOLAK_TTD,
        ])->exists();
    }

    private function clearCompetingDefaults(int $documentTypeId, array $unitIds, ?int $exceptId = null): void
    {
        $query = WorkflowTemplate::where('document_type_id', $documentTypeId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId));

        if (empty($unitIds)) {
            $query->whereDoesntHave('units');
        } else {
            $query->whereHas('units', fn ($q) => $q->whereIn('units.id', $unitIds));
        }

        $query->update(['is_default' => false]);
    }
}
