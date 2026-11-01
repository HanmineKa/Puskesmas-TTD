<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBatchTtdRequest;
use App\Http\Requests\StoreDistribusiPustuRequest;
use App\Http\Requests\StoreDistribusiSekolahRequest;
use App\Http\Requests\StorePenerimaanPaketSiswiRequest;
use App\Models\Siswi;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RantaiPasokController extends Controller
{
    private const TABLET_PER_PAKET = 52;

    private const HARI_PERINGATAN_KADALUWARSA = 90;

    public function batches(Request $request): JsonResponse
    {
        $this->requireRole($request, 'ADMINISTRATOR');

        return response()->json(['data' => DB::table('batch_ttd')->orderByDesc('tgl_terima')->orderByDesc('id')->get()]);
    }

    public function stockSummary(Request $request): JsonResponse
    {
        $this->requireRole($request, 'ADMINISTRATOR');
        $rows = DB::table('stok_pustu as stok')
            ->join('pustu', 'pustu.id', '=', 'stok.pustu_id')
            ->join('batch_ttd as batch', 'batch.id', '=', 'stok.batch_id')
            ->where('stok.jumlah', '>', 0)
            ->orderBy('pustu.nama')->orderBy('batch.tgl_kadaluwarsa')
            ->get([
                'pustu.id as pustu_id', 'pustu.nama as nama_pustu', 'batch.id as batch_id',
                'batch.nomor_batch', 'batch.tgl_kadaluwarsa', 'stok.jumlah',
            ]);

        return response()->json(['data' => $rows]);
    }

    public function options(Request $request, ActivityLogger $logger): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU'], true) && $user->status === 'AKTIF', 403);
        $options = [];

        if ($user->role === 'ADMINISTRATOR') {
            $options['pustu'] = DB::table('pustu')->where('aktif', true)->orderBy('nama')->get(['id', 'nama']);
            $options['batch'] = DB::table('batch_ttd')->whereDate('tgl_kadaluwarsa', '>=', today())
                ->orderBy('tgl_kadaluwarsa')->get(['id', 'nomor_batch', 'tgl_kadaluwarsa', 'jumlah_masuk']);
        } elseif ($user->role === 'ADMIN_PUSTU') {
            $pustuId = $this->adminPustuId($request);
            $options['sekolah'] = DB::table('sekolah')->where('pustu_id', $pustuId)->where('status', 'AKTIF')
                ->orderBy('nama')->get(['id', 'nama']);
            $options['guru'] = DB::table('guru')->join('users', 'users.id', '=', 'guru.user_id')
                ->where('guru.sekolah_id', $request->integer('sekolah_id') ?: -1)->where('users.status', 'AKTIF')
                ->orderBy('guru.nama')->get(['guru.id', 'guru.nama']);
            $options['batch'] = DB::table('stok_pustu as stok')->join('batch_ttd as batch', 'batch.id', '=', 'stok.batch_id')
                ->where('stok.pustu_id', $pustuId)->where('stok.jumlah', '>', 0)->whereDate('batch.tgl_kadaluwarsa', '>=', today())
                ->orderBy('batch.tgl_kadaluwarsa')
                ->get(['batch.id', 'batch.nomor_batch', 'batch.isi_per_box', 'batch.tgl_kadaluwarsa', 'stok.jumlah']);
        } else {
            $teacher = $this->teacher($request);
            $schoolYearId = DB::table('tahun_ajaran')->where('aktif', true)->value('id');
            $enrollments = DB::table('enrollment')
                ->join('siswi', 'siswi.id', '=', 'enrollment.siswi_id')
                ->join('kelas', 'kelas.id', '=', 'enrollment.kelas_id')
                ->where('enrollment.sekolah_id', $teacher->sekolah_id)
                ->where('enrollment.tahun_ajaran_id', $schoolYearId)->where('enrollment.status', 'AKTIF')
                ->whereIn('siswi.status_pelacakan', ['AKTIF', 'DALAM_MUTASI'])
                ->orderBy('kelas_id')->orderBy('enrollment.id')
                ->get(['siswi.id as siswi_id', 'enrollment.id as enrollment_id', 'kelas.nama as nama_kelas']);
            $studentDetails = Siswi::query()->whereIn('id', $enrollments->pluck('siswi_id'))
                ->get(['id', 'nama', 'nisn'])->keyBy('id');
            $students = $enrollments->map(function (object $enrollment) use ($studentDetails): array {
                $student = $studentDetails->get($enrollment->siswi_id);

                return [
                    'id' => $student->id,
                    'nama' => $student->nama,
                    'nisn_tersamar' => str_repeat('•', max(0, mb_strlen($student->nisn) - 4)).mb_substr($student->nisn, -4),
                    'enrollment_id' => $enrollment->enrollment_id,
                    'nama_kelas' => $enrollment->nama_kelas,
                ];
            });
            $options['siswi'] = $students;
            $logger->dataAccess(
                $user, 'BUKA_DETAIL_SISWI', 'Enrollment', null, $request,
                ['sekolah_id' => $teacher->sekolah_id], $students->count(),
            );
            $options['serah_terima'] = DB::table('distribusi_sekolah as distribusi')
                ->join('distribusi_sekolah_item as item', 'item.distribusi_sekolah_id', '=', 'distribusi.id')
                ->join('batch_ttd as batch', 'batch.id', '=', 'item.batch_id')
                ->where('distribusi.sekolah_id', $teacher->sekolah_id)->where('distribusi.status', 'COMPLETED')
                ->orderByDesc('distribusi.tgl_konfirmasi')
                ->get(['distribusi.id', 'distribusi.nomor_dokumen', 'item.batch_id', 'batch.nomor_batch']);
        }

        return response()->json(['data' => $options]);
    }

    public function storeBatch(StoreBatchTtdRequest $request, ActivityLogger $logger): JsonResponse
    {
        $values = $request->validated();
        $total = (int) $values['jumlah_box'] * (int) $values['isi_per_box'];
        abort_if($total > 100000000, 422, 'Jumlah tablet melebihi batas yang dapat dicatat.');

        $batch = DB::transaction(function () use ($request, $values, $total, $logger): object {
            $id = DB::table('batch_ttd')->insertGetId([
                'nomor_batch' => $values['nomor_batch'],
                'tgl_kadaluwarsa' => $values['tgl_kadaluwarsa'],
                'jumlah_box' => $values['jumlah_box'],
                'isi_per_box' => $values['isi_per_box'],
                'jumlah_masuk' => $total,
                'tgl_terima' => $values['tgl_terima'],
                'sumber' => $values['sumber'] ?? null,
                'dicatat_oleh' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $logger->audit(
                $request->user(), 'STOK_TTD_DICATAT', 'BatchTtd', $id,
                ['jumlah_masuk' => ['after' => $total], 'nomor_batch' => ['after' => $values['nomor_batch']]],
                $request,
            );

            return DB::table('batch_ttd')->where('id', $id)->first();
        });

        return response()->json(['data' => $batch, 'message' => 'Stok TTD berhasil dicatat.'], 201);
    }

    public function pustuDistributions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true) && $user->status === 'AKTIF', 403);
        $query = DB::table('distribusi_pustu as distribusi')
            ->join('batch_ttd as batch', 'batch.id', '=', 'distribusi.batch_id')
            ->join('pustu', 'pustu.id', '=', 'distribusi.pustu_id')
            ->select('distribusi.*', 'batch.nomor_batch', 'batch.tgl_kadaluwarsa', 'pustu.nama as nama_pustu')
            ->orderByDesc('distribusi.tgl_kirim')
            ->orderByDesc('distribusi.id');

        if ($user->role === 'ADMIN_PUSTU') {
            $query->where('distribusi.pustu_id', $this->adminPustuId($request));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function storePustuDistribution(
        StoreDistribusiPustuRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $distribution = DB::transaction(function () use ($request, $values, $logger): object {
            $batch = DB::table('batch_ttd')->lockForUpdate()->find($values['batch_id']);
            abort_unless($batch, 404);
            abort_if($batch->tgl_kadaluwarsa < $values['tgl_kirim'], 422, 'Batch sudah kedaluwarsa pada tanggal pengiriman.');
            $pustu = DB::table('pustu')->where('id', $values['pustu_id'])->where('aktif', true)->first();
            abort_unless($pustu, 422, 'Puskesmas pembantu tidak aktif.');

            $reserved = (int) DB::table('distribusi_pustu')
                ->where('batch_id', $batch->id)
                ->whereIn('status', ['PENDING', 'COMPLETED'])
                ->sum('jumlah');
            if ($reserved + (int) $values['jumlah'] > (int) $batch->jumlah_masuk) {
                throw ValidationException::withMessages(['jumlah' => 'Jumlah pengiriman melebihi stok batch yang tersedia.']);
            }

            $id = DB::table('distribusi_pustu')->insertGetId([
                'batch_id' => $batch->id,
                'pustu_id' => $pustu->id,
                'jumlah' => $values['jumlah'],
                'status' => 'PENDING',
                'dikirim_oleh' => $request->user()->id,
                'tgl_kirim' => $values['tgl_kirim'],
                'catatan' => $values['catatan'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $logger->audit(
                $request->user(), 'PENGIRIMAN_TTD_KE_PUSTU', 'DistribusiPustu', $id,
                ['jumlah' => ['after' => (int) $values['jumlah']], 'status' => ['after' => 'PENDING']],
                $request, 'OPERASIONAL', $pustu->id,
            );
            $recipientIds = DB::table('admin_pustu')->where('pustu_id', $pustu->id)->pluck('user_id');
            foreach ($recipientIds as $recipientId) {
                DB::table('notifikasi')->insert([
                    'user_id' => $recipientId,
                    'tipe' => 'PENERIMAAN_TTD_PUSTU',
                    'judul' => 'Pengiriman TTD menunggu penerimaan',
                    'isi' => 'Ada pengiriman TTD baru yang perlu dikonfirmasi.',
                    'tautan' => '/obat-ttd/penerimaan-puskesmas',
                    'dibaca_at' => null,
                    'created_at' => now(),
                ]);
            }

            return DB::table('distribusi_pustu')->where('id', $id)->first();
        });

        return response()->json(['data' => $distribution, 'message' => 'Pengiriman ke Pustu berhasil dibuat.'], 201);
    }

    public function confirmPustuDistribution(Request $request, int $distributionId, ActivityLogger $logger): JsonResponse
    {
        $pustuId = $this->adminPustuId($request);
        $distribution = DB::transaction(function () use ($request, $distributionId, $pustuId, $logger): object {
            $row = DB::table('distribusi_pustu')->lockForUpdate()->find($distributionId);
            abort_unless($row && (int) $row->pustu_id === $pustuId, 404);
            abort_unless($row->status === 'PENDING', 409, 'Pengiriman ini sudah diproses.');
            DB::table('pustu')->where('id', $pustuId)->lockForUpdate()->first();

            $stock = DB::table('stok_pustu')->where('pustu_id', $pustuId)->where('batch_id', $row->batch_id)->lockForUpdate()->first();
            if ($stock) {
                DB::table('stok_pustu')->where('id', $stock->id)->increment('jumlah', $row->jumlah, ['updated_at' => now()]);
            } else {
                DB::table('stok_pustu')->insert([
                    'pustu_id' => $pustuId,
                    'batch_id' => $row->batch_id,
                    'jumlah' => $row->jumlah,
                    'updated_at' => now(),
                ]);
            }
            DB::table('distribusi_pustu')->where('id', $row->id)->update([
                'status' => 'COMPLETED',
                'dikonfirmasi_oleh' => $request->user()->id,
                'tgl_konfirmasi' => now(),
                'updated_at' => now(),
            ]);
            $logger->audit(
                $request->user(), 'PENERIMAAN_TTD_PUSTU_DIKONFIRMASI', 'DistribusiPustu', $row->id,
                ['status' => ['before' => 'PENDING', 'after' => 'COMPLETED'], 'jumlah' => ['bertambah' => (int) $row->jumlah]],
                $request, 'OPERASIONAL', $pustuId,
            );

            return DB::table('distribusi_pustu')->where('id', $row->id)->first();
        });

        return response()->json(['data' => $distribution, 'message' => 'Penerimaan TTD berhasil dikonfirmasi.']);
    }

    public function pustuStock(Request $request): JsonResponse
    {
        $pustuId = $this->adminPustuId($request);
        $warningDate = today()->addDays(self::HARI_PERINGATAN_KADALUWARSA)->toDateString();
        $rows = DB::table('stok_pustu as stok')
            ->join('batch_ttd as batch', 'batch.id', '=', 'stok.batch_id')
            ->where('stok.pustu_id', $pustuId)
            ->where('stok.jumlah', '>', 0)
            ->select('stok.batch_id', 'stok.jumlah', 'batch.nomor_batch', 'batch.jumlah_box', 'batch.isi_per_box', 'batch.tgl_kadaluwarsa')
            ->orderBy('batch.tgl_kadaluwarsa')
            ->get()
            ->map(fn (object $row): array => [
                ...((array) $row),
                'status_kadaluwarsa' => $row->tgl_kadaluwarsa < today()->toDateString()
                    ? 'KEDALUWARSA'
                    : ($row->tgl_kadaluwarsa <= $warningDate ? 'SEGERA_KEDALUWARSA' : 'AMAN'),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function schoolDistributions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMIN_PUSTU', 'GURU'], true) && $user->status === 'AKTIF', 403);
        $query = DB::table('distribusi_sekolah as distribusi')
            ->join('sekolah', 'sekolah.id', '=', 'distribusi.sekolah_id')
            ->select('distribusi.*', 'sekolah.npsn', 'sekolah.kelurahan_id')
            ->orderByDesc('distribusi.tgl_serah_terima')
            ->orderByDesc('distribusi.id');

        if ($user->role === 'ADMIN_PUSTU') {
            $query->where('distribusi.pustu_id', $this->adminPustuId($request));
        } else {
            $teacher = $this->teacher($request);
            $query->where('distribusi.sekolah_id', $teacher->sekolah_id);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function storeSchoolDistribution(
        StoreDistribusiSekolahRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $pustuId = $this->adminPustuId($request);
        $distribution = DB::transaction(function () use ($request, $values, $pustuId, $logger): object {
            $pustu = DB::table('pustu')->where('id', $pustuId)->lockForUpdate()->first();
            abort_unless($pustu, 403);
            $school = DB::table('sekolah')->where('id', $values['sekolah_id'])
                ->where('pustu_id', $pustuId)->where('status', 'AKTIF')->first();
            abort_unless($school, 404);
            $teacher = DB::table('guru')->join('users', 'users.id', '=', 'guru.user_id')
                ->where('guru.id', $values['guru_penerima_id'])
                ->where('guru.sekolah_id', $school->id)->where('users.status', 'AKTIF')
                ->select('guru.id', 'guru.user_id', 'guru.nama')->first();
            abort_unless($teacher, 422, 'Guru penerima harus berasal dari sekolah tujuan dan berstatus aktif.');
            $admin = DB::table('admin_pustu')->where('user_id', $request->user()->id)->first();
            abort_unless($admin, 403);

            $year = (int) substr($values['tgl_serah_terima'], 0, 4);
            $sequence = (int) DB::table('distribusi_sekolah')->where('pustu_id', $pustuId)
                ->where('tahun', $year)->lockForUpdate()->max('nomor_urut') + 1;
            $documentNumber = sprintf('TTD/%d/%d/%04d', $pustuId, $year, $sequence);
            $headerId = DB::table('distribusi_sekolah')->insertGetId([
                'pustu_id' => $pustuId,
                'tahun' => $year,
                'nomor_urut' => $sequence,
                'nomor_dokumen' => $documentNumber,
                'tgl_serah_terima' => $values['tgl_serah_terima'],
                'sekolah_id' => $school->id,
                'guru_penerima_id' => $teacher->id,
                'dikirim_oleh' => $request->user()->id,
                'nama_petugas' => $admin->nama,
                'nama_guru' => $teacher->nama,
                'nama_sekolah' => $school->nama,
                'keterangan' => $values['keterangan'] ?? null,
                'status' => 'PENDING',
                'tgl_kirim' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = collect($values['items'])->sortBy('batch_id');
            foreach ($items as $item) {
                $batch = DB::table('batch_ttd')->where('id', $item['batch_id'])->lockForUpdate()->first();
                abort_unless($batch && $batch->tgl_kadaluwarsa >= $values['tgl_serah_terima'], 422, 'Batch tidak tersedia atau sudah kedaluwarsa.');
                $stock = DB::table('stok_pustu')->where('pustu_id', $pustuId)->where('batch_id', $batch->id)->lockForUpdate()->first();
                $pending = (int) DB::table('distribusi_sekolah_item as item')
                    ->join('distribusi_sekolah as distribusi', 'distribusi.id', '=', 'item.distribusi_sekolah_id')
                    ->where('distribusi.pustu_id', $pustuId)->where('distribusi.status', 'PENDING')
                    ->where('item.batch_id', $batch->id)->sum('item.jumlah_tablet');
                $quantity = (int) $item['jumlah_box'] * (int) $batch->isi_per_box;
                if (! $stock || (int) $stock->jumlah - $pending < $quantity) {
                    throw ValidationException::withMessages(['items' => 'Stok salah satu batch tidak mencukupi setelah memperhitungkan serah-terima yang menunggu.']);
                }
                DB::table('distribusi_sekolah_item')->insert([
                    'distribusi_sekolah_id' => $headerId,
                    'batch_id' => $batch->id,
                    'nama_barang' => 'Tablet Tambah Darah (TTD)',
                    'jumlah_box' => $item['jumlah_box'],
                    'isi_per_box' => $batch->isi_per_box,
                    'jumlah_tablet' => $quantity,
                    'keterangan' => $item['keterangan'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $logger->audit(
                $request->user(), 'SERAH_TERIMA_TTD_DIBUAT', 'DistribusiSekolah', $headerId,
                ['nomor_dokumen' => ['after' => $documentNumber], 'status' => ['after' => 'PENDING']],
                $request, 'OPERASIONAL', $pustuId,
            );
            DB::table('notifikasi')->insert([
                'user_id' => $teacher->user_id,
                'tipe' => 'PENERIMAAN_TTD_SEKOLAH',
                'judul' => 'Serah-terima TTD menunggu penerimaan',
                'isi' => 'Ada serah-terima TTD yang perlu dikonfirmasi.',
                'tautan' => '/obat-ttd/penerimaan-pustu',
                'dibaca_at' => null,
                'created_at' => now(),
            ]);

            return DB::table('distribusi_sekolah')->where('id', $headerId)->first();
        });

        return response()->json(['data' => $distribution, 'message' => 'Serah-terima ke sekolah berhasil dibuat.'], 201);
    }

    public function schoolDistribution(Request $request, int $distributionId): JsonResponse
    {
        $header = $this->scopedSchoolDistribution($request, $distributionId);
        $items = DB::table('distribusi_sekolah_item as item')
            ->join('batch_ttd as batch', 'batch.id', '=', 'item.batch_id')
            ->where('item.distribusi_sekolah_id', $header->id)
            ->select('item.*', 'batch.nomor_batch', 'batch.tgl_kadaluwarsa')
            ->get();

        return response()->json(['data' => ['dokumen' => $header, 'items' => $items]]);
    }

    public function confirmSchoolDistribution(Request $request, int $distributionId, ActivityLogger $logger): JsonResponse
    {
        $teacher = $this->teacher($request);
        $header = DB::transaction(function () use ($request, $distributionId, $teacher, $logger): object {
            $initial = DB::table('distribusi_sekolah')->where('id', $distributionId)->first();
            abort_unless($initial && (int) $initial->sekolah_id === (int) $teacher->sekolah_id, 404);
            DB::table('pustu')->where('id', $initial->pustu_id)->lockForUpdate()->first();
            $row = DB::table('distribusi_sekolah')->where('id', $distributionId)->lockForUpdate()->first();
            abort_unless($row && (int) $row->sekolah_id === (int) $teacher->sekolah_id, 404);
            abort_unless($row->status === 'PENDING', 409, 'Dokumen serah-terima ini sudah diproses.');
            $items = DB::table('distribusi_sekolah_item')->where('distribusi_sekolah_id', $row->id)->orderBy('batch_id')->get();
            foreach ($items as $item) {
                $stock = DB::table('stok_pustu')->where('pustu_id', $row->pustu_id)->where('batch_id', $item->batch_id)->lockForUpdate()->first();
                abort_unless($stock && (int) $stock->jumlah >= (int) $item->jumlah_tablet, 409, 'Stok Pustu tidak mencukupi untuk menyelesaikan serah-terima.');
                $batch = DB::table('batch_ttd')->where('id', $item->batch_id)->first();
                abort_if($batch->tgl_kadaluwarsa < today()->toDateString(), 409, 'Batch sudah kedaluwarsa dan tidak dapat diterima sekolah.');
                DB::table('stok_pustu')->where('id', $stock->id)->decrement('jumlah', $item->jumlah_tablet, ['updated_at' => now()]);
            }
            DB::table('distribusi_sekolah')->where('id', $row->id)->update([
                'status' => 'COMPLETED',
                'dikonfirmasi_oleh' => $request->user()->id,
                'tgl_konfirmasi' => now(),
                'updated_at' => now(),
            ]);
            $logger->audit(
                $request->user(), 'SERAH_TERIMA_TTD_DIKONFIRMASI', 'DistribusiSekolah', $row->id,
                ['status' => ['before' => 'PENDING', 'after' => 'COMPLETED']],
                $request, 'OPERASIONAL', $row->pustu_id,
            );

            return DB::table('distribusi_sekolah')->where('id', $row->id)->first();
        });

        return response()->json(['data' => $header, 'message' => 'Penerimaan TTD berhasil dikonfirmasi.']);
    }

    public function cancelSchoolDistribution(Request $request, int $distributionId, ActivityLogger $logger): JsonResponse
    {
        $pustuId = $this->adminPustuId($request);
        DB::transaction(function () use ($request, $distributionId, $pustuId, $logger): void {
            $initial = DB::table('distribusi_sekolah')->where('id', $distributionId)->first();
            abort_unless($initial && (int) $initial->pustu_id === $pustuId, 404);
            DB::table('pustu')->where('id', $pustuId)->lockForUpdate()->first();
            $row = DB::table('distribusi_sekolah')->where('id', $distributionId)->lockForUpdate()->first();
            abort_unless($row && (int) $row->pustu_id === $pustuId, 404);
            abort_unless(in_array($row->status, ['PENDING', 'COMPLETED'], true), 409, 'Dokumen serah-terima ini sudah dibatalkan.');
            abort_if(DB::table('penerimaan_paket_siswi')->where('distribusi_sekolah_id', $row->id)->exists(), 409, 'Dokumen tidak dapat dibatalkan karena paket sudah dicatat untuk Siswi.');

            if ($row->status === 'COMPLETED') {
                foreach (DB::table('distribusi_sekolah_item')->where('distribusi_sekolah_id', $row->id)->orderBy('batch_id')->get() as $item) {
                    $stock = DB::table('stok_pustu')->where('pustu_id', $pustuId)->where('batch_id', $item->batch_id)->lockForUpdate()->first();
                    if ($stock) {
                        DB::table('stok_pustu')->where('id', $stock->id)->increment('jumlah', $item->jumlah_tablet, ['updated_at' => now()]);
                    } else {
                        DB::table('stok_pustu')->insert([
                            'pustu_id' => $pustuId,
                            'batch_id' => $item->batch_id,
                            'jumlah' => $item->jumlah_tablet,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
            DB::table('distribusi_sekolah')->where('id', $row->id)->update(['status' => 'DIBATALKAN', 'updated_at' => now()]);
            $logger->audit(
                $request->user(), 'SERAH_TERIMA_TTD_DIBATALKAN', 'DistribusiSekolah', $row->id,
                ['status' => ['before' => $row->status, 'after' => 'DIBATALKAN']],
                $request, 'OPERASIONAL', $pustuId,
            );
        });

        return response()->json(['message' => 'Serah-terima dibatalkan dan stok Pustu disesuaikan.']);
    }

    public function printSchoolDistribution(Request $request, int $distributionId, ActivityLogger $logger): JsonResponse
    {
        $header = $this->scopedSchoolDistribution($request, $distributionId);
        abort_unless($request->user()->role === 'ADMIN_PUSTU', 403);
        $logger->audit(
            $request->user(), 'SERAH_TERIMA_TTD_DICETAK', 'DistribusiSekolah', $header->id,
            ['nomor_dokumen' => ['after' => $header->nomor_dokumen]], $request, 'OPERASIONAL', $header->pustu_id,
        );

        return $this->schoolDistribution($request, $distributionId);
    }

    public function studentPackages(Request $request, ActivityLogger $logger): JsonResponse
    {
        $teacher = $this->teacher($request);
        $schoolYearId = DB::table('tahun_ajaran')->where('aktif', true)->value('id');
        $rows = DB::table('penerimaan_paket_siswi as paket')
            ->join('siswi', 'siswi.id', '=', 'paket.siswi_id')
            ->join('enrollment', 'enrollment.id', '=', 'paket.enrollment_id')
            ->join('kelas', 'kelas.id', '=', 'enrollment.kelas_id')
            ->join('batch_ttd as batch', 'batch.id', '=', 'paket.batch_id')
            ->where('enrollment.sekolah_id', $teacher->sekolah_id)
            ->when($schoolYearId, fn ($query) => $query->where('paket.tahun_ajaran_id', $schoolYearId))
            ->select('paket.*', 'siswi.nama as nama_siswi', 'kelas.nama as nama_kelas', 'batch.nomor_batch')
            ->orderByDesc('paket.tgl_diterima')->limit(500)->get();
        $studentNames = Siswi::query()->whereIn('id', $rows->pluck('siswi_id'))
            ->get(['id', 'nama'])->keyBy('id');
        $rows = $rows->map(function (object $row) use ($studentNames): object {
            $row->nama_siswi = $studentNames->get($row->siswi_id)?->nama;

            return $row;
        });
        $logger->dataAccess(
            $request->user(), 'BUKA_DETAIL_SISWI', 'PenerimaanPaketSiswi', null, $request,
            ['sekolah_id' => $teacher->sekolah_id], $rows->count(),
        );

        return response()->json(['data' => $rows]);
    }

    public function storeStudentPackage(
        StorePenerimaanPaketSiswiRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $teacher = $this->teacher($request);
        $values = $request->validated();

        try {
            $package = DB::transaction(function () use ($request, $teacher, $values, $logger): object {
                $school = DB::table('sekolah')->where('id', $teacher->sekolah_id)->lockForUpdate()->first();
                abort_unless($school, 403);
                $schoolYear = DB::table('tahun_ajaran')->where('aktif', true)->first();
                abort_unless($schoolYear, 409, 'Belum ada tahun ajaran aktif.');
                $enrollment = DB::table('enrollment')->where('siswi_id', $values['siswi_id'])
                    ->where('sekolah_id', $teacher->sekolah_id)
                    ->where('tahun_ajaran_id', $schoolYear->id)->where('status', 'AKTIF')->first();
                abort_unless($enrollment, 404);
                $distribution = DB::table('distribusi_sekolah')->where('id', $values['distribusi_sekolah_id'])
                    ->where('sekolah_id', $teacher->sekolah_id)->where('status', 'COMPLETED')
                    ->lockForUpdate()->first();
                abort_unless($distribution, 422, 'Pilih serah-terima yang sudah dikonfirmasi untuk sekolah ini.');
                abort_unless(DB::table('distribusi_sekolah_item')->where('distribusi_sekolah_id', $distribution->id)
                    ->where('batch_id', $values['batch_id'])->exists(), 422, 'Batch tidak termasuk pada dokumen serah-terima tersebut.');

                $delivered = (int) DB::table('distribusi_sekolah_item as item')
                    ->join('distribusi_sekolah as distribusi', 'distribusi.id', '=', 'item.distribusi_sekolah_id')
                    ->where('distribusi.sekolah_id', $teacher->sekolah_id)
                    ->where('distribusi.status', 'COMPLETED')->where('item.batch_id', $values['batch_id'])
                    ->sum('item.jumlah_tablet');
                $allocated = (int) DB::table('penerimaan_paket_siswi')->where('batch_id', $values['batch_id'])
                    ->join('enrollment', 'enrollment.id', '=', 'penerimaan_paket_siswi.enrollment_id')
                    ->where('penerimaan_paket_siswi.tahun_ajaran_id', $schoolYear->id)
                    ->where('enrollment.sekolah_id', $teacher->sekolah_id)
                    ->sum('jumlah_tablet');
                if ($allocated + self::TABLET_PER_PAKET > $delivered) {
                    throw ValidationException::withMessages(['siswi_id' => 'Sisa batch yang telah diterima sekolah tidak cukup untuk satu paket 52 tablet.']);
                }

                $id = DB::table('penerimaan_paket_siswi')->insertGetId([
                    'siswi_id' => $values['siswi_id'],
                    'enrollment_id' => $enrollment->id,
                    'tahun_ajaran_id' => $schoolYear->id,
                    'distribusi_sekolah_id' => $distribution->id,
                    'batch_id' => $values['batch_id'],
                    'jumlah_tablet' => self::TABLET_PER_PAKET,
                    'tgl_diterima' => $values['tgl_diterima'],
                    'dicatat_oleh' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $logger->audit(
                    $request->user(), 'PAKET_TTD_SISWI_DICATAT', 'PenerimaanPaketSiswi', $id,
                    ['jumlah_tablet' => ['after' => self::TABLET_PER_PAKET]],
                    $request, 'OPERASIONAL', $enrollment->pustu_id,
                );

                return DB::table('penerimaan_paket_siswi')->where('id', $id)->first();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['siswi_id' => 'Siswi sudah menerima paket pada tahun ajaran ini.']);
        }

        return response()->json(['data' => $package, 'message' => 'Pembagian paket TTD berhasil dicatat.'], 201);
    }

    private function scopedSchoolDistribution(Request $request, int $distributionId): object
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMIN_PUSTU', 'GURU'], true) && $user->status === 'AKTIF', 403);
        $query = DB::table('distribusi_sekolah')->where('id', $distributionId);
        if ($user->role === 'ADMIN_PUSTU') {
            $query->where('pustu_id', $this->adminPustuId($request));
        } else {
            $query->where('sekolah_id', $this->teacher($request)->sekolah_id);
        }

        return $query->first() ?? abort(404);
    }

    private function adminPustuId(Request $request): int
    {
        abort_unless($request->user()->role === 'ADMIN_PUSTU' && $request->user()->status === 'AKTIF', 403);

        return (int) DB::table('admin_pustu')->where('user_id', $request->user()->id)->value('pustu_id') ?: abort(403);
    }

    private function teacher(Request $request): object
    {
        abort_unless($request->user()->role === 'GURU' && $request->user()->status === 'AKTIF', 403);

        return DB::table('guru')->where('user_id', $request->user()->id)->first() ?? abort(403);
    }

    private function requireRole(Request $request, string $role): void
    {
        abort_unless($request->user()->role === $role && $request->user()->status === 'AKTIF', 403);
    }
}
