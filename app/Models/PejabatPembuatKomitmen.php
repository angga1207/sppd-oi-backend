<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PejabatPembuatKomitmen extends Model
{
    protected $table = 'pejabat_pembuat_komitmen';

    protected $fillable = [
        'instance_id',
        'nama',
        'nip',
        'jabatan',
        'pangkat',
        'golongan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
