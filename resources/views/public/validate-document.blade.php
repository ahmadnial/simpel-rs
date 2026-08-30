<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Validasi Dokumen — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg-base); padding:1.5rem; }
        .validation-card { width:100%; max-width:580px; padding:2.5rem; background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:var(--radius-2xl); box-shadow:var(--shadow-xl); }
        .validation-header { text-align:center; margin-bottom:1.75rem; }
        .validation-icon { width:72px; height:72px; margin:0 auto 1rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background:#eff6ff; border:2px solid #60a5fa; font-size:2rem; }
        .validation-title { font-family:var(--font-display); font-size:1.45rem; font-weight:700; color:var(--text-primary); }
        .validation-sub { margin-top:.5rem; color:var(--text-muted); font-size:.875rem; line-height:1.55; }
        .result-error { margin-bottom:1rem; padding:1rem; color:#991b1b; background:#fef2f2; border:1px solid #fca5a5; border-radius:var(--radius-md); }
        .upload-box { padding:1.25rem; border:1px solid var(--border-subtle); border-radius:var(--radius-md); background:var(--bg-elevated); }
        .upload-box label { display:block; margin-bottom:.65rem; font-weight:700; }
        .upload-box input { display:block; width:100%; }
        .upload-box button { width:100%; margin-top:1rem; padding:.75rem 1rem; }
        .privacy-note { margin-top:1rem; color:var(--text-muted); font-size:.78rem; line-height:1.5; text-align:center; }
    </style>
</head>
<body>
<main class="validation-card">
    <div class="validation-header">
        <div class="validation-icon">🔍</div>
        <div class="validation-title">Validasi Dokumen SIMPEL-RS</div>
        <div class="validation-sub">Unggah satu file PDF untuk memastikan apakah byte dokumen identik dengan record pengesahan resmi SIMPEL-RS.</div>
    </div>

    @if(($lookupResult['status'] ?? null) === 'not_found')
        <div class="result-error">
            <strong>Dokumen Tidak Cocok atau Tidak Terdaftar</strong>
            <div style="margin-top:.35rem">{{ $lookupResult['message'] }}</div>
        </div>
    @endif

    <form class="upload-box" method="POST" enctype="multipart/form-data" action="{{ route('public.document.verify') }}">
        @csrf
        <label for="pdf">Pilih PDF yang akan diperiksa</label>
        <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
        @error('pdf')<div style="color:#dc2626; margin-top:.5rem">{{ $message }}</div>@enderror
        <button type="submit">Periksa Keaslian Dokumen</button>
    </form>

    <div class="privacy-note">File diproses sementara untuk menghitung SHA-256, tidak disimpan sebagai upload, dan tidak dianggap resmi jika hash tidak cocok.</div>
</main>
</body>
</html>
