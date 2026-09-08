@php
    $closedPeers = $tickets->filter(fn ($ticket) => $ticket->closed_by_verification_id === (int) $decision->id);
@endphp
@if($closedPeers->isNotEmpty())
    <div class="timeline-note">
        {{ $closedPeers->first()->direset_alasan }}
        <div>Tugas pemeriksaan yang selesai melalui keputusan ini:
            {{ $closedPeers->map(fn ($ticket) => $ticket->verifikator?->name ?? 'Pengguna tidak tersedia')->implode(', ') }}.
            Para penerima tersebut tidak memberikan keputusan pada tiket ini.
        </div>
    </div>
@endif
