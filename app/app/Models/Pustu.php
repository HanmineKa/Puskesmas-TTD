<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pustu extends Model
{
    protected $table = 'pustu';

    protected $fillable = ['puskesmas_utama_id', 'nama', 'alamat', 'kontak', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function puskesmasUtama(): BelongsTo
    {
        return $this->belongsTo(PuskesmasUtama::class);
    }

    public function adminPustu(): HasMany
    {
        return $this->hasMany(AdminPustu::class);
    }

    public function kelurahanWilayah(): BelongsToMany
    {
        return $this->belongsToMany(Kelurahan::class, 'wilayah_kerja_pustu');
    }

    public function sekolah(): HasMany
    {
        return $this->hasMany(Sekolah::class);
    }
}
