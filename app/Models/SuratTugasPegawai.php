<?php

namespace App\Models;

use App\Traits\HasNanoId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SuratTugasPegawai extends Model
{
    use HasNanoId;
    protected $table = 'surat_tugas_pegawai';

    protected $fillable = [
        'surat_tugas_id',
        'semesta_pegawai_id',
        'nip',
        'nama_lengkap',
        'jabatan',
        'pangkat',
        'golongan',
        'eselon',
        'id_skpd',
        'nama_skpd',
        'id_jabatan',
    ];

    public function suratTugas(): BelongsTo
    {
        return $this->belongsTo(SuratTugas::class);
    }

    public function suratPerjalananDinas(): HasOne
    {
        return $this->hasOne(SuratPerjalananDinas::class);
    }
}
