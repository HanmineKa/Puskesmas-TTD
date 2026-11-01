<?php

namespace Tests\Feature;

use App\Models\AdminPustu;
use App\Models\Enrollment;
use App\Models\Guru;
use App\Models\Kecamatan;
use App\Models\Kelas;
use App\Models\Kelurahan;
use App\Models\PuskesmasUtama;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\Siswi;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TahapEmpatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
    }

    public function test_administrator_records_stock_and_pustu_confirms_in_a_transaction(): void
    {
        $world = $this->createWorld();
        $batch = $this->actingAs($world['administrator'])->postJson('/api/obat-ttd/batch', [
            'nomor_batch' => 'BATCH-UTAMA-001',
            'tgl_kadaluwarsa' => '2027-10-19',
            'jumlah_box' => 6,
            'isi_per_box' => 100,
            'tgl_terima' => '2026-10-19',
            'sumber' => 'Gudang Dinas Kesehatan',
        ])->assertCreated()->json('data');

        $this->assertSame(600, (int) $batch['jumlah_masuk']);
        $distribution = $this->postJson('/api/obat-ttd/pengiriman-pustu', [
            'batch_id' => $batch['id'], 'pustu_id' => $world['pustu']->id,
            'jumlah' => 500, 'tgl_kirim' => '2026-10-19',
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->assertDatabaseHas('notifikasi', ['user_id' => $world['pustuAdmin']->id, 'tipe' => 'PENERIMAAN_TTD_PUSTU']);

        $this->actingAs($world['pustuAdmin'])->patchJson(
            '/api/obat-ttd/pengiriman-pustu/'.$distribution['id'].'/konfirmasi',
        )->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertDatabaseHas('stok_pustu', [
            'pustu_id' => $world['pustu']->id, 'batch_id' => $batch['id'], 'jumlah' => 500,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'aksi' => 'PENERIMAAN_TTD_PUSTU_DIKONFIRMASI', 'objek_id' => $distribution['id'],
        ]);
        $this->actingAs($world['administrator'])->getJson('/api/obat-ttd/rekap-stok')
            ->assertOk()->assertJsonPath('data.0.nama_pustu', 'Pustu A')->assertJsonPath('data.0.jumlah', 500);
    }

    public function test_pustu_shipment_reserves_batch_quantity_and_is_scoped_to_its_recipient(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 5);
        $distribution = $this->actingAs($world['administrator'])->postJson('/api/obat-ttd/pengiriman-pustu', [
            'batch_id' => $batchId, 'pustu_id' => $world['pustu']->id,
            'jumlah' => 400, 'tgl_kirim' => '2026-10-19',
        ])->assertCreated()->json('data');

        $this->postJson('/api/obat-ttd/pengiriman-pustu', [
            'batch_id' => $batchId, 'pustu_id' => $world['otherPustu']->id,
            'jumlah' => 101, 'tgl_kirim' => '2026-10-19',
        ])->assertUnprocessable()->assertJsonValidationErrors('jumlah');
        $this->actingAs($world['otherPustuAdmin'])->patchJson(
            '/api/obat-ttd/pengiriman-pustu/'.$distribution['id'].'/konfirmasi',
        )->assertNotFound();
        $this->assertDatabaseHas('distribusi_pustu', ['id' => $distribution['id'], 'status' => 'PENDING']);
    }

    public function test_school_delivery_reserves_stock_and_teacher_confirmation_decrements_it_once(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 10);
        $this->putStock($world['pustu']->id, $batchId, 500);
        $delivery = $this->createSchoolDelivery($world, $batchId, 3);
        $this->assertSame('TTD/'.$world['pustu']->id.'/2026/0001', $delivery['nomor_dokumen']);
        $this->assertSame('Nama Petugas A', $delivery['nama_petugas']);
        $this->assertSame('Sekolah A', $delivery['nama_sekolah']);
        $this->actingAs($world['pustuAdmin'])->postJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/cetak',
        )->assertOk()->assertJsonPath('data.dokumen.nomor_dokumen', $delivery['nomor_dokumen']);
        $this->assertDatabaseHas('audit_log', [
            'aksi' => 'SERAH_TERIMA_TTD_DICETAK', 'objek_id' => $delivery['id'],
        ]);

        $this->actingAs($world['pustuAdmin'])->postJson('/api/obat-ttd/serah-terima-sekolah', [
            'sekolah_id' => $world['school']->id,
            'guru_penerima_id' => $world['teacherProfile']->id,
            'tgl_serah_terima' => '2026-10-19',
            'items' => [['batch_id' => $batchId, 'jumlah_box' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->actingAs($world['teacher'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/konfirmasi',
        )->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertDatabaseHas('stok_pustu', [
            'pustu_id' => $world['pustu']->id, 'batch_id' => $batchId, 'jumlah' => 200,
        ]);
        $this->actingAs($world['teacher'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/konfirmasi',
        )->assertStatus(409);
    }

    public function test_teacher_cannot_read_or_confirm_another_school_delivery(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 10);
        $this->putStock($world['pustu']->id, $batchId, 500);
        $delivery = $this->createSchoolDelivery($world, $batchId, 1);

        $this->actingAs($world['otherTeacher'])->getJson('/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'])->assertNotFound();
        $this->patchJson('/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/konfirmasi')->assertNotFound();
        $this->assertDatabaseHas('distribusi_sekolah', ['id' => $delivery['id'], 'status' => 'PENDING']);
    }

    public function test_cancelling_a_completed_school_delivery_restores_stock(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 10);
        $this->putStock($world['pustu']->id, $batchId, 500);
        $delivery = $this->createSchoolDelivery($world, $batchId, 3);
        $this->actingAs($world['teacher'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/konfirmasi',
        )->assertOk();
        $this->actingAs($world['pustuAdmin'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/batalkan',
        )->assertOk();

        $this->assertDatabaseHas('stok_pustu', ['pustu_id' => $world['pustu']->id, 'batch_id' => $batchId, 'jumlah' => 500]);
        $this->assertDatabaseHas('distribusi_sekolah', ['id' => $delivery['id'], 'status' => 'DIBATALKAN']);
    }

    public function test_student_packages_are_limited_to_52_tablets_and_one_per_school_year(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 10);
        $this->putStock($world['pustu']->id, $batchId, 500);
        $delivery = $this->createSchoolDelivery($world, $batchId, 1);
        $this->actingAs($world['teacher'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/konfirmasi',
        )->assertOk();

        $payload = [
            'siswi_id' => $world['studentProfile']->id,
            'distribusi_sekolah_id' => $delivery['id'],
            'batch_id' => $batchId,
            'tgl_diterima' => '2026-10-19',
        ];
        $this->actingAs($world['teacher'])->postJson('/api/obat-ttd/paket-siswi', $payload)
            ->assertCreated()->assertJsonPath('data.jumlah_tablet', 52);
        $this->assertDatabaseHas('penerimaan_paket_siswi', [
            'siswi_id' => $world['studentProfile']->id,
            'jumlah_tablet' => 52,
            'tahun_ajaran_id' => $world['schoolYear']->id,
        ]);
        $this->actingAs($world['teacher'])->getJson('/api/obat-ttd/paket-siswi')
            ->assertOk()->assertJsonPath('data.0.nama_siswi', 'Siswi A')
            ->assertJsonMissingPath('data.0.nisn');
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $world['teacher']->id,
            'jenis_akses' => 'BUKA_DETAIL_SISWI',
            'jumlah_baris' => 1,
        ]);
        $this->postJson('/api/obat-ttd/paket-siswi', [
            ...$payload, 'siswi_id' => $world['otherStudentProfile']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('siswi_id');
        $this->assertDatabaseCount('penerimaan_paket_siswi', 1);
        $this->actingAs($world['pustuAdmin'])->patchJson(
            '/api/obat-ttd/serah-terima-sekolah/'.$delivery['id'].'/batalkan',
        )->assertStatus(409);
    }

    public function test_expiring_stock_is_labeled_in_the_pustu_inventory(): void
    {
        $world = $this->createWorld();
        $batchId = $this->createBatch($world['administrator'], 10, '2026-11-18');
        $this->putStock($world['pustu']->id, $batchId, 100);

        $this->actingAs($world['pustuAdmin'])->getJson('/api/obat-ttd/stok-pustu')
            ->assertOk()->assertJsonPath('data.0.status_kadaluwarsa', 'SEGERA_KEDALUWARSA');
    }

    public function test_teacher_package_options_mask_student_identity_and_log_the_read(): void
    {
        $world = $this->createWorld();

        $response = $this->actingAs($world['teacher'])->getJson('/api/obat-ttd/pilihan')->assertOk();

        $this->assertSame('••••••6789', $response->json('data.siswi.0.nisn_tersamar'));
        $this->assertStringNotContainsString('0123456789', $response->getContent());
        $this->assertDatabaseHas('log_akses_data', [
            'user_id' => $world['teacher']->id,
            'jenis_akses' => 'BUKA_DETAIL_SISWI',
            'jumlah_baris' => 2,
        ]);
    }

    private function createWorld(): array
    {
        $healthCenter = PuskesmasUtama::query()->create(['nama' => 'Puskesmas Tahap Empat']);
        $pustu = Pustu::query()->create(['puskesmas_utama_id' => $healthCenter->id, 'nama' => 'Pustu A', 'aktif' => true]);
        $otherPustu = Pustu::query()->create(['puskesmas_utama_id' => $healthCenter->id, 'nama' => 'Pustu B', 'aktif' => true]);
        $administrator = User::factory()->create(['role' => 'ADMINISTRATOR', 'status' => 'AKTIF']);
        $pustuAdmin = User::factory()->create(['role' => 'ADMIN_PUSTU', 'status' => 'AKTIF']);
        $otherPustuAdmin = User::factory()->create(['role' => 'ADMIN_PUSTU', 'status' => 'AKTIF']);
        AdminPustu::query()->create([
            'user_id' => $pustuAdmin->id, 'pustu_id' => $pustu->id, 'nama' => 'Nama Petugas A',
            'jenis_identitas' => 'NIK', 'nomor_identitas' => '1234567890123456',
            'nomor_identitas_hash' => hash('sha256', 'pustu-admin-a'), 'dibuat_oleh' => $administrator->id,
        ]);
        AdminPustu::query()->create([
            'user_id' => $otherPustuAdmin->id, 'pustu_id' => $otherPustu->id, 'nama' => 'Nama Petugas B',
            'jenis_identitas' => 'NIK', 'nomor_identitas' => '2234567890123456',
            'nomor_identitas_hash' => hash('sha256', 'pustu-admin-b'), 'dibuat_oleh' => $administrator->id,
        ]);
        $district = Kecamatan::query()->create(['nama' => 'Kecamatan Tahap Empat']);
        $village = Kelurahan::query()->create(['kecamatan_id' => $district->id, 'nama' => 'Kelurahan Tahap Empat']);
        $school = Sekolah::query()->create([
            'npsn' => '88000001', 'nama' => 'Sekolah A', 'jenjang' => 'SMP',
            'kelurahan_id' => $village->id, 'pustu_id' => $pustu->id, 'status' => 'AKTIF',
        ]);
        $otherSchool = Sekolah::query()->create([
            'npsn' => '88000002', 'nama' => 'Sekolah B', 'jenjang' => 'SMP',
            'kelurahan_id' => $village->id, 'pustu_id' => $otherPustu->id, 'status' => 'AKTIF',
        ]);
        TahunAjaran::query()->update(['aktif' => false]);
        $schoolYear = TahunAjaran::query()->create([
            'label' => '2026/2027', 'tgl_mulai' => '2026-07-01', 'tgl_selesai' => '2027-06-30', 'aktif' => true,
        ]);
        $class = Kelas::query()->create([
            'sekolah_id' => $school->id, 'tahun_ajaran_id' => $schoolYear->id, 'nama' => 'VII-A', 'tingkat' => 7,
        ]);
        Kelas::query()->create([
            'sekolah_id' => $otherSchool->id, 'tahun_ajaran_id' => $schoolYear->id, 'nama' => 'VII-B', 'tingkat' => 7,
        ]);
        $teacher = User::factory()->create(['role' => 'GURU', 'status' => 'AKTIF']);
        $otherTeacher = User::factory()->create(['role' => 'GURU', 'status' => 'AKTIF']);
        $teacherProfile = Guru::query()->create([
            'user_id' => $teacher->id, 'sekolah_id' => $school->id, 'nama' => 'Guru A',
            'nuptk' => '1234567890123456', 'nuptk_hash' => hash('sha256', 'guru-a'),
        ]);
        Guru::query()->create([
            'user_id' => $otherTeacher->id, 'sekolah_id' => $otherSchool->id, 'nama' => 'Guru B',
            'nuptk' => '2234567890123456', 'nuptk_hash' => hash('sha256', 'guru-b'),
        ]);
        $student = User::factory()->create(['role' => 'SISWI', 'status' => 'AKTIF']);
        $otherStudent = User::factory()->create(['role' => 'SISWI', 'status' => 'AKTIF']);
        $studentProfile = $this->makeStudent($student, '0123456789', 'Siswi A');
        $otherStudentProfile = $this->makeStudent($otherStudent, '1123456789', 'Siswi B');
        $this->createEnrollment($studentProfile, $school, $class, $schoolYear, $pustu);
        $this->createEnrollment($otherStudentProfile, $school, $class, $schoolYear, $pustu);

        return compact(
            'administrator', 'pustuAdmin', 'otherPustuAdmin', 'pustu', 'otherPustu', 'school', 'otherSchool',
            'schoolYear', 'teacher', 'otherTeacher', 'teacherProfile', 'studentProfile', 'otherStudentProfile',
        );
    }

    private function createBatch(User $administrator, int $boxes, string $expiration = '2027-10-19'): int
    {
        $number = DB::table('batch_ttd')->count() + 1;

        return DB::table('batch_ttd')->insertGetId([
            'nomor_batch' => 'BATCH-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'tgl_kadaluwarsa' => $expiration, 'jumlah_box' => $boxes, 'isi_per_box' => 100,
            'jumlah_masuk' => $boxes * 100, 'tgl_terima' => '2026-10-19', 'sumber' => 'Gudang',
            'dicatat_oleh' => $administrator->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function putStock(int $pustuId, int $batchId, int $quantity): void
    {
        DB::table('stok_pustu')->insert([
            'pustu_id' => $pustuId, 'batch_id' => $batchId, 'jumlah' => $quantity, 'updated_at' => now(),
        ]);
    }

    private function createSchoolDelivery(array $world, int $batchId, int $boxes): array
    {
        return $this->actingAs($world['pustuAdmin'])->postJson('/api/obat-ttd/serah-terima-sekolah', [
            'sekolah_id' => $world['school']->id, 'guru_penerima_id' => $world['teacherProfile']->id,
            'tgl_serah_terima' => '2026-10-19', 'keterangan' => 'Penyaluran program mingguan.',
            'items' => [['batch_id' => $batchId, 'jumlah_box' => $boxes]],
        ])->assertCreated()->json('data');
    }

    private function makeStudent(User $user, string $nisn, string $name): Siswi
    {
        return Siswi::query()->create([
            'user_id' => $user->id, 'nisn' => $nisn, 'nisn_hash' => hash('sha256', $nisn),
            'nama' => $name, 'status_pelacakan' => 'AKTIF', 'persetujuan_data_at' => now(), 'versi_persetujuan' => 'v1',
        ]);
    }

    private function createEnrollment(Siswi $student, Sekolah $school, Kelas $class, TahunAjaran $schoolYear, Pustu $pustu): Enrollment
    {
        return Enrollment::query()->create([
            'siswi_id' => $student->id, 'sekolah_id' => $school->id, 'kelas_id' => $class->id,
            'tahun_ajaran_id' => $schoolYear->id, 'pustu_id' => $pustu->id,
            'tgl_masuk' => '2026-07-01', 'status' => 'AKTIF',
        ]);
    }
}
