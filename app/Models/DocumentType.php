<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'kode', 'nama', 'singkatan', 'deskripsi',
        'format_nomor', 'mulai_nomor', 'is_active', 'urutan',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function workflowTemplates()
    {
        return $this->hasMany(WorkflowTemplate::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function numberingSequences()
    {
        return $this->hasMany(NumberingSequence::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('urutan');
    }

    // Generate nomor surat
    public function generateNomor(Unit $unit, int $nomorUrut, \DateTime $tanggal): string
    {
        $bulanRomawi = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
        $indukKode = $unit->parent ? ($unit->parent->singkatan ?? $unit->parent->kode) : ($unit->singkatan ?? $unit->kode);
        $replacements = [
            '{urut}'         => str_pad($nomorUrut, 3, '0', STR_PAD_LEFT),
            '{kode}'         => $this->singkatan,
            '{unit}'         => $unit->singkatan ?? $unit->kode,
            '{induk}'        => $indukKode,
            '{kode_induk}'   => $indukKode,
            '{rs}'           => config('app.kode_rs', 'RSNR'),
            '{bulan_romawi}' => $bulanRomawi[(int)$tanggal->format('n') - 1],
            '{bulan}'        => $tanggal->format('m'),
            '{tahun}'        => $tanggal->format('Y'),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $this->format_nomor);
    }
}
