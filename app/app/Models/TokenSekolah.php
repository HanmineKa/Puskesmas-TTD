<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenSekolah extends Model
{
    protected $table = 'token_sekolah';

    public $timestamps = false;

    protected $fillable = [
        'sekolah_id', 'tahun_ajaran_id', 'kode', 'aktif', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
