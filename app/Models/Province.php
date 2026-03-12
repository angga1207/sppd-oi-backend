<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'nama'];

    public function kabupatens(): HasMany
    {
        return $this->hasMany(Kabupaten::class, 'id_provinsi');
    }
}
