<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminPustuController;
use App\Http\Controllers\Api\AlasanTidakMinumController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GuruController;
use App\Http\Controllers\Api\KecamatanController;
use App\Http\Controllers\Api\KelurahanController;
use App\Http\Controllers\Api\LaporanMingguanController;
use App\Http\Controllers\Api\LaporanRekapController;
use App\Http\Controllers\Api\LoginHistoryController;
use App\Http\Controllers\Api\MutasiSiswiController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PengaturanPelaporanController;
use App\Http\Controllers\Api\PengecualianMedisController;
use App\Http\Controllers\Api\PengingatSiswiController;
use App\Http\Controllers\Api\PustuController;
use App\Http\Controllers\Api\RantaiPasokController;
use App\Http\Controllers\Api\SekolahController;
use App\Http\Controllers\Api\SiswiController;
use App\Http\Controllers\Api\TahunAjaranController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('/sekolah-terdaftar', [GuruController::class, 'registrableSchools']);
Route::get('/token-sekolah/{code}/kelas', [GuruController::class, 'registrationOptions'])->middleware('throttle:10,1');
Route::post('/guru/registrasi', [GuruController::class, 'register'])->middleware('throttle:5,1');
Route::post('/siswi/registrasi', [SiswiController::class, 'register'])->middleware('throttle:5,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::middleware('auth:sanctum')->prefix('account')->group(function () {
    Route::post('/change-temporary-password', [OnboardingController::class, 'changeTemporaryPassword']);
});
Route::get('/session', function (Request $request) {
    $user = $request->user();
    $setupRequired = $user?->wajib_ganti_sandi ? ['GANTI_SANDI'] : [];

    return response()->json([
        'user' => $user ? [...$user->only(['id', 'role', 'username', 'status', 'wajib_ganti_sandi']), 'nama_pengguna' => $user->namaPengguna()] : null,
        'requires_account_setup' => $setupRequired !== [],
        'setup_required' => $setupRequired,
    ]);
});
Route::middleware(['auth:sanctum', 'account.setup'])->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/notifikasi', function (Request $request) {
        return response()->json([
            'data' => DB::table('notifikasi')->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->limit(10)->get(),
        ]);
    });
    Route::patch('/notifikasi/{notifikasiId}/dibaca', function (Request $request, int $notifikasiId) {
        $updated = DB::table('notifikasi')
            ->where('user_id', $request->user()->id)
            ->where('id', $notifikasiId)
            ->whereNull('dibaca_at')
            ->update(['dibaca_at' => now()]);

        abort_unless($updated, 404);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    });
    Route::apiResource('pustu', PustuController::class);
    Route::apiResource('admin-pustu', AdminPustuController::class);
    Route::post('/admin-pustu/{adminPustu}/reset-password', [AdminPustuController::class, 'resetPassword']);
    Route::apiResource('sekolah', SekolahController::class);
    Route::get('/guru', [GuruController::class, 'index']);
    Route::get('/guru/kelas', [GuruController::class, 'kelas']);
    Route::post('/guru/kelas', [GuruController::class, 'storeKelas']);
    Route::get('/guru/token-sekolah', [GuruController::class, 'tokenSekolah']);
    Route::post('/guru/token-sekolah', [GuruController::class, 'storeTokenSekolah']);
    Route::patch('/guru/token-sekolah/{tokenSekolah}/nonaktif', [GuruController::class, 'deactivateToken']);
    Route::get('/guru/{guru}/dokumen-sk', [GuruController::class, 'document']);
    Route::get('/guru/{guru}', [GuruController::class, 'show']);
    Route::patch('/guru/{guru}/verifikasi', [GuruController::class, 'verify']);
    Route::get('/siswi/saya', [SiswiController::class, 'profile']);
    Route::get('/kelas', [SiswiController::class, 'classes']);
    Route::get('/siswi', [SiswiController::class, 'index']);
    Route::get('/siswi/{siswi}', [SiswiController::class, 'show']);
    Route::get('/alasan-tidak-minum', AlasanTidakMinumController::class);
    Route::get('/laporan-mingguan', [LaporanMingguanController::class, 'index']);
    Route::get('/laporan-rekap/options', [LaporanRekapController::class, 'options']);
    Route::get('/laporan-rekap', [LaporanRekapController::class, 'index']);
    Route::post('/laporan-rekap/ekspor', [LaporanRekapController::class, 'export']);
    Route::post('/laporan-mingguan', [LaporanMingguanController::class, 'store']);
    Route::get('/laporan-mingguan/saya', [LaporanMingguanController::class, 'mine']);
    Route::post('/laporan-mingguan/{laporanMingguan}/unggah-ulang', [LaporanMingguanController::class, 'resubmit']);
    Route::post('/pengecualian-medis', [PengecualianMedisController::class, 'store']);
    Route::post('/siswi/{siswi}/pengingat', PengingatSiswiController::class);
    Route::get('/pengaturan/pelaporan', [PengaturanPelaporanController::class, 'index']);
    Route::put('/pengaturan/pelaporan', [PengaturanPelaporanController::class, 'update']);
    Route::patch('/pengaturan/pelaporan', [PengaturanPelaporanController::class, 'update']);
    Route::patch('/laporan-mingguan/{laporanMingguan}/validasi', [LaporanMingguanController::class, 'validateReport']);
    Route::get('/laporan-mingguan/{laporanMingguan}/foto', [LaporanMingguanController::class, 'photo'])
        ->middleware('signed')->name('laporan-mingguan.foto');
    Route::patch('/enrollment/{enrollment}/kelas', [SiswiController::class, 'changeClass']);
    Route::get('/mutasi-siswi', [MutasiSiswiController::class, 'index']);
    Route::get('/mutasi-siswi/sekolah-tujuan', [MutasiSiswiController::class, 'destinations']);
    Route::post('/mutasi-siswi', [MutasiSiswiController::class, 'store']);
    Route::patch('/mutasi-siswi/{mutasiSiswi}/keputusan', [MutasiSiswiController::class, 'review']);
    Route::post('/mutasi-siswi/{mutasiSiswi}/konfirmasi', [MutasiSiswiController::class, 'confirm']);
    Route::get('/kecamatan', [KecamatanController::class, 'index']);
    Route::post('/kecamatan', [KecamatanController::class, 'store']);
    Route::put('/kecamatan/{kecamatan}', [KecamatanController::class, 'update']);
    Route::patch('/kecamatan/{kecamatan}', [KecamatanController::class, 'update']);
    Route::get('/kelurahan', [KelurahanController::class, 'index']);
    Route::post('/kelurahan', [KelurahanController::class, 'store']);
    Route::put('/kelurahan/{kelurahan}', [KelurahanController::class, 'update']);
    Route::patch('/kelurahan/{kelurahan}', [KelurahanController::class, 'update']);
    Route::get('/tahun-ajaran', [TahunAjaranController::class, 'index']);
    Route::post('/tahun-ajaran', [TahunAjaranController::class, 'store']);
    Route::put('/tahun-ajaran/{tahunAjaran}', [TahunAjaranController::class, 'update']);
    Route::patch('/tahun-ajaran/{tahunAjaran}', [TahunAjaranController::class, 'update']);
    Route::get('/log-aktivitas', [ActivityLogController::class, 'index']);
    Route::get('/riwayat-login', [LoginHistoryController::class, 'index']);
    Route::get('/obat-ttd/batch', [RantaiPasokController::class, 'batches']);
    Route::post('/obat-ttd/batch', [RantaiPasokController::class, 'storeBatch']);
    Route::get('/obat-ttd/rekap-stok', [RantaiPasokController::class, 'stockSummary']);
    Route::get('/obat-ttd/pilihan', [RantaiPasokController::class, 'options']);
    Route::get('/obat-ttd/pengiriman-pustu', [RantaiPasokController::class, 'pustuDistributions']);
    Route::post('/obat-ttd/pengiriman-pustu', [RantaiPasokController::class, 'storePustuDistribution']);
    Route::patch('/obat-ttd/pengiriman-pustu/{distributionId}/konfirmasi', [RantaiPasokController::class, 'confirmPustuDistribution']);
    Route::get('/obat-ttd/stok-pustu', [RantaiPasokController::class, 'pustuStock']);
    Route::get('/obat-ttd/serah-terima-sekolah', [RantaiPasokController::class, 'schoolDistributions']);
    Route::post('/obat-ttd/serah-terima-sekolah', [RantaiPasokController::class, 'storeSchoolDistribution']);
    Route::get('/obat-ttd/serah-terima-sekolah/{distributionId}', [RantaiPasokController::class, 'schoolDistribution']);
    Route::patch('/obat-ttd/serah-terima-sekolah/{distributionId}/konfirmasi', [RantaiPasokController::class, 'confirmSchoolDistribution']);
    Route::patch('/obat-ttd/serah-terima-sekolah/{distributionId}/batalkan', [RantaiPasokController::class, 'cancelSchoolDistribution']);
    Route::post('/obat-ttd/serah-terima-sekolah/{distributionId}/cetak', [RantaiPasokController::class, 'printSchoolDistribution']);
    Route::get('/obat-ttd/paket-siswi', [RantaiPasokController::class, 'studentPackages']);
    Route::post('/obat-ttd/paket-siswi', [RantaiPasokController::class, 'storeStudentPackage']);
});
