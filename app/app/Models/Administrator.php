<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administrator extends Model
{
    protected $table = 'administrator';

    protected $fillable = ['user_id', 'puskesmas_utama_id', 'nama'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function puskesmasUtama(): BelongsTo
    {
        return $this->belongsTo(PuskesmasUtama::class);
    }
}
