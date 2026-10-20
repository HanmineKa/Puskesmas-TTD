<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterGuruRequest;
use App\Http\Requests\StoreKelasRequest;
use App\Http\Requests\StoreTokenSekolahRequest;
use App\Http\Requests\VerifyGuruRequest;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Sekolah;
use App\Models\TahunAjaran;
use App\Models\TokenSekolah;
use App\Models\User;
use App\Services\Files\PrivateDocumentStorage;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GuruController extends Controller
{
    public function registrableSchools(): JsonResponse
    {
        return response()->json([
            'data' => Sekolah::query()->aktif()->orderBy('nama')->get(['id', 'npsn', 'nama', 'jenjang']),
        ]);
    }

    public function registrationOptions(string $code): JsonResponse
    {
        $token = TokenSekolah::query()->with('sekolah:id,nama,jenjang', 'tahunAjaran:id,label')
            ->where('kode', $code)->where('aktif', true)->first();
        if (! $token || $token->sekolah->status !== 'AKTIF' || ! $token->tahunAjaran->aktif) {
            throw ValidationException::withMessages(['kode_token' => 'Token sekolah tidak berlaku.']);
        }

        return response()->json([
            'sekolah' => $token->sekolah,
            'tahun_ajaran' => $token->tahunAjaran->label,
            'kelas' => Kelas::query()->where('sekolah_id', $token->sekolah_id)
                ->where('tahun_ajaran_id', $token->tahun_ajaran_id)->orderBy('tingkat')->orderBy('nama')
                ->get(['id', 'nama', 'tingkat']),
        ]);
    }

    public function register(
        RegisterGuruRequest $request,
        BlindIndex $blindIndex,
        PrivateDocumentStorage $documents,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $documentPath = $documents->store($values['dokumen_sk']);

        try {
            $guru = DB::transaction(function () use ($request, $values, $documentPath, $blindIndex, $logger): Guru {
                $identity = $values['nuptk'];
                $user = User::query()->create([
                    'role' => 'GURU',
                    'login_hash' => $blindIndex->hash('NUPTK:'.$identity),
                    'password' => $values['password'],
                    'status' => 'MENUNGGU_VERIFIKASI',
                    'wajib_ganti_sandi' => false,
                ]);
                $guru = Guru::query()->create([
                    'user_id' => $user->id,
                    'sekolah_id' => $values['sekolah_id'],
                    'nama' => $values['nama'],
                    'nuptk' => $identity,
                    'nuptk_hash' => $blindIndex->hash($identity),
                    'dokumen_sk' => $documentPath,
                ]);
                $logger->audit(
                    null,
                    'GURU_MENDAFTAR',
                    'Guru',
                    $guru->id,
                    ['akun' => ['after' => 'MENUNGGU_VERIFIKASI']],
                    $request,
                    'OPERASIONAL',
                    $guru->sekolah->pustu_id,
                );

                return $guru;
            });
        } catch (Throwable $exception) {
            $documents->delete($documentPath);
            throw $exception;
        }

        return response()->json([
            'data' => $this->serialize($guru->load(['sekolah:id,nama', 'user:id,role,status'])),
            'message' => 'Pendaftaran berhasil. Akun menunggu verifikasi petugas.',
        ], 201);
    }

    public function index(Request $request, BlindIndex $blindIndex): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);
        $filters = $request->validate([
            'nuptk' => ['nullable', 'digits:16'],
            'sekolah_id' => ['nullable', 'integer', 'exists:sekolah,id'],
            'kelurahan_id' => ['nullable', 'integer', 'exists:kelurahan,id'],
            'status' => ['nullable', 'in:AKTIF,MENUNGGU_VERIFIKASI,DITOLAK,NONAKTIF'],
        ]);
        $query = Guru::query()->with(['sekolah:id,nama,jenjang,kelurahan_id,pustu_id', 'sekolah.kelurahan:id,nama', 'user:id,status']);
        $this->scopeGuruQuery($query, $request->user());
        $query->when(isset($filters['nuptk']), fn (Builder $builder) => $builder->where('nuptk_hash', $blindIndex->hash($filters['nuptk'])));
        $query->when(isset($filters['sekolah_id']), fn (Builder $builder) => $builder->where('sekolah_id', $filters['sekolah_id']));
        $query->when(isset($filters['kelurahan_id']), fn (Builder $builder) => $builder->whereHas('sekolah', fn (Builder $school) => $school->where('kelurahan_id', $filters['kelurahan_id'])));
        $query->when(isset($filters['status']), fn (Builder $builder) => $builder->whereHas('user', fn (Builder $user) => $user->where('status', $filters['status'])));

        return response()->json(['data' => $query->orderBy('nama')->paginate(25)->through(fn (Guru $guru): array => $this->serialize($guru, true))]);
    }

    public function show(Request $request, Guru $guru, ActivityLogger $logger): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);
        $this->authorizeGuruScope($request->user(), $guru);
        $guru->load(['sekolah.kelurahan.kecamatan', 'user:id,role,status']);
        $logger->dataAccess($request->user(), 'BUKA_DETAIL_GURU', 'Guru', $guru->id, $request);

        return response()->json(['data' => $this->serialize($guru, false)]);
    }

    public function document(
        Request $request,
        Guru $guru,
        PrivateDocumentStorage $documents,
        ActivityLogger $logger,
    ): Response {
        $this->authorizeGuruScope($request->user(), $guru);
        abort_unless($guru->dokumen_sk, 404);
        $logger->dataAccess($request->user(), 'BUKA_DETAIL_GURU', 'Guru', $guru->id, $request);
        $extension = pathinfo(str_replace('.enc', '', $guru->dokumen_sk), PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return response($documents->retrieve($guru->dokumen_sk))
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="surat-tugas.'.$extension.'"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function verify(
        VerifyGuruRequest $request,
        Guru $guru,
        ActivityLogger $logger,
    ): JsonResponse {
        $this->authorizeGuruScope($request->user(), $guru);
        abort_unless($guru->user->status === 'MENUNGGU_VERIFIKASI', 409, 'Pendaftaran Guru sudah diputuskan.');
        $decision = $request->validated('keputusan');
        $approved = $decision === 'DISETUJUI';

        DB::transaction(function () use ($request, $guru, $approved, $logger): void {
            $guru->user()->update(['status' => $approved ? 'AKTIF' : 'DITOLAK']);
            $guru->update([
                'diverifikasi_oleh' => $request->user()->id,
                'tgl_verifikasi' => now(),
                'alasan_penolakan' => $approved ? null : $request->validated('alasan_penolakan'),
            ]);
            $logger->audit(
                $request->user(),
                $approved ? 'GURU_DISETUJUI' : 'GURU_DITOLAK',
                'Guru',
                $guru->id,
                ['status' => ['after' => $approved ? 'AKTIF' : 'DITOLAK']],
                $request,
                'OPERASIONAL',
                $guru->sekolah->pustu_id,
            );
            DB::table('notifikasi')->insert([
                'user_id' => $guru->user_id,
                'tipe' => $approved ? 'GURU_DISETUJUI' : 'GURU_DITOLAK',
                'judul' => $approved ? 'Akun Guru disetujui' : 'Pendaftaran Guru ditolak',
                'isi' => $approved ? 'Akun Guru Anda sudah aktif.' : 'Pendaftaran Guru belum dapat disetujui. Periksa kembali dokumen dan hubungi petugas.',
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => $approved ? 'Akun Guru berhasil disetujui.' : 'Pendaftaran Guru ditolak.']);
    }

    public function kelas(Request $request): JsonResponse
    {
        $guru = $this->activeTeacher($request->user());
        $year = TahunAjaran::query()->where('aktif', true)->first();

        return response()->json([
            'data' => $year
                ? Kelas::query()->where('sekolah_id', $guru->sekolah_id)->where('tahun_ajaran_id', $year->id)->orderBy('tingkat')->orderBy('nama')->get()
                : [],
        ]);
    }

    public function storeKelas(StoreKelasRequest $request, ActivityLogger $logger): JsonResponse
    {
        $guru = $this->activeTeacher($request->user());
        $year = TahunAjaran::query()->where('aktif', true)->firstOrFail();
        $kelas = Kelas::query()->create([
            'sekolah_id' => $guru->sekolah_id,
            'tahun_ajaran_id' => $year->id,
            ...$request->validated(),
        ]);
        $logger->audit($request->user(), 'KELAS_DIBUAT', 'Kelas', $kelas->id, ['nama' => ['after' => $kelas->nama]], $request, 'OPERASIONAL', $guru->sekolah->pustu_id);

        return response()->json(['data' => $kelas], 201);
    }

    public function tokenSekolah(Request $request): JsonResponse
    {
        $guru = $this->activeTeacher($request->user());

        return response()->json([
            'data' => TokenSekolah::query()->with('tahunAjaran:id,label')
                ->where('sekolah_id', $guru->sekolah_id)
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function storeTokenSekolah(
        StoreTokenSekolahRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $guru = $this->activeTeacher($request->user());
        $year = TahunAjaran::query()->findOrFail($request->validated('tahun_ajaran_id'));

        $token = DB::transaction(function () use ($request, $guru, $year, $logger): TokenSekolah {
            TokenSekolah::query()
                ->where('sekolah_id', $guru->sekolah_id)
                ->where('tahun_ajaran_id', $year->id)
                ->where('aktif', true)
                ->update(['aktif' => false]);

            $token = TokenSekolah::query()->create([
                'sekolah_id' => $guru->sekolah_id,
                'tahun_ajaran_id' => $year->id,
                'kode' => Str::upper(Str::random(12)),
                'aktif' => true,
                'dibuat_oleh' => $request->user()->id,
            ]);
            $logger->audit($request->user(), 'TOKEN_SEKOLAH_DIBUAT', 'TokenSekolah', $token->id, ['kode' => ['berubah' => true]], $request, 'OPERASIONAL', $guru->sekolah->pustu_id);

            return $token;
        });

        return response()->json(['data' => $token->load('tahunAjaran:id,label')], 201);
    }

    public function deactivateToken(Request $request, TokenSekolah $tokenSekolah, ActivityLogger $logger): JsonResponse
    {
        $guru = $this->activeTeacher($request->user());
        abort_unless($tokenSekolah->sekolah_id === $guru->sekolah_id, 404);
        abort_unless($tokenSekolah->aktif, 409, 'Token sudah tidak aktif.');
        $tokenSekolah->update(['aktif' => false]);
        $logger->audit(
            $request->user(), 'TOKEN_SEKOLAH_DINONAKTIFKAN', 'TokenSekolah', $tokenSekolah->id,
            ['kode' => ['berubah' => true]], $request, 'OPERASIONAL', $guru->sekolah->pustu_id,
        );

        return response()->json(['message' => 'Token sekolah berhasil dinonaktifkan.']);
    }

    private function activeTeacher(User $user): Guru
    {
        abort_unless($user->role === 'GURU' && $user->status === 'AKTIF', 403);

        return $user->guru()->with('sekolah')->firstOrFail();
    }

    private function authorizeGuruScope(User $user, Guru $guru): void
    {
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);
        if ($user->role === 'ADMIN_PUSTU') {
            abort_unless($user->adminPustu?->pustu_id === $guru->sekolah->pustu_id, 404);
        }
    }

    private function scopeGuruQuery(Builder $query, User $user): void
    {
        if ($user->role === 'ADMIN_PUSTU') {
            $pustuId = $user->adminPustu?->pustu_id;
            abort_unless($pustuId, 403);
            $query->whereHas('sekolah', fn (Builder $school) => $school->where('pustu_id', $pustuId));
        } else {
            abort_unless($user->isAdministrator(), 403);
        }
    }

    private function serialize(Guru $guru, bool $maskIdentity = true): array
    {
        $identity = $guru->nuptk;

        return [
            'id' => $guru->id,
            'nama' => $guru->nama,
            'nuptk' => $maskIdentity ? str_repeat('•', 12).mb_substr($identity, -4) : $identity,
            'sekolah' => $guru->sekolah ? [
                'id' => $guru->sekolah->id,
                'nama' => $guru->sekolah->nama,
                'jenjang' => $guru->sekolah->jenjang,
                'kelurahan' => $guru->sekolah->kelurahan?->nama,
                'pustu_id' => $guru->sekolah->pustu_id,
            ] : null,
            'user' => ['role' => 'GURU', 'status' => $guru->user?->status],
            'tgl_verifikasi' => $guru->tgl_verifikasi?->toISOString(),
            'alasan_penolakan' => $guru->alasan_penolakan,
        ];
    }
}
