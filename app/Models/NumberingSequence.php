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
    public static function getNextNomor(int $documentTypeId, ?int $unitId, int $tahun): int
    {
        return DB::transaction(function () use ($documentTypeId, $unitId, $tahun) {
            $seq = self::lockForUpdate()->firstOrCreate(
                [
                    'document_type_id' => $documentTypeId,
                    'unit_id'          => $unitId,
                    'tahun'            => $tahun,
                ],
                ['nomor_terakhir' => 0]
            );

            $seq->increment('nomor_terakhir');
            return $seq->fresh()->nomor_terakhir;
        });
    }
}
