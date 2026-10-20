<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePengaturanPelaporanRequest;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PengaturanPelaporanController extends Controller
{
    private const DEFAULTS = [
        'batas_hari_validasi' => 7,
        'jeda_pengingat_hari' => 7,
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'ADMINISTRATOR' && $request->user()->status === 'AKTIF', 403);

        return response()->json(['data' => $this->values()]);
    }

    public function update(StorePengaturanPelaporanRequest $request, ActivityLogger $logger): JsonResponse
    {
        $values = $request->validated();
        DB::transaction(function () use ($request, $values, $logger): void {
            foreach ($values as $key => $value) {
                DB::table('pengaturan')->updateOrInsert(
                    ['kunci' => $key],
                    ['nilai' => (string) $value, 'diubah_oleh' => $request->user()->id, 'updated_at' => now()],
                );
            }
            $logger->audit(
                $request->user(), 'PENGATURAN_PELAPORAN_DIPERBARUI', 'Pengaturan', null,
                [
                    'batas_hari_validasi' => ['after' => $values['batas_hari_validasi']],
                    'jeda_pengingat_hari' => ['after' => $values['jeda_pengingat_hari']],
                ],
                $request,
            );
        });

        return response()->json(['data' => $this->values(), 'message' => 'Pengaturan pelaporan berhasil disimpan.']);
    }

    private function values(): array
    {
        $stored = DB::table('pengaturan')
            ->whereIn('kunci', array_keys(self::DEFAULTS))
            ->pluck('nilai', 'kunci');

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = (int) ($stored[$key] ?? $default);
        }

        return $values;
    }
}
