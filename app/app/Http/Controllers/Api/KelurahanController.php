<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKelurahanRequest;
use App\Http\Requests\UpdateKelurahanRequest;
use App\Models\Kelurahan;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KelurahanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);
        $query = Kelurahan::query()->with('kecamatan:id,nama')->withCount('pustuWilayah')->orderBy('nama');
        if ($request->user()->isAdminPustu()) {
            $query->whereHas('pustuWilayah', fn ($builder) => $builder->whereKey($request->user()->adminPustu?->pustu_id));
        }
        $query->when($request->integer('kecamatan_id'), fn ($builder, $id) => $builder->where('kecamatan_id', $id));

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function store(StoreKelurahanRequest $request, ActivityLogger $logger): JsonResponse
    {
        $kelurahan = Kelurahan::query()->create($request->validated());
        $logger->audit($request->user(), 'KELURAHAN_DIBUAT', 'Kelurahan', $kelurahan->id, ['nama' => ['after' => $kelurahan->nama]], $request, 'OPERASIONAL');

        return response()->json(['data' => $kelurahan->load('kecamatan:id,nama')], 201);
    }

    public function update(UpdateKelurahanRequest $request, Kelurahan $kelurahan, ActivityLogger $logger): JsonResponse
    {
        $before = $kelurahan->only(['kecamatan_id', 'nama', 'kode']);
        $kelurahan->update($request->validated());
        $changes = [];
        foreach ($before as $field => $value) {
            if ($value !== $kelurahan->{$field}) {
                $changes[$field] = ['before' => $value, 'after' => $kelurahan->{$field}];
            }
        }
        $logger->audit($request->user(), 'KELURAHAN_DIPERBARUI', 'Kelurahan', $kelurahan->id, $changes, $request, 'OPERASIONAL');

        return response()->json(['data' => $kelurahan->fresh()->load('kecamatan:id,nama')]);
    }
}
