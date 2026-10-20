<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Guru;
use App\Models\Kecamatan;
use App\Models\Kelas;
use App\Models\Kelurahan;
use App\Models\LaporanMingguan;
use App\Models\PuskesmasUtama;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\Siswi;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Files\PrivatePhotoStorage;
use Database\Seeders\AlasanTidakMinumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TahapTigaTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_reporting_route_returns_the_spa_shell(): void
    {
        $this->get('/pelaporan')->assertOk()->assertSee('id="app"', false);
    }

    public function test_authenticated_users_only_receive_active_reasons_for_weekly_reports(): void
    {
        $user = User::factory()->create(['role' => 'SISWI']);
        DB::table('alasan_tidak_minum')->insert([
            ['kode' => 'LUPA', 'nama' => 'Lupa', 'kategori' => 'LAINNYA', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()],
            ['kode' => 'NONAKTIF', 'nama' => 'Tidak ditampilkan', 'kategori' => 'LAINNYA', 'aktif' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user)->getJson('/api/alasan-tidak-minum')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kode', 'LUPA');
    }

    public function test_reason_seeder_creates_the_five_standard_choices_idempotently(): void
    {
        app(AlasanTidakMinumSeeder::class)->run();
        app(AlasanTidakMinumSeeder::class)->run();

        $this->assertSame(5, DB::table('alasan_tidak_minum')->count());
        $this->assertDatabaseHas('alasan_tidak_minum', [
            'kode' => 'MAAG_GERD',
            'nama' => 'Gangguan lambung',
            'aktif' => true,
        ]);
    }

    public function test_student_can_submit_a_drunk_report_with_encrypted_private_evidence(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        Storage::fake('local');

        $response = $this->actingAs($student)->postJson('/api/laporan-mingguan', [
            'status_minum' => 'SUDAH',
            'waktu_minum' => '2026-10-19 08:30:00',
            'foto' => UploadedFile::fake()->image('kemasan.png', 1200, 800),
            'catatan' => 'Diminum setelah sarapan.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status_validasi', 'MENUNGGU');
        $reportId = $response->json('data.id');
        $report = DB::table('laporan_mingguan')->where('id', $reportId)->first();

        $this->assertSame($siswi->id, $report->siswi_id);
        $this->assertSame($enrollment->id, $report->enrollment_id);
        $this->assertSame(16, (int) $report->minggu_ke);
        $this->assertSame('Diminum setelah sarapan.', Crypt::decryptString($report->catatan));
        $this->assertTrue(Storage::disk('local')->exists($report->foto_path));
        $this->assertNotSame('Diminum setelah sarapan.', $report->catatan);
        $this->assertSame('SUDAH', $report->status_minum);
    }

    public function test_student_can_submit_a_not_drunk_report_with_encrypted_note(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        $reasonId = DB::table('alasan_tidak_minum')->insertGetId([
            'kode' => 'MAAG_GERD',
            'nama' => 'Gangguan lambung',
            'kategori' => 'MEDIS',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->postJson('/api/laporan-mingguan', [
            'status_minum' => 'TIDAK',
            'alasan_id' => $reasonId,
            'catatan' => 'Perut terasa tidak nyaman.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status_validasi', null);
        $report = DB::table('laporan_mingguan')->where('siswi_id', $siswi->id)->first();

        $this->assertSame($enrollment->id, $report->enrollment_id);
        $this->assertSame($reasonId, $report->alasan_id);
        $this->assertSame('Perut terasa tidak nyaman.', Crypt::decryptString($report->catatan));
        $this->assertNull($report->foto_path);
    }

    public function test_student_cannot_submit_two_reports_for_the_same_week(): void
    {
        [$student, $siswi] = $this->createActiveEnrollment();
        $reasonId = DB::table('alasan_tidak_minum')->insertGetId([
            'kode' => 'LUPA',
            'nama' => 'Lupa minum',
            'kategori' => 'LAINNYA',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = ['status_minum' => 'TIDAK', 'alasan_id' => $reasonId];

        $this->actingAs($student)->postJson('/api/laporan-mingguan', $payload)->assertCreated();
        $this->postJson('/api/laporan-mingguan', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('laporan');

        $this->assertSame(1, DB::table('laporan_mingguan')->where('siswi_id', $siswi->id)->count());
    }

    public function test_teacher_dashboard_counts_pending_reports_as_provisionally_adherent(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = User::factory()->create(['role' => 'GURU', 'status' => 'AKTIF']);
        Guru::query()->create([
            'user_id' => $teacher->id,
            'sekolah_id' => $enrollment->sekolah_id,
            'nama' => 'Guru Pelaporan',
            'nuptk' => '1234567890123456',
            'nuptk_hash' => hash('sha256', 'guru-pelaporan'),
        ]);
        $schoolYear = $enrollment->tahunAjaran;
        DB::table('laporan_mingguan')->insert([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $schoolYear->id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('laporan_mingguan')->insert([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $schoolYear->id,
            'minggu_ke' => 14,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->actingAs($teacher)->getJson('/api/laporan-mingguan')
            ->assertOk()
            ->assertJsonPath('ringkasan.persentase_kepatuhan', 100)
            ->assertJsonPath('ringkasan.validasi_tertunda', 1)
            ->assertJsonPath('data.0.status_validasi', 'MENUNGGU');
    }

    public function test_rejected_reports_do_not_raise_adherence_and_graduates_leave_active_monitoring(): void
    {
        [, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);
        LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'TIDAK_VALID',
        ]);
        $overdueReport = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 14,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
        ]);
        DB::table('laporan_mingguan')->where('id', $overdueReport->id)->update([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->actingAs($teacher)->getJson('/api/laporan-mingguan')
            ->assertOk()
            ->assertJsonPath('ringkasan.persentase_kepatuhan', 0)
            ->assertJsonPath('ringkasan.tidak_valid', 1)
            ->assertJsonPath('ringkasan.validasi_tertunda', 1);

        DB::table('siswi')->where('id', $siswi->id)->update(['status_pelacakan' => 'LULUS']);
        DB::table('enrollment')->where('id', $enrollment->id)->update(['status' => 'SELESAI', 'tgl_keluar' => today()]);

        $this->getJson('/api/laporan-mingguan')
            ->assertOk()
            ->assertJsonPath('ringkasan.jumlah_siswi', 0)
            ->assertJsonPath('ringkasan.validasi_tertunda', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_teacher_from_another_school_cannot_validate_a_report(): void
    {
        [, $siswi, $enrollment] = $this->createActiveEnrollment();
        $sourceSchool = Sekolah::query()->findOrFail($enrollment->sekolah_id);
        $otherSchool = Sekolah::query()->create([
            'npsn' => (string) (90000000 + DB::table('sekolah')->count()),
            'nama' => 'Sekolah Lain',
            'jenjang' => $sourceSchool->jenjang,
            'kelurahan_id' => $sourceSchool->kelurahan_id,
            'pustu_id' => $sourceSchool->pustu_id,
            'status' => 'AKTIF',
        ]);
        $otherTeacher = $this->createTeacher($otherSchool->id);
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
        ]);

        $this->actingAs($otherTeacher)->patchJson('/api/laporan-mingguan/'.$report->id.'/validasi', [
            'status_validasi' => 'VALID',
        ])->assertNotFound();

        $this->assertSame('MENUNGGU', $report->fresh()->status_validasi);
    }

    public function test_teacher_can_reject_evidence_and_notify_student(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);
        Storage::fake('local');
        $photoPath = app(PrivatePhotoStorage::class)->storeEvidence(
            UploadedFile::fake()->image('kemasan.png', 900, 900),
        );
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'waktu_minum' => now(),
            'foto_path' => $photoPath,
            'status_validasi' => 'MENUNGGU',
        ]);

        $this->actingAs($teacher)->patchJson('/api/laporan-mingguan/'.$report->id.'/validasi', [
            'status_validasi' => 'TIDAK_VALID',
            'catatan_validasi' => 'Foto kemasan belum terlihat jelas.',
        ])->assertOk()->assertJsonPath('data.status_validasi', 'TIDAK_VALID');

        $this->assertSame('Foto kemasan belum terlihat jelas.', $report->fresh()->catatan_validasi);
        $this->assertDatabaseHas('notifikasi', [
            'user_id' => $student->id,
            'tipe' => 'LAPORAN_TIDAK_VALID',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'aksi' => 'LAPORAN_MINGGUAN_TIDAK_VALID',
            'objek_id' => $report->id,
        ]);
    }

    public function test_teacher_can_open_only_own_school_evidence_through_a_signed_url(): void
    {
        [, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);
        $school = Sekolah::query()->findOrFail($enrollment->sekolah_id);
        $otherSchool = Sekolah::query()->create([
            'npsn' => (string) (90000000 + DB::table('sekolah')->count()),
            'nama' => 'Sekolah Pembanding',
            'jenjang' => $school->jenjang,
            'kelurahan_id' => $school->kelurahan_id,
            'pustu_id' => $school->pustu_id,
            'status' => 'AKTIF',
        ]);
        $otherSchoolTeacher = $this->createTeacher($otherSchool->id);
        Storage::fake('local');
        $photoPath = app(PrivatePhotoStorage::class)->storeEvidence(
            UploadedFile::fake()->image('kemasan.png', 800, 800),
        );
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'waktu_minum' => now(),
            'foto_path' => $photoPath,
            'status_validasi' => 'MENUNGGU',
        ]);
        $photoUrl = $this->actingAs($teacher)->getJson('/api/laporan-mingguan')
            ->assertOk()
            ->json('data.0.foto_url');

        $response = $this->get($photoUrl);

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringStartsWith("\xFF\xD8", $response->getContent());
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $teacher->id,
            'jenis_akses' => 'LIHAT_FOTO',
            'objek_id' => $report->id,
        ]);
        $this->actingAs($otherSchoolTeacher)->get($photoUrl)->assertNotFound();
    }

    public function test_student_can_read_own_report_and_resubmit_rejected_evidence(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        Storage::fake('local');
        $oldPhotoPath = app(PrivatePhotoStorage::class)->storeEvidence(
            UploadedFile::fake()->image('bukti-lama.png', 800, 800),
        );
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 16,
            'status_minum' => 'SUDAH',
            'waktu_minum' => now(),
            'foto_path' => $oldPhotoPath,
            'status_validasi' => 'TIDAK_VALID',
        ]);
        $otherStudent = User::factory()->create(['role' => 'SISWI', 'status' => 'AKTIF']);

        $this->actingAs($student)->getJson('/api/laporan-mingguan/saya')
            ->assertOk()
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.status_validasi', 'TIDAK_VALID');
        $response = $this->postJson('/api/laporan-mingguan/'.$report->id.'/unggah-ulang', [
            'foto' => UploadedFile::fake()->image('bukti-baru.png', 1000, 800),
        ]);

        $response->assertOk()->assertJsonPath('data.status_validasi', 'MENUNGGU');
        $this->assertFalse(Storage::disk('local')->exists($oldPhotoPath));
        $newPhotoPath = $report->fresh()->foto_path;
        $this->assertNotSame($oldPhotoPath, $newPhotoPath);
        $this->assertTrue(Storage::disk('local')->exists($newPhotoPath));
        $this->actingAs($otherStudent)->postJson('/api/laporan-mingguan/'.$report->id.'/unggah-ulang', [
            'foto' => UploadedFile::fake()->image('foto-lain.png', 600, 600),
        ])->assertNotFound();
    }

    public function test_teacher_can_mark_a_medical_exception_with_encrypted_details(): void
    {
        [, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);

        $response = $this->actingAs($teacher)->postJson('/api/pengecualian-medis', [
            'siswi_id' => $siswi->id,
            'detail' => 'Siswi sedang menjalani pemeriksaan kesehatan.',
            'tgl_mulai' => '2026-10-19',
        ]);

        $response->assertCreated();
        $exception = DB::table('pengecualian_medis')->first();
        $this->assertSame('Siswi sedang menjalani pemeriksaan kesehatan.', Crypt::decryptString($exception->detail));
        $this->assertNotSame('Siswi sedang menjalani pemeriksaan kesehatan.', $exception->detail);
        $this->assertDatabaseHas('audit_log', [
            'aksi' => 'PENGECUALIAN_MEDIS_DITANDAI',
            'objek_id' => $exception->id,
        ]);
    }

    public function test_teacher_can_send_a_manual_reminder_to_a_student_in_own_school(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);

        $this->actingAs($teacher)->postJson('/api/siswi/'.$siswi->id.'/pengingat')
            ->assertCreated();

        $this->assertDatabaseHas('pengingat', [
            'jenis' => 'PENGINGAT_MINUM',
            'penerima_user_id' => $student->id,
            'pengirim_user_id' => $teacher->id,
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'user_id' => $student->id,
            'tipe' => 'PENGINGAT_MINUM',
            'isi' => 'Guru mengingatkan untuk mengisi laporan mingguan TTD.',
        ]);
        $this->postJson('/api/siswi/'.$siswi->id.'/pengingat')->assertTooManyRequests();
        $this->assertSame(1, DB::table('pengingat')->where('penerima_user_id', $student->id)->count());
    }

    public function test_scheduled_validation_reminders_respect_seven_day_threshold_and_repeat_gap(): void
    {
        [$student, $siswi, $enrollment] = $this->createActiveEnrollment();
        $teacher = $this->createTeacher($enrollment->sekolah_id);
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 14,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
        ]);
        DB::table('laporan_mingguan')->where('id', $report->id)->update([
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        Artisan::call('app:kirim-pengingat-validasi-tertunda');
        Artisan::call('app:kirim-pengingat-validasi-tertunda');

        $this->assertSame(1, DB::table('pengingat')->where('laporan_id', $report->id)->count());
        $this->assertDatabaseHas('pengingat', [
            'jenis' => 'PENGINGAT_VALIDASI',
            'penerima_user_id' => $teacher->id,
            'laporan_id' => $report->id,
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'user_id' => $teacher->id,
            'tipe' => 'VALIDASI_TERTUNDA',
            'isi' => 'Ada laporan mingguan TTD yang menunggu validasi.',
        ]);
        $this->assertDatabaseMissing('notifikasi', [
            'user_id' => $teacher->id,
            'isi' => 'Siswi Pelaporan',
        ]);
    }

    public function test_scheduled_validation_reminders_skip_students_who_are_no_longer_active(): void
    {
        [, $siswi, $enrollment] = $this->createActiveEnrollment();
        DB::table('siswi')->where('id', $siswi->id)->update(['status_pelacakan' => 'LULUS']);
        $this->createTeacher($enrollment->sekolah_id);
        $report = LaporanMingguan::query()->create([
            'siswi_id' => $siswi->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
            'minggu_ke' => 14,
            'status_minum' => 'SUDAH',
            'status_validasi' => 'MENUNGGU',
        ]);
        DB::table('laporan_mingguan')->where('id', $report->id)->update([
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        Artisan::call('app:kirim-pengingat-validasi-tertunda');

        $this->assertDatabaseMissing('pengingat', ['laporan_id' => $report->id]);
    }

    public function test_only_administrator_can_update_reporting_reminder_settings(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR', 'status' => 'AKTIF']);
        $teacher = User::factory()->create(['role' => 'GURU', 'status' => 'AKTIF']);

        $this->actingAs($administrator)->getJson('/api/pengaturan/pelaporan')
            ->assertOk()
            ->assertJsonPath('data.batas_hari_validasi', 7)
            ->assertJsonPath('data.jeda_pengingat_hari', 7);
        $this->putJson('/api/pengaturan/pelaporan', [
            'batas_hari_validasi' => 5,
            'jeda_pengingat_hari' => 7,
        ])->assertOk()->assertJsonPath('data.batas_hari_validasi', 5);
        $this->actingAs($teacher)->putJson('/api/pengaturan/pelaporan', [
            'batas_hari_validasi' => 5,
            'jeda_pengingat_hari' => 7,
        ])->assertForbidden();

        $this->assertDatabaseHas('pengaturan', ['kunci' => 'batas_hari_validasi', 'nilai' => '5']);
    }

    private function createTeacher(int $schoolId): User
    {
        $teacher = User::factory()->create(['role' => 'GURU', 'status' => 'AKTIF']);
        Guru::query()->create([
            'user_id' => $teacher->id,
            'sekolah_id' => $schoolId,
            'nama' => 'Guru Pelaporan',
            'nuptk' => '1234567890123456',
            'nuptk_hash' => hash('sha256', 'guru-'.$schoolId.'-'.$teacher->id),
        ]);

        return $teacher;
    }

    /** @return array{User, Siswi, Enrollment} */
    private function createActiveEnrollment(): array
    {
        $healthCenter = PuskesmasUtama::query()->create(['nama' => 'Puskesmas Pelaporan']);
        $subHealthCenter = Pustu::query()->create([
            'puskesmas_utama_id' => $healthCenter->id,
            'nama' => 'Pustu Pelaporan',
            'aktif' => true,
        ]);
        $district = Kecamatan::query()->create(['nama' => 'Kecamatan Pelaporan']);
        $village = Kelurahan::query()->create([
            'kecamatan_id' => $district->id,
            'nama' => 'Kelurahan Pelaporan',
        ]);
        $school = Sekolah::query()->create([
            'npsn' => (string) (90000000 + DB::table('sekolah')->count()),
            'nama' => 'Sekolah Pelaporan',
            'jenjang' => 'SMP',
            'kelurahan_id' => $village->id,
            'pustu_id' => $subHealthCenter->id,
            'status' => 'AKTIF',
        ]);
        TahunAjaran::query()->update(['aktif' => false]);
        $schoolYearCount = TahunAjaran::query()->count();
        $schoolYear = TahunAjaran::query()->create([
            'label' => sprintf('%04d/%04d', 2030 + $schoolYearCount, 2031 + $schoolYearCount),
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
        ]);
        $class = Kelas::query()->create([
            'sekolah_id' => $school->id,
            'tahun_ajaran_id' => $schoolYear->id,
            'nama' => 'VII-A',
            'tingkat' => 7,
            'kelas_akhir' => false,
        ]);
        $student = User::factory()->create(['role' => 'SISWI', 'status' => 'AKTIF']);
        $studentCount = Siswi::query()->count();
        $siswi = Siswi::query()->create([
            'user_id' => $student->id,
            'nisn' => '0123456789',
            'nisn_hash' => hash('sha256', 'tahap-tiga-'.$studentCount),
            'nama' => 'Siswi Pelaporan',
            'status_pelacakan' => 'AKTIF',
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $enrollment = Enrollment::query()->create([
            'siswi_id' => $siswi->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $class->id,
            'tahun_ajaran_id' => $schoolYear->id,
            'pustu_id' => $subHealthCenter->id,
            'tgl_masuk' => '2026-07-01',
            'status' => 'AKTIF',
        ]);

        return [$student, $siswi, $enrollment];
    }
}
