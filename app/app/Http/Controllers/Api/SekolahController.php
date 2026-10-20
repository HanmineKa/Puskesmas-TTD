<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSekolahRequest;
use App\Http\Requests\UpdateSekolahRequest;
use App\Models\Pustu;
use App\Models\Sekolah;
use App\Models\SekolahPustuHistory;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SekolahController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Sekolah::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:8'],
            'kelurahan_id' => ['nullable', 'integer', 'exists:kelurahan,id'],
            'jenjang' => ['nullable', 'in:SMP,SMA,SMK'],
            'status' => ['nullable', 'in:AKTIF,NONAKTIF'],
            'pustu_id' => ['nullable', 'integer', 'exists:pustu,id'],
        ]);

        $query = Sekolah::query()->with(['kelurahan:id,nama,kecamatan_id', 'pustu:id,nama'])->orderBy('nama');
        if ($request->user()->isAdminPustu()) {
            $query->where('pustu_id', $request->user()->adminPustu?->pustu_id);
        } elseif (isset($filters['pustu_id'])) {
            $query->where('pustu_id', $filters['pustu_id']);
        }

        $query->when(isset($filters['q']), fn ($builder) => $builder->where('npsn', 'like', '%'.$filters['q'].'%'));
        $query->when(isset($filters['kelurahan_id']), fn ($builder) => $builder->where('kelurahan_id', $filters['kelurahan_id']));
        $query->when(isset($filters['jenjang']), fn ($builder) => $builder->where('jenjang', $filters['jenjang']));
        $query->when(isset($filters['status']), fn ($builder) => $builder->where('status', $filters['status']));

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function store(StoreSekolahRequest $request, ActivityLogger $logger): JsonResponse
    {
        $values = $request->safe()->only(['npsn', 'nama', 'jenjang', 'alamat', 'kelurahan_id', 'kontak']);
        $pustuId = $request->user()->isAdministrator()
            ? $request->validated('pustu_id')
            : $request->user()->adminPustu?->pustu_id;
        abort_unless($pustuId, 403);
        $this->ensureKelurahanInPustuTerritory($request->user(), (int) $pustuId, (int) $values['kelurahan_id']);

        $sekolah = DB::transaction(function () use ($request, $values, $pustuId, $logger): Sekolah {
            $sekolah = Sekolah::query()->create($values + [
                'pustu_id' => $pustuId,
                'status' => 'AKTIF',
            ]);
            SekolahPustuHistory::query()->create([
                'sekolah_id' => $sekolah->id,
                'pustu_id' => $pustuId,
                'tgl_mulai' => now()->toDateString(),
                'diubah_oleh' => $request->user()->id,
            ]);
            $logger->audit(
                $request->user(), 'SEKOLAH_DIBUAT', 'Sekolah', $sekolah->id,
                ['npsn' => ['after' => $sekolah->npsn], 'nama' => ['after' => $sekolah->nama]],
                $request, 'OPERASIONAL', $pustuId,
            );

            return $sekolah;
        });

        return response()->json(['data' => $sekolah->load(['kelurahan', 'pustu'])], 201);
    }

    public function show(Request $request, Sekolah $sekolah): JsonResponse
    {
        Gate::authorize('view', $sekolah);

        return response()->json(['data' => $sekolah->load(['kelurahan.kecamatan', 'pustu'])]);
    }

    public function update(
        UpdateSekolahRequest $request,
        Sekolah $sekolah,
        ActivityLogger $logger,
    ): JsonResponse {
        $before = $sekolah->only(['npsn', 'nama', 'jenjang', 'alamat', 'kelurahan_id', 'pustu_id', 'kontak', 'status']);
        $values = $request->safe()->only(['npsn', 'nama', 'jenjang', 'alamat', 'kelurahan_id', 'kontak', 'status']);
        $newPustuId = $request->user()->isAdministrator()
            ? (int) ($request->validated('pustu_id') ?? $sekolah->pustu_id)
            : (int) $sekolah->pustu_id;
        $newKelurahanId = (int) ($values['kelurahan_id'] ?? $sekolah->kelurahan_id);
        $this->ensureKelurahanInPustuTerritory($request->user(), $newPustuId, $newKelurahanId);
        $values['pustu_id'] = $newPustuId;

        DB::transaction(function () use ($request, $sekolah, $values, $before, $newPustuId, $logger): void {
            if ($newPustuId !== (int) $sekolah->pustu_id) {
                SekolahPustuHistory::query()
                    ->where('sekolah_id', $sekolah->id)
                    ->whereNull('tgl_selesai')
                    ->update(['tgl_selesai' => now()->toDateString()]);
                SekolahPustuHistory::query()->create([
                    'sekolah_id' => $sekolah->id,
                    'pustu_id' => $newPustuId,
                    'tgl_mulai' => now()->toDateString(),
                    'diubah_oleh' => $request->user()->id,
                ]);
            }

            $sekolah->update($values);
            $logger->audit(
                $request->user(), 'SEKOLAH_DIPERBARUI', 'Sekolah', $sekolah->id,
                $this->changes($before, $sekolah->only(array_keys($before))),
                $request, 'OPERASIONAL', $newPustuId,
            );
        });

        return response()->json(['data' => $sekolah->fresh()->load(['kelurahan', 'pustu'])]);
    }

    public function destroy(Request $request, Sekolah $sekolah, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('delete', $sekolah);
        DB::transaction(function () use ($request, $sekolah, $logger): void {
            $sekolah->update(['status' => 'NONAKTIF']);
            $logger->audit(
                $request->user(), 'SEKOLAH_DINONAKTIFKAN', 'Sekolah', $sekolah->id,
                ['status' => ['before' => 'AKTIF', 'after' => 'NONAKTIF']],
                $request, 'OPERASIONAL', $sekolah->pustu_id,
            );
        });

        return response()->json(['message' => 'Sekolah berhasil dinonaktifkan.']);
    }

    private function ensureKelurahanInPustuTerritory(User $user, int $pustuId, int $kelurahanId): void
    {
        if ($user->isAdministrator()) {
            return;
        }

        abort_unless(
            Pustu::query()->whereKey($pustuId)
                ->whereHas('kelurahanWilayah', fn ($query) => $query->whereKey($kelurahanId))
                ->exists(),
            422,
            'Kelurahan sekolah harus termasuk wilayah kerja Pustu Anda.',
        );
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $key => $value) {
            if ($value !== $after[$key]) {
                $changes[$key] = ['before' => $value, 'after' => $after[$key]];
            }
        }

        return $changes;
    }
}
