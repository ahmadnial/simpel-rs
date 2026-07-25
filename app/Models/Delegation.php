<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Delegation extends Model
{
    use HasFactory;

    protected $fillable = [
        'pejabat_id', 'delegasi_id', 'tipe', 'alasan',
        'berlaku_dari', 'berlaku_sampai', 'is_active', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'berlaku_dari'    => 'date',
            'berlaku_sampai'  => 'date',
            'is_active'       => 'boolean',
        ];
    }

    public function pejabat()
    {
        return $this->belongsTo(User::class, 'pejabat_id');
    }

    public function delegasi()
    {
        return $this->belongsTo(User::class, 'delegasi_id');
    }

    public function pembuatDelegasi()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('berlaku_dari', '<=', now()->toDateString())
            ->where('berlaku_sampai', '>=', now()->toDateString());
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active
            && now()->between($this->berlaku_dari, $this->berlaku_sampai);
    }
}
