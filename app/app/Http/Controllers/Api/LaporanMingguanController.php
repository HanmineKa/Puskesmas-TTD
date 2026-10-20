<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaporanMingguanRequest;
use App\Http\Requests\UploadUlangLaporanRequest;
use App\Http\Requests\ValidasiLaporanMingguanRequest;
use App\Models\Enrollment;
use App\Models\Guru;
use App\Models\LaporanMingguan;
use App\Models\TahunAjaran;
use App\Services\Files\PrivatePhotoStorage;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaporanMingguanController extends Controller
{
    public function mine(Request $request, ActivityLogger $logger): JsonResponse
    {
        abort_unless($request->user()->role === 'SISWI' && $request->user()->status === 'AKTIF', 403);
        $student = $request->user()->siswi()->firstOrFail();
        $reports = LaporanMingguan::query()
            ->with('alasan:id,nama,kategori')
            ->where('siswi_id', $student->id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('minggu_ke')
            ->limit(52)
            ->get();

        foreach ($reports as $report) {
            $logger->dataAccess($request->user(), 'BUKA_DETAIL_SISWI', 'LaporanMingguan', $report->id, $request);
        }

        return response()->json([
            'data' => $reports->map(fn (LaporanMingguan $report): array => [
                'id' => $report->id,
                'minggu_ke' => $report->minggu_ke,
                'status_minum' => $report->status_minum,
                'waktu_minum' => $report->waktu_minum?->toIso8601String(),
                'status_validasi' => $report->status_validasi,
                'alasan' => $report->alasan?->nama,
                'catatan' => $report->catatan,
                'catatan_validasi' => $report->catatan_validasi,
                'foto_url' => $report->foto_path
                    ? URL::temporarySignedRoute('laporan-mingguan.foto', now()->addMinutes(5), ['laporanMingguan' => $report->id]) : null,
                'dapat_unggah_ulang' => $report->status_validasi === 'TIDAK_VALID',
            ]),
        ]);
    }

    public function index(Request $request, ActivityLogger $logger): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'GURU' && $user->status === 'AKTIF', 403);
        $teacher = $user->guru;
        abort_unless($teacher, 403);

        $schoolYear = TahunAjaran::query()->where('aktif', true)->first();
        if (! $schoolYear) {
            return response()->json([
                'ringkasan' => ['minggu_ke' => null, 'persentase_kepatuhan' => 0, 'validasi_tertunda' => 0],
                'data' => [],
            ]);
        }

        $today = today();
        $yearStart = $schoolYear->tgl_mulai->copy()->startOfDay();
        $week = min(52, intdiv((int) $yearStart->diffInDays($today), 7) + 1);
        $enrollments = Enrollment::query()
            ->with(['siswi:id,nama,nisn,status_pelacakan', 'kelas:id,nama,tingkat'])
            ->where('sekolah_id', $teacher->sekolah_id)
            ->where('tahun_ajaran_id', $schoolYear->id)
            ->where('status', 'AKTIF')
            ->whereDate('tgl_masuk', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('tgl_keluar')->orWhereDate('tgl_keluar', '>=', $today))
            ->whereHas('siswi', fn (Builder $query) => $query->whereIn('status_pelacakan', ['AKTIF', 'DALAM_MUTASI']))
            ->orderBy('kelas_id')
            ->orderBy('id')
            ->get();
        $reports = LaporanMingguan::query()
            ->with('alasan:id,nama,kategori')
            ->where('tahun_ajaran_id', $schoolYear->id)
            ->where('minggu_ke', $week)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('enrollment_id');
        $adherentCount = $reports->where('status_minum', 'SUDAH')
            ->whereIn('status_validasi', ['MENUNGGU', 'VALID'])->count();
        $validationLimit = (int) (DB::table('pengaturan')->where('kunci', 'batas_hari_validasi')->value('nilai') ?? 7);
        $overdueReports = LaporanMingguan::query()
            ->where('status_validasi', 'MENUNGGU')
            ->where('created_at', '<=', now()->subDays($validationLimit))
            ->whereHas('enrollment', fn (Builder $query) => $query
                ->where('status', 'AKTIF')
                ->where('sekolah_id', $teacher->sekolah_id)
                ->where('tahun_ajaran_id', $schoolYear->id))
            ->whereHas('siswi', fn (Builder $query) => $query->whereIn('status_pelacakan', ['AKTIF', 'DALAM_MUTASI']))
            ->count();

        $rows = $enrollments->map(function (Enrollment $enrollment) use ($reports, $logger, $request): array {
            $report = $reports->get($enrollment->id);
            $student = $enrollment->siswi;
            $logger->dataAccess($request->user(), 'BUKA_DETAIL_SISWI', 'Siswi', $student->id, $request);

            return [
                'enrollment_id' => $enrollment->id,
                'siswi_id' => $student->id,
                'nama' => $student->nama,
                'nisn_tersamar' => str_repeat('•', 6).mb_substr($student->nisn, -4),
                'kelas' => $enrollment->kelas->nama,
                'status_minum' => $report?->status_minum,
                'status_validasi' => $report?->status_validasi,
                'alasan' => $report?->alasan?->nama,
                'catatan' => $report?->catatan,
                'laporan_id' => $report?->id,
                'foto_url' => $report?->foto_path
                    ? URL::temporarySignedRoute('laporan-mingguan.foto', now()->addMinutes(5), [
                        'laporanMingguan' => $report->id,
                    ]) : null,
            ];
        });

        return response()->json([
            'ringkasan' => [
                'minggu_ke' => $week,
                'jumlah_siswi' => $enrollments->count(),
                'jumlah_melapor' => $reports->count(),
                'persentase_kepatuhan' => $enrollments->isEmpty()
                    ? 0 : round($adherentCount / $enrollments->count() * 100, 1),
                'valid' => $reports->where('status_validasi', 'VALID')->count(),
                'menunggu' => $reports->where('status_validasi', 'MENUNGGU')->count(),
                'tidak_valid' => $reports->where('status_validasi', 'TIDAK_VALID')->count(),
                'validasi_tertunda' => $overdueReports,
            ],
            'data' => $rows,
        ]);
    }

    public function store(
        StoreLaporanMingguanRequest $request,
        PrivatePhotoStorage $photos,
        ActivityLogger $logger,
    ): JsonResponse {
        $student = $request->user()->siswi()->firstOrFail();
        abort_unless(in_array($student->status_pelacakan, ['AKTIF', 'DALAM_MUTASI'], true), 403);

        $enrollment = $student->enrollmentAktif()->with('tahunAjaran')->firstOrFail();
        $schoolYear = TahunAjaran::query()->where('aktif', true)->firstOrFail();
        abort_unless($enrollment->tahun_ajaran_id === $schoolYear->id, 409, 'Enrollment Siswi tidak sesuai dengan tahun ajaran aktif.');

        $today = today();
        $yearStart = $schoolYear->tgl_mulai->copy()->startOfDay();
        $yearEnd = $schoolYear->tgl_selesai->copy()->endOfDay();
        abort_unless($today->betweenIncluded($yearStart, $yearEnd), 422, 'Tanggal laporan berada di luar tahun ajaran aktif.');

        $week = min(52, intdiv((int) $yearStart->diffInDays($today), 7) + 1);
        $existingReport = LaporanMingguan::query()
            ->where('siswi_id', $student->id)
            ->where('tahun_ajaran_id', $schoolYear->id)
            ->where('minggu_ke', $week)
            ->exists();
        if ($existingReport) {
            throw ValidationException::withMessages(['laporan' => 'Laporan untuk minggu ini sudah dikirim.']);
        }

        $photoPath = $request->hasFile('foto') ? $photos->storeEvidence($request->file('foto')) : null;

        try {
            $report = DB::transaction(function () use ($request, $student, $enrollment, $schoolYear, $week, $photoPath, $logger): LaporanMingguan {
                $report = LaporanMingguan::query()->create([
                    'siswi_id' => $student->id,
                    'enrollment_id' => $enrollment->id,
                    'tahun_ajaran_id' => $schoolYear->id,
                    'minggu_ke' => $week,
                    'status_minum' => $request->validated('status_minum'),
                    'waktu_minum' => $request->validated('waktu_minum'),
                    'foto_path' => $photoPath,
                    'alasan_id' => $request->validated('alasan_id'),
                    'catatan' => $request->validated('catatan'),
                    'status_validasi' => $request->validated('status_minum') === 'SUDAH' ? 'MENUNGGU' : null,
                ]);
                $logger->audit(
                    $request->user(), 'LAPORAN_MINGGUAN_DIBUAT', 'LaporanMingguan', $report->id,
                    ['status_minum' => ['after' => $report->status_minum], 'minggu_ke' => ['after' => $week]],
                    $request, 'OPERASIONAL', $enrollment->pustu_id,
                );

                return $report;
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }

            if ($exception instanceof UniqueConstraintViolationException
                && LaporanMingguan::query()
                    ->where('siswi_id', $student->id)
                    ->where('tahun_ajaran_id', $schoolYear->id)
                    ->where('minggu_ke', $week)
                    ->exists()) {
                throw ValidationException::withMessages(['laporan' => 'Laporan untuk minggu ini sudah dikirim.']);
            }

            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $report->id,
                'minggu_ke' => $report->minggu_ke,
                'status_minum' => $report->status_minum,
                'status_validasi' => $report->status_validasi,
            ],
            'message' => 'Laporan mingguan berhasil dikirim.',
        ], 201);
    }

    public function validateReport(
        ValidasiLaporanMingguanRequest $request,
        LaporanMingguan $laporanMingguan,
        ActivityLogger $logger,
    ): JsonResponse {
        $teacher = $this->activeTeacher($request);
        $enrollment = Enrollment::query()->findOrFail($laporanMingguan->enrollment_id);
        abort_unless($enrollment->sekolah_id === $teacher->sekolah_id, 404);
        abort_unless($laporanMingguan->status_minum === 'SUDAH' && $laporanMingguan->status_validasi === 'MENUNGGU', 409, 'Laporan ini tidak menunggu validasi.');

        $status = $request->validated('status_validasi');
        DB::transaction(function () use ($request, $laporanMingguan, $enrollment, $teacher, $status, $logger): void {
            $report = LaporanMingguan::query()->lockForUpdate()->findOrFail($laporanMingguan->id);
            abort_unless($report->status_validasi === 'MENUNGGU', 409, 'Laporan sudah divalidasi.');
            $report->update([
                'status_validasi' => $status,
                'divalidasi_oleh' => $teacher->id,
                'tgl_validasi' => now(),
                'catatan_validasi' => $request->validated('catatan_validasi'),
            ]);
            $logger->audit(
                $request->user(),
                $status === 'VALID' ? 'LAPORAN_MINGGUAN_VALID' : 'LAPORAN_MINGGUAN_TIDAK_VALID',
                'LaporanMingguan',
                $report->id,
                ['status_validasi' => ['after' => $status], 'catatan_validasi' => ['after' => $request->validated('catatan_validasi')]],
                $request,
                'OPERASIONAL',
                $enrollment->pustu_id,
            );

            if ($status === 'TIDAK_VALID') {
                DB::table('notifikasi')->insert([
                    'user_id' => $report->siswi()->value('user_id'),
                    'tipe' => 'LAPORAN_TIDAK_VALID',
                    'judul' => 'Foto laporan perlu diunggah ulang',
                    'isi' => 'Guru meminta foto bukti laporan minggu ini diunggah ulang.',
                    'tautan' => '/program',
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json([
            'data' => ['id' => $laporanMingguan->id, 'status_validasi' => $status],
            'message' => 'Validasi laporan berhasil disimpan.',
        ]);
    }

    public function resubmit(
        UploadUlangLaporanRequest $request,
        LaporanMingguan $laporanMingguan,
        PrivatePhotoStorage $photos,
        ActivityLogger $logger,
    ): JsonResponse {
        $student = $request->user()->siswi()->firstOrFail();
        abort_unless($laporanMingguan->siswi_id === $student->id, 404);
        abort_unless($laporanMingguan->status_minum === 'SUDAH' && $laporanMingguan->status_validasi === 'TIDAK_VALID', 409, 'Laporan ini tidak dapat diunggah ulang.');

        $oldPhotoPath = $laporanMingguan->foto_path;
        $newPhotoPath = $photos->storeEvidence($request->file('foto'));

        try {
            DB::transaction(function () use ($request, $laporanMingguan, $student, $newPhotoPath, $logger): void {
                $report = LaporanMingguan::query()->lockForUpdate()->findOrFail($laporanMingguan->id);
                abort_unless($report->siswi_id === $student->id && $report->status_validasi === 'TIDAK_VALID', 409, 'Status laporan sudah berubah.');
                $report->update([
                    'foto_path' => $newPhotoPath,
                    'status_validasi' => 'MENUNGGU',
                    'divalidasi_oleh' => null,
                    'tgl_validasi' => null,
                    'catatan_validasi' => null,
                ]);
                $logger->audit(
                    $request->user(), 'LAPORAN_MINGGUAN_DIUNGGAH_ULANG', 'LaporanMingguan', $report->id,
                    ['foto_path' => ['berubah' => true], 'status_validasi' => ['after' => 'MENUNGGU']],
                    $request, 'OPERASIONAL', $report->enrollment()->value('pustu_id'),
                );
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPhotoPath);
            throw $exception;
        }

        if ($oldPhotoPath) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return response()->json([
            'data' => ['id' => $laporanMingguan->id, 'status_validasi' => 'MENUNGGU'],
            'message' => 'Foto bukti berhasil diunggah ulang dan menunggu validasi Guru.',
        ]);
    }

    public function photo(
        Request $request,
        LaporanMingguan $laporanMingguan,
        PrivatePhotoStorage $photos,
        ActivityLogger $logger,
    ): Response {
        $teacher = $this->activeTeacher($request);
        $enrollment = Enrollment::query()->findOrFail($laporanMingguan->enrollment_id);
        abort_unless($enrollment->sekolah_id === $teacher->sekolah_id, 404);
        abort_unless($laporanMingguan->foto_path, 404);
        $logger->dataAccess($request->user(), 'LIHAT_FOTO', 'LaporanMingguan', $laporanMingguan->id, $request);

        return response($photos->retrieveEvidence($laporanMingguan->foto_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function activeTeacher(Request $request): Guru
    {
        abort_unless($request->user()->role === 'GURU' && $request->user()->status === 'AKTIF', 403);

        return $request->user()->guru ?? abort(403);
    }
}
