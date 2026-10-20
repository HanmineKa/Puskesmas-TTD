<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TahunAjaran extends Model
{
    protected $table = 'tahun_ajaran';

    protected $fillable = ['label', 'tgl_mulai', 'tgl_selesai', 'aktif'];

    protected function casts(): array
    {
        return ['tgl_mulai' => 'date', 'tgl_selesai' => 'date', 'aktif' => 'boolean'];
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
