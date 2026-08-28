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
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode OTP Tanda Tangan Elektronik SIMPEL-RS')
            ->greeting("Halo {$notifiable->name},")
            ->line('Berikut kode OTP untuk pengesahan elektronik internal naskah dinas:')
            ->line("**{$this->otp}**")
            ->line("Kode berlaku selama {$this->expiryMinutes} menit dan hanya untuk satu kali proses tanda tangan.")
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
