<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

use App\Models\Traits\FixesSqlServerDates;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, FixesSqlServerDates;

    protected $fillable = [
        'name', 'nip', 'email', 'phone', 'jabatan',
        'unit_id', 'avatar', 'is_active', 'password',
        'otp_code', 'otp_expires_at',
    ];

    protected $hidden = ['password', 'remember_token', 'otp_code'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at'    => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // Relasi
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'pengusul_id');
    }

    public function verifications()
    {
        return $this->hasMany(DocumentVerification::class, 'verifikator_id');
    }

    public function signatures()
    {
        return $this->hasMany(DocumentSignature::class, 'penandatangan_id');
    }

    public function delegationsAsOwner()
    {
        return $this->hasMany(Delegation::class, 'pejabat_id');
    }

    public function delegationsAsDelegate()
    {
        return $this->hasMany(Delegation::class, 'delegasi_id');
    }

    public function activeDelegation()
    {
        return $this->delegationsAsDelegate()
            ->where('is_active', true)
            ->where('berlaku_dari', '<=', now()->toDateString())
            ->where('berlaku_sampai', '>=', now()->toDateString())
            ->latest()
            ->first();
    }

    // Generate OTP
    public function generateOtp(): string
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->update([
            'otp_code'       => $otp,
            'otp_expires_at' => now()->addMinutes(config('app.otp_expiry_minutes', 5)),
        ]);
        return $otp;
    }

    public function isOtpValid(string $otp): bool
    {
        return $this->otp_code === $otp
            && $this->otp_expires_at
            && now()->lt($this->otp_expires_at);
    }

    public function getAvatarUrlAttribute(): string
    {
        return $this->avatar
            ? asset('storage/' . $this->avatar)
            : 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=6366f1&color=fff';
    }
}
