<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePengecualianMedisRequest;
use App\Models\PengecualianMedis;
use App\Models\Siswi;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PengecualianMedisController extends Controller
{
    public function store(StorePengecualianMedisRequest $request, ActivityLogger $logger): JsonResponse
    {
        $teacher = $request->user()->guru;
        abort_unless($teacher, 403);
        $student = Siswi::query()->with('enrollmentAktif')->findOrFail($request->validated('siswi_id'));
        abort_unless($student->enrollmentAktif?->sekolah_id === $teacher->sekolah_id, 404);

        $exception = DB::transaction(function () use ($request, $student, $logger): PengecualianMedis {
            $exception = PengecualianMedis::query()->create([
                'siswi_id' => $student->id,
                'detail' => $request->validated('detail'),
                'ditandai_oleh' => $request->user()->id,
                'tgl_mulai' => $request->validated('tgl_mulai'),
                'tgl_selesai' => $request->validated('tgl_selesai'),
            ]);
            $logger->audit(
                $request->user(), 'PENGECUALIAN_MEDIS_DITANDAI', 'PengecualianMedis', $exception->id,
                ['detail' => ['berubah' => true], 'tgl_mulai' => ['after' => $exception->tgl_mulai->toDateString()]],
                $request, 'OPERASIONAL', $student->enrollmentAktif->pustu_id,
            );

            return $exception;
        });

        return response()->json([
            'data' => ['id' => $exception->id, 'tgl_mulai' => $exception->tgl_mulai->toDateString()],
            'message' => 'Pengecualian medis berhasil dicatat.',
        ], 201);
    }
}
