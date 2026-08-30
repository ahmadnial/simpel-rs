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
        body { margin:0; min-height:100vh; padding:32px 18px; color:#172033; background:radial-gradient(circle at 15% 0%,#dbeafe 0,transparent 34%),radial-gradient(circle at 90% 16%,#dcfce7 0,transparent 30%),#f4f7fb; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; }
        .shell { width:min(100%,860px); margin:0 auto; }
        .brand { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .brand-mark { display:flex; align-items:center; gap:12px; font-weight:800; letter-spacing:-.02em; }
        .brand-icon { width:42px; height:42px; display:grid; place-items:center; border-radius:13px; color:#fff; background:linear-gradient(135deg,#2563eb,#0f766e); box-shadow:0 10px 24px rgba(37,99,235,.22); }
        .brand-note { color:#64748b; font-size:.78rem; text-align:right; }
        .card { overflow:hidden; background:rgba(255,255,255,.96); border:1px solid #dbe4f0; border-radius:24px; box-shadow:0 24px 70px rgba(30,64,175,.10); }
        .hero { padding:32px; color:#fff; background:linear-gradient(130deg,#172554 0%,#1d4ed8 58%,#0f766e 120%); }
        .hero.is-error { background:linear-gradient(130deg,#450a0a,#b91c1c); }
        .hero-row { display:flex; align-items:flex-start; gap:18px; }
        .status-icon { flex:0 0 58px; width:58px; height:58px; display:grid; place-items:center; border-radius:18px; background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.28); font-size:1.8rem; }
        .eyebrow { margin-bottom:6px; font-size:.72rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:#bfdbfe; }
        .hero h1 { margin:0; font-size:clamp(1.35rem,4vw,2rem); line-height:1.15; letter-spacing:-.035em; }
        .hero p { margin:10px 0 0; max-width:650px; color:#dbeafe; line-height:1.55; font-size:.9rem; }
        .content { padding:28px 32px 32px; }
        .status-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:24px; }
        .status-box { padding:18px; border-radius:16px; border:1px solid; }
        .status-box strong { display:block; margin-bottom:6px; font-size:.86rem; letter-spacing:.02em; }
        .status-box p { margin:0; font-size:.82rem; line-height:1.5; }
        .status-box.good { color:#14532d; background:#f0fdf4; border-color:#86efac; }
        .status-box.warn { color:#854d0e; background:#fefce8; border-color:#fde047; }
        .status-box.bad { color:#991b1b; background:#fef2f2; border-color:#fca5a5; }
        .section-title { margin:26px 0 10px; font-size:.76rem; font-weight:800; letter-spacing:.1em; color:#64748b; text-transform:uppercase; }
        .metadata { display:grid; grid-template-columns:1fr 1fr; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; }
        .meta { min-width:0; padding:15px 17px; border-bottom:1px solid #e2e8f0; }
        .meta:nth-child(odd) { border-right:1px solid #e2e8f0; }
        .meta:nth-last-child(-n+2) { border-bottom:0; }
        .meta-label { margin-bottom:5px; color:#64748b; font-size:.69rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
        .meta-value { color:#1e293b; font-size:.88rem; font-weight:650; line-height:1.45; overflow-wrap:anywhere; }
        .hash { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.72rem; font-weight:500; }
        details { margin-top:18px; border:1px solid #e2e8f0; border-radius:14px; background:#f8fafc; }
        summary { cursor:pointer; padding:14px 16px; font-weight:750; font-size:.84rem; }
        .checks { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:0 16px 16px; color:#475569; font-size:.76rem; }
        .check { display:flex; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px dashed #dbe4f0; }
        .upload { margin-top:22px; padding:18px; border:1px solid #cbd5e1; border-radius:16px; background:#f8fafc; }
        .upload label { display:block; margin-bottom:6px; font-size:.9rem; font-weight:800; }
        .upload small { display:block; margin-bottom:12px; color:#64748b; line-height:1.45; }
        .upload-row { display:flex; align-items:center; gap:12px; }
        .upload input { min-width:0; flex:1; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:10px; }
        .upload button { padding:11px 18px; white-space:nowrap; color:#fff; background:#1d4ed8; border:0; border-radius:10px; font-weight:750; cursor:pointer; }
        .error-text { margin-top:8px; color:#b91c1c; font-size:.8rem; }
        .legal { margin:18px 0 0; text-align:center; color:#64748b; font-size:.74rem; line-height:1.55; }
        .not-found { padding:38px 32px; text-align:center; }
        .not-found h1 { margin:12px 0 8px; color:#991b1b; font-size:1.45rem; }
        .not-found p { margin:0 auto; max-width:520px; color:#64748b; line-height:1.6; }
        .back-link { display:inline-block; margin-top:20px; color:#1d4ed8; font-weight:750; text-decoration:none; }
        @media(max-width:680px) { body{padding:16px 10px}.brand-note{display:none}.hero,.content{padding:24px 19px}.hero-row{flex-direction:column}.status-grid,.metadata,.checks{grid-template-columns:1fr}.meta:nth-child(odd){border-right:0}.meta:nth-last-child(2){border-bottom:1px solid #e2e8f0}.upload-row{align-items:stretch;flex-direction:column}.upload button{width:100%} }
    </style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <div class="brand-mark"><span class="brand-icon">SR</span><span>SIMPEL-RS</span></div>
        <div class="brand-note">Layanan validasi dokumen<br>Rumah Sakit Nur Rohmah</div>
    </header>

    <article class="card">
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
                    : ($legacyIntegrityValid ? 'Pengesahan Tercatat — Validasi Terbatas' : 'Bukti Pengesahan Tidak Valid');
                $recordDescription = $cryptographicallyVerified
                    ? 'Evidence kriptografis, persetujuan OTP, segel institusi, jejak audit, dan penyimpanan immutable berhasil diverifikasi.'
                    : ($legacyIntegrityValid
                        ? 'PDF server cocok dengan hash lama, tetapi record ini belum memiliki evidence kriptografis v2 yang lengkap.'
                        : 'Satu atau lebih pemeriksaan evidence gagal. Dokumen tidak boleh dianggap terverifikasi.');
                $fileBoxClass = match($fileStatus) { 'match' => $cryptographicallyVerified ? 'good' : 'warn', 'mismatch' => 'bad', default => 'warn' };
                $fileTitle = match($fileStatus) {
                    'match' => $cryptographicallyVerified
                        ? 'File PDF Asli — Hash Cocok'
                        : ($legacyIntegrityValid ? 'Hash File Cocok — Validasi Terbatas' : 'Hash File Cocok — Bukti Pengesahan Tidak Valid'),
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
                    <div class="meta"><div class="meta-label">Pejabat yang Mengesahkan</div><div class="meta-value">{{ $signature->penandatangan->name }} · {{ $signature->penandatangan->jabatan ?? 'Pejabat Penandatangan' }}</div></div>
                    <div class="meta"><div class="meta-label">SHA-256 Dokumen Resmi</div><div class="meta-value hash">{{ $signature->hash_dokumen }}</div></div>
                </div>

                @if($hasCryptographicEvidence)
                    <details>
                        <summary>Rincian pemeriksaan teknis</summary>
                        <div class="checks">
                            <div class="check"><span>Hash PDF evidence</span><strong>{{ ($verification['checks']['pdf_hash'] ?? false) ? 'Valid' : 'Gagal' }}</strong></div>
                            <div class="check"><span>Persetujuan OTP</span><strong>{{ ($verification['checks']['otp_receipt_binding'] ?? false) ? 'Valid' : 'Gagal' }}</strong></div>
                            <div class="check"><span>Segel institusi</span><strong>{{ ($verification['checks']['institution_signature'] ?? false) ? 'Valid' : 'Gagal' }}</strong></div>
                            <div class="check"><span>Kunci institusi</span><strong>{{ in_array($verification['key_status'] ?? '', ['active','retired'], true) ? 'Dikenali' : 'Bermasalah' }}</strong></div>
                            <div class="check"><span>Jejak audit</span><strong>{{ ($verification['checks']['audit_chain'] ?? false) && ($verification['checks']['audit_checkpoint'] ?? false) ? 'Valid' : 'Gagal' }}</strong></div>
                            <div class="check"><span>Penyimpanan immutable</span><strong>{{ ($verification['checks']['immutable_storage'] ?? false) ? 'Valid' : 'Gagal' }}</strong></div>
                        </div>
                    </details>
                @endif

                <form class="upload" method="POST" enctype="multipart/form-data" action="{{ route('public.verify.upload', $signature->qr_token) }}">
                    @csrf
                    <label for="pdf">Periksa keaslian file PDF yang Anda terima</label>
                    <small>File tidak disimpan atau ditampilkan. Sistem hanya menghitung SHA-256 dan membandingkannya byte-per-byte dengan dokumen resmi.</small>
                    <div class="upload-row">
                        <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
                        <button type="submit">Periksa File</button>
                    </div>
                    @error('pdf')<div class="error-text">{{ $message }}</div>@enderror
                </form>

                <p class="legal">SIMPEL-RS hanya menyatakan file asli apabila SHA-256 cocok sepenuhnya dan seluruh bukti pengesahan berhasil diverifikasi.</p>
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
