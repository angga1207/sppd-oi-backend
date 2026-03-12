<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instance extends Model
{
    use Searchable;

    protected $fillable = [
        'id_eoffice',
        'name',
        'alias',
        'code',
        'logo',
        'status',
        'description',
        'address',
        'phone',
        'fax',
        'kode_pos',
        'email',
        'website',
        'facebook',
        'instagram',
        'youtube',
    ];

    protected $searchable = [
        'name',
        'alias',
        'code',
        'description',
        'address',
        'phone',
        'email',
        'website',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
