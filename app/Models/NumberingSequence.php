<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class NumberingSequence extends Model
{
    use HasFactory;

    protected $fillable = ['document_type_id', 'unit_id', 'tahun', 'nomor_terakhir'];

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Increment nomor secara atomic dan kembalikan nomor berikutnya.
     * Menggunakan row-level lock untuk mencegah duplikasi pada concurrent access.
     */
    public static function getNextNomor(DocumentType $type, int $tahun): int
    {
        return DB::transaction(function () use ($type, $tahun) {
            // Isolasi hanya berdasarkan tipe dokumen dan tahun (global untuk semua unit)
            $seq = self::lockForUpdate()->firstOrCreate(
                [
                    'document_type_id' => $type->id,
                    'unit_id'          => null, // Sengaja di-null-kan agar 1 klasifikasi berbagi 1 counter
                    'tahun'            => $tahun,
                ],
                // Jika baru dibuat, mulai dari (mulai_nomor - 1) karena akan di-increment di bawah
                ['nomor_terakhir' => max(0, $type->mulai_nomor - 1)]
            );

            $seq->increment('nomor_terakhir');
            return $seq->fresh()->nomor_terakhir;
        });
    }
}
