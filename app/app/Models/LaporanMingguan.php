<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaporanMingguan extends Model
{
    protected $table = 'laporan_mingguan';

    protected $fillable = [
        'siswi_id', 'enrollment_id', 'tahun_ajaran_id', 'minggu_ke', 'status_minum',
        'waktu_minum', 'foto_path', 'alasan_id', 'catatan', 'status_validasi',
        'divalidasi_oleh', 'tgl_validasi', 'catatan_validasi',
    ];

    protected function casts(): array
    {
        return [
            'waktu_minum' => 'datetime',
            'catatan' => 'encrypted',
            'tgl_validasi' => 'datetime',
            'catatan_validasi' => 'encrypted',
        ];
    }

    public function siswi(): BelongsTo
    {
        return $this->belongsTo(Siswi::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function alasan(): BelongsTo
    {
        return $this->belongsTo(AlasanTidakMinum::class, 'alasan_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'divalidasi_oleh');
    }

    public function pengingat(): HasMany
    {
        return $this->hasMany(Pengingat::class, 'laporan_id');
    }
}
