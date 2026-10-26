<?php

namespace Tests\Feature;

use App\Models\AdminPustu;
use App\Models\Enrollment;
use App\Models\Guru;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\Siswi;
use App\Models\TokenSekolah;
use App\Models\User;
use App\Services\Security\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TahapDuaTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_registration_encrypts_identity_and_document_and_waits_for_verification(): void
    {
        Storage::fake('local');
        $school = $this->createSchool();
        $identity = '1234567890123456';

        $response = $this->post('/api/guru/registrasi', [
            'sekolah_id' => $school->id,
            'nama' => 'Guru Pengujian',
            'nuptk' => $identity,
            'password' => 'sandi-guru1',
            'password_confirmation' => 'sandi-guru1',
            'dokumen_sk' => UploadedFile::fake()->create('surat-tugas.pdf', 20, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.status', 'MENUNGGU_VERIFIKASI');
        $user = User::query()->where('role', 'GURU')->firstOrFail();
        $guru = DB::table('guru')->where('user_id', $user->id)->first();

        $this->assertSame($identity, Crypt::decryptString($guru->nuptk));
        $this->assertSame(app(BlindIndex::class)->hash($identity), $guru->nuptk_hash);
        $this->assertNotSame($identity, $guru->nuptk);
        $this->assertTrue(Storage::disk('local')->exists($guru->dokumen_sk));
        $this->assertNotSame('surat-tugas.pdf', $guru->dokumen_sk);
    }

    public function test_student_registration_creates_an_enrollment_and_duplicate_nisn_requests_a_transfer(): void
    {
        $firstSchool = $this->createSchool();
        $secondSchool = Sekolah::query()->create([
            'npsn' => '87654321',
            'nama' => 'Sekolah Tujuan',
            'jenjang' => 'SMP',
            'kelurahan_id' => $firstSchool->kelurahan_id,
            'pustu_id' => $firstSchool->pustu_id,
            'status' => 'AKTIF',
        ]);
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027',
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstClass = DB::table('kelas')->insertGetId([
            'sekolah_id' => $firstSchool->id,
            'tahun_ajaran_id' => $yearId,
            'nama' => 'VII-A',
            'tingkat' => 7,
            'kelas_akhir' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondClass = DB::table('kelas')->insertGetId([
            'sekolah_id' => $secondSchool->id,
            'tahun_ajaran_id' => $yearId,
            'nama' => 'VII-B',
            'tingkat' => 7,
            'kelas_akhir' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstToken = $this->createToken($firstSchool, $yearId, 'TOKEN-UJI-1');
        $secondToken = $this->createToken($secondSchool, $yearId, 'TOKEN-UJI-2');
        $payload = [
            'nama' => 'Siswi Pengujian',
            'nisn' => '0123456789',
            'password' => 'sandi-siswi1',
            'password_confirmation' => 'sandi-siswi1',
            'persetujuan_data' => true,
            'versi_persetujuan' => 'v1',
        ];

        $this->postJson('/api/siswi/registrasi', $payload + [
            'kode_token' => $firstToken->kode,
            'kelas_id' => $firstClass,
        ])->assertCreated();

        $siswi = Siswi::query()->firstOrFail();
        $this->assertSame(1, User::query()->where('role', 'SISWI')->count());
        $this->assertSame('AKTIF', $siswi->enrollmentAktif()->firstOrFail()->status);
        $this->assertSame('0123456789', Crypt::decryptString($siswi->getRawOriginal('nisn')));
        $this->assertSame('Siswi Pengujian', Crypt::decryptString($siswi->getRawOriginal('nama')));
        $this->assertNotSame('0123456789', DB::table('siswi')->value('nisn'));

        $this->postJson('/api/siswi/registrasi', $payload + [
            'kode_token' => $secondToken->kode,
            'kelas_id' => $secondClass,
        ])->assertAccepted()->assertJsonPath('data.status', 'PENDING');

        $this->assertSame(1, User::query()->where('role', 'SISWI')->count());
        $this->assertSame(1, DB::table('siswi')->count());
        $this->assertSame('DALAM_MUTASI', $siswi->fresh()->status_pelacakan);
        $this->assertDatabaseHas('mutasi_siswi', [
            'siswi_id' => $siswi->id,
            'jenis' => 'PINDAH_SEKOLAH_SATU_PUSTU',
            'status' => 'PENDING',
        ]);
        $mutationId = DB::table('mutasi_siswi')->where('siswi_id', $siswi->id)->value('id');
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $this->actingAs($administrator)->patchJson('/api/mutasi-siswi/'.$mutationId.'/keputusan', [
            'keputusan' => 'DITOLAK',
            'catatan_penolakan' => 'Data sekolah tujuan perlu diperiksa.',
        ])->assertOk();
        $this->assertDatabaseHas('mutasi_siswi', ['id' => $mutationId, 'status' => 'DITOLAK']);
        $this->assertSame('AKTIF', $siswi->fresh()->status_pelacakan);
    }

    public function test_existing_nisn_without_active_enrollment_uses_last_school_as_transfer_origin(): void
    {
        $sourceSchool = $this->createSchool();
        $destinationSchool = Sekolah::query()->create([
            'npsn' => '87654321',
            'nama' => 'Sekolah Tujuan',
            'jenjang' => 'SMP',
            'kelurahan_id' => $sourceSchool->kelurahan_id,
            'pustu_id' => $sourceSchool->pustu_id,
            'status' => 'AKTIF',
        ]);
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30',
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $classId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $destinationSchool->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-B', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sourceClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $sourceSchool->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'IX-A', 'tingkat' => 9,
            'kelas_akhir' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $siswiUser = User::factory()->create([
            'role' => 'SISWI',
            'login_hash' => app(BlindIndex::class)->hash('NISN:0123456789'),
        ]);
        $siswi = Siswi::query()->create([
            'user_id' => $siswiUser->id,
            'nisn' => '0123456789',
            'nisn_hash' => app(BlindIndex::class)->hash('0123456789'),
            'nama' => 'Siswi Lulus',
            'status_pelacakan' => 'LULUS',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $oldEnrollment = Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $sourceSchool->id,
            'kelas_id' => $sourceClassId,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $sourceSchool->pustu_id,
            'tgl_masuk' => '2025-07-01',
            'tgl_keluar' => '2026-06-30',
            'status' => 'SELESAI',
            'jenis_keluar' => 'LULUS',
        ]);
        $token = TokenSekolah::query()->create([
            'sekolah_id' => $destinationSchool->id,
            'tahun_ajaran_id' => $yearId,
            'kode' => 'TOKEN-MUTASI',
            'aktif' => true,
            'dibuat_oleh' => User::factory()->create(['role' => 'ADMINISTRATOR'])->id,
        ]);

        $this->postJson('/api/siswi/registrasi', [
            'nama' => 'Siswi Lulus',
            'nisn' => '0123456789',
            'password' => 'sandi-siswi1',
            'password_confirmation' => 'sandi-siswi1',
            'kode_token' => $token->kode,
            'kelas_id' => $classId,
            'persetujuan_data' => true,
            'versi_persetujuan' => 'v1',
        ])->assertAccepted();

        $this->assertDatabaseHas('enrollment', ['id' => $oldEnrollment->id, 'status' => 'SELESAI']);
        $this->assertDatabaseHas('mutasi_siswi', [
            'siswi_id' => $siswi->id,
            'enrollment_asal_id' => $oldEnrollment->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_inactive_school_is_unavailable_for_registration(): void
    {
        $school = $this->createSchool();
        $school->update(['status' => 'NONAKTIF']);
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027',
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $school->id,
            'tahun_ajaran_id' => $yearId,
            'nama' => 'VII-A',
            'tingkat' => 7,
            'kelas_akhir' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createToken($school, $yearId, 'TOKEN-SEKOLAH-NONAKTIF');

        $this->getJson('/api/sekolah-terdaftar')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/token-sekolah/TOKEN-SEKOLAH-NONAKTIF/kelas')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kode_token');
        $this->postJson('/api/siswi/registrasi', [
            'nama' => 'Siswi Uji',
            'nisn' => '0123456789',
            'password' => 'sandi-siswi1',
            'password_confirmation' => 'sandi-siswi1',
            'kode_token' => 'TOKEN-SEKOLAH-NONAKTIF',
            'kelas_id' => $classId,
            'persetujuan_data' => true,
            'versi_persetujuan' => 'v1',
        ])->assertUnprocessable()->assertJsonValidationErrors('kode_token');

        $this->assertDatabaseHas('sekolah', ['id' => $school->id, 'status' => 'NONAKTIF']);
    }

    public function test_student_can_read_only_own_profile_and_access_is_logged(): void
    {
        $school = $this->createSchool();
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30',
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $classId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $school->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-A', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role' => 'SISWI']);
        $siswi = Siswi::query()->create([
            'user_id' => $user->id,
            'nisn' => '0123456789',
            'nisn_hash' => app(BlindIndex::class)->hash('0123456789'),
            'nama' => 'Siswi Pribadi',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $school->pustu_id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);

        $this->actingAs($user)->getJson('/api/siswi/saya')
            ->assertOk()
            ->assertJsonPath('data.nama', 'Siswi Pribadi')
            ->assertJsonPath('data.nisn', '0123456789');
        $this->getJson('/api/session')->assertJsonPath('user.nama_pengguna', 'Siswi Pribadi');

        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $user->id,
            'jenis_akses' => 'BUKA_DETAIL_SISWI',
            'objek_id' => $siswi->id,
        ]);
    }

    public function test_administrator_exact_search_masks_identity_and_opening_details_is_logged(): void
    {
        $school = $this->createSchool();
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30',
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $classId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $school->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-A', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $teacherIdentity = '4567890123456789';
        $teacherUser = User::factory()->create([
            'role' => 'GURU',
            'login_hash' => app(BlindIndex::class)->hash('NUPTK:'.$teacherIdentity),
        ]);
        $teacher = Guru::query()->create([
            'user_id' => $teacherUser->id,
            'sekolah_id' => $school->id,
            'nama' => 'Guru Detail',
            'nuptk' => $teacherIdentity,
            'nuptk_hash' => app(BlindIndex::class)->hash($teacherIdentity),
        ]);
        $studentUser = User::factory()->create(['role' => 'SISWI']);
        $siswi = Siswi::query()->create([
            'user_id' => $studentUser->id,
            'nisn' => '9876543210',
            'nisn_hash' => app(BlindIndex::class)->hash('9876543210'),
            'nama' => 'Siswi Detail',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $school->pustu_id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);

        $this->actingAs($administrator)->getJson('/api/guru?nuptk='.$teacherIdentity)
            ->assertOk()
            ->assertJsonPath('data.data.0.nuptk', str_repeat('•', 12).'6789')
            ->assertJsonPath('data.data.0.sekolah.nama', 'Sekolah Pengujian')
            ->assertJsonPath('data.data.0.sekolah.kelurahan', 'Kelurahan Pengujian');
        $this->getJson('/api/guru/'.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.nuptk', $teacherIdentity);
        $this->getJson('/api/siswi?nisn=9876543210')
            ->assertOk()
            ->assertJsonPath('data.data.0.nisn', str_repeat('•', 6).'3210');
        $this->getJson('/api/siswi/'.$siswi->id)
            ->assertOk()
            ->assertJsonPath('data.nisn', '9876543210');

        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $administrator->id,
            'jenis_akses' => 'BUKA_DETAIL_GURU',
            'objek_id' => $teacher->id,
        ]);
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $administrator->id,
            'jenis_akses' => 'BUKA_DETAIL_SISWI',
            'objek_id' => $siswi->id,
        ]);
    }

    public function test_class_list_and_student_filter_are_available_to_pustu_staff(): void
    {
        [$school, , $enrollment, $yearId] = $this->createActiveStudentEnrollment();
        $otherClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $school->id,
            'tahun_ajaran_id' => $yearId,
            'nama' => 'VII-B',
            'tingkat' => 7,
            'kelas_akhir' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminPustu = $this->createPustuReviewer(Pustu::query()->findOrFail($school->pustu_id), '198701012009011001');
        $otherPuskesmasId = DB::table('puskesmas_utama')->insertGetId(['nama' => 'Puskesmas Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherPustuId = DB::table('pustu')->insertGetId(['puskesmas_utama_id' => $otherPuskesmasId, 'nama' => 'Pustu Lain', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherDistrictId = DB::table('kecamatan')->insertGetId(['nama' => 'Kecamatan Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherSubdistrictId = DB::table('kelurahan')->insertGetId(['kecamatan_id' => $otherDistrictId, 'nama' => 'Kelurahan Lain', 'created_at' => now(), 'updated_at' => now()]);
        $otherSchoolId = DB::table('sekolah')->insertGetId([
            'npsn' => '87654321', 'nama' => 'Sekolah Lain', 'jenjang' => 'SMP', 'kelurahan_id' => $otherSubdistrictId,
            'pustu_id' => $otherPustuId, 'status' => 'AKTIF', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $outsideClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $otherSchoolId, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-X', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($adminPustu)->getJson('/api/kelas')
            ->assertOk()
            ->assertJsonFragment(['id' => $enrollment->kelas_id, 'nama' => 'SMP-7'])
            ->assertJsonFragment(['id' => $otherClassId, 'nama' => 'VII-B'])
            ->assertJsonMissing(['id' => $outsideClassId, 'nama' => 'VII-X']);
        $this->getJson('/api/siswi?kelas_id='.$enrollment->kelas_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson('/api/siswi?kelas_id='.$otherClassId)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function test_pustu_admin_can_only_verify_teachers_in_its_own_territory(): void
    {
        $school = $this->createSchool();
        $pustu = Pustu::query()->findOrFail($school->pustu_id);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $identity = '1234567890123456';
        $teacherUser = User::factory()->create([
            'role' => 'GURU',
            'status' => 'MENUNGGU_VERIFIKASI',
            'login_hash' => app(BlindIndex::class)->hash('NUPTK:'.$identity),
        ]);
        $teacher = Guru::query()->create([
            'user_id' => $teacherUser->id,
            'sekolah_id' => $school->id,
            'nama' => 'Guru Menunggu',
            'nuptk' => $identity,
            'nuptk_hash' => app(BlindIndex::class)->hash($identity),
            'dokumen_sk' => 'dokumen-guru/uji.pdf.enc',
        ]);
        $adminUser = User::factory()->create(['role' => 'ADMIN_PUSTU']);
        AdminPustu::query()->create([
            'user_id' => $adminUser->id,
            'pustu_id' => $pustu->id,
            'nama' => 'Petugas Pengujian',
            'jenis_identitas' => 'NIP',
            'nomor_identitas' => '198701012009011001',
            'nomor_identitas_hash' => app(BlindIndex::class)->hash('NIP:198701012009011001'),
            'dibuat_oleh' => $administrator->id,
        ]);
        $otherPustu = Pustu::query()->create([
            'puskesmas_utama_id' => $pustu->puskesmas_utama_id,
            'nama' => 'Pustu Lain',
            'aktif' => true,
        ]);
        $otherAdmin = User::factory()->create(['role' => 'ADMIN_PUSTU']);
        AdminPustu::query()->create([
            'user_id' => $otherAdmin->id,
            'pustu_id' => $otherPustu->id,
            'nama' => 'Petugas Lain',
            'jenis_identitas' => 'NIP',
            'nomor_identitas' => '198701022009011002',
            'nomor_identitas_hash' => app(BlindIndex::class)->hash('NIP:198701022009011002'),
            'dibuat_oleh' => $administrator->id,
        ]);

        $this->actingAs($otherAdmin)->patchJson('/api/guru/'.$teacher->id.'/verifikasi', [
            'keputusan' => 'DISETUJUI',
        ])->assertNotFound();
        $this->actingAs($adminUser)->patchJson('/api/guru/'.$teacher->id.'/verifikasi', [
            'keputusan' => 'DISETUJUI',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $teacherUser->id, 'status' => 'AKTIF']);
        $this->assertDatabaseHas('notifikasi', ['user_id' => $teacherUser->id, 'tipe' => 'GURU_DISETUJUI']);
    }

    public function test_active_teacher_can_create_school_classes_and_only_one_active_token(): void
    {
        $school = $this->createSchool();
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027',
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $identity = '2345678901234567';
        $teacherUser = User::factory()->create([
            'role' => 'GURU',
            'login_hash' => app(BlindIndex::class)->hash('NUPTK:'.$identity),
        ]);
        Guru::query()->create([
            'user_id' => $teacherUser->id,
            'sekolah_id' => $school->id,
            'nama' => 'Guru Pengujian',
            'nuptk' => $identity,
            'nuptk_hash' => app(BlindIndex::class)->hash($identity),
        ]);

        $firstClass = $this->actingAs($teacherUser)->postJson('/api/guru/kelas', [
            'nama' => 'VII-A',
            'tingkat' => 7,
        ])->assertCreated()->json('data.id');
        $secondClass = $this->postJson('/api/guru/kelas', [
            'nama' => 'VII-B',
            'tingkat' => 7,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/guru/token-sekolah', ['tahun_ajaran_id' => $yearId])->assertCreated();
        $this->postJson('/api/guru/token-sekolah', ['tahun_ajaran_id' => $yearId])->assertCreated();

        $this->assertDatabaseHas('kelas', ['sekolah_id' => $school->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-A']);
        $this->assertSame(2, TokenSekolah::query()->where('sekolah_id', $school->id)->count());
        $this->assertSame(1, TokenSekolah::query()->where('sekolah_id', $school->id)->where('aktif', true)->count());

        $activeToken = TokenSekolah::query()->where('sekolah_id', $school->id)->where('aktif', true)->firstOrFail();
        $this->patchJson('/api/guru/token-sekolah/'.$activeToken->id.'/nonaktif')->assertOk();
        $this->assertSame(0, TokenSekolah::query()->where('sekolah_id', $school->id)->where('aktif', true)->count());

        $studentUser = User::factory()->create(['role' => 'SISWI']);
        $student = Siswi::query()->create([
            'user_id' => $studentUser->id,
            'nisn' => '0123456789',
            'nisn_hash' => app(BlindIndex::class)->hash('0123456789'),
            'nama' => 'Siswi Pindah Kelas',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $enrollment = Enrollment::query()->create([
            'siswi_id' => $student->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $firstClass,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $school->pustu_id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);

        $this->patchJson('/api/enrollment/'.$enrollment->id.'/kelas', ['kelas_id' => $secondClass])->assertOk();
        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'kelas_id' => $secondClass]);
    }

    public function test_administrator_can_verify_a_teacher_across_all_pustu(): void
    {
        $school = $this->createSchool();
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $identity = '3456789012345678';
        $teacherUser = User::factory()->create([
            'role' => 'GURU',
            'status' => 'MENUNGGU_VERIFIKASI',
            'login_hash' => app(BlindIndex::class)->hash('NUPTK:'.$identity),
        ]);
        $teacher = Guru::query()->create([
            'user_id' => $teacherUser->id,
            'sekolah_id' => $school->id,
            'nama' => 'Guru Pengujian',
            'nuptk' => $identity,
            'nuptk_hash' => app(BlindIndex::class)->hash($identity),
        ]);

        $this->actingAs($administrator)->patchJson('/api/guru/'.$teacher->id.'/verifikasi', [
            'keputusan' => 'DISETUJUI',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $teacherUser->id, 'status' => 'AKTIF']);
    }

    public function test_cross_pustu_transfer_requires_destination_approval_and_confirmation(): void
    {
        $sourceSchool = $this->createSchool();
        $sourcePustu = Pustu::query()->findOrFail($sourceSchool->pustu_id);
        $destinationPustu = Pustu::query()->create([
            'puskesmas_utama_id' => $sourcePustu->puskesmas_utama_id,
            'nama' => 'Pustu Tujuan',
            'aktif' => true,
        ]);
        $destinationSchool = Sekolah::query()->create([
            'npsn' => '87654321',
            'nama' => 'Sekolah Tujuan',
            'jenjang' => 'SMP',
            'kelurahan_id' => $sourceSchool->kelurahan_id,
            'pustu_id' => $destinationPustu->id,
            'status' => 'AKTIF',
        ]);
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30',
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sourceClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $sourceSchool->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-A', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinationClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $destinationSchool->id, 'tahun_ajaran_id' => $yearId, 'nama' => 'VII-B', 'tingkat' => 7,
            'kelas_akhir' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $siswiUser = User::factory()->create([
            'role' => 'SISWI',
            'login_hash' => app(BlindIndex::class)->hash('NISN:0123456789'),
        ]);
        $siswi = Siswi::query()->create([
            'user_id' => $siswiUser->id,
            'nisn' => '0123456789',
            'nisn_hash' => app(BlindIndex::class)->hash('0123456789'),
            'nama' => 'Siswi Mutasi',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $enrollment = Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $sourceSchool->id,
            'kelas_id' => $sourceClassId,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $sourcePustu->id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);
        $sourceReviewer = $this->createPustuReviewer($sourcePustu, '198701012009011001');
        $destinationReviewer = $this->createPustuReviewer($destinationPustu, '198701022009011002');

        $mutation = $this->actingAs($sourceReviewer)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'PINDAH_ANTAR_PUSTU',
            'sekolah_tujuan_id' => $destinationSchool->id,
            'kelas_tujuan_id' => $destinationClassId,
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->getJson('/api/mutasi-siswi')
            ->assertOk()
            ->assertJsonPath('data.data.0.dapat_diputuskan', false);
        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertNotFound();
        $this->actingAs($destinationReviewer)
            ->getJson('/api/mutasi-siswi')
            ->assertOk()
            ->assertJsonPath('data.data.0.dapat_diputuskan', true);
        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])
            ->assertOk();
        $this->getJson('/api/mutasi-siswi')
            ->assertOk()
            ->assertJsonPath('data.data.0.perlu_konfirmasi', true);
        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'status' => 'AKTIF']);

        $this->postJson('/api/mutasi-siswi/'.$mutation['id'].'/konfirmasi', [
            'kelas_tujuan_id' => $destinationClassId,
        ])->assertOk();

        $this->assertDatabaseHas('enrollment', [
            'id' => $enrollment->id,
            'status' => 'SELESAI',
            'jenis_keluar' => 'PINDAH_ANTAR_PUSTU',
            'mutasi_id' => $mutation['id'],
        ]);
        $this->assertDatabaseHas('enrollment', [
            'siswi_id' => $siswi->id,
            'sekolah_id' => $destinationSchool->id,
            'kelas_id' => $destinationClassId,
            'pustu_id' => $destinationPustu->id,
            'status' => 'AKTIF',
        ]);
        $this->assertDatabaseHas('mutasi_siswi', ['id' => $mutation['id'], 'status' => 'SELESAI']);
        $this->actingAs($sourceReviewer)->getJson('/api/siswi')
            ->assertOk()
            ->assertJsonPath('data.data.0.enrollments.0.pustu_id', $sourcePustu->id);
        $this->actingAs($destinationReviewer)->getJson('/api/siswi')
            ->assertOk()
            ->assertJsonPath('data.data.0.enrollments.0.pustu_id', $destinationPustu->id);
    }

    public function test_same_pustu_transfer_completes_with_one_decision(): void
    {
        [$sourceSchool, $siswi, $enrollment, $yearId] = $this->createActiveStudentEnrollment();
        $destinationSchool = Sekolah::query()->create([
            'npsn' => '87654321', 'nama' => 'Sekolah Tujuan', 'jenjang' => 'SMP',
            'kelurahan_id' => $sourceSchool->kelurahan_id,
            'pustu_id' => $sourceSchool->pustu_id,
            'status' => 'AKTIF',
        ]);
        $destinationClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $destinationSchool->id, 'tahun_ajaran_id' => $yearId,
            'nama' => 'VIII-A', 'tingkat' => 8, 'kelas_akhir' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $mutation = $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'PINDAH_SEKOLAH_SATU_PUSTU',
            'sekolah_tujuan_id' => $destinationSchool->id,
            'kelas_tujuan_id' => $destinationClassId,
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();

        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'status' => 'SELESAI']);
        $this->assertDatabaseHas('enrollment', [
            'siswi_id' => $siswi->id, 'sekolah_id' => $destinationSchool->id,
            'kelas_id' => $destinationClassId, 'status' => 'AKTIF',
        ]);
        $this->assertDatabaseHas('mutasi_siswi', ['id' => $mutation['id'], 'status' => 'SELESAI']);
    }

    public function test_graduation_requires_final_sma_or_smk_class(): void
    {
        [$school, , $enrollment] = $this->createActiveStudentEnrollment('SMP', 9, true);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);

        $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'LULUS',
            'tgl_efektif' => '2026-10-20',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('mutasi_siswi')->count());

        $school->update(['jenjang' => 'SMA']);
        $enrollment->kelas()->update(['kelas_akhir' => true]);
        $mutation = $this->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'LULUS',
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();
        $this->assertDatabaseHas('siswi', ['id' => $enrollment->siswi_id, 'status_pelacakan' => 'LULUS']);
    }

    public function test_final_smk_class_thirteen_can_graduate(): void
    {
        [, $siswi, $enrollment] = $this->createActiveStudentEnrollment('SMK', 13, true);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $mutation = $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'LULUS',
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();

        $this->assertDatabaseHas('enrollment', [
            'id' => $enrollment->id,
            'status' => 'SELESAI',
            'jenis_keluar' => 'LULUS',
        ]);
        $this->assertDatabaseHas('siswi', ['id' => $siswi->id, 'status_pelacakan' => 'LULUS']);
    }

    public function test_leaving_region_closes_enrollment_and_updates_tracking_status(): void
    {
        [, $siswi, $enrollment] = $this->createActiveStudentEnrollment();
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $mutation = $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'KELUAR_WILAYAH',
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();

        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'status' => 'SELESAI', 'jenis_keluar' => 'KELUAR_WILAYAH']);
        $this->assertDatabaseHas('siswi', ['id' => $siswi->id, 'status_pelacakan' => 'KELUAR_WILAYAH']);
    }

    public function test_smp_to_sma_mutation_creates_new_active_enrollment(): void
    {
        [$sourceSchool, $siswi, $enrollment, $yearId] = $this->createActiveStudentEnrollment();
        $destinationSchool = Sekolah::query()->create([
            'npsn' => '87654321', 'nama' => 'SMA Tujuan', 'jenjang' => 'SMA',
            'kelurahan_id' => $sourceSchool->kelurahan_id,
            'pustu_id' => $sourceSchool->pustu_id,
            'status' => 'AKTIF',
        ]);
        $destinationClassId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $destinationSchool->id, 'tahun_ajaran_id' => $yearId,
            'nama' => 'X-A', 'tingkat' => 10, 'kelas_akhir' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $mutation = $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'NAIK_JENJANG',
            'sekolah_tujuan_id' => $destinationSchool->id,
            'kelas_tujuan_id' => $destinationClassId,
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();

        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'status' => 'SELESAI', 'jenis_keluar' => 'NAIK_JENJANG']);
        $this->assertDatabaseHas('enrollment', [
            'siswi_id' => $siswi->id, 'sekolah_id' => $destinationSchool->id,
            'kelas_id' => $destinationClassId, 'status' => 'AKTIF',
        ]);
    }

    public function test_dropout_mutation_closes_enrollment_and_updates_tracking_status(): void
    {
        [$school, $siswi, $enrollment] = $this->createActiveStudentEnrollment();
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $mutation = $this->actingAs($administrator)->postJson('/api/mutasi-siswi', [
            'enrollment_id' => $enrollment->id,
            'jenis' => 'PUTUS_SEKOLAH',
            'tgl_efektif' => '2026-10-20',
        ])->assertCreated()->json('data');

        $this->patchJson('/api/mutasi-siswi/'.$mutation['id'].'/keputusan', ['keputusan' => 'DISETUJUI'])->assertOk();

        $this->assertDatabaseHas('enrollment', ['id' => $enrollment->id, 'status' => 'SELESAI', 'jenis_keluar' => 'PUTUS_SEKOLAH']);
        $this->assertDatabaseHas('siswi', ['id' => $siswi->id, 'status_pelacakan' => 'PUTUS_SEKOLAH']);
        $this->assertSame($school->pustu_id, $enrollment->fresh()->pustu_id);
    }

    /** @return array{Sekolah, Siswi, Enrollment, int} */
    private function createActiveStudentEnrollment(string $level = 'SMP', int $grade = 7, bool $finalClass = false): array
    {
        $school = $this->createSchool();
        $school->update(['jenjang' => $level]);
        $yearId = DB::table('tahun_ajaran')->insertGetId([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30',
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $classId = DB::table('kelas')->insertGetId([
            'sekolah_id' => $school->id, 'tahun_ajaran_id' => $yearId,
            'nama' => $level.'-'.$grade, 'tingkat' => $grade, 'kelas_akhir' => $finalClass,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $identity = '0123456789';
        $user = User::factory()->create([
            'role' => 'SISWI',
            'login_hash' => app(BlindIndex::class)->hash('NISN:'.$identity),
        ]);
        $siswi = Siswi::query()->create([
            'user_id' => $user->id,
            'nisn' => $identity,
            'nisn_hash' => app(BlindIndex::class)->hash($identity),
            'nama' => 'Siswi Pengujian',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $enrollment = Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'pustu_id' => $school->pustu_id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);

        return [$school, $siswi, $enrollment, $yearId];
    }

    private function createPustuReviewer(Pustu $pustu, string $identity): User
    {
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR']);
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

        return $user;
    }

    private function createToken(Sekolah $school, int $yearId, string $code): TokenSekolah
    {
        return TokenSekolah::query()->create([
            'sekolah_id' => $school->id,
            'tahun_ajaran_id' => $yearId,
            'kode' => $code,
            'aktif' => true,
            'dibuat_oleh' => User::factory()->create(['role' => 'ADMINISTRATOR'])->id,
        ]);
    }

    private function createSchool(): Sekolah
    {
        $puskesmasId = DB::table('puskesmas_utama')->insertGetId([
            'nama' => 'Puskesmas Pengujian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pustuId = DB::table('pustu')->insertGetId([
            'puskesmas_utama_id' => $puskesmasId,
            'nama' => 'Pustu Pengujian',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kecamatanId = DB::table('kecamatan')->insertGetId([
            'nama' => 'Kecamatan Pengujian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kelurahanId = DB::table('kelurahan')->insertGetId([
            'kecamatan_id' => $kecamatanId,
            'nama' => 'Kelurahan Pengujian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Sekolah::query()->create([
            'npsn' => '12345678',
            'nama' => 'Sekolah Pengujian',
            'jenjang' => 'SMP',
            'kelurahan_id' => $kelurahanId,
            'pustu_id' => $pustuId,
            'status' => 'AKTIF',
        ]);
    }
}
