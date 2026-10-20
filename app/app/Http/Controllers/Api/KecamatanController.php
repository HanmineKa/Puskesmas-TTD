<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKecamatanRequest;
use App\Http\Requests\UpdateKecamatanRequest;
use App\Models\Kecamatan;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KecamatanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);

        return response()->json([
            'data' => Kecamatan::query()->withCount('kelurahan')->orderBy('nama')->paginate(50),
        ]);
    }

    public function store(StoreKecamatanRequest $request, ActivityLogger $logger): JsonResponse
    {
        $kecamatan = Kecamatan::query()->create($request->validated());
        $logger->audit($request->user(), 'KECAMATAN_DIBUAT', 'Kecamatan', $kecamatan->id, ['nama' => ['after' => $kecamatan->nama]], $request, 'OPERASIONAL');

        return response()->json(['data' => $kecamatan], 201);
    }

    public function update(UpdateKecamatanRequest $request, Kecamatan $kecamatan, ActivityLogger $logger): JsonResponse
    {
        $before = $kecamatan->only(['nama', 'kode']);
        $kecamatan->update($request->validated());
        $changes = [];
        foreach ($before as $field => $value) {
            if ($value !== $kecamatan->{$field}) {
                $changes[$field] = ['before' => $value, 'after' => $kecamatan->{$field}];
            }
        }
        $logger->audit($request->user(), 'KECAMATAN_DIPERBARUI', 'Kecamatan', $kecamatan->id, $changes, $request, 'OPERASIONAL');

        return response()->json(['data' => $kecamatan->fresh()->loadCount('kelurahan')]);
    }
}
