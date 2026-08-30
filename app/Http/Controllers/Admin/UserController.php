<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Unit;
use App\Services\SigningOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['unit', 'roles']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('nip', 'like', "%{$search}%")
                  ->orWhere('jabatan', 'like', "%{$search}%");
            });
        }

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        $users = $query->latest()->paginate(15)->withQueryString();
        $units = Unit::active()->get();
        $roles = Role::all();

        return view('admin.users.index', compact('users', 'units', 'roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'otp_email' => 'nullable|email|max:255',
            'password' => 'required|string|min:6',
            'unit_id' => 'nullable|exists:units,id',
            'nip' => 'nullable|string|max:50',
            'jabatan' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $request->has('is_active');

        $user = User::create($validated);

        if ($request->filled('roles')) {
            $user->syncRoles($request->roles);
        }

        return redirect()->route('admin.users.index')->with('success', 'Akun Pengguna berhasil ditambahkan.');
    }

    public function update(Request $request, User $user, SigningOtpService $signingOtpService)
    {
        $originalEmail = $user->email;
        $originalOtpEmail = $user->otp_email;
        $originalRoles = $user->getRoleNames()->sort()->values()->all();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'otp_email' => 'nullable|email|max:255',
            'password' => 'nullable|string|min:6',
            'unit_id' => 'nullable|exists:units,id',
            'nip' => 'nullable|string|max:50',
            'jabatan' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
        ]);

        if ($request->filled('password')) {
            $validated['password'] = Hash::make($request->password);
        } else {
            unset($validated['password']);
        }

        $validated['is_active'] = $request->has('is_active');

        $user->update($validated);

        if ($request->has('roles')) {
            $user->syncRoles($request->roles ?? []);
        }

        $securityContextChanged = $originalEmail !== $user->fresh()->email
            || $originalOtpEmail !== $user->fresh()->otp_email
            || $request->filled('password')
            || $originalRoles !== $user->fresh()->getRoleNames()->sort()->values()->all()
            || $user->wasChanged('is_active');
        if ($securityContextChanged) {
            $signingOtpService->revokeActive($user, reason: 'account_or_role_changed');
        }

        return redirect()->route('admin.users.index')->with('success', 'Akun Pengguna berhasil diperbarui.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun Anda sendiri yang sedang digunakan.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'Akun Pengguna berhasil dihapus.');
    }
}
