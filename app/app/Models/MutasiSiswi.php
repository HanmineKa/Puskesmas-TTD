<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MutasiSiswi extends Model
{
    protected $table = 'mutasi_siswi';

    protected $fillable = [
        'siswi_id', 'jenis', 'enrollment_asal_id', 'sekolah_asal_id', 'pustu_asal_id',
        'sekolah_tujuan_id', 'pustu_tujuan_id', 'kelas_tujuan_id', 'status',
        'tgl_efektif', 'alasan', 'diajukan_oleh', 'disetujui_oleh', 'tgl_keputusan', 'catatan_penolakan',
    ];

    protected function casts(): array
    {
        return [
            'alasan' => 'encrypted',
            'tgl_efektif' => 'date',
            'tgl_keputusan' => 'datetime',
        ];
    }

    public function siswi(): BelongsTo
    {
        return $this->belongsTo(Siswi::class);
    }

    public function enrollmentAsal(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_asal_id');
    }

    public function sekolahAsal(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class, 'sekolah_asal_id');
    }

    public function sekolahTujuan(): BelongsTo
    {
        return $this->belongsTo(Sekolah::class, 'sekolah_tujuan_id');
    }

    public function kelasTujuan(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_tujuan_id');
    }

    public function diajukanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }
}
