<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->nomor_surat ?? $document->judul }} — SIMPEL-RS</title>
    <style>
        @page {
            margin: 20mm 15mm 20mm 15mm;
        }

        body, table, td, th, p, div, span {
            font-family: 'Times New Roman', Times, serif !important;
            font-size: 11pt;
            line-height: 1.5;
            color: #000000;
        }

        .content-body {
            margin: 0;
            text-align: justify;
        }

        .content-body p {
            margin-bottom: 6px;
        }

        table {
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            margin: 10px 0;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
        }

        table td, table th {
            padding: 6px 8px;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
        }

        .watermark-draft {
            position: fixed;
            top: 40%;
            left: 5%;
            width: 90%;
            text-align: center;
            font-size: 42pt;
            font-weight: 900;
            font-family: Arial, sans-serif !important;
            color: rgba(220, 38, 38, 0.16);
            transform: rotate(-30deg);
            text-transform: uppercase;
            letter-spacing: 4px;
            z-index: 9999;
            pointer-events: none;
        }
    </style>
</head>
<body>

    @if(!$signature)
    <div class="watermark-draft">
        DRAFT &bull; BELUM TTE
    </div>
    @endif

    {{-- ISI NASKAH DINAS MURNI DARI (.DOCX UPLOAD) BESERTA KOP SURAT ASLI & VARIABLE REPLACE --}}
    <div class="content-body">
        {!! $bodyHtml !!}
    </div>

    @if($signature)
    <div class="signature-footer-banner">
        🔏 <strong>DOKUMEN RESMI TERVERIFIKASI TTE (SIMPEL-RS)</strong><br/>
        Penandatangan: <strong>{{ $signature->penandatangan->name }}</strong> ({{ $signature->penandatangan->jabatan ?? 'Pejabat' }}) &bull;
        Waktu TTE: {{ $signature->ditandatangani_at->translatedFormat('d F Y H:i:s') }} WIB<br/>
        Hash (SHA-256): <code style="font-family:monospace">{{ $signature->hash_dokumen }}</code>
    </div>
    @endif

</body>
</html>
