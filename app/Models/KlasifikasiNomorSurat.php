<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KlasifikasiNomorSurat extends Model
{
    use HasFactory, Searchable;
    protected $table = 'klasifikasi';
    protected $fillable = [
        'parent_id',
        'kode',
        'klasifikasi',
        'deskripsi',
        'status',
    ];

    protected $searchable = [
        'parent.kode',
        'kode',
        'klasifikasi',
        'deskripsi',
    ];

    public function parent()
    {
        return $this->belongsTo(KlasifikasiNomorSurat::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(KlasifikasiNomorSurat::class, 'parent_id');
    }
}
