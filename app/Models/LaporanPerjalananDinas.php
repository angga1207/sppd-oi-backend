<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaporanPerjalananDinas extends Model
{
    protected $table = 'laporan_perjalanan_dinas';

    protected $fillable = [
        'spd_id',
        'laporan',
        'lampiran',
    ];

    protected function casts(): array
    {
        return [
            'lampiran' => 'json',
        ];
    }

    public function suratPerjalananDinas(): BelongsTo
    {
        return $this->belongsTo(SuratPerjalananDinas::class, 'spd_id');
    }
}
