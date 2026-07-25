<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — SIMPEL-RS</title>
    <meta name="description" content="Login ke Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit">
    @vite(['resources/css/app.css'])
    <style>
        body { display: flex; min-height: 100vh; overflow: hidden; }

        .login-left {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            overflow: hidden;
        }

        .login-bg {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, #0a0b14 0%, #0f1020 40%, #141528 100%);
        }

        /* Animated gradient orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.25;
            animation: float 8s ease-in-out infinite;
        }

        .orb-1 {
            width: 500px; height: 500px;
            background: var(--brand-700);
            top: -100px; left: -100px;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 400px; height: 400px;
            background: #7c3aed;
            bottom: -80px; right: -80px;
            animation-delay: -3s;
        }

        .orb-3 {
            width: 300px; height: 300px;
            background: #1e40af;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -5s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.05); }
        }

        .login-left-content {
            position: relative;
            z-index: 1;
            text-align: center;
            color: white;
        }

        .login-logo {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, var(--brand-500), var(--brand-700));
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 2rem;
            box-shadow: 0 0 60px rgba(99, 102, 241, 0.5);
        }

        .login-brand-name {
            font-family: var(--font-display);
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .login-brand-desc {
            font-size: 1rem;
            color: rgba(255,255,255,0.6);
            max-width: 340px;
            margin: 0 auto 3rem;
            line-height: 1.7;
        }

        .login-features {
            display: flex;
            flex-direction: column;
            gap: 0.875rem;
            max-width: 320px;
            margin: 0 auto;
        }

        .login-feature {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            text-align: left;
            color: rgba(255,255,255,0.75);
            font-size: 0.9rem;
        }

        .login-feature-icon {
            width: 36px; height: 36px;
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: var(--brand-300);
        }

        /* Right panel */
        .login-right {
            width: 480px;
            background: var(--bg-surface);
            border-left: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
        }

        .login-form-container {
            width: 100%;
            max-width: 380px;
        }

        .login-form-title {
            font-family: var(--font-display);
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .login-form-sub {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            display: flex; align-items: center;
        }

        .password-toggle:hover { color: var(--text-secondary); }

        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            cursor: pointer;
        }

        input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--brand-500);
            cursor: pointer;
        }

        .login-btn {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, var(--brand-600), var(--brand-700));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            box-shadow: 0 0 30px rgba(99, 102, 241, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .login-btn:hover {
            background: linear-gradient(135deg, var(--brand-500), var(--brand-600));
            box-shadow: 0 0 50px rgba(99, 102, 241, 0.5);
            transform: translateY(-1px);
        }

        .login-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-subtle);
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .login-left { display: none; }
            .login-right { width: 100%; border-left: none; }
        }
    </style>
</head>
<body>

    {{-- Left Panel --}}
    <div class="login-left">
        <div class="login-bg">
            <div class="orb orb-1"></div>
            <div class="orb orb-2"></div>
            <div class="orb orb-3"></div>
        </div>
        <div class="login-left-content">
            <div class="login-logo">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
            </div>
            <div class="login-brand-name">SIMPEL-RS</div>
            <div class="login-brand-desc">
                Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit yang aman, tertelusur, dan sesuai standar akreditasi KARS.
            </div>

            <div class="login-features">
                <div class="login-feature">
                    <div class="login-feature-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    </div>
                    Verifikasi dokumen berjenjang & tertelusur
                </div>
                <div class="login-feature">
                    <div class="login-feature-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>
                    </div>
                    Tanda tangan elektronik (TTE) aman
                </div>
                <div class="login-feature">
                    <div class="login-feature-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                    </div>
                    Arsip digital searchable selamanya
                </div>
                <div class="login-feature">
                    <div class="login-feature-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    Audit trail lengkap untuk akreditasi
                </div>
            </div>
        </div>
    </div>

    {{-- Right Panel — Form --}}
    <div class="login-right">
        <div class="login-form-container">
            <div class="login-form-title">Selamat Datang 👋</div>
            <div class="login-form-sub">Masuk dengan akun SIMPEL-RS Anda</div>

            {{-- Error Messages --}}
            @if($errors->any())
            <div class="alert alert-error" style="margin-bottom: 1.5rem">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div>
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
            @endif

            @if(session('success'))
            <div class="alert alert-success" style="margin-bottom: 1.5rem">
                {{ session('success') }}
            </div>
            @endif

            <form method="POST" action="{{ route('login') }}" id="login-form">
                @csrf

                <div class="form-group">
                    <label for="email" class="form-label">Email / NIP</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}"
                        value="{{ old('email') }}"
                        placeholder="nama@rumahsakit.com"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="••••••••"
                            required
                            style="padding-right: 44px"
                        >
                        <button type="button" class="password-toggle" onclick="togglePassword()" id="pw-toggle">
                            <svg id="pw-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="pw-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="remember-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        Ingat saya
                    </label>
                </div>

                <button type="submit" class="login-btn" id="login-submit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    Masuk ke Sistem
                </button>
            </form>

            <div class="login-footer">
                <div>SIMPEL-RS v1.0 &mdash; Hak akses dikelola oleh administrator.</div>
                <div style="margin-top: 6px">Lupa password? Hubungi Admin IT.</div>
            </div>
        </div>
    </div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const eye = document.getElementById('pw-eye');
        const eyeOff = document.getElementById('pw-eye-off');
        if (input.type === 'password') {
            input.type = 'text';
            eye.style.display = 'none';
            eyeOff.style.display = 'block';
        } else {
            input.type = 'password';
            eye.style.display = 'block';
            eyeOff.style.display = 'none';
        }
    }

    // Disable submit on loading
    document.getElementById('login-form').addEventListener('submit', function() {
        const btn = document.getElementById('login-submit');
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Memproses...';
        btn.disabled = true;
    });
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.is-invalid { border-color: #ef4444 !important; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important; }
</style>

</body>
</html>
