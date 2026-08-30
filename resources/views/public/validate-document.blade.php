<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Validasi Dokumen — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; color:#172033; background:radial-gradient(circle at 8% 8%,rgba(191,219,254,.82),transparent 32%),radial-gradient(circle at 92% 88%,rgba(167,243,208,.58),transparent 32%),#f4f7fb; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; }
        .page { width:min(100%,1080px); margin:0 auto; padding:28px 20px 40px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:20px; }
        .brand { display:flex; align-items:center; gap:12px; color:#172033; font-weight:850; letter-spacing:-.025em; }
        .brand-mark { width:44px; height:44px; display:grid; place-items:center; color:#fff; background:linear-gradient(135deg,#1d4ed8,#0f766e); border-radius:14px; box-shadow:0 10px 26px rgba(29,78,216,.22); font-size:.86rem; }
        .top-note { color:#64748b; font-size:.78rem; line-height:1.45; text-align:right; }
        .panel { display:grid; grid-template-columns:.92fr 1.08fr; min-height:610px; overflow:hidden; background:rgba(255,255,255,.96); border:1px solid rgba(203,213,225,.88); border-radius:28px; box-shadow:0 28px 90px rgba(30,64,175,.12); }
        .intro { position:relative; overflow:hidden; padding:46px 42px; color:#fff; background:linear-gradient(145deg,#172554 0%,#1e40af 58%,#0f766e 125%); }
        .intro:before,.intro:after { content:""; position:absolute; border-radius:999px; border:1px solid rgba(255,255,255,.13); }
        .intro:before { width:330px; height:330px; right:-180px; top:-120px; }
        .intro:after { width:240px; height:240px; left:-150px; bottom:-120px; }
        .intro-content { position:relative; z-index:1; }
        .secure-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 11px; color:#dbeafe; background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.18); border-radius:999px; font-size:.72rem; font-weight:750; letter-spacing:.025em; }
        .secure-dot { width:8px; height:8px; background:#6ee7b7; border-radius:50%; box-shadow:0 0 0 4px rgba(110,231,183,.13); }
        .intro h1 { margin:24px 0 14px; font-size:clamp(2rem,5vw,3.15rem); line-height:1.02; letter-spacing:-.055em; }
        .intro-lead { margin:0; max-width:420px; color:#dbeafe; font-size:.93rem; line-height:1.7; }
        .steps { display:grid; gap:16px; margin-top:38px; }
        .step { display:grid; grid-template-columns:36px 1fr; gap:12px; align-items:start; }
        .step-number { width:34px; height:34px; display:grid; place-items:center; color:#bfdbfe; background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.18); border-radius:11px; font-size:.78rem; font-weight:850; }
        .step strong { display:block; margin:1px 0 3px; font-size:.86rem; }
        .step span { display:block; color:#bfdbfe; font-size:.76rem; line-height:1.45; }
        .form-side { padding:42px; display:flex; flex-direction:column; justify-content:center; }
        .form-eyebrow { margin-bottom:7px; color:#1d4ed8; font-size:.7rem; font-weight:850; letter-spacing:.12em; text-transform:uppercase; }
        .form-side h2 { margin:0; color:#172033; font-size:1.65rem; letter-spacing:-.035em; }
        .form-description { margin:9px 0 22px; color:#64748b; font-size:.86rem; line-height:1.6; }
        .result-error { display:flex; gap:12px; margin-bottom:18px; padding:15px; color:#991b1b; background:#fff1f2; border:1px solid #fda4af; border-radius:15px; }
        .result-icon { flex:0 0 32px; width:32px; height:32px; display:grid; place-items:center; background:#ffe4e6; border-radius:10px; font-weight:900; }
        .result-error strong { display:block; margin:1px 0 4px; font-size:.86rem; }
        .result-error p { margin:0; font-size:.78rem; line-height:1.5; }
        .upload-card { margin:0; padding:0; border:0; }
        .drop-zone { display:block; padding:24px; text-align:center; background:linear-gradient(180deg,#f8fbff,#f1f5f9); border:1.5px dashed #93c5fd; border-radius:18px; transition:border-color .2s,background .2s,transform .2s; }
        .drop-zone:focus-within { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 4px rgba(37,99,235,.10); }
        .file-icon { width:56px; height:56px; margin:0 auto 12px; display:grid; place-items:center; color:#1d4ed8; background:#dbeafe; border-radius:17px; }
        .drop-zone label { display:block; margin-bottom:5px; color:#1e293b; font-size:.94rem; font-weight:850; }
        .drop-help { margin:0 0 15px; color:#64748b; font-size:.76rem; line-height:1.45; }
        input[type=file] { width:100%; padding:7px; color:#475569; background:#fff; border:1px solid #cbd5e1; border-radius:11px; font-size:.78rem; }
        input[type=file]::file-selector-button { margin-right:10px; padding:9px 13px; color:#fff; background:#1d4ed8; border:0; border-radius:8px; font-weight:750; cursor:pointer; }
        .error-text { margin:9px 0 0; color:#b91c1c; font-size:.78rem; font-weight:650; }
        .submit { width:100%; margin-top:14px; padding:14px 18px; display:flex; align-items:center; justify-content:center; gap:10px; color:#fff; background:linear-gradient(135deg,#1d4ed8,#2563eb); border:0; border-radius:13px; box-shadow:0 10px 22px rgba(37,99,235,.22); font-size:.9rem; font-weight:850; cursor:pointer; transition:transform .18s,box-shadow .18s; }
        .submit:hover { transform:translateY(-1px); box-shadow:0 14px 28px rgba(37,99,235,.28); }
        .footnote { margin:13px 0 0; color:#94a3b8; text-align:center; font-size:.68rem; line-height:1.45; }
        @media(max-width:820px) { .panel{grid-template-columns:1fr}.intro{padding:36px 28px}.intro h1{font-size:2.25rem}.steps{grid-template-columns:repeat(3,1fr);gap:10px}.step{grid-template-columns:1fr}.form-side{padding:34px 28px} }
        @media(max-width:560px) { .page{padding:16px 10px 28px}.top-note{display:none}.panel{border-radius:22px}.intro,.form-side{padding:28px 20px}.intro h1{font-size:2rem}.steps{grid-template-columns:1fr}.step{grid-template-columns:34px 1fr}.drop-zone{padding:19px 14px} }
    </style>
</head>
<body>
@php $maxUploadMb = (int) ceil(config('tte.verifier.max_upload_kilobytes') / 1024); @endphp
<main class="page">
    <header class="topbar">
        <div class="brand"><span class="brand-mark">SR</span><span>SIMPEL-RS</span></div>
        <div class="top-note">Layanan validasi dokumen<br>Rumah Sakit Nur Rohmah</div>
    </header>

    <section class="panel">
        <div class="intro">
            <div class="intro-content">
                <div class="secure-chip"><span class="secure-dot"></span> Layanan validasi resmi SIMPEL-RS</div>
                <h1>Pastikan dokumen Anda asli.</h1>
                <p class="intro-lead">Unggah PDF yang Anda terima untuk memeriksa keaslian dokumen dan status pengesahannya.</p>

                <div class="steps" aria-label="Tahapan validasi">
                    <div class="step"><div class="step-number">01</div><div><strong>Pilih PDF</strong><span>Gunakan file digital yang Anda terima.</span></div></div>
                    <div class="step"><div class="step-number">02</div><div><strong>Periksa Dokumen</strong><span>Sistem mencocokkan file dengan data resmi.</span></div></div>
                    <div class="step"><div class="step-number">03</div><div><strong>Lihat Hasil</strong><span>Keaslian dokumen dan status pengesahan ditampilkan dengan jelas.</span></div></div>
                </div>
            </div>
        </div>

        <div class="form-side">
            <div class="form-eyebrow">Validasi publik</div>
            <h2>Periksa keaslian PDF</h2>
            <p class="form-description">Tidak memerlukan akun atau dokumen pembanding. Cukup pilih satu file PDF untuk diperiksa.</p>

            @if(($lookupResult['status'] ?? null) === 'not_found')
                <div class="result-error" role="alert">
                    <div class="result-icon">!</div>
                    <div>
                        <strong>Dokumen Tidak Cocok atau Tidak Terdaftar</strong>
                        <p>{{ $lookupResult['message'] }}</p>
                    </div>
                </div>
            @endif

            <form method="POST" enctype="multipart/form-data" action="{{ route('public.document.verify') }}">
                @csrf
                <fieldset class="upload-card">
                    <legend class="sr-only">Unggah PDF untuk validasi</legend>
                    <div class="drop-zone">
                        <div class="file-icon" aria-hidden="true">
                            <svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6m-3 3 3-3 3 3"/></svg>
                        </div>
                        <label for="pdf">Pilih file PDF dari perangkat Anda</label>
                        <p class="drop-help">PDF maksimal {{ $maxUploadMb }} MB · file digital asli memberikan hasil paling akurat</p>
                        <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
                    </div>
                    @error('pdf')<div class="error-text">{{ $message }}</div>@enderror
                    <button class="submit" type="submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                        Validasi Dokumen Sekarang
                    </button>
                </fieldset>
            </form>

            <p class="footnote">Gunakan file PDF asli. Hasil scan, kompresi, atau cetak ulang dapat memberikan hasil berbeda.</p>
        </div>
    </section>
</main>
</body>
</html>
