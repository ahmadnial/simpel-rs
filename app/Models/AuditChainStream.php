<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditChainStream extends Model
{
    protected $primaryKey = 'stream_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['stream_id', 'last_sequence', 'last_event_hash'];

    protected function casts(): array
    {
        return ['last_sequence' => 'integer'];
    }
}
