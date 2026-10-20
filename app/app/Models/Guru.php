<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guru extends Model
{
    protected $table = 'guru';

    protected $fillable = [
        'user_id', 'sekolah_id', 'nama', 'nuptk', 'nuptk_hash', 'dokumen_sk',
        'diverifikasi_oleh', 'tgl_verifikasi', 'alasan_penolakan',
    ];

    protected function casts(): array
    {
        return [
            'nuptk' => 'encrypted',
            'tgl_verifikasi' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class);
    }

    public function diverifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
