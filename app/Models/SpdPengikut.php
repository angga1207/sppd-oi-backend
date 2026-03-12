<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpdPengikut extends Model
{
    protected $table = 'spd_pengikut';

    protected $fillable = [
        'spd_id',
        'nama',
        'tanggal_lahir',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
        ];
    }

    public function suratPerjalananDinas(): BelongsTo
    {
        return $this->belongsTo(SuratPerjalananDinas::class, 'spd_id');
    }
}
