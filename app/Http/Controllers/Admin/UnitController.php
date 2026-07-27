<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Unit;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $query = Unit::with('parent');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%")
                  ->orWhere('singkatan', 'like', "%{$search}%");
            });
        }

        $units = $query->orderBy('urutan')->orderBy('nama')->paginate(15)->withQueryString();
        $parentUnits = Unit::whereNull('parent_id')->orderBy('nama')->get();

        return view('admin.units.index', compact('units', 'parentUnits'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:20|unique:units,kode',
            'nama' => 'required|string|max:255',
            'singkatan' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:units,id',
            'urutan' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['urutan'] = $request->urutan ?? 0;

        Unit::create($validated);

        return redirect()->route('admin.units.index')->with('success', 'Unit Kerja / Instalasi berhasil ditambahkan.');
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'kode' => 'required|string|max:20|unique:units,kode,' . $unit->id,
            'nama' => 'required|string|max:255',
            'singkatan' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:units,id',
            'urutan' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['urutan'] = $request->urutan ?? 0;

        $unit->update($validated);

        return redirect()->route('admin.units.index')->with('success', 'Unit Kerja / Instalasi berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->children()->count() > 0) {
            return back()->with('error', 'Unit Kerja tidak dapat dihapus karena memiliki sub-unit.');
        }

        if ($unit->users()->count() > 0) {
            return back()->with('error', 'Unit Kerja tidak dapat dihapus karena masih memiliki pengguna terdaftar.');
        }

        $unit->delete();

        return redirect()->route('admin.units.index')->with('success', 'Unit Kerja berhasil dihapus.');
    }
}
