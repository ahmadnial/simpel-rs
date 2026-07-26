<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Traits\FixesSqlServerDates;

class AuditLog extends Model
{
    use FixesSqlServerDates;

    const UPDATED_AT = null; // Immutable — hanya created_at

    protected $fillable = [
        'user_id', 'user_name', 'aksi', 'model_type', 'model_id',
        'deskripsi', 'data_lama', 'data_baru', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data_lama'  => 'array',
            'data_baru'  => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo('model');
    }

    // Tidak bisa diedit/dihapus — override methods
    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('Audit log bersifat immutable dan tidak dapat diubah.');
    }

    public function delete()
    {
        throw new \LogicException('Audit log bersifat immutable dan tidak dapat dihapus.');
    }

    /**
     * Catat aksi baru ke audit log.
     */
    public static function catat(
        string  $aksi,
        string  $deskripsi,
        ?object $subject   = null,
        array   $dataLama  = [],
        array   $dataBaru  = []
    ): self {
        $user = auth()->user();
        $log  = new self();
        $log->forceFill([
            'user_id'    => $user?->id,
            'user_name'  => $user?->name ?? 'System',
            'aksi'       => $aksi,
            'deskripsi'  => $deskripsi,
            'model_type' => $subject ? get_class($subject) : null,
            'model_id'   => $subject?->id,
            'data_lama'  => $dataLama  ?: null,
            'data_baru'  => $dataBaru  ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
        $log->saveQuietly();
        return $log;
    }
}
