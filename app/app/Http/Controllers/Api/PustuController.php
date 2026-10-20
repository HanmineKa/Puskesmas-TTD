<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePustuRequest;
use App\Http\Requests\UpdatePustuRequest;
use App\Models\Pustu;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PustuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Pustu::class);
        $query = Pustu::query()->withCount('sekolah')->orderBy('nama');

        if ($request->user()->isAdminPustu()) {
            $query->whereKey($request->user()->adminPustu?->pustu_id);
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function store(StorePustuRequest $request, ActivityLogger $logger): JsonResponse
    {
        $values = $request->safe()->only(['nama', 'alamat', 'kontak', 'aktif']);
        $pustu = DB::transaction(function () use ($request, $values, $logger): Pustu {
            $puskesmasId = DB::table('puskesmas_utama')->value('id');
            abort_unless($puskesmasId, 409, 'Data Puskesmas utama belum tersedia.');

            $pustu = Pustu::query()->create($values + ['puskesmas_utama_id' => $puskesmasId]);
            $pustu->kelurahanWilayah()->sync($request->validated('wilayah_kelurahan_ids', []));
            $logger->audit(
                $request->user(), 'PUSTU_DIBUAT', 'Pustu', $pustu->id,
                ['nama' => ['after' => $pustu->nama]], $request, 'KEAMANAN', $pustu->id,
            );

            return $pustu;
        });

        return response()->json(['data' => $pustu->load('kelurahanWilayah')], 201);
    }

    public function show(Pustu $pustu): JsonResponse
    {
        Gate::authorize('view', $pustu);

        return response()->json(['data' => $pustu->load('kelurahanWilayah')->loadCount('sekolah')]);
    }

    public function update(UpdatePustuRequest $request, Pustu $pustu, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('update', $pustu);
        $before = $pustu->only(['nama', 'alamat', 'kontak', 'aktif']);
        $values = $request->safe()->only(['nama', 'alamat', 'kontak', 'aktif']);

        DB::transaction(function () use ($request, $pustu, $values, $before, $logger): void {
            $pustu->update($values);
            if ($request->exists('wilayah_kelurahan_ids')) {
                $pustu->kelurahanWilayah()->sync($request->validated('wilayah_kelurahan_ids', []));
            }

            $logger->audit(
                $request->user(), 'PUSTU_DIPERBARUI', 'Pustu', $pustu->id,
                $this->changes($before, $pustu->only(array_keys($before))), $request, 'KEAMANAN', $pustu->id,
            );
        });

        return response()->json(['data' => $pustu->fresh()->load('kelurahanWilayah')]);
    }

    public function destroy(Request $request, Pustu $pustu, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('delete', $pustu);
        DB::transaction(function () use ($request, $pustu, $logger): void {
            $pustu->update(['aktif' => false]);
            $logger->audit(
                $request->user(), 'PUSTU_DINONAKTIFKAN', 'Pustu', $pustu->id,
                ['aktif' => ['before' => true, 'after' => false]], $request, 'KEAMANAN', $pustu->id,
            );
        });

        return response()->json(['message' => 'Pustu berhasil dinonaktifkan.']);
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
