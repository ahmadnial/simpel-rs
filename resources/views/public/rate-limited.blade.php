<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Permintaan Dibatasi — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:radial-gradient(circle at 20% 10%,#dbeafe,transparent 35%),#f4f7fb;font-family:Inter,ui-sans-serif,system-ui;color:#172033}.card{width:min(100%,520px);padding:36px;text-align:center;background:#fff;border:1px solid #dbe4f0;border-radius:24px;box-shadow:0 24px 70px rgba(30,64,175,.10)}.icon{width:64px;height:64px;margin:0 auto 16px;display:grid;place-items:center;border-radius:18px;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;font-size:1.6rem}h1{margin:0 0 10px;font-size:1.45rem;letter-spacing:-.025em}p{margin:0;color:#64748b;line-height:1.6;font-size:.9rem}a{display:inline-block;margin-top:22px;padding:11px 17px;color:#fff;background:#1d4ed8;border-radius:10px;text-decoration:none;font-weight:750}
    </style>
</head>
<body>
<main class="card">
    <div class="icon">⏱</div>
    <h1>Permintaan Terlalu Sering</h1>
    <p>Akses sementara dibatasi untuk melindungi layanan validasi. Tunggu sejenak sebelum mencoba kembali.</p>
    <a href="{{ route('public.document.form') }}">Kembali ke Validasi Dokumen</a>
</main>
</body>
</html>
