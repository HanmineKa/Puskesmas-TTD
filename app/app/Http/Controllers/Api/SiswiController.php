<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterSiswiRequest;
use App\Models\Enrollment;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MutasiSiswi;
use App\Models\Siswi;
use App\Models\TokenSekolah;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiswiController extends Controller
{
    public function profile(Request $request, ActivityLogger $logger): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'SISWI', 403);
        $siswi = $user->siswi()->with($this->enrollmentRelationsFor($user))->firstOrFail();
        $logger->dataAccess($user, 'BUKA_DETAIL_SISWI', 'Siswi', $siswi->id, $request);

        return response()->json(['data' => $this->serialize($siswi, false)]);
    }

    public function register(
        RegisterSiswiRequest $request,
        BlindIndex $blindIndex,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $token = TokenSekolah::query()->with('sekolah', 'tahunAjaran')
            ->where('kode', $values['kode_token'])
            ->where('aktif', true)
            ->first();
        if (! $token || $token->sekolah->status !== 'AKTIF' || ! $token->tahunAjaran->aktif) {
            throw ValidationException::withMessages(['kode_token' => 'Token sekolah tidak berlaku.']);
        }

        $kelas = Kelas::query()->findOrFail($values['kelas_id']);
        if ($kelas->sekolah_id !== $token->sekolah_id || $kelas->tahun_ajaran_id !== $token->tahun_ajaran_id) {
            throw ValidationException::withMessages(['kelas_id' => 'Kelas tidak sesuai dengan sekolah dan tahun ajaran pada token.']);
        }

        $nisnHash = $blindIndex->hash($values['nisn']);
        $existingSiswi = Siswi::query()->where('nisn_hash', $nisnHash)->first();
        if ($existingSiswi) {
            return $this->createTransferRequest($request, $existingSiswi, $kelas, $token, $logger);
        }

        $siswi = DB::transaction(function () use ($request, $values, $nisnHash, $kelas, $token, $logger): Siswi {
            $user = User::query()->create([
                'role' => 'SISWI',
                'login_hash' => app(BlindIndex::class)->hash('NISN:'.$values['nisn']),
                'password' => $values['password'],
                'status' => 'AKTIF',
                'wajib_ganti_sandi' => false,
            ]);
            $siswi = Siswi::query()->create([
                'user_id' => $user->id,
                'nisn' => $values['nisn'],
                'nisn_hash' => $nisnHash,
                'nama' => $values['nama'],
                'status_pelacakan' => 'AKTIF',
                'persetujuan_data_at' => now(),
                'versi_persetujuan' => $values['versi_persetujuan'],
            ]);
            Enrollment::query()->create([
                'siswi_id' => $siswi->id,
                'sekolah_id' => $kelas->sekolah_id,
                'kelas_id' => $kelas->id,
                'tahun_ajaran_id' => $kelas->tahun_ajaran_id,
                'pustu_id' => $token->sekolah->pustu_id,
                'tgl_masuk' => now()->toDateString(),
                'status' => 'AKTIF',
            ]);
            $logger->audit(null, 'SISWI_MENDAFTAR', 'Siswi', $siswi->id, ['akun' => ['after' => 'AKTIF']], $request, 'OPERASIONAL', $token->sekolah->pustu_id);

            return $siswi;
        });

        return response()->json([
            'data' => ['id' => $siswi->id, 'status' => $siswi->status_pelacakan],
            'message' => 'Pendaftaran berhasil. Akun Siswi sudah aktif.',
        ], 201);
    }

    public function index(Request $request, BlindIndex $blindIndex): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);
        $filters = $request->validate([
            'nisn' => ['nullable', 'digits:10'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
            'sekolah_id' => ['nullable', 'integer', 'exists:sekolah,id'],
            'kelurahan_id' => ['nullable', 'integer', 'exists:kelurahan,id'],
            'status' => ['nullable', 'in:AKTIF,DALAM_MUTASI,LULUS,KELUAR_WILAYAH,PUTUS_SEKOLAH'],
        ]);
        $query = Siswi::query()->with($this->enrollmentRelationsFor($user));
        $this->scopeSiswiQuery($query, $user);
        $query->when(isset($filters['nisn']), fn (Builder $builder) => $builder->where('nisn_hash', $blindIndex->hash($filters['nisn'])));
        $query->when(isset($filters['kelas_id']), fn (Builder $builder) => $builder->whereHas('enrollments', fn (Builder $enrollment) => $enrollment->where('kelas_id', $filters['kelas_id'])));
        $query->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status_pelacakan', $filters['status']));
        $query->when(isset($filters['sekolah_id']), fn (Builder $builder) => $builder->whereHas('enrollments', fn (Builder $enrollment) => $enrollment->where('sekolah_id', $filters['sekolah_id'])));
        $query->when(isset($filters['kelurahan_id']), fn (Builder $builder) => $builder->whereHas('enrollments.sekolah', fn (Builder $school) => $school->where('kelurahan_id', $filters['kelurahan_id'])));

        return response()->json([
            'data' => $query->orderBy('id')->paginate(25)->through(fn (Siswi $siswi): array => $this->serialize($siswi, true)),
        ]);
    }

    public function show(Request $request, Siswi $siswi, ActivityLogger $logger): JsonResponse
    {
        $this->authorizeSiswiScope($request->user(), $siswi);
        $siswi->load([...$this->enrollmentRelationsFor($request->user(), true), 'user:id,status']);
        $logger->dataAccess($request->user(), 'BUKA_DETAIL_SISWI', 'Siswi', $siswi->id, $request);

        return response()->json(['data' => $this->serialize($siswi, false)]);
    }

    public function changeClass(Request $request, Enrollment $enrollment, ActivityLogger $logger): JsonResponse
    {
        $guru = $this->activeTeacher($request->user());
        $values = $request->validate(['kelas_id' => ['required', 'integer', 'exists:kelas,id']]);
        abort_unless($enrollment->status === 'AKTIF' && $enrollment->sekolah_id === $guru->sekolah_id, 404);
        $kelas = Kelas::query()->findOrFail($values['kelas_id']);
        abort_unless($kelas->sekolah_id === $enrollment->sekolah_id && $kelas->tahun_ajaran_id === $enrollment->tahun_ajaran_id, 422, 'Kelas harus berada di sekolah dan tahun ajaran yang sama.');

        $oldClass = $enrollment->kelas_id;
        $enrollment->update(['kelas_id' => $kelas->id]);
        $logger->audit(
            $request->user(),
            'SISWI_PINDAH_KELAS',
            'Enrollment',
            $enrollment->id,
            ['kelas_id' => ['before' => $oldClass, 'after' => $kelas->id]],
            $request,
            'OPERASIONAL',
            $enrollment->pustu_id,
        );

        return response()->json(['message' => 'Kelas Siswi berhasil diperbarui.']);
    }

    public function classes(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);

        $query = Kelas::query()->with('sekolah:id,nama,pustu_id');
        if ($user->role === 'ADMIN_PUSTU') {
            $pustuId = $user->adminPustu?->pustu_id;
            abort_unless($pustuId, 403);
            $query->whereHas('sekolah', fn (Builder $school) => $school->where('pustu_id', $pustuId));
        } elseif ($user->role === 'GURU') {
            $query->where('sekolah_id', $this->activeTeacher($user)->sekolah_id);
        }

        return response()->json([
            'data' => $query->orderBy('sekolah_id')->orderBy('tingkat')->orderBy('nama')
                ->get(['id', 'sekolah_id', 'nama', 'tingkat']),
        ]);
    }

    private function createTransferRequest(
        RegisterSiswiRequest $request,
        Siswi $siswi,
        Kelas $kelas,
        TokenSekolah $token,
        ActivityLogger $logger,
    ): JsonResponse {
        $source = $siswi->enrollmentAktif()->with('sekolah')->first()
            ?? $siswi->enrollments()->with('sekolah')->latest('tgl_keluar')->first();
        if (! $source) {
            throw ValidationException::withMessages(['nisn' => 'NISN sudah terdaftar. Hubungi petugas untuk bantuan perpindahan sekolah.']);
        }
        if ($source->sekolah_id === $kelas->sekolah_id) {
            throw ValidationException::withMessages(['nisn' => 'NISN sudah terdaftar di sekolah ini. Silakan masuk menggunakan akun yang ada.']);
        }
        if (MutasiSiswi::query()->where('siswi_id', $siswi->id)->whereIn('status', ['PENDING', 'DISETUJUI'])->exists()) {
            throw ValidationException::withMessages(['nisn' => 'Pengajuan mutasi untuk NISN ini masih diproses.']);
        }

        $changesLevel = $source->sekolah->jenjang !== $token->sekolah->jenjang;
        if ($changesLevel
            && ($source->sekolah->jenjang !== 'SMP' || ! in_array($token->sekolah->jenjang, ['SMA', 'SMK'], true))) {
            throw ValidationException::withMessages(['kode_token' => 'Mutasi naik jenjang hanya berlaku dari SMP ke SMA atau SMK.']);
        }
        $type = $changesLevel
            ? 'NAIK_JENJANG'
            : ($source->pustu_id === $token->sekolah->pustu_id ? 'PINDAH_SEKOLAH_SATU_PUSTU' : 'PINDAH_ANTAR_PUSTU');
        $mutation = DB::transaction(function () use ($request, $siswi, $source, $kelas, $token, $type, $logger): MutasiSiswi {
            $mutation = MutasiSiswi::query()->create([
                'siswi_id' => $siswi->id,
                'jenis' => $type,
                'enrollment_asal_id' => $source->id,
                'sekolah_asal_id' => $source->sekolah_id,
                'pustu_asal_id' => $source->pustu_id,
                'sekolah_tujuan_id' => $token->sekolah_id,
                'pustu_tujuan_id' => $token->sekolah->pustu_id,
                'kelas_tujuan_id' => $kelas->id,
                'status' => 'PENDING',
                'tgl_efektif' => now()->toDateString(),
                'diajukan_oleh' => $siswi->user_id,
            ]);
            $siswi->update(['status_pelacakan' => 'DALAM_MUTASI']);
            $logger->audit(null, 'MUTASI_SISWI_DIAJUKAN', 'MutasiSiswi', $mutation->id, ['status' => ['after' => 'PENDING']], $request, 'OPERASIONAL', $source->pustu_id);

            return $mutation;
        });

        return response()->json([
            'data' => ['id' => $mutation->id, 'status' => $mutation->status],
            'message' => 'NISN sudah terdaftar. Pengajuan perpindahan telah dikirim untuk diverifikasi.',
        ], 202);
    }

    private function scopeSiswiQuery(Builder $query, User $user): void
    {
        if ($user->role === 'ADMINISTRATOR') {
            return;
        }
        if ($user->role === 'ADMIN_PUSTU') {
            $pustuId = $user->adminPustu?->pustu_id;
            abort_unless($pustuId, 403);
            $query->whereHas('enrollments', fn (Builder $enrollment) => $enrollment->where('pustu_id', $pustuId));

            return;
        }

        $guru = $this->activeTeacher($user);
        $query->whereHas('enrollments', fn (Builder $enrollment) => $enrollment
            ->where('sekolah_id', $guru->sekolah_id)->where('status', 'AKTIF'));
    }

    private function enrollmentRelationsFor(User $user, bool $includeDistrict = false): array
    {
        $schoolRelations = $includeDistrict ? 'sekolah.kelurahan.kecamatan' : 'sekolah.kelurahan';
        if ($user->role === 'ADMIN_PUSTU') {
            $pustuId = $user->adminPustu?->pustu_id;

            return [
                'enrollments' => fn ($query) => $query->where('pustu_id', $pustuId),
                'enrollments.'.$schoolRelations,
                'enrollments.kelas',
            ];
        }
        if ($user->role === 'GURU') {
            $schoolId = $user->guru?->sekolah_id;

            return [
                'enrollments' => fn ($query) => $query->where('sekolah_id', $schoolId)->where('status', 'AKTIF'),
                'enrollments.'.$schoolRelations,
                'enrollments.kelas',
            ];
        }

        return ['enrollments.'.$schoolRelations, 'enrollments.kelas'];
    }

    private function authorizeSiswiScope(User $user, Siswi $siswi): void
    {
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);
        if ($user->role === 'ADMINISTRATOR') {
            return;
        }
        if ($user->role === 'ADMIN_PUSTU') {
            abort_unless($siswi->enrollments()->where('pustu_id', $user->adminPustu?->pustu_id)->exists(), 404);

            return;
        }
        $guru = $this->activeTeacher($user);
        abort_unless($siswi->enrollmentAktif()->where('sekolah_id', $guru->sekolah_id)->exists(), 404);
    }

    private function activeTeacher(User $user): Guru
    {
        abort_unless($user->role === 'GURU' && $user->status === 'AKTIF', 403);

        return $user->guru()->firstOrFail();
    }

    private function serialize(Siswi $siswi, bool $maskIdentity): array
    {
        $identity = $siswi->nisn;

        return [
            'id' => $siswi->id,
            'nama' => $siswi->nama,
            'nisn' => $maskIdentity ? str_repeat('•', 6).mb_substr($identity, -4) : $identity,
            'status_pelacakan' => $siswi->status_pelacakan,
            'enrollments' => $siswi->enrollments->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'sekolah' => $enrollment->sekolah?->nama,
                'kelurahan' => $enrollment->sekolah?->kelurahan?->nama,
                'kelas' => $enrollment->kelas?->nama,
                'kelas_id' => $enrollment->kelas_id,
                'tahun_ajaran_id' => $enrollment->tahun_ajaran_id,
                'pustu_id' => $enrollment->pustu_id,
            ])->values(),
        ];
    }
}
