<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users'     => User::count(),
            'total_units'     => Unit::count(),
            'total_types'     => DocumentType::count(),
            'total_workflows' => WorkflowTemplate::count(),
        ];

        $users = User::with(['unit', 'roles'])->latest()->paginate(10);
        $units = Unit::with('parent')->orderBy('urutan')->get();
        $documentTypes = DocumentType::orderBy('urutan')->get();
        $auditLogs = AuditLog::latest('created_at')->take(10)->get();

        return view('admin.index', compact('stats', 'users', 'units', 'documentTypes', 'auditLogs'));
    }
}
