<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sekolah extends Model
{
    protected $table = 'sekolah';

    protected $fillable = [
        'npsn', 'nama', 'jenjang', 'alamat', 'kelurahan_id', 'pustu_id', 'kontak', 'status',
    ];

    public function pustu(): BelongsTo
    {
        return $this->belongsTo(Pustu::class);
    }

    public function kelurahan(): BelongsTo
    {
        return $this->belongsTo(Kelurahan::class);
    }

    public function riwayatPustu(): HasMany
    {
        return $this->hasMany(SekolahPustuHistory::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', 'AKTIF');
    }

    public function guru(): HasMany
    {
        return $this->hasMany(Guru::class);
    }

    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public function tokenSekolah(): HasMany
    {
        return $this->hasMany(TokenSekolah::class);
    }
}
