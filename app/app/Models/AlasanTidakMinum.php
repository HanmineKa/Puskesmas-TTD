<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlasanTidakMinum extends Model
{
    protected $table = 'alasan_tidak_minum';

    protected $fillable = ['kode', 'nama', 'kategori', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }
}
