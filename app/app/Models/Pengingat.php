<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pengingat extends Model
{
    protected $table = 'pengingat';

    public $timestamps = false;

    protected $fillable = ['jenis', 'penerima_user_id', 'pengirim_user_id', 'laporan_id', 'dikirim_at'];

    protected function casts(): array
    {
        return ['dikirim_at' => 'datetime'];
    }

    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penerima_user_id');
    }

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(LaporanMingguan::class, 'laporan_id');
    }
}
