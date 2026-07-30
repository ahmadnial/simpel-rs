<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DokumenNotification extends Notification
{
    use Queueable;

    public Document $document;
    public string $tipeEvent;
    public string $judulNotif;
    public string $pesan;
    public string $url;

    /**
     * Create a new notification instance.
     *
     * @param Document $document
     * @param string $tipeEvent ('diajukan', 'revisi', 'menunggu_ttd', 'ditandatangani', 'dipublikasikan')
     * @param string $judulNotif
     * @param string $pesan
     * @param string|null $url
     */
    public function __construct(
        Document $document,
        string $tipeEvent,
        string $judulNotif,
        string $pesan,
        ?string $url = null
    ) {
        $this->document   = $document;
        $this->tipeEvent  = $tipeEvent;
        $this->judulNotif = $judulNotif;
        $this->pesan      = $pesan;
        $this->url        = $url ?? route('dokumen.show', $document);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'nomor_surat' => $this->document->nomor_surat ?? 'Draft',
            'judul_doc'   => $this->document->judul,
            'tipe_event'  => $this->tipeEvent,
            'title'       => $this->judulNotif,
            'message'     => $this->pesan,
            'url'         => $this->url,
        ];
    }
}
