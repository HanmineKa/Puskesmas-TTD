<?php

namespace Database\Seeders;

use App\Models\Administrator;
use App\Models\AdminPustu;
use App\Models\Kecamatan;
use App\Models\Kelurahan;
use App\Models\PuskesmasUtama;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\SekolahPustuHistory;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Security\BlindIndex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Akun demo hanya boleh dibuat di lingkungan nonproduksi.');
        }

        $credentials = DB::transaction(function (): array {
            $blindIndex = app(BlindIndex::class);
            $accounts = [];
            $administrator = $this->demoUser(
                'ADMINISTRATOR', $blindIndex->hash('USERNAME:demo-admin'), 'demo-admin', 'AKTIF', true, $accounts,
            );

            $puskesmas = PuskesmasUtama::query()->updateOrCreate(
                ['nama' => 'Puskesmas Demo'],
                ['alamat' => 'Alamat contoh', 'kontak' => '021000000'],
            );
            Administrator::query()->updateOrCreate(
                ['user_id' => $administrator->id],
                ['puskesmas_utama_id' => $puskesmas->id, 'nama' => 'Administrator Demo'],
            );

            $pustu = Pustu::query()->updateOrCreate(
                ['nama' => 'Pustu Demo'],
                ['puskesmas_utama_id' => $puskesmas->id, 'alamat' => 'Alamat Pustu Demo', 'aktif' => true],
            );
            $adminPustuIdentity = '198701012009011001';
            $adminPustuHash = $blindIndex->hash('NIP:'.$adminPustuIdentity);
            $adminPustuUser = $this->demoUser(
                'ADMIN_PUSTU', $adminPustuHash, null, 'AKTIF', true, $accounts,
            );
            AdminPustu::query()->updateOrCreate(
                ['user_id' => $adminPustuUser->id],
                [
                    'pustu_id' => $pustu->id,
                    'nama' => 'Petugas Demo',
                    'jabatan' => 'Petugas Kesehatan',
                    'nomor_hp' => '081200000001',
                    'jenis_identitas' => 'NIP',
                    'nomor_identitas' => $adminPustuIdentity,
                    'nomor_identitas_hash' => $adminPustuHash,
                    'dibuat_oleh' => $administrator->id,
                ],
            );

            $kecamatan = Kecamatan::query()->updateOrCreate(['nama' => 'Kecamatan Demo']);
            $kelurahan = Kelurahan::query()->updateOrCreate(
                ['kecamatan_id' => $kecamatan->id, 'nama' => 'Kelurahan Demo'],
            );
            $pustu->kelurahanWilayah()->syncWithoutDetaching([$kelurahan->id]);

            $sekolah = Sekolah::query()->updateOrCreate(
                ['npsn' => '99900001'],
                [
                    'nama' => 'SMP Demo',
                    'jenjang' => 'SMP',
                    'kelurahan_id' => $kelurahan->id,
                    'pustu_id' => $pustu->id,
                    'status' => 'AKTIF',
                ],
            );
            SekolahPustuHistory::query()->updateOrCreate(
                ['sekolah_id' => $sekolah->id, 'pustu_id' => $pustu->id, 'tgl_mulai' => '2026-07-01'],
                ['diubah_oleh' => $administrator->id],
            );

            $tahunAjaran = TahunAjaran::query()->updateOrCreate(
                ['label' => '2026/2027'],
                ['tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30', 'aktif' => false],
            );
            if (! TahunAjaran::query()->where('aktif', true)->exists()) {
                $tahunAjaran->update(['aktif' => true]);
            }

            $kelasId = DB::table('kelas')->where([
                'sekolah_id' => $sekolah->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'nama' => 'VII-A',
            ])->value('id');
            DB::table('kelas')->updateOrInsert(
                [
                    'sekolah_id' => $sekolah->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                    'nama' => 'VII-A',
                ],
                [
                    'tingkat' => 7,
                    'kelas_akhir' => false,
                    'updated_at' => now(),
                ],
            );
            $kelasId = DB::table('kelas')->where([
                'sekolah_id' => $sekolah->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'nama' => 'VII-A',
            ])->value('id');

            $guruIdentity = '1234567890123456';
            $guruUser = $this->demoUser(
                'GURU', $blindIndex->hash('NUPTK:'.$guruIdentity), null, 'AKTIF', false, $accounts,
            );
            DB::table('guru')->updateOrInsert(
                ['user_id' => $guruUser->id],
                [
                    'sekolah_id' => $sekolah->id,
                    'nama' => 'Guru Demo',
                    'nuptk' => Crypt::encryptString($guruIdentity),
                    'nuptk_hash' => $blindIndex->hash($guruIdentity),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $siswiIdentity = '0123456789';
            $siswiUser = $this->demoUser(
                'SISWI', $blindIndex->hash('NISN:'.$siswiIdentity), null, 'AKTIF', false, $accounts,
            );
            DB::table('siswi')->updateOrInsert(
                ['user_id' => $siswiUser->id],
                [
                    'nisn' => Crypt::encryptString($siswiIdentity),
                    'nisn_hash' => $blindIndex->hash($siswiIdentity),
                    'nama' => Crypt::encryptString('Siswi Demo'),
                    'status_pelacakan' => 'AKTIF',
                    'persetujuan_data_at' => now(),
                    'versi_persetujuan' => 'demo-v1',
                    'updated_at' => now(),
                ],
            );
            $siswiId = DB::table('siswi')->where('user_id', $siswiUser->id)->value('id');

            DB::table('enrollment')->updateOrInsert(
                [
                    'siswi_id' => $siswiId,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ],
                [
                    'sekolah_id' => $sekolah->id,
                    'kelas_id' => $kelasId,
                    'pustu_id' => $pustu->id,
                    'tgl_masuk' => '2026-07-01',
                    'tgl_keluar' => null,
                    'status' => 'AKTIF',
                    'jenis_keluar' => null,
                    'updated_at' => now(),
                ],
            );

            return $accounts;
        });

        if ($credentials !== []) {
            $this->command?->table(['Peran', 'Identitas Login', 'Kata Sandi Sementara'], $credentials);
            $this->command?->warn('Simpan kredensial baru sekarang. Kata sandi Administrator dan Admin Pustu wajib diganti saat login pertama.');
        } else {
            $this->command?->info('Akun demo sudah ada; tidak ada password baru yang dibuat.');
        }
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $accounts */
    private function demoUser(
        string $role,
        string $loginHash,
        ?string $username,
        string $status,
        bool $requiresPasswordChange,
        array &$accounts,
    ): User {
        $user = User::query()->where('login_hash', $loginHash)->first();
        if (! $user && $role === 'ADMINISTRATOR' && $username !== null) {
            $user = User::query()->where('username', $username)->first();
        }
        $user ??= new User;
        $password = Str::password(15);
        $user->forceFill([
            'role' => $role,
            'login_hash' => $loginHash,
            'username' => $username,
            'password' => $password,
            'status' => $status,
            'wajib_ganti_sandi' => $requiresPasswordChange,
            'login_gagal' => 0,
            'terkunci_sampai' => null,
            'terakhir_login_at' => null,
            'remember_token' => null,
        ])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $identity = $username ?? match ($role) {
            'ADMIN_PUSTU' => '198701012009011001 (NIP)',
            'GURU' => '1234567890123456 (NUPTK)',
            'SISWI' => '0123456789 (NISN)',
            default => '—',
        };
        $accounts[] = [$role, $identity, $password];

        return $user;
    }
}
