<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuskesmasUtama extends Model
{
    protected $table = 'puskesmas_utama';

    protected $fillable = ['nama', 'alamat', 'kontak'];

    public function pustu(): HasMany
    {
        return $this->hasMany(Pustu::class);
    }

    public function administrator(): HasMany
    {
        return $this->hasMany(Administrator::class);
    }
}
