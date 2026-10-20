<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengecualianMedis extends Model
{
    protected $table = 'pengecualian_medis';

    protected $fillable = ['siswi_id', 'detail', 'ditandai_oleh', 'tgl_mulai', 'tgl_selesai'];

    protected function casts(): array
    {
        return [
            'detail' => 'encrypted',
            'tgl_mulai' => 'date',
            'tgl_selesai' => 'date',
        ];
    }

    public function siswi(): BelongsTo
    {
        return $this->belongsTo(Siswi::class);
    }

    public function penanda(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditandai_oleh');
    }
}
