<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPustu extends Model
{
    protected $table = 'admin_pustu';

    protected $fillable = [
        'user_id', 'pustu_id', 'nama', 'jabatan', 'nomor_hp', 'jenis_identitas',
        'nomor_identitas', 'nomor_identitas_hash', 'dibuat_oleh',
    ];

    protected $hidden = ['nomor_identitas', 'nomor_identitas_hash'];

    protected $appends = ['nomor_identitas_tersamar'];

    protected function casts(): array
    {
        return ['nomor_identitas' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pustu(): BelongsTo
    {
        return $this->belongsTo(Pustu::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    protected function nomorIdentitasTersamar(): Attribute
    {
        return Attribute::get(function (): ?string {
            $identity = (string) $this->nomor_identitas;

            if ($identity === '') {
                return null;
            }

            return str_repeat('•', max(0, mb_strlen($identity) - 4)).mb_substr($identity, -4);
        });
    }
}
