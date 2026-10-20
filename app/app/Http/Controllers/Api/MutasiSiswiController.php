<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmMutasiSiswiRequest;
use App\Http\Requests\ReviewMutasiSiswiRequest;
use App\Http\Requests\StoreMutasiSiswiRequest;
use App\Models\Enrollment;
use App\Models\Kelas;
use App\Models\MutasiSiswi;
use App\Models\Sekolah;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MutasiSiswiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);
        $query = MutasiSiswi::query()->with([
            'siswi:id,nama,nisn,status_pelacakan',
            'enrollmentAsal.sekolah:id,nama,jenjang',
            'sekolahTujuan:id,nama,jenjang,pustu_id',
            'kelasTujuan:id,nama,tingkat',
        ]);
        if ($request->user()->role === 'GURU') {
            $query->where('diajukan_oleh', $request->user()->id);
        } elseif ($request->user()->role === 'ADMIN_PUSTU') {
            $pustuId = $request->user()->adminPustu?->pustu_id;
            abort_unless($pustuId, 403);
            $query->where(function (Builder $scope) use ($pustuId): void {
                $scope->where('pustu_asal_id', $pustuId)->orWhere('pustu_tujuan_id', $pustuId);
            });
            $query->where(function (Builder $scope) use ($pustuId): void {
                $scope->where('status', 'PENDING')
                    ->orWhere(function (Builder $confirmation) use ($pustuId): void {
                        $confirmation->where('status', 'DISETUJUI')
                            ->where('jenis', 'PINDAH_ANTAR_PUSTU')
                            ->where('pustu_tujuan_id', $pustuId);
                    });
            });
        } elseif ($request->user()->role === 'ADMINISTRATOR') {
            $query->where('status', 'PENDING');
        }
        $query->when($request->query('status'), fn (Builder $builder, string $status) => $builder->where('status', $status));

        $activeYearId = TahunAjaran::query()->where('aktif', true)->value('id');
        $mutations = $query->latest()->paginate(25)->through(fn (MutasiSiswi $mutation): array => [
            'id' => $mutation->id,
            'jenis' => $mutation->jenis,
            'status' => $mutation->status,
            'dapat_diputuskan' => in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true)
                && $mutation->status === 'PENDING' && ($mutation->jenis === 'PINDAH_ANTAR_PUSTU'
                ? $request->user()->role === 'ADMIN_PUSTU'
                    && $request->user()->adminPustu?->pustu_id === $mutation->pustu_tujuan_id
                : $request->user()->role === 'ADMINISTRATOR'
                    || ($request->user()->role === 'ADMIN_PUSTU'
                        && $request->user()->adminPustu?->pustu_id === $mutation->pustu_asal_id)),
            'perlu_konfirmasi' => $request->user()->role === 'ADMIN_PUSTU'
                && $request->user()->adminPustu?->pustu_id === $mutation->pustu_tujuan_id
                && $mutation->jenis === 'PINDAH_ANTAR_PUSTU'
                && $mutation->status === 'DISETUJUI',
            'tgl_efektif' => $mutation->tgl_efektif?->toDateString(),
            'nisn_tersamar' => str_repeat('•', 6).mb_substr((string) $mutation->siswi->nisn, -4),
            'sekolah_asal' => $mutation->enrollmentAsal?->sekolah?->nama,
            'sekolah_tujuan' => $mutation->sekolahTujuan?->nama,
            'pustu_asal_id' => $mutation->pustu_asal_id,
            'pustu_tujuan_id' => $mutation->pustu_tujuan_id,
            'kelas_tujuan_id' => $mutation->kelas_tujuan_id,
            'kelas_tujuan_opsi' => $mutation->sekolahTujuan && $activeYearId
                ? $mutation->sekolahTujuan->kelas()->where('tahun_ajaran_id', $activeYearId)
                    ->orderBy('tingkat')->orderBy('nama')->get(['id', 'nama'])
                : [],
        ]);

        return response()->json(['data' => $mutations]);
    }

    public function destinations(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);
        $yearId = TahunAjaran::query()->where('aktif', true)->value('id');

        return response()->json([
            'data' => Sekolah::query()->aktif()->orderBy('nama')->get(['id', 'nama', 'jenjang', 'pustu_id'])->map(fn (Sekolah $school): array => [
                'id' => $school->id,
                'nama' => $school->nama,
                'jenjang' => $school->jenjang,
                'pustu_id' => $school->pustu_id,
                'kelas' => $yearId
                    ? $school->kelas()->where('tahun_ajaran_id', $yearId)->orderBy('tingkat')->orderBy('nama')->get(['id', 'nama'])
                    : [],
            ]),
        ]);
    }

    public function store(
        StoreMutasiSiswiRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $enrollment = Enrollment::query()->with(['siswi', 'sekolah'])->findOrFail($values['enrollment_id']);
        $this->authorizeOrigin($request->user(), $enrollment);
        $school = isset($values['sekolah_tujuan_id']) ? Sekolah::query()->findOrFail($values['sekolah_tujuan_id']) : null;
        $class = isset($values['kelas_tujuan_id']) ? Kelas::query()->findOrFail($values['kelas_tujuan_id']) : null;
        if ($class && (! $school || $class->sekolah_id !== $school->id)) {
            abort(422, 'Kelas tujuan harus berada di sekolah tujuan.');
        }
        if (in_array($values['jenis'], ['PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU', 'NAIK_JENJANG'], true)
            && ! $school) {
            abort(422, 'Sekolah tujuan wajib dipilih untuk jenis mutasi ini.');
        }
        if (in_array($values['jenis'], ['KELUAR_WILAYAH', 'LULUS', 'PUTUS_SEKOLAH'], true) && $school) {
            abort(422, 'Jenis mutasi ini tidak memiliki sekolah tujuan.');
        }
        if ($school && $values['jenis'] === 'PINDAH_SEKOLAH_SATU_PUSTU' && $school->pustu_id !== $enrollment->pustu_id) {
            abort(422, 'Pilih jenis pindah antar-Pustu untuk sekolah dengan pembina berbeda.');
        }
        if ($school && $values['jenis'] === 'PINDAH_ANTAR_PUSTU' && $school->pustu_id === $enrollment->pustu_id) {
            abort(422, 'Sekolah tujuan masih dibina Pustu yang sama.');
        }
        if ($school && $values['jenis'] === 'NAIK_JENJANG' && $school->jenjang === $enrollment->sekolah->jenjang) {
            abort(422, 'Mutasi naik jenjang harus menuju jenjang sekolah yang berbeda.');
        }
        if ($school && $values['jenis'] === 'NAIK_JENJANG'
            && ($enrollment->sekolah->jenjang !== 'SMP' || ! in_array($school->jenjang, ['SMA', 'SMK'], true))) {
            abort(422, 'Naik jenjang hanya berlaku dari SMP ke SMA atau SMK.');
        }
        if ($values['jenis'] === 'LULUS') {
            abort_unless(
                in_array($enrollment->sekolah->jenjang, ['SMA', 'SMK'], true)
                    && $enrollment->kelas?->kelas_akhir,
                422,
                'Siswi hanya dapat lulus dari kelas akhir SMA atau SMK.',
            );
        }
        if (MutasiSiswi::query()->where('siswi_id', $enrollment->siswi_id)->whereIn('status', ['PENDING', 'DISETUJUI'])->exists()) {
            abort(409, 'Masih ada pengajuan mutasi yang sedang diproses.');
        }

        $mutation = DB::transaction(function () use ($request, $enrollment, $school, $class, $values, $logger): MutasiSiswi {
            $mutation = MutasiSiswi::query()->create([
                'siswi_id' => $enrollment->siswi_id,
                'jenis' => $values['jenis'],
                'enrollment_asal_id' => $enrollment->id,
                'sekolah_asal_id' => $enrollment->sekolah_id,
                'pustu_asal_id' => $enrollment->pustu_id,
                'sekolah_tujuan_id' => $school?->id,
                'pustu_tujuan_id' => $school?->pustu_id,
                'kelas_tujuan_id' => $class?->id,
                'status' => 'PENDING',
                'tgl_efektif' => $values['tgl_efektif'],
                'alasan' => $values['alasan'] ?? null,
                'diajukan_oleh' => $request->user()->id,
            ]);
            $enrollment->siswi->update(['status_pelacakan' => 'DALAM_MUTASI']);
            $logger->audit(
                $request->user(), 'MUTASI_SISWI_DIAJUKAN', 'MutasiSiswi', $mutation->id,
                ['status' => ['after' => 'PENDING'], 'jenis' => ['after' => $mutation->jenis]],
                $request, 'OPERASIONAL', $enrollment->pustu_id,
            );

            return $mutation;
        });

        return response()->json(['data' => $mutation], 201);
    }

    public function review(
        ReviewMutasiSiswiRequest $request,
        MutasiSiswi $mutasiSiswi,
        ActivityLogger $logger,
    ): JsonResponse {
        abort_unless($mutasiSiswi->status === 'PENDING', 409, 'Pengajuan mutasi sudah diputuskan.');
        $this->authorizeDestination($request->user(), $mutasiSiswi);
        $decision = $request->validated('keputusan');
        $approved = $decision === 'DISETUJUI';

        DB::transaction(function () use ($request, $mutasiSiswi, $approved, $logger): void {
            if (! $approved) {
                $mutasiSiswi->update([
                    'status' => 'DITOLAK',
                    'disetujui_oleh' => $request->user()->id,
                    'tgl_keputusan' => now(),
                    'catatan_penolakan' => $request->validated('catatan_penolakan'),
                ]);
                $sourceEnrollment = Enrollment::query()->findOrFail($mutasiSiswi->enrollment_asal_id);
                $mutasiSiswi->siswi()->update([
                    'status_pelacakan' => $this->trackingStatusAfterRejection($sourceEnrollment),
                ]);
            } elseif ($mutasiSiswi->jenis === 'PINDAH_ANTAR_PUSTU') {
                $mutasiSiswi->update([
                    'status' => 'DISETUJUI',
                    'disetujui_oleh' => $request->user()->id,
                    'tgl_keputusan' => now(),
                ]);
                $this->notifyMutation($mutasiSiswi, 'MUTASI_DISETUJUI', 'Mutasi disetujui', 'Pengajuan pindah Pustu disetujui. Lengkapi konfirmasi penerimaan di Pustu tujuan.');
            } else {
                $classId = $request->validated('kelas_tujuan_id') ?? $mutasiSiswi->kelas_tujuan_id;
                if (in_array($mutasiSiswi->jenis, ['PINDAH_SEKOLAH_SATU_PUSTU', 'NAIK_JENJANG'], true) && ! $classId) {
                    abort(422, 'Pilih kelas tujuan untuk menyelesaikan mutasi.');
                }
                if ($classId) {
                    $class = Kelas::query()->findOrFail($classId);
                    abort_unless($class->sekolah_id === $mutasiSiswi->sekolah_tujuan_id, 422, 'Kelas harus berada di sekolah tujuan.');
                    $mutasiSiswi->kelas_tujuan_id = $class->id;
                }
                $mutasiSiswi->update([
                    'status' => 'DISETUJUI',
                    'disetujui_oleh' => $request->user()->id,
                    'tgl_keputusan' => now(),
                ]);
                $this->completeMutation($mutasiSiswi);
                $mutasiSiswi->update(['status' => 'SELESAI']);
                $this->notifyMutation($mutasiSiswi, 'MUTASI_DISETUJUI', 'Mutasi selesai', 'Pengajuan perpindahan Siswi sudah diproses.');
            }

            $logger->audit(
                $request->user(),
                $approved ? 'MUTASI_SISWI_DISETUJUI' : 'MUTASI_SISWI_DITOLAK',
                'MutasiSiswi',
                $mutasiSiswi->id,
                ['status' => ['after' => $approved ? 'DISETUJUI' : 'DITOLAK']],
                $request,
                'OPERASIONAL',
                $mutasiSiswi->pustu_tujuan_id ?? $mutasiSiswi->pustu_asal_id,
            );
        });

        return response()->json(['message' => $approved ? 'Keputusan mutasi berhasil disimpan.' : 'Pengajuan mutasi ditolak.']);
    }

    public function confirm(
        ConfirmMutasiSiswiRequest $request,
        MutasiSiswi $mutasiSiswi,
        ActivityLogger $logger,
    ): JsonResponse {
        abort_unless($mutasiSiswi->jenis === 'PINDAH_ANTAR_PUSTU' && $mutasiSiswi->status === 'DISETUJUI', 409, 'Mutasi belum siap dikonfirmasi.');
        $this->authorizeDestination($request->user(), $mutasiSiswi);
        $class = Kelas::query()->findOrFail($request->validated('kelas_tujuan_id'));
        abort_unless($class->sekolah_id === $mutasiSiswi->sekolah_tujuan_id, 422, 'Kelas harus berada di sekolah tujuan.');

        DB::transaction(function () use ($request, $mutasiSiswi, $class, $logger): void {
            $mutasiSiswi->update(['kelas_tujuan_id' => $class->id]);
            $this->completeMutation($mutasiSiswi);
            $mutasiSiswi->update(['status' => 'SELESAI']);
            $logger->audit(
                $request->user(), 'MUTASI_ANTAR_PUSTU_DIKONFIRMASI', 'MutasiSiswi', $mutasiSiswi->id,
                ['status' => ['after' => 'SELESAI']], $request, 'OPERASIONAL', $mutasiSiswi->pustu_tujuan_id,
            );
            $this->notifyMutation($mutasiSiswi, 'MUTASI_SELESAI', 'Mutasi selesai', 'Pustu tujuan sudah mengonfirmasi penerimaan Siswi.');
        });

        return response()->json(['message' => 'Penerimaan mutasi berhasil dikonfirmasi.']);
    }

    private function authorizeOrigin(User $user, Enrollment $enrollment): void
    {
        if ($user->role === 'ADMINISTRATOR') {
            return;
        }
        if ($user->role === 'ADMIN_PUSTU') {
            abort_unless($user->adminPustu?->pustu_id === $enrollment->pustu_id, 404);

            return;
        }
        abort_unless(
            $user->role === 'GURU'
                && $user->status === 'AKTIF'
                && $user->guru?->sekolah_id === $enrollment->sekolah_id,
            404,
        );
    }

    private function authorizeDestination(User $user, MutasiSiswi $mutation): void
    {
        if ($mutation->jenis === 'PINDAH_ANTAR_PUSTU') {
            abort_unless($user->role === 'ADMIN_PUSTU', 403);
            abort_unless($user->adminPustu?->pustu_id === $mutation->pustu_tujuan_id, 404);

            return;
        }
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);
        if ($user->role === 'ADMIN_PUSTU') {
            $expectedPustu = $mutation->jenis === 'PINDAH_ANTAR_PUSTU'
                ? $mutation->pustu_tujuan_id
                : $mutation->pustu_asal_id;
            abort_unless($user->adminPustu?->pustu_id === $expectedPustu, 404);
        }
    }

    private function completeMutation(MutasiSiswi $mutation): void
    {
        $source = Enrollment::query()->lockForUpdate()->findOrFail($mutation->enrollment_asal_id);
        if ($source->status === 'AKTIF') {
            $source->update([
                'status' => 'SELESAI',
                'tgl_keluar' => $mutation->tgl_efektif,
                'jenis_keluar' => $mutation->jenis,
                'mutasi_id' => $mutation->id,
            ]);
        }

        if ($mutation->sekolah_tujuan_id && $mutation->kelas_tujuan_id) {
            Enrollment::query()->create([
                'siswi_id' => $mutation->siswi_id,
                'sekolah_id' => $mutation->sekolah_tujuan_id,
                'kelas_id' => $mutation->kelas_tujuan_id,
                'tahun_ajaran_id' => Kelas::query()->findOrFail($mutation->kelas_tujuan_id)->tahun_ajaran_id,
                'pustu_id' => $mutation->pustu_tujuan_id,
                'tgl_masuk' => $mutation->tgl_efektif,
                'status' => 'AKTIF',
            ]);
            $mutation->siswi()->update(['status_pelacakan' => 'AKTIF']);

            return;
        }

        $trackingStatus = match ($mutation->jenis) {
            'LULUS' => 'LULUS',
            'KELUAR_WILAYAH' => 'KELUAR_WILAYAH',
            default => 'PUTUS_SEKOLAH',
        };
        $mutation->siswi()->update(['status_pelacakan' => $trackingStatus]);
    }

    private function notifyMutation(MutasiSiswi $mutation, string $type, string $title, string $message): void
    {
        DB::table('notifikasi')->insert([
            'user_id' => $mutation->siswi->user_id,
            'tipe' => $type,
            'judul' => $title,
            'isi' => $message,
            'created_at' => now(),
        ]);
    }

    private function trackingStatusAfterRejection(Enrollment $enrollment): string
    {
        if ($enrollment->status === 'AKTIF') {
            return 'AKTIF';
        }

        return match ($enrollment->jenis_keluar) {
            'LULUS' => 'LULUS',
            'KELUAR_WILAYAH' => 'KELUAR_WILAYAH',
            'PUTUS_SEKOLAH' => 'PUTUS_SEKOLAH',
            default => 'AKTIF',
        };
    }
}
