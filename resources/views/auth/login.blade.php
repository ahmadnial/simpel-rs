<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — SIMPEL-RS</title>
    <meta name="description" content="Login ke Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit">
    @vite(['resources/css/app.css'])
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", sans-serif;
            background: #f0f4f8;
            position: relative;
            overflow: hidden;
            color: #1e293b;
        }

        /* Vibrant Pastel Vector Background */
        .vector-bg-layer {
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(circle at 10% 15%, rgba(56, 189, 248, 0.25) 0%, transparent 40%),
                radial-gradient(circle at 90% 85%, rgba(129, 140, 248, 0.25) 0%, transparent 40%),
                linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 40%, #e0e7ff 100%);
        }

        /* Floating Vector Illustration Accent */
        .vector-art-side {
            position: absolute;
            right: 4%;
            top: 50%;
            transform: translateY(-50%);
            width: 480px;
            height: 480px;
            background-image: url('{{ asset("images/login-bg-vector.png") }}');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            filter: drop-shadow(0 20px 30px rgba(14, 165, 233, 0.15));
            pointer-events: none;
            animation: floatVector 8s ease-in-out infinite alternate;
        }

        @keyframes floatVector {
            0% { transform: translateY(-50%) translateY(0px) scale(1); }
            100% { transform: translateY(-50%) translateY(-15px) scale(1.02); }
        }

        /* Soft Animated Gradient Orbs */
        .orb-bubble {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            pointer-events: none;
            opacity: 0.6;
            animation: bubblePulse 10s ease-in-out infinite alternate;
        }
        .orb-1 { width: 380px; height: 380px; background: #a5b4fc; top: -100px; left: -80px; }
        .orb-2 { width: 420px; height: 420px; background: #7dd3fc; bottom: -120px; left: 35%; animation-delay: -5s; }

        @keyframes bubblePulse {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.12) translate(20px, -20px); }
        }

        /* Main macOS Glass Sheet Modal */
        .macos-modal {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 940px;
            min-height: 540px;
            margin: 20px;
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(30px) saturate(190%);
            -webkit-backdrop-filter: blur(30px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 28px;
            box-shadow: 
                0 25px 60px rgba(15, 23, 42, 0.12),
                0 4px 16px rgba(15, 23, 42, 0.04),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            display: flex;
            overflow: hidden;
            animation: macosApperance 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes macosApperance {
            0% { opacity: 0; transform: scale(0.95) translateY(16px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* macOS Window Controls (Traffic Lights) */
        .window-controls {
            position: absolute;
            top: 20px;
            left: 24px;
            display: flex;
            gap: 8px;
            z-index: 20;
        }
        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            box-shadow: inset 0 1px 1px rgba(0,0,0,0.15);
        }
        .dot-close { background: #ff5f56; border: 0.5px solid #e0443e; }
        .dot-minimize { background: #ffbd2e; border: 0.5px solid #dea123; }
        .dot-expand { background: #27c93f; border: 0.5px solid #1aab29; }

        /* Left Hero Section with Vector Illustration */
        .macos-hero {
            flex: 1.15;
            padding: 4.5rem 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: linear-gradient(135deg, rgba(240, 249, 255, 0.6) 0%, rgba(224, 231, 255, 0.4) 100%);
            border-right: 1px solid rgba(226, 232, 240, 0.8);
            position: relative;
        }

        .hero-top {
            animation: fadeInChild 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
        }

        .login-brand-logo {
            display: block;
            width: min(100%, 340px);
            height: auto;
            margin: 0 0 1.25rem;
        }

        .app-icon-badge {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 
                0 10px 22px rgba(2, 132, 199, 0.3),
                inset 0 1px 1px rgba(255, 255, 255, 0.4);
            margin-bottom: 1.25rem;
        }

        .hero-title {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #0f172a;
            margin: 0 0 0.4rem;
        }

        .hero-subtitle {
            font-size: 0.92rem;
            line-height: 1.6;
            color: #475569;
            margin: 0 0 1.75rem;
            max-width: 330px;
        }

        /* Vector Illustration Card inside Left Hero */
        .hero-vector-box {
            width: 100%;
            height: 210px;
            background-image: url('{{ asset("images/login-bg-vector.png") }}');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            border-radius: 18px;
            transition: transform 0.4s ease;
        }

        .hero-vector-box:hover {
            transform: scale(1.03);
        }

        .hero-footer {
            font-size: 0.78rem;
            color: #94a3b8;
            margin-top: 1.5rem;
            animation: fadeInChild 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.25s both;
        }

        /* Right Form Section */
        .macos-form-section {
            flex: 1;
            padding: 4.5rem 3rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(255, 255, 255, 0.65);
            animation: fadeInChild 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.15s both;
        }

        .form-header-title {
            font-size: 1.7rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0f172a;
            margin: 0 0 0.3rem;
        }

        .form-header-sub {
            font-size: 0.88rem;
            color: #64748b;
            margin: 0 0 2rem;
        }

        /* Material Flat Inputs */
        .input-group {
            margin-bottom: 1.25rem;
        }

        .input-label {
            display: block;
            font-size: 0.84rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.4rem;
        }

        .input-wrapper {
            position: relative;
        }

        .macos-input {
            width: 100%;
            padding: 0.82rem 1rem 0.82rem 2.6rem;
            font-size: 0.92rem;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 14px;
            outline: none;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
            transition: all 0.2s ease;
        }

        .macos-input:focus {
            border-color: #2563eb;
            box-shadow: 
                0 0 0 3.5px rgba(37, 99, 235, 0.18),
                0 1px 3px rgba(15, 23, 42, 0.05);
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .macos-input:focus + .input-icon {
            color: #2563eb;
        }

        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .pw-toggle:hover { color: #475569; }

        /* macOS Material Button */
        .btn-macos-login {
            width: 100%;
            padding: 0.88rem;
            margin-top: 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: inherit;
            color: #ffffff;
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            border: 1px solid #1e40af;
            border-radius: 14px;
            cursor: pointer;
            box-shadow: 
                0 4px 14px rgba(37, 99, 235, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-macos-login:hover {
            background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
            transform: translateY(-1px);
            box-shadow: 
                0 6px 20px rgba(37, 99, 235, 0.45),
                inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }

        .btn-macos-login:active {
            transform: scale(0.98);
        }

        @keyframes fadeInChild {
            0% { opacity: 0; transform: translateY(12px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 860px) {
            .macos-hero { display: none; }
            .macos-modal { max-width: 440px; min-height: auto; }
            .macos-form-section { padding: 3.5rem 2rem 2.5rem; }
        }
    </style>
</head>
<body>

    {{-- Pastel Vector Background Layer --}}
    <div class="vector-bg-layer"></div>
    <div class="orb-bubble orb-1"></div>
    <div class="orb-bubble orb-2"></div>

    {{-- macOS Glass Window Modal --}}
    <div class="macos-modal">
        
        {{-- macOS Traffic Lights --}}
        <div class="window-controls">
            <div class="dot dot-close"></div>
            <div class="dot dot-minimize"></div>
            <div class="dot dot-expand"></div>
        </div>

        {{-- Left Hero Section with Vector Illustration --}}
        <div class="macos-hero">
            <div class="hero-top">
                <img src="{{ asset('images/brand/simpel-rs-wordmark-v1.png') }}" alt="SIMPEL-RS" class="login-brand-logo">
                <p class="hero-subtitle">Sistem Informasi Manajemen Persuratan dan Pengesahan Elektronik Internal Rumah Sakit Nur Rohmah</p>
            </div>

            <div class="hero-footer">
                SIMPEL-RS v1.0 &bull; Unit Teknologi Informasi (IT) Rumah Sakit Nur Rohmah
            </div>
        </div>

        {{-- Right Login Form --}}
        <div class="macos-form-section">
            <div style="width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg, #2563eb, #3b82f6); display:flex; align-items:center; justify-content:center; margin-bottom:12px; box-shadow:0 6px 16px rgba(37,99,235,0.25);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
            <div class="form-header-title">Selamat Datang!</div>
            <div class="form-header-sub">Masuk dengan akun pengguna resmi Anda</div>

            @if(isset($errors) && $errors->any())
            <div style="padding: 12px 16px; background: rgba(254, 242, 242, 0.9); border: 1px solid #fca5a5; color: #991b1b; border-radius: 14px; font-size: 0.85rem; margin-bottom: 1.5rem;">
                @foreach($errors->all() as $error)
                    <div>&bull; {{ $error }}</div>
                @endforeach
            </div>
            @endif

            @if(session('success'))
            <div style="padding: 12px 16px; background: rgba(240, 253, 244, 0.9); border: 1px solid #86efac; color: #166534; border-radius: 14px; font-size: 0.85rem; margin-bottom: 1.5rem;">
                {{ session('success') }}
            </div>
            @endif

            <form method="POST" action="{{ route('login') }}" id="login-form">
                @csrf

                <div class="input-group">
                    <label for="email" class="input-label">Alamat Email</label>
                    <div class="input-wrapper">
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="macos-input"
                            value="{{ old('email') }}"
                            placeholder="nama@rumahsakit.com"
                            required
                            autofocus
                        >
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                </div>

                <div class="input-group">
                    <label for="password" class="input-label">Kata Sandi</label>
                    <div class="input-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="macos-input"
                            placeholder="••••••••"
                            required
                            style="padding-right: 44px;"
                        >
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        
                        <button type="button" class="pw-toggle" onclick="togglePassword()" id="pw-toggle" title="Tampilkan/Sembunyikan Kata Sandi">
                            <svg id="pw-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="pw-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 0.5rem;">
                    <label style="display:flex; align-items:center; gap:8px; color:#475569; font-size:0.85rem; cursor:pointer;">
                        <input type="checkbox" name="remember" style="accent-color:#2563eb; width:15px; height:15px;"> Ingat Saya
                    </label>
                </div>

                <button type="submit" class="btn-macos-login" id="login-submit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    Masuk ke Akun
                </button>
            </form>

            <div style="margin-top:1.25rem; padding-top:1.1rem; border-top:1px solid #e2e8f0; text-align:center; font-size:.85rem; color:#64748b;">
                Menerima dokumen dari SIMPEL-RS?
                <a href="{{ route('public.document.form') }}" style="color:#1d4ed8; font-weight:700; text-decoration:none;">Validasi keaslian PDF</a>
            </div>

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

    document.getElementById('login-form').addEventListener('submit', function() {
        const btn = document.getElementById('login-submit');
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 0.8s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Memproses...';
        btn.disabled = true;
    });
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

</body>
</html>
