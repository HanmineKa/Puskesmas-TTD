<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pengingat;
use App\Models\Siswi;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PengingatSiswiController extends Controller
{
    public function __invoke(Request $request, Siswi $siswi, ActivityLogger $logger): JsonResponse
    {
        abort_unless($request->user()->role === 'GURU' && $request->user()->status === 'AKTIF', 403);
        $teacher = $request->user()->guru;
        $enrollment = $siswi->enrollmentAktif()->first();
        abort_unless($teacher && $enrollment?->sekolah_id === $teacher->sekolah_id, 404);

        $gapDays = (int) (DB::table('pengaturan')->where('kunci', 'jeda_pengingat_hari')->value('nilai') ?? 7);
        $recentReminderExists = Pengingat::query()
            ->where('jenis', 'PENGINGAT_MINUM')
            ->where('penerima_user_id', $siswi->user_id)
            ->where('dikirim_at', '>=', now()->subDays($gapDays))
            ->exists();
        abort_if($recentReminderExists, 429, 'Pengingat untuk Siswi ini baru saja dikirim.');

        DB::transaction(function () use ($request, $siswi, $enrollment, $logger): void {
            Pengingat::query()->create([
                'jenis' => 'PENGINGAT_MINUM',
                'penerima_user_id' => $siswi->user_id,
                'pengirim_user_id' => $request->user()->id,
                'laporan_id' => null,
                'dikirim_at' => now(),
            ]);
            DB::table('notifikasi')->insert([
                'user_id' => $siswi->user_id,
                'tipe' => 'PENGINGAT_MINUM',
                'judul' => 'Pengingat laporan mingguan TTD',
                'isi' => 'Guru mengingatkan untuk mengisi laporan mingguan TTD.',
                'created_at' => now(),
            ]);
            $logger->audit(
                $request->user(), 'PENGINGAT_MINUM_DIKIRIM', 'Siswi', $siswi->id,
                ['pengingat' => ['after' => true]], $request, 'OPERASIONAL', $enrollment->pustu_id,
            );
        });

        return response()->json(['message' => 'Pengingat berhasil dikirim.'], 201);
    }
}
