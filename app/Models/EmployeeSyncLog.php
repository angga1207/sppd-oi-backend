<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSyncLog extends Model
{
    protected $fillable = [
        'instance_id',
        'instance_name',
        'id_skpd',
        'status',
        'total_fetched',
        'total_created',
        'total_updated',
        'total_deleted',
        'error_message',
        'duration_seconds',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
