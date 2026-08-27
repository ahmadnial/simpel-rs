<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkflowStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_template_id', 'urutan', 'nama_tahap',
        'tipe', 'role_nama', 'sla_hari_kerja',
        'min_approval', 'mode_verifikasi',
    ];

    protected function casts(): array
    {
        return [
            'sla_hari_kerja' => 'integer', 
            'urutan'         => 'integer',
            'min_approval'   => 'integer',
        ];
    }

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function verifications()
    {
        return $this->hasMany(DocumentVerification::class);
    }

    public function verifierPool()
    {
        return $this->hasMany(WorkflowStepVerifier::class);
    }

    public function isVerifikasi(): bool
    {
        return $this->tipe === 'verifikasi';
    }

    public function isPenandatangan(): bool
    {
        return $this->tipe === 'penandatangan';
    }

    public function isParallelQuorum(): bool
    {
        return $this->mode_verifikasi === 'parallel';
    }
}
