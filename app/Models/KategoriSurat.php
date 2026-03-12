<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KategoriSurat extends Model
{
    protected $table = 'kategori_surat';

    protected $fillable = [
        'nama',
        'keterangan',
        'is_active',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Surat tugas yang menggunakan kategori ini
     */
    public function suratTugas(): HasMany
    {
        return $this->hasMany(SuratTugas::class, 'kategori_id');
    }

    /**
     * Scope: hanya yang aktif, urut berdasarkan urutan
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('urutan');
    }
}
