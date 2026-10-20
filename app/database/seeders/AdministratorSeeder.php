<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Security\BlindIndex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdministratorSeeder extends Seeder
{
    public function run(): void
    {
        $puskesmasName = trim((string) config('ttd.puskesmas_nama'));
        $name = trim((string) config('ttd.administrator.nama'));
        $username = trim((string) config('ttd.administrator.username'));
        $password = (string) config('ttd.administrator.password');

        if ($puskesmasName === '' || $name === '' || $username === '' || $password === '') {
            throw new RuntimeException('Isi PUSKESMAS_NAMA, TTD_ADMIN_NAMA, TTD_ADMIN_USERNAME, dan TTD_ADMIN_PASSWORD di .env sebelum menjalankan seeder.');
        }

        if (mb_strlen($password) < 8 || mb_strlen($password) > 15) {
            throw new RuntimeException('Kata sandi awal harus terdiri dari 8 sampai 15 karakter.');
        }

        DB::transaction(function () use ($puskesmasName, $name, $username, $password): void {
            $puskesmasId = DB::table('puskesmas_utama')->value('id');

            if (! $puskesmasId) {
                $puskesmasId = DB::table('puskesmas_utama')->insertGetId([
                    'nama' => $puskesmasName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $user = User::query()->updateOrCreate(
                ['login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$username)],
                [
                    'role' => 'ADMINISTRATOR',
                    'username' => $username,
                    'password' => $password,
                    'status' => 'AKTIF',
                ],
            );

            $administrator = DB::table('administrator')->where('user_id', $user->id)->exists();
            $administratorValues = [
                'puskesmas_utama_id' => $puskesmasId,
                'nama' => $name,
                'updated_at' => now(),
            ];

            if (! $administrator) {
                $administratorValues['created_at'] = now();
            }

            DB::table('administrator')->updateOrInsert(
                ['user_id' => $user->id],
                $administratorValues,
            );
        });
    }
}
