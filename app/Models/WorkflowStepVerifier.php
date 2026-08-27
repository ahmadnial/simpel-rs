<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkflowStepVerifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_step_id',
        'tipe_pool',
        'role_nama',
        'user_id',
    ];

    public function step()
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
