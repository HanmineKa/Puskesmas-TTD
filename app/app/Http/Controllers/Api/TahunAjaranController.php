<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTahunAjaranRequest;
use App\Http\Requests\UpdateTahunAjaranRequest;
use App\Models\TahunAjaran;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TahunAjaranController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true), 403);

        return response()->json([
            'data' => TahunAjaran::query()->orderByDesc('tgl_mulai')->paginate(25),
        ]);
    }

    public function store(StoreTahunAjaranRequest $request, ActivityLogger $logger): JsonResponse
    {
        $values = $request->validated();
        $activate = $values['aktif'] ?? false;
        $values['aktif'] = false;

        $tahunAjaran = DB::transaction(function () use ($request, $values, $activate, $logger): TahunAjaran {
            $tahunAjaran = TahunAjaran::query()->create($values);

            if ($activate) {
                $this->activate($tahunAjaran);
            }

            $logger->audit(
                $request->user(), 'TAHUN_AJARAN_DIBUAT', 'TahunAjaran', $tahunAjaran->id,
                ['label' => ['after' => $tahunAjaran->label]], $request, 'KEAMANAN',
            );

            return $tahunAjaran->fresh();
        });

        return response()->json(['data' => $tahunAjaran], 201);
    }

    public function update(
        UpdateTahunAjaranRequest $request,
        TahunAjaran $tahunAjaran,
        ActivityLogger $logger,
    ): JsonResponse {
        $before = $tahunAjaran->only(['label', 'tgl_mulai', 'tgl_selesai', 'aktif']);
        $values = $request->validated();
        $activate = $values['aktif'] ?? false;
        unset($values['aktif']);

        DB::transaction(function () use ($request, $tahunAjaran, $values, $activate, $before, $logger): void {
            $tahunAjaran->update($values);
            if ($activate) {
                $this->activate($tahunAjaran);
            } elseif (array_key_exists('aktif', $request->validated())) {
                $tahunAjaran->update(['aktif' => false]);
            }

            $logger->audit(
                $request->user(), 'TAHUN_AJARAN_DIPERBARUI', 'TahunAjaran', $tahunAjaran->id,
                $this->changes($before, $tahunAjaran->fresh()->only(array_keys($before))), $request, 'KEAMANAN',
            );
        });

        return response()->json(['data' => $tahunAjaran->fresh()]);
    }

    private function activate(TahunAjaran $tahunAjaran): void
    {
        $puskesmas = DB::table('puskesmas_utama')->orderBy('id')->lockForUpdate()->first();
        abort_unless($puskesmas, 409, 'Data Puskesmas utama belum tersedia.');

        TahunAjaran::query()->whereKeyNot($tahunAjaran->id)->where('aktif', true)->update(['aktif' => false]);
        $tahunAjaran->update(['aktif' => true]);
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
