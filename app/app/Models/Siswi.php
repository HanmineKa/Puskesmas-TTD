<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Siswi extends Model
{
    protected $table = 'siswi';

    protected $fillable = [
        'user_id', 'nisn', 'nisn_hash', 'nama', 'status_pelacakan',
        'persetujuan_data_at', 'versi_persetujuan',
    ];

    protected function casts(): array
    {
        return [
            'nisn' => 'encrypted',
            'nama' => 'encrypted',
            'persetujuan_data_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function enrollmentAktif(): HasOne
    {
        return $this->hasOne(Enrollment::class)->where('status', 'AKTIF');
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(MutasiSiswi::class);
    }
}
