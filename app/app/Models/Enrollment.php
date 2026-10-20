<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    protected $table = 'enrollment';

    protected $fillable = [
        'siswi_id', 'sekolah_id', 'kelas_id', 'tahun_ajaran_id', 'pustu_id',
        'tgl_masuk', 'tgl_keluar', 'status', 'jenis_keluar', 'mutasi_id',
    ];

    protected function casts(): array
    {
        return [
            'tgl_masuk' => 'date',
            'tgl_keluar' => 'date',
        ];
    }

    public function siswi(): BelongsTo
    {
        return $this->belongsTo(Siswi::class);
    }

    public function sekolah(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function pustu(): BelongsTo
    {
        return $this->belongsTo(Pustu::class);
    }

    public function mutasi(): BelongsTo
    {
        return $this->belongsTo(MutasiSiswi::class);
    }
}
