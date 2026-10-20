<?php

namespace Tests\Feature;

use App\Models\AdminPustu;
use App\Models\Kecamatan;
use App\Models\Kelurahan;
use App\Models\PuskesmasUtama;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TahapSatuTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pustu_only_sees_and_changes_schools_in_its_territory(): void
    {
        $administrator = $this->createAdministrator();
        [$pustuA, $adminUserA, $kelurahanA] = $this->createPustuAccount($administrator, '198701012009011001');
        [$pustuB, , $kelurahanB] = $this->createPustuAccount($administrator, '198701022009011002');
        $pustuA->kelurahanWilayah()->attach($kelurahanA->id);
        $pustuB->kelurahanWilayah()->attach($kelurahanB->id);
        $sekolahA = $this->createSchool($pustuA, $kelurahanA, '12345678');
        $sekolahB = $this->createSchool($pustuB, $kelurahanB, '87654321');

        $this->actingAs($adminUserA)
            ->getJson('/api/sekolah')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.npsn', '12345678');

        $this->getJson('/api/sekolah/'.$sekolahB->id)->assertForbidden();

        $this->patchJson('/api/sekolah/'.$sekolahA->id, [
            'pustu_id' => $pustuB->id,
            'nama' => 'Sekolah Coba Pindah',
        ])->assertUnprocessable()->assertJsonValidationErrors('pustu_id');

        $this->postJson('/api/sekolah', [
            'npsn' => '11112222',
            'nama' => 'Sekolah Baru',
            'jenjang' => 'SMP',
            'kelurahan_id' => $kelurahanA->id,
            'pustu_id' => $pustuB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('pustu_id');

        $this->postJson('/api/sekolah', [
            'npsn' => '11112222',
            'nama' => 'Sekolah Baru',
            'jenjang' => 'SMP',
            'kelurahan_id' => $kelurahanA->id,
        ])->assertCreated()->assertJsonPath('data.pustu_id', $pustuA->id);

        $this->assertDatabaseHas('sekolah', ['npsn' => '11112222', 'pustu_id' => $pustuA->id]);
    }

    public function test_administrator_can_move_school_and_preserve_pustu_history(): void
    {
        $administrator = $this->createAdministrator();
        [$pustuA, , $kelurahanA] = $this->createPustuAccount($administrator, '198701012009011001');
        [$pustuB, , $kelurahanB] = $this->createPustuAccount($administrator, '198701022009011002');
        $sekolah = $this->createSchool($pustuA, $kelurahanA, '12345678');
        DB::table('sekolah_pustu_history')->insert([
            'sekolah_id' => $sekolah->id,
            'pustu_id' => $pustuA->id,
            'tgl_mulai' => '2026-01-01',
            'diubah_oleh' => $administrator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($administrator)->patchJson('/api/sekolah/'.$sekolah->id, [
            'pustu_id' => $pustuB->id,
            'kelurahan_id' => $kelurahanB->id,
        ])->assertOk()->assertJsonPath('data.pustu_id', $pustuB->id);

        $this->assertDatabaseHas('sekolah_pustu_history', [
            'sekolah_id' => $sekolah->id,
            'pustu_id' => $pustuA->id,
            'tgl_selesai' => now()->toDateString(),
        ]);
        $this->assertDatabaseHas('sekolah_pustu_history', [
            'sekolah_id' => $sekolah->id,
            'pustu_id' => $pustuB->id,
            'tgl_selesai' => null,
        ]);
    }

    public function test_administrator_can_create_and_deactivate_pustu_without_deleting_it(): void
    {
        $administrator = $this->createAdministrator();
        PuskesmasUtama::query()->create(['nama' => 'Puskesmas Pengujian']);
        $kecamatan = Kecamatan::query()->create(['nama' => 'Kecamatan Uji']);
        $kelurahan = Kelurahan::query()->create(['kecamatan_id' => $kecamatan->id, 'nama' => 'Kelurahan Uji']);

        $created = $this->actingAs($administrator)->postJson('/api/pustu', [
            'nama' => 'Pustu Barat',
            'alamat' => 'Jalan Melati',
            'wilayah_kelurahan_ids' => [$kelurahan->id],
        ])->assertCreated();
        $pustuId = $created->json('data.id');

        $this->deleteJson('/api/pustu/'.$pustuId)->assertOk();

        $this->assertDatabaseHas('pustu', ['id' => $pustuId, 'aktif' => false]);
    }

    public function test_administrator_can_create_pustu_admin_and_identity_is_masked_in_response(): void
    {
        $administrator = $this->createAdministrator();
        $puskesmas = PuskesmasUtama::query()->create(['nama' => 'Puskesmas Uji']);
        $pustu = Pustu::query()->create([
            'puskesmas_utama_id' => $puskesmas->id,
            'nama' => 'Pustu Uji',
            'aktif' => true,
        ]);

        $response = $this->actingAs($administrator)->postJson('/api/admin-pustu', [
            'pustu_id' => $pustu->id,
            'nama' => 'Petugas Uji',
            'jabatan' => 'Bidan',
            'nomor_hp' => '08123456789',
            'jenis_identitas' => 'NIP',
            'nomor_identitas' => '198701012009011001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.status', 'AKTIF')
            ->assertJsonPath('data.user.wajib_ganti_sandi', true)
            ->assertJsonMissingPath('data.nomor_identitas')
            ->assertJsonMissingPath('data.nomor_identitas_hash');

        $maskedIdentity = $response->json('data.nomor_identitas_tersamar');
        $this->assertStringEndsWith('1001', $maskedIdentity);
        $this->assertSame(18, mb_strlen($maskedIdentity));
        $this->assertNotEmpty($response->json('kata_sandi_sementara'));
        $this->assertDatabaseHas('admin_pustu', [
            'pustu_id' => $pustu->id,
            'nomor_identitas_hash' => app(BlindIndex::class)->hash('NIP:198701012009011001'),
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'user_id' => $administrator->id,
            'tipe' => 'AKUN_PUSTU_DIBUAT',
            'isi' => 'Administrator telah membuat akun petugas Pustu baru.',
        ]);
    }

    public function test_only_one_school_year_can_be_active(): void
    {
        $administrator = $this->createAdministrator();
        PuskesmasUtama::query()->create(['nama' => 'Puskesmas Pengujian']);

        $this->actingAs($administrator)->postJson('/api/tahun-ajaran', [
            'label' => '2026/2027',
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
        ])->assertCreated();

        $this->postJson('/api/tahun-ajaran', [
            'label' => '2027/2028',
            'tgl_mulai' => '2027-07-01',
            'tgl_selesai' => '2028-06-30',
            'aktif' => true,
        ])->assertCreated();

        $this->assertSame(1, DB::table('tahun_ajaran')->where('aktif', true)->count());
        $this->assertDatabaseHas('tahun_ajaran', ['label' => '2027/2028', 'aktif' => true]);
        $this->assertDatabaseHas('tahun_ajaran', ['label' => '2026/2027', 'aktif' => false]);

        $firstYear = DB::table('tahun_ajaran')->where('label', '2026/2027')->first();
        $this->patchJson('/api/tahun-ajaran/'.$firstYear->id, ['aktif' => true])->assertOk();
        $this->assertDatabaseHas('tahun_ajaran', ['label' => '2026/2027', 'aktif' => true]);
        $this->assertDatabaseHas('tahun_ajaran', ['label' => '2027/2028', 'aktif' => false]);
    }

    public function test_administrator_can_update_district_and_subdistrict_master_data(): void
    {
        $administrator = $this->createAdministrator();
        $kecamatan = Kecamatan::query()->create(['nama' => 'Kecamatan Lama']);
        $kelurahan = Kelurahan::query()->create(['kecamatan_id' => $kecamatan->id, 'nama' => 'Kelurahan Lama']);

        $this->actingAs($administrator)->patchJson('/api/kecamatan/'.$kecamatan->id, [
            'nama' => 'Kecamatan Baru',
        ])->assertOk()->assertJsonPath('data.nama', 'Kecamatan Baru');

        $this->patchJson('/api/kelurahan/'.$kelurahan->id, [
            'nama' => 'Kelurahan Baru',
        ])->assertOk()->assertJsonPath('data.nama', 'Kelurahan Baru');

        $this->assertDatabaseHas('kecamatan', ['id' => $kecamatan->id, 'nama' => 'Kecamatan Baru']);
        $this->assertDatabaseHas('kelurahan', ['id' => $kelurahan->id, 'nama' => 'Kelurahan Baru']);
    }

    public function test_activity_and_login_logs_are_scoped_by_role_and_owner(): void
    {
        $administrator = $this->createAdministrator();
        [$pustuA, $adminUserA] = $this->createPustuAccount($administrator, '198701012009011001');
        [$pustuB, $adminUserB] = $this->createPustuAccount($administrator, '198701022009011002');
        $request = request()->create('/api/log-aktivitas', 'GET');
        $logger = app(ActivityLogger::class);
        $logger->audit($adminUserA, 'SEKOLAH_DIBUAT', 'Sekolah', 1, [], $request, 'OPERASIONAL', $pustuA->id);
        $logger->audit($adminUserB, 'SEKOLAH_DIBUAT', 'Sekolah', 2, [], $request, 'OPERASIONAL', $pustuB->id);
        $logger->audit($administrator, 'ADMIN_PUSTU_DIBUAT', 'AdminPustu', 1, [], $request, 'KEAMANAN');
        $logger->login($adminUserA, 'LOGIN_BERHASIL', $request);
        $logger->login($adminUserB, 'LOGIN_BERHASIL', $request);

        $this->actingAs($adminUserA)
            ->getJson('/api/log-aktivitas')
            ->assertOk()
            ->assertJsonCount(1, 'audit.data')
            ->assertJsonMissingPath('login')
            ->assertJsonMissingPath('akses_data');

        $this->getJson('/api/riwayat-login')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.user_id', $adminUserA->id);

        $this->actingAs($administrator)
            ->getJson('/api/log-aktivitas')
            ->assertOk()
            ->assertJsonCount(3, 'audit.data')
            ->assertJsonCount(2, 'login.data');
    }

    public function test_repeated_failed_login_locks_account_and_alerts_administrator(): void
    {
        $administrator = $this->createAdministrator();
        $identity = 'siswi-lockout';
        $user = User::factory()->create([
            'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$identity),
            'password' => 'kata-sandi-benar',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('Origin', 'http://localhost')
                ->postJson('/api/login', ['identitas' => $identity, 'password' => 'sandi-salah'])
                ->assertUnprocessable();
        }

        $this->assertDatabaseHas('log_login', [
            'user_id' => $user->id,
            'peristiwa' => 'LOGIN_GAGAL',
        ]);
        $this->assertNotNull($user->fresh()->terkunci_sampai);
        $this->assertDatabaseHas('notifikasi', [
            'user_id' => $administrator->id,
            'tipe' => 'LOGIN_GAGAL_BERULANG',
            'isi' => 'Sebuah akun dikunci setelah beberapa kali percobaan masuk gagal.',
        ]);
    }

    public function test_new_pustu_admin_must_change_temporary_password_before_operations(): void
    {
        $identity = '198701012009011001';
        $user = User::factory()->create([
            'role' => 'ADMIN_PUSTU',
            'login_hash' => app(BlindIndex::class)->hash('NIP:'.$identity),
            'password' => 'sementara1',
            'wajib_ganti_sandi' => true,
        ]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user')
            ->assertStatus(423);

        foreach (['1234567', '1234567890123456'] as $invalidPassword) {
            $this->postJson('/api/account/change-temporary-password', [
                'current_password' => 'sementara1',
                'password' => $invalidPassword,
                'password_confirmation' => $invalidPassword,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->postJson('/api/account/change-temporary-password', [
            'current_password' => 'sementara1',
            'password' => 'sandi-baru1',
            'password_confirmation' => 'sandi-baru1',
        ])->assertOk();

        $this->getJson('/api/user')->assertOk();

        $this->assertFalse($user->fresh()->wajib_ganti_sandi);
    }

    public function test_notifications_are_visible_and_markable_only_by_their_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $now = now();
        $ownNotificationId = DB::table('notifikasi')->insertGetId([
            'user_id' => $owner->id,
            'tipe' => 'UJI',
            'judul' => 'Pemberitahuan uji',
            'isi' => 'Pesan umum.',
            'created_at' => $now,
        ]);
        $otherNotificationId = DB::table('notifikasi')->insertGetId([
            'user_id' => $otherUser->id,
            'tipe' => 'UJI',
            'judul' => 'Pemberitahuan rahasia',
            'isi' => 'Tidak boleh terlihat.',
            'created_at' => $now,
        ]);

        $this->actingAs($owner)->getJson('/api/notifikasi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownNotificationId);

        $this->patchJson('/api/notifikasi/'.$otherNotificationId.'/dibaca')->assertNotFound();
        $this->patchJson('/api/notifikasi/'.$ownNotificationId.'/dibaca')->assertOk();
        $this->assertDatabaseHas('notifikasi', ['id' => $ownNotificationId, 'dibaca_at' => now()->toDateTimeString()]);
    }

    public function test_login_checks_both_nuptk_and_nik_candidates_for_sixteen_digit_identity(): void
    {
        $identity = '1234567890123456';
        User::factory()->create([
            'role' => 'GURU',
            'login_hash' => app(BlindIndex::class)->hash('NUPTK:'.$identity),
            'password' => 'kata-sandi-guru',
        ]);
        $adminPustu = User::factory()->create([
            'role' => 'ADMIN_PUSTU',
            'login_hash' => app(BlindIndex::class)->hash('NIK:'.$identity),
            'password' => 'sandi-pustu-1',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'identitas' => $identity,
                'password' => 'sandi-pustu-1',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'ADMIN_PUSTU');

        $this->assertDatabaseHas('log_login', [
            'user_id' => $adminPustu->id,
            'peristiwa' => 'LOGIN_BERHASIL',
        ]);
    }

    public function test_demo_seeder_refreshes_all_roles_without_duplicate_profiles(): void
    {
        app(DemoAccountsSeeder::class)->run();
        $administrator = User::query()->where('username', 'demo-admin')->firstOrFail();
        $administrator->forceFill([
            'password' => 'password-demo-lama',
            'login_gagal' => 5,
        ])->save();

        app(DemoAccountsSeeder::class)->run();

        $this->assertSame(4, User::query()->count());
        $this->assertSame(1, DB::table('administrator')->count());
        $this->assertSame(1, DB::table('admin_pustu')->count());
        $this->assertSame(1, DB::table('guru')->count());
        $this->assertSame(1, DB::table('siswi')->count());
        $this->assertSame(1, DB::table('enrollment')->count());
        $this->assertSame(
            app(BlindIndex::class)->hash('USERNAME:demo-admin'),
            $administrator->login_hash,
        );
        $this->assertSame('1234567890123456', Crypt::decryptString(DB::table('guru')->value('nuptk')));
        $this->assertSame('0123456789', Crypt::decryptString(DB::table('siswi')->value('nisn')));
        $this->assertSame('Siswi Demo', Crypt::decryptString(DB::table('siswi')->value('nama')));
        $this->assertSame(2, User::query()->where('wajib_ganti_sandi', true)->count());
        $administrator->refresh();
        $this->assertFalse(Hash::check('password-demo-lama', $administrator->password));
        $this->assertSame(0, $administrator->login_gagal);
    }

    private function createAdministrator(): User
    {
        $username = 'administrator-uji';

        return User::factory()->create([
            'role' => 'ADMINISTRATOR',
            'username' => $username,
            'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$username),
        ]);
    }

    /** @return array{Pustu, User, Kelurahan} */
    private function createPustuAccount(User $administrator, string $identity): array
    {
        $puskesmas = PuskesmasUtama::query()->create(['nama' => 'Puskesmas '.$identity]);
        $pustu = Pustu::query()->create([
            'puskesmas_utama_id' => $puskesmas->id,
            'nama' => 'Pustu '.$identity,
            'aktif' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'ADMIN_PUSTU',
            'login_hash' => app(BlindIndex::class)->hash('NIP:'.$identity),
        ]);
        AdminPustu::query()->create([
            'user_id' => $user->id,
            'pustu_id' => $pustu->id,
            'nama' => 'Petugas '.$identity,
            'jenis_identitas' => 'NIP',
            'nomor_identitas' => $identity,
            'nomor_identitas_hash' => app(BlindIndex::class)->hash('NIP:'.$identity),
            'dibuat_oleh' => $administrator->id,
        ]);
        $kecamatan = Kecamatan::query()->create(['nama' => 'Kecamatan '.$identity]);
        $kelurahan = Kelurahan::query()->create(['kecamatan_id' => $kecamatan->id, 'nama' => 'Kelurahan '.$identity]);

        return [$pustu, $user, $kelurahan];
    }

    private function createSchool(Pustu $pustu, Kelurahan $kelurahan, string $npsn): Sekolah
    {
        return Sekolah::query()->create([
            'npsn' => $npsn,
            'nama' => 'Sekolah '.$npsn,
            'jenjang' => 'SMP',
            'kelurahan_id' => $kelurahan->id,
            'pustu_id' => $pustu->id,
            'status' => 'AKTIF',
        ]);
    }
}
