<?php

namespace Tests\Feature;

use App\Models\AdminPustu;
use App\Models\AlasanTidakMinum;
use App\Models\Enrollment;
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
use App\Services\Security\BlindIndex;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaporanRekapTest extends TestCase
{
    public function test_pustu_dashboard_counts_historical_active_weeks_and_validation_states(): void
    {
        $this->travelTo('2026-10-31 12:00:00');
        $year = $this->createSchoolYear();
        [$user, , $school, $student, $enrollment] = $this->createDataset('A', $year, '2026-07-22', 'LULUS');
        $reason = AlasanTidakMinum::query()->create([
            'kode' => 'EFEK-UJI',
            'nama' => 'Mual',
            'kategori' => 'EFEK_SAMPING',
            'aktif' => true,
        ]);
        $pending = $this->createReport($student, $enrollment, $year, 1, 'SUDAH', 'MENUNGGU');
        $this->createReport($student, $enrollment, $year, 2, 'SUDAH', 'TIDAK_VALID');
        $this->createReport($student, $enrollment, $year, 3, 'TIDAK', null, $reason->id);
        DB::table('laporan_mingguan')->where('id', $pending->id)->update([
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);

        $response = $this->actingAs($user)->getJson('/api/laporan-rekap?tahun_ajaran_id='.$year->id.'&bulan=7');

        $response->assertOk()
            ->assertJsonPath('ringkasan.jumlah_siswi', 1)
            ->assertJsonPath('ringkasan.cakupan_penerimaan', 100)
            ->assertJsonPath('ringkasan.jumlah_minggu_diharapkan', 4)
            ->assertJsonPath('ringkasan.jumlah_minggu_patut', 1)
            ->assertJsonPath('ringkasan.tingkat_kepatuhan', 25)
            ->assertJsonPath('ringkasan.valid', 0)
            ->assertJsonPath('ringkasan.menunggu', 1)
            ->assertJsonPath('ringkasan.tidak_valid', 1)
            ->assertJsonPath('ringkasan.validasi_tertunda', 1)
            ->assertJsonPath('perbandingan_sekolah.0.nama', $school->nama)
            ->assertJsonPath('perbandingan_sekolah.0.validasi_tertunda', 1)
            ->assertJsonPath('alasan.0.nama', 'Mual')
            ->assertJsonMissing(['nama' => $student->nama])
            ->assertJsonMissing(['nisn' => $student->nisn]);
    }

    public function test_pustu_dashboard_does_not_include_another_pustus_schools(): void
    {
        $year = $this->createSchoolYear();
        [$user, , $schoolA] = $this->createDataset('A', $year);
        $this->createDataset('B', $year);

        $this->actingAs($user)
            ->getJson('/api/laporan-rekap?tahun_ajaran_id='.$year->id.'&bulan=7')
            ->assertOk()
            ->assertJsonCount(1, 'perbandingan_sekolah')
            ->assertJsonPath('perbandingan_sekolah.0.nama', $schoolA->nama);
    }

    public function test_teacher_cannot_read_the_administrator_report_endpoint(): void
    {
        $teacher = User::factory()->create(['role' => 'GURU']);

        $this->actingAs($teacher)->getJson('/api/laporan-rekap')->assertForbidden();
    }

    public function test_identity_export_requires_a_correct_password_and_records_access(): void
    {
        $year = $this->createSchoolYear();
        [, , , $student, $enrollment] = $this->createDataset('A', $year);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR', 'password' => 'sandi-ekspor1']);
        $this->createReport($student, $enrollment, $year, 1, 'SUDAH', 'VALID');

        $this->actingAs($administrator)->postJson('/api/laporan-rekap/ekspor', [
            'jenis' => 'IDENTITAS',
            'tahun_ajaran_id' => $year->id,
            'kata_sandi' => 'sandi-salah',
        ])->assertUnprocessable()->assertJsonValidationErrors('kata_sandi');

        $this->assertDatabaseMissing('log_akses_data', ['jenis_akses' => 'EKSPOR']);

        $response = $this->actingAs($administrator)->postJson('/api/laporan-rekap/ekspor', [
            'jenis' => 'IDENTITAS',
            'tahun_ajaran_id' => $year->id,
            'kata_sandi' => 'sandi-ekspor1',
        ]);

        $response->assertOk()
            ->assertStreamed()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $administrator->id,
            'jenis_akses' => 'EKSPOR',
            'jumlah_baris' => 1,
        ]);
    }

    public function test_aggregate_export_does_not_require_a_password_and_is_audited(): void
    {
        $year = $this->createSchoolYear();
        [$pustuUser, , , $student, $enrollment] = $this->createDataset('A', $year);
        $this->createReport($student, $enrollment, $year, 1, 'SUDAH', 'MENUNGGU');

        $response = $this->actingAs($pustuUser)->postJson('/api/laporan-rekap/ekspor', [
            'jenis' => 'AGREGAT',
            'tahun_ajaran_id' => $year->id,
        ]);

        $response->assertOk()
            ->assertStreamed()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $pustuUser->id,
            'jenis_akses' => 'EKSPOR',
            'jumlah_baris' => 1,
        ]);
    }

    private function createSchoolYear(): TahunAjaran
    {
        TahunAjaran::query()->update(['aktif' => false]);

        return TahunAjaran::query()->create([
            'label' => sprintf('26/27-%03d', TahunAjaran::query()->count() + 1),
            'tgl_mulai' => '2026-07-01',
            'tgl_selesai' => '2027-06-30',
            'aktif' => true,
        ]);
    }

    /** @return array{User, Pustu, Sekolah, Siswi, Enrollment} */
    private function createDataset(string $suffix, TahunAjaran $year, ?string $leaveDate = null, string $studentStatus = 'AKTIF'): array
    {
        $creator = User::factory()->create(['role' => 'ADMINISTRATOR']);
        $user = User::factory()->create(['role' => 'ADMIN_PUSTU']);
        $identity = str_pad((string) $user->id, 18, '0', STR_PAD_LEFT);
        $healthCenter = PuskesmasUtama::query()->create(['nama' => 'Puskesmas '.$suffix]);
        $pustu = Pustu::query()->create([
            'puskesmas_utama_id' => $healthCenter->id,
            'nama' => 'Pustu '.$suffix,
            'aktif' => true,
        ]);
        AdminPustu::query()->create([
            'user_id' => $user->id,
            'pustu_id' => $pustu->id,
            'nama' => 'Petugas '.$suffix,
            'jenis_identitas' => 'NIP',
            'nomor_identitas' => $identity,
            'nomor_identitas_hash' => app(BlindIndex::class)->hash('NIP:'.$identity),
            'dibuat_oleh' => $creator->id,
        ]);
        $district = Kecamatan::query()->create(['nama' => 'Kecamatan '.$suffix]);
        $village = Kelurahan::query()->create(['kecamatan_id' => $district->id, 'nama' => 'Kelurahan '.$suffix]);
        $school = Sekolah::query()->create([
            'npsn' => (string) (10000000 + Sekolah::query()->count() + 1),
            'nama' => 'Sekolah '.$suffix,
            'jenjang' => 'SMP',
            'kelurahan_id' => $village->id,
            'pustu_id' => $pustu->id,
            'status' => 'AKTIF',
        ]);
        $class = Kelas::query()->create([
            'sekolah_id' => $school->id,
            'tahun_ajaran_id' => $year->id,
            'nama' => 'VII-A',
            'tingkat' => 7,
            'kelas_akhir' => false,
        ]);
        $studentUser = User::factory()->create(['role' => 'SISWI']);
        $student = Siswi::query()->create([
            'user_id' => $studentUser->id,
            'nisn' => str_pad((string) (1234567800 + Siswi::query()->count() + 1), 10, '0', STR_PAD_LEFT),
            'nisn_hash' => hash('sha256', 'laporan-rekap-'.$studentUser->id),
            'nama' => 'Siswi '.$suffix,
            'status_pelacakan' => $studentStatus,
            'persetujuan_data_at' => now(),
            'versi_persetujuan' => 'v1',
        ]);
        $enrollment = Enrollment::query()->create([
            'siswi_id' => $student->id,
            'sekolah_id' => $school->id,
            'kelas_id' => $class->id,
            'tahun_ajaran_id' => $year->id,
            'pustu_id' => $pustu->id,
            'tgl_masuk' => '2026-07-01',
            'tgl_keluar' => $leaveDate,
            'status' => $leaveDate === null ? 'AKTIF' : 'SELESAI',
        ]);

        return [$user, $pustu, $school, $student, $enrollment];
    }

    private function createReport(
        Siswi $student,
        Enrollment $enrollment,
        TahunAjaran $year,
        int $week,
        string $drinkStatus,
        ?string $validationStatus,
        ?int $reasonId = null,
    ): LaporanMingguan {
        return LaporanMingguan::query()->create([
            'siswi_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'tahun_ajaran_id' => $year->id,
            'minggu_ke' => $week,
            'status_minum' => $drinkStatus,
            'status_validasi' => $validationStatus,
            'alasan_id' => $reasonId,
        ]);
    }
}
