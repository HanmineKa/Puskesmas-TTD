<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kelurahan extends Model
{
    protected $table = 'kelurahan';

    protected $fillable = ['kecamatan_id', 'nama', 'kode'];

    public function kecamatan(): BelongsTo
    {
        return $this->belongsTo(Kecamatan::class);
    }

    public function pustuWilayah(): BelongsToMany
    {
        return $this->belongsToMany(Pustu::class, 'wilayah_kerja_pustu');
    }

    public function sekolah(): HasMany
    {
        return $this->hasMany(Sekolah::class);
    }
}
