<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Validasi Pengesahan Dokumen — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; padding:36px 18px; color:#172033; background:#f4f6f8; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; }
        .shell { width:min(100%,820px); margin:0 auto; }
        .verify-brandbar { display:flex; align-items:center; gap:10px; padding:18px 26px; color:#334155; background:#fff; border-bottom:1px solid #e5e7eb; font-size:.82rem; font-weight:800; letter-spacing:-.01em; }
        .verify-brandbar span:first-child { width:30px; height:30px; display:grid; place-items:center; color:#fff; background:#1e3a5f; border-radius:8px; font-size:.65rem; }
        .verify-brandbar small { margin-left:auto; color:#94a3b8; font-size:.72rem; font-weight:600; }
        .card { overflow:hidden; background:#fff; border:1px solid #dde3ea; border-radius:18px; box-shadow:0 12px 35px rgba(15,23,42,.07); }
        .hero { padding:30px 32px; color:#172033; background:#fff; border-bottom:1px solid #e5e7eb; }
        .hero.is-error { background:#fff; }
        .hero-row { display:flex; align-items:flex-start; gap:18px; }
        .status-icon { flex:0 0 50px; width:50px; height:50px; display:grid; place-items:center; border-radius:50%; color:#166534; background:#ecfdf3; border:1px solid #bbf7d0; font-size:1.45rem; }
        .hero.is-error .status-icon { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
        .eyebrow { margin-bottom:6px; font-size:.68rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:#64748b; }
        .hero h1 { margin:0; font-size:clamp(1.25rem,3.5vw,1.65rem); line-height:1.2; letter-spacing:-.025em; }
        .hero p { margin:8px 0 0; max-width:650px; color:#64748b; line-height:1.55; font-size:.84rem; }
        .content { padding:26px 32px 30px; }
        .status-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:24px; }
        .status-box { padding:16px 17px; border-radius:12px; border:1px solid; }
        .status-box strong { display:block; margin-bottom:6px; font-size:.86rem; letter-spacing:.02em; }
        .status-box p { margin:0; font-size:.82rem; line-height:1.5; }
        .status-box.good { color:#244534; background:#f8faf9; border-color:#d6e5dc; border-left:3px solid #22c55e; }
        .status-box.warn { color:#713f12; background:#fffdf7; border-color:#ede4c8; border-left:3px solid #d97706; }
        .status-box.bad { color:#7f1d1d; background:#fffafa; border-color:#f1d5d5; border-left:3px solid #dc2626; }
        .section-title { margin:26px 0 10px; font-size:.76rem; font-weight:800; letter-spacing:.1em; color:#64748b; text-transform:uppercase; }
        .metadata { display:grid; grid-template-columns:1fr 1fr; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; }
        .meta { min-width:0; padding:15px 17px; border-bottom:1px solid #e2e8f0; }
        .meta:nth-child(odd) { border-right:1px solid #e2e8f0; }
        .meta:nth-last-child(-n+2) { border-bottom:0; }
        .meta-label { margin-bottom:5px; color:#64748b; font-size:.69rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
        .meta-value { color:#1e293b; font-size:.88rem; font-weight:650; line-height:1.45; overflow-wrap:anywhere; }
        .upload { margin-top:22px; padding:18px; border:1px solid #cbd5e1; border-radius:16px; background:#f8fafc; }
        .upload label { display:block; margin-bottom:6px; font-size:.9rem; font-weight:800; }
        .upload small { display:block; margin-bottom:12px; color:#64748b; line-height:1.45; }
        .upload-row { display:flex; align-items:center; gap:12px; }
        .upload input { min-width:0; flex:1; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:10px; }
        .upload button { padding:11px 18px; white-space:nowrap; color:#fff; background:#1e3a5f; border:0; border-radius:9px; font-weight:750; cursor:pointer; }
        .error-text { margin-top:8px; color:#b91c1c; font-size:.8rem; }
        .legal { margin:18px 0 0; text-align:center; color:#64748b; font-size:.74rem; line-height:1.55; }
        .not-found { padding:38px 32px; text-align:center; }
        .not-found h1 { margin:12px 0 8px; color:#991b1b; font-size:1.45rem; }
        .not-found p { margin:0 auto; max-width:520px; color:#64748b; line-height:1.6; }
        .back-link { display:inline-block; margin-top:20px; color:#1d4ed8; font-weight:750; text-decoration:none; }
        @media(max-width:680px) { body{padding:16px 10px}.verify-brandbar small{display:none}.hero,.content{padding:23px 19px}.hero-row{gap:14px}.status-grid,.metadata{grid-template-columns:1fr}.meta:nth-child(odd){border-right:0}.meta:nth-last-child(2){border-bottom:1px solid #e2e8f0}.upload-row{align-items:stretch;flex-direction:column}.upload button{width:100%} }
    </style>
</head>
<body>
<main class="shell">
    <article class="card">
        <div class="verify-brandbar"><span>SR</span><strong>SIMPEL-RS</strong><small>Validasi Dokumen</small></div>
        @if($signature)
            @php
                $fileStatus = $fileVerification['status'] ?? 'not_checked';
                $administrativeStatus = $verification['administrative_status'] ?? 'legacy_unknown';
                $hasCryptographicEvidence = $signature->evidence !== null;
                $cryptographicallyVerified = $hasCryptographicEvidence
                    && ($verification['valid'] ?? false)
                    && $administrativeStatus === 'valid';
                $legacyIntegrityValid = ! $hasCryptographicEvidence && (bool) $integrityValid;
                $recordProblem = ! $cryptographicallyVerified && ! $legacyIntegrityValid;
                $recordTitle = $cryptographicallyVerified
                    ? 'Pengesahan Elektronik Internal Terverifikasi'
                    : ($legacyIntegrityValid ? 'Pengesahan Tercatat — Pemeriksaan Terbatas' : 'Bukti Pengesahan Tidak Valid');
                $recordDescription = $cryptographicallyVerified
                    ? 'Data dokumen dan bukti pengesahan berhasil diperiksa.'
                    : ($legacyIntegrityValid
                        ? 'Dokumen tercatat pada sistem lama dan belum memiliki pemeriksaan selengkap dokumen terbaru.'
                        : 'Pemeriksaan bukti pengesahan tidak berhasil. Dokumen tidak boleh dianggap valid.');
                $fileBoxClass = match($fileStatus) { 'match' => $cryptographicallyVerified ? 'good' : 'warn', 'mismatch' => 'bad', default => 'warn' };
                $fileTitle = match($fileStatus) {
                    'match' => $cryptographicallyVerified
                        ? 'File PDF Sesuai Dokumen Resmi'
                        : ($legacyIntegrityValid ? 'File Cocok — Pemeriksaan Terbatas' : 'File Cocok — Pengesahan Tidak Valid'),
                    'mismatch' => 'File PDF Tidak Cocok',
                    default => 'Keaslian File Belum Diperiksa',
                };
                $adminLabel = match($administrativeStatus) { 'valid' => 'Berlaku', 'revoked' => 'Dicabut', 'superseded' => 'Digantikan', default => 'Tidak tersedia' };
            @endphp

            <section class="hero {{ $recordProblem ? 'is-error' : '' }}">
                <div class="hero-row">
                    <div class="status-icon">{{ $recordProblem ? '!' : '✓' }}</div>
                    <div>
                        <div class="eyebrow">Hasil validasi pengesahan</div>
                        <h1>{{ $recordTitle }}</h1>
                        <p>{{ $recordDescription }}</p>
                    </div>
                </div>
            </section>

            <div class="content">
                <div class="status-grid">
                    <div class="status-box {{ $recordProblem ? 'bad' : ($cryptographicallyVerified ? 'good' : 'warn') }}">
                        <strong>Status Pengesahan</strong>
                        <p>{{ $recordTitle }} · status administratif: {{ $adminLabel }}.</p>
                    </div>
                    <div class="status-box {{ $fileBoxClass }}">
                        <strong>{{ $fileTitle }}</strong>
                        <p>{{ $fileVerification['message'] ?? 'Belum ada PDF pengguna yang dibandingkan dengan dokumen resmi.' }}</p>
                    </div>
                </div>

                <div class="section-title">Identitas dokumen</div>
                <div class="metadata">
                    <div class="meta"><div class="meta-label">Nomor Dokumen</div><div class="meta-value">{{ $signature->document->nomor_surat ?? 'Belum diterbitkan' }}</div></div>
                    <div class="meta"><div class="meta-label">Tanggal Pengesahan</div><div class="meta-value">{{ $signature->ditandatangani_at->timezone('Asia/Jakarta')->translatedFormat('d F Y, H:i') }} WIB</div></div>
                    <div class="meta"><div class="meta-label">Judul Dokumen</div><div class="meta-value">{{ $signature->document->judul }}</div></div>
                    <div class="meta"><div class="meta-label">Jenis dan Unit</div><div class="meta-value">{{ $signature->document->documentType->nama }} · {{ $signature->document->unit->nama }}</div></div>
                    <div class="meta" style="grid-column:1/-1;border-right:0;border-bottom:0"><div class="meta-label">Pejabat yang Mengesahkan</div><div class="meta-value">{{ $signature->penandatangan->name }} · {{ $signature->penandatangan->jabatan ?? 'Pejabat Penandatangan' }}</div></div>
                </div>

                <form class="upload" method="POST" enctype="multipart/form-data" action="{{ route('public.verify.upload', $signature->qr_token) }}">
                    @csrf
                    <label for="pdf">Periksa keaslian file PDF yang Anda terima</label>
                    <small>Pilih file PDF yang Anda terima untuk dibandingkan dengan dokumen resmi.</small>
                    <div class="upload-row">
                        <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
                        <button type="submit">Periksa File</button>
                    </div>
                    @error('pdf')<div class="error-text">{{ $message }}</div>@enderror
                </form>

                <p class="legal">SIMPEL-RS hanya menyatakan dokumen valid apabila file dan bukti pengesahannya sesuai dengan data resmi.</p>
            </div>
        @else
            <section class="not-found">
                <div class="status-icon" style="margin:0 auto;color:#991b1b;background:#fef2f2;border-color:#fca5a5">!</div>
                <h1>Data Pengesahan Tidak Ditemukan</h1>
                <p>Tautan tidak valid atau tidak terdaftar. Demi keamanan, sistem tidak memberikan informasi tambahan mengenai token yang diperiksa.</p>
                <a class="back-link" href="{{ route('public.document.form') }}">Validasi menggunakan file PDF</a>
            </section>
        @endif
    </article>
</main>
</body>
</html>
