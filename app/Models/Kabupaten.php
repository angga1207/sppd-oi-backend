<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kabupaten extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'id_provinsi', 'nama'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'id_provinsi');
    }

    public function kecamatans(): HasMany
    {
        return $this->hasMany(Kecamatan::class, 'id_kabupaten');
    }
}
