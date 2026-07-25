<?php

namespace App\Http\Controllers;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Http\Request;

class DelegasiController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $delegations = Delegation::where('pejabat_id', $user->id)
            ->orWhere('delegasi_id', $user->id)
            ->with(['pejabat', 'delegasi', 'pembuatDelegasi'])
            ->latest()
            ->paginate(10);

        $eligibleUsers = User::where('is_active', true)
            ->where('id', '!=', $user->id)
            ->get();

        return view('delegasi.index', compact('delegations', 'eligibleUsers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'delegasi_id'    => 'required|exists:users,id|different:pejabat_id',
            'tipe'           => 'required|in:plt,plh',
            'alasan'         => 'nullable|string|max:255',
            'berlaku_dari'   => 'required|date',
            'berlaku_sampai' => 'required|date|after_or_equal:berlaku_dari',
        ]);

        Delegation::create([
            'pejabat_id'     => auth()->id(),
            'delegasi_id'    => $validated['delegasi_id'],
            'tipe'           => $validated['tipe'],
            'alasan'         => $validated['alasan'] ?? null,
            'berlaku_dari'   => $validated['berlaku_dari'],
            'berlaku_sampai' => $validated['berlaku_sampai'],
            'is_active'      => true,
            'dibuat_oleh'    => auth()->id(),
        ]);

        return back()->with('success', 'Delegasi wewenang berhasil ditambahkan.');
    }

    public function destroy(Delegation $delegation)
    {
        abort_unless($delegation->pejabat_id === auth()->id() || auth()->user()->hasRole('super_admin'), 403);

        $delegation->update(['is_active' => false]);

        return back()->with('success', 'Delegasi berhasil dinonaktifkan.');
    }
}
