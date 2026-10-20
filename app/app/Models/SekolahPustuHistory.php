<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SekolahPustuHistory extends Model
{
    protected $table = 'sekolah_pustu_history';

    protected $fillable = ['sekolah_id', 'pustu_id', 'tgl_mulai', 'tgl_selesai', 'diubah_oleh'];

    protected function casts(): array
    {
        return ['tgl_mulai' => 'date', 'tgl_selesai' => 'date'];
    }

    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class);
    }

    public function pustu(): BelongsTo
    {
        return $this->belongsTo(Pustu::class);
    }

    public function pengubah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh');
    }
}
