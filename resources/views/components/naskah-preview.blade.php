@props(['document'])

@php
    $parser = new \App\Services\DocxParserService();
    $currentVersion = $document->currentVersion;
    $bodyHtml = $currentVersion ? $parser->parseToHtml($currentVersion->file_path) : '<p style="color:#666">Belum ada file dokumen yang diunggah.</p>';

    // Cari calon penandatangan dari workflow step tipe 'penandatangan' atau role penandatangan
    $calonPenandatangan = \App\Models\User::role('penandatangan')->first()
        ?? \App\Models\User::whereHas('roles', fn($q) => $q->where('name', 'like', '%direktur%'))->first()
        ?? $document->pengusul;

    $signature = $document->signature;
@endphp

<div class="naskah-paper-container">

    {{-- KOP SURAT RUMAH SAKIT --}}
    <div class="kop-surat">
        <div style="display:flex; align-items:center; justify-content:center; gap:20px; border-bottom:3px double #1e293b; padding-bottom:15px; margin-bottom:20px">
            <div style="width:60px; height:60px; background:linear-gradient(135deg, #4f46e5, #3730a3); border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-weight:800; font-size:1.4rem">
                RS
            </div>
            <div style="text-align:center">
                <div style="font-size:0.85rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#475569">PEMERINTAH KOTA / RUMAH SAKIT RS</div>
                <div style="font-size:1.35rem; font-weight:800; color:#0f172a; font-family:serif; margin:2px 0">RUMAH SAKIT UMUM SIMPEL-RS</div>
                <div style="font-size:0.8rem; color:#64748b">Unit Kerja: <strong>{{ strtoupper($document->unit->nama ?? 'BAGIAN UMUM') }}</strong></div>
                <div style="font-size:0.75rem; color:#94a3b8">Jl. Kesehatan No. 100 &bull; Telp (021) 555-0199 &bull; Website: www.simpel-rs.test</div>
            </div>
        </div>
    </div>

    {{-- METADATA NASKAH DINAS --}}
    <div class="naskah-meta-box">
        <table style="width:100%; border-collapse:collapse; margin-bottom:20px; font-size:0.9rem; color:#1e293b">
            <tr>
                <td style="width:140px; font-weight:600; padding:4px 0">Nomor Surat</td>
                <td style="width:15px">:</td>
                <td style="font-family:monospace; font-weight:700; color:#3b82f6">
                    @if($document->nomor_surat)
                        {{ $document->nomor_surat }}
                    @else
                        <span style="color:#f59e0b; background:#fef3c7; padding:2px 8px; border-radius:4px; font-size:0.85rem">[DRAFT NOMOR: {{ $document->documentType->generateNomor($document->unit, 1, now()) }}]</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="font-weight:600; padding:4px 0">Tanggal Surat</td>
                <td>:</td>
                <td>
                    @if($document->tanggal_surat)
                        {{ $document->tanggal_surat->translatedFormat('d F Y') }}
                    @else
                        <span style="color:#64748b">[DRAFT TANGGAL: {{ now()->translatedFormat('d F Y') }}]</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="font-weight:600; padding:4px 0">Jenis Naskah</td>
                <td>:</td>
                <td><strong>{{ $document->documentType->nama }}</strong> ({{ $document->documentType->singkatan }})</td>
            </tr>
            <tr>
                <td style="font-weight:600; padding:4px 0">Perihal / Hal</td>
                <td>:</td>
                <td><strong>{{ $document->perihal ?? $document->judul }}</strong></td>
            </tr>
        </table>
    </div>

    <hr style="border:none; border-top:1px dashed #cbd5e1; margin:15px 0 25px">

    {{-- ISI NASKAH DINAS (.DOCX CONTENT) --}}
    <div class="naskah-body-content">
        <div id="php-parsed-content" class="parsed-docx-body">
            {!! $bodyHtml !!}
        </div>
        <div id="docx-js-container" class="docx-js-body" style="display:none"></div>
    </div>

    <hr style="border:none; border-top:1px dashed #cbd5e1; margin:30px 0 20px">

    {{-- BLOK TANDA TANGAN & PENGESAHAN --}}
    <div class="naskah-signature-block">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px; font-size:0.875rem; color:#0f172a; margin-top:20px">

            {{-- Pengusul --}}
            <div style="text-align:center">
                <div style="color:#64748b; font-size:0.8rem">Pengusul Naskah:</div>
                <div style="font-weight:600; margin-top:4px">{{ $document->pengusul->unit->nama ?? 'Unit Kerja' }}</div>
                <div style="height:60px; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-style:italic">
                    (Draft Terverifikasi)
                </div>
                <div style="font-weight:700; text-decoration:underline">{{ $document->pengusul->name }}</div>
                <div style="font-size:0.78rem; color:#64748b">{{ $document->pengusul->jabatan ?? 'Staf Pengusul' }}</div>
            </div>

            {{-- Pejabat Penandatangan --}}
            <div style="text-align:center">
                <div style="color:#64748b; font-size:0.8rem">Calon Pejabat Penandatangan:</div>
                <div style="font-weight:600; margin-top:4px">{{ $calonPenandatangan->jabatan ?? 'Direktur Rumah Sakit' }}</div>

                <div style="height:70px; display:flex; align-items:center; justify-content:center; margin:8px 0">
                    @if($signature)
                        <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; border:1px solid #86efac; padding:6px 12px; border-radius:8px; text-align:left">
                            <div style="font-size:1.5rem">🔏</div>
                            <div>
                                <div style="font-weight:700; color:#166534; font-size:0.78rem">TTE SAH SHA-256</div>
                                <div style="font-size:0.7rem; color:#15803d; font-family:monospace">{{ Str::limit($signature->hash_dokumen, 16) }}</div>
                            </div>
                        </div>
                    @else
                        <div style="border:2px dashed #cbd5e1; padding:8px 16px; border-radius:8px; color:#94a3b8; font-size:0.78rem; background:#f8fafc">
                            [ MENUNGGU TTE DENGAN OTP ]
                        </div>
                    @endif
                </div>

                <div style="font-weight:700; text-decoration:underline">{{ $signature ? $signature->penandatangan->name : $calonPenandatangan->name }}</div>
                <div style="font-size:0.78rem; color:#64748b">{{ $calonPenandatangan->jabatan ?? 'Direktur' }}</div>
            </div>

        </div>
    </div>

</div>

<style>
.naskah-paper-container {
    background: #ffffff;
    color: #0f172a;
    padding: 45px 50px;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    font-family: 'Times New Roman', Times, serif, 'Inter', sans-serif;
    line-height: 1.6;
    max-width: 820px;
    margin: 0 auto;
}

.naskah-body-content p {
    margin-bottom: 1rem;
    font-size: 1.02rem;
    text-align: justify;
}
</style>
