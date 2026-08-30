<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpTandaTangan extends Notification
{
    use Queueable;

    public function __construct(
        public string $otp,
        public int $expiryMinutes,
        public string $challengeId = 'legacy',
        public ?string $documentTitle = null,
        public ?string $documentNumber = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Permintaan Tanda Tangan Internal SIMPEL-RS')
            ->greeting("Halo {$notifiable->name},")
            ->line('Kode berikut hanya berlaku untuk permintaan tanda tangan internal yang Anda mulai sendiri:')
            ->line("**{$this->otp}**")
            ->line("Request ID: {$this->challengeId}")
            ->when($this->documentNumber, fn (MailMessage $mail) => $mail->line("Nomor dokumen: {$this->documentNumber}"))
            ->when($this->documentTitle, fn (MailMessage $mail) => $mail->line("Judul dokumen: {$this->documentTitle}"))
            ->line("Kode berlaku selama {$this->expiryMinutes} menit dan hanya untuk transaksi, versi, serta sesi ini.")
            ->line('Jangan bagikan kode ini kepada siapa pun, termasuk pihak yang mengaku dari tim IT.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'Kode OTP Tanda Tangan',
            'message' => "Kode OTP tanda tangan Anda telah dikirim, berlaku {$this->expiryMinutes} menit.",
        ];
    }
}
