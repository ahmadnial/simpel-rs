<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — SIMPEL-RS</title>
    <meta name="description" content="Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.3/dist/docx-preview.min.js"></script>
    @livewireStyles
    @stack('styles')
</head>
<body>

<div class="app-shell">

    {{-- ==================== SIDEBAR ==================== --}}
    <aside class="sidebar" id="sidebar">

        {{-- Brand --}}
        <div class="sidebar-brand">
            <div class="sidebar-logo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
            </div>
            <div class="sidebar-brand-text">
                <div class="sidebar-brand-name">SIMPEL-RS</div>
                <div class="sidebar-brand-sub">Persuratan Elektronik</div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="sidebar-nav">

            <div class="nav-section-label">Utama</div>

            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                Dashboard
            </a>

            <a href="{{ route('dokumen.create') }}" class="nav-item {{ request()->routeIs('dokumen.create') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                Buat Dokumen
            </a>

            <a href="{{ route('dokumen.index') }}" class="nav-item {{ request()->routeIs('dokumen.*') && !request()->routeIs('dokumen.create') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                Dokumen Saya
            </a>

            <div class="nav-section-label">Tindakan</div>

            @can('dokumen.verifikasi')
            <a href="{{ route('verifikasi.index') }}" class="nav-item {{ request()->routeIs('verifikasi.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 11 12 14 22 4"/>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                Antrian Verifikasi
                @php $antrian = \App\Models\DocumentVerification::where('verifikator_id', auth()->id())->where('status','menunggu')->count(); @endphp
                @if($antrian > 0)
                    <span class="nav-badge">{{ $antrian }}</span>
                @endif
            </a>
            @endcan

            @can('dokumen.tanda_tangan')
            <a href="{{ route('ttd.index') }}" class="nav-item {{ request()->routeIs('ttd.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 19l7-7 3 3-7 7-3-3z"/>
                    <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                    <path d="M2 2l7.586 7.586"/>
                    <circle cx="11" cy="11" r="2"/>
                </svg>
                Antrian TTD
            </a>
            @endcan

            @can('dokumen.publikasi')
            <a href="{{ route('publikasi.index') }}" class="nav-item {{ request()->routeIs('publikasi.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
                Publikasi
            </a>
            @endcan

            <div class="nav-section-label">Arsip & Laporan</div>

            <a href="{{ route('arsip.index') }}" class="nav-item {{ request()->routeIs('arsip.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="21 8 21 21 3 21 3 8"/>
                    <rect x="1" y="3" width="22" height="5"/>
                    <line x1="10" y1="12" x2="14" y2="12"/>
                </svg>
                Arsip Dokumen
            </a>

            @can('laporan.lihat')
            <a href="{{ route('laporan.index') }}" class="nav-item {{ request()->routeIs('laporan.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"/>
                    <line x1="12" y1="20" x2="12" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
                Laporan & Statistik
            </a>
            @endcan

            <a href="{{ route('delegasi.index') }}" class="nav-item {{ request()->routeIs('delegasi.*') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Delegasi / Plt
            </a>

            @role('super_admin|admin_unit')
            <div class="nav-section-label">Administrasi</div>
            <a href="{{ route('admin.index') }}" class="nav-item {{ request()->routeIs('admin.index') ? 'active' : '' }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
                </svg>
                Dashboard Admin
            </a>
            <a href="{{ route('admin.units.index') }}" class="nav-item {{ request()->routeIs('admin.units.*') ? 'active' : '' }}" style="padding-left: 2rem; font-size: 0.85rem;">
                🏢 Master Unit Kerja
            </a>
            <a href="{{ route('admin.users.index') }}" class="nav-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" style="padding-left: 2rem; font-size: 0.85rem;">
                👤 Master Pengguna
            </a>
            <a href="{{ route('admin.jenis-naskah.index') }}" class="nav-item {{ request()->routeIs('admin.jenis-naskah.*') ? 'active' : '' }}" style="padding-left: 2rem; font-size: 0.85rem;">
                📋 Klasifikasi Naskah
            </a>
            <a href="{{ route('admin.workflows.index') }}" class="nav-item {{ request()->routeIs('admin.workflows.*') ? 'active' : '' }}" style="padding-left: 2rem; font-size: 0.85rem;">
                ⚙️ Template Workflow
            </a>
            @endrole

        </nav>

        {{-- User Profile --}}
        <div class="sidebar-user">
            <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="user-avatar">
            <div class="user-info">
                <div class="user-name">{{ auth()->user()->name }}</div>
                <div class="user-role">{{ auth()->user()->unit?->singkatan ?? auth()->user()->getRoleNames()->first() }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="margin-left:auto">
                @csrf
                <button type="submit" class="btn btn-icon btn-secondary" title="Keluar" style="flex-shrink:0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </button>
            </form>
        </div>

    </aside>

    {{-- ==================== MAIN CONTENT ==================== --}}
    <div class="main-content">

        {{-- Topbar --}}
        <header class="topbar">
            <div class="topbar-left">
                <button class="btn btn-icon btn-secondary" id="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')" style="display:none">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <nav class="breadcrumb">
                    <a href="{{ route('dashboard') }}" style="color: var(--text-muted)">Home</a>
                    @yield('breadcrumb')
                </nav>
            </div>
            <div class="topbar-right">
                {{-- Notifikasi --}}
                <button class="notif-btn" id="notif-btn" aria-label="Notifikasi">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    @php $notifCount = auth()->user()->unreadNotifications()->count(); @endphp
                    @if($notifCount > 0)
                        <span class="notif-count">{{ $notifCount > 9 ? '9+' : $notifCount }}</span>
                    @endif
                </button>

                {{-- User Dropdown --}}
                <div class="dropdown">
                    <button class="btn btn-secondary btn-sm" id="user-menu-btn" style="gap: 8px;">
                        <img src="{{ auth()->user()->avatar_url }}" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover">
                        {{ Str::limit(auth()->user()->name, 20) }}
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="dropdown-menu" id="user-menu" style="display:none">
                        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-subtle)">
                            <div style="font-weight:600; font-size:0.85rem">{{ auth()->user()->name }}</div>
                            <div style="font-size:0.75rem; color:var(--text-muted)">{{ auth()->user()->email }}</div>
                        </div>
                        <a href="#" class="dropdown-item">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Profil Saya
                        </a>
                        <hr class="dropdown-divider">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item danger">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                Keluar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- Flash Messages --}}
        @if(session('success'))
        <div style="padding: 0 var(--space-8); padding-top: var(--space-4)">
            <div class="alert alert-success fade-in">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                {{ session('success') }}
            </div>
        </div>
        @endif

        @if(session('error'))
        <div style="padding: 0 var(--space-8); padding-top: var(--space-4)">
            <div class="alert alert-error fade-in">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                {{ session('error') }}
            </div>
        </div>
        @endif

        {{-- Page Content --}}
        <main class="page-content">
            @yield('content')
        </main>

    </div>
</div>

@livewireScripts
@stack('scripts')
<script>
    // User menu dropdown
    const menuBtn = document.getElementById('user-menu-btn');
    const menu = document.getElementById('user-menu');
    if (menuBtn && menu) {
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        });
        document.addEventListener('click', () => { menu.style.display = 'none'; });
    }

    // Show sidebar toggle on mobile
    if (window.innerWidth <= 1024) {
        const toggle = document.getElementById('sidebar-toggle');
        if (toggle) toggle.style.display = 'flex';
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }
</script>
</body>
</html>
