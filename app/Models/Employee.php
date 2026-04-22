<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use SoftDeletes, Searchable;

    protected $fillable = [
        'semesta_id',
        'nama_lengkap',
        'nip',
        'jenis_pegawai',
        'instance_id',
        'id_skpd',
        'id_jabatan',
        'jabatan',
        'kepala_skpd',
        'foto_pegawai',
        'email',
        'no_hp',
        'eselon',
        'golongan',
        'pangkat',
        'ref_jabatan_baru',
        'is_kepegawaian',
    ];

    protected $searchable = [
        'nama_lengkap',
        'nip',
        'jabatan'
    ];

    protected function casts(): array
    {
        return [
            'ref_jabatan_baru' => 'json',
            'is_kepegawaian' => 'boolean',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
