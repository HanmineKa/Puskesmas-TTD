<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['role', 'login_hash', 'username', 'password', 'status', 'wajib_ganti_sandi'])]
#[Hidden(['password', 'remember_token', 'login_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'wajib_ganti_sandi' => 'boolean',
            'terkunci_sampai' => 'datetime',
            'terakhir_login_at' => 'datetime',
        ];
    }

    public function administrator(): HasOne
    {
        return $this->hasOne(Administrator::class);
    }

    public function adminPustu(): HasOne
    {
        return $this->hasOne(AdminPustu::class);
    }

    public function guru(): HasOne
    {
        return $this->hasOne(Guru::class);
    }

    public function siswi(): HasOne
    {
        return $this->hasOne(Siswi::class);
    }

    public function isAdministrator(): bool
    {
        return $this->role === 'ADMINISTRATOR';
    }

    public function isAdminPustu(): bool
    {
        return $this->role === 'ADMIN_PUSTU';
    }

    public function namaPengguna(): ?string
    {
        return match ($this->role) {
            'ADMINISTRATOR' => $this->administrator?->nama,
            'ADMIN_PUSTU' => $this->adminPustu?->nama,
            'GURU' => $this->guru?->nama,
            'SISWI' => $this->siswi?->nama,
            default => null,
        };
    }
}
