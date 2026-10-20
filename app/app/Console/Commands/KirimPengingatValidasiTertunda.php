<?php

namespace App\Console\Commands;

use App\Models\Guru;
use App\Models\LaporanMingguan;
use App\Models\Pengingat;
use App\Models\TahunAjaran;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('app:kirim-pengingat-validasi-tertunda')]
#[Description('Mengirim pengingat Guru untuk laporan yang lama menunggu validasi.')]
class KirimPengingatValidasiTertunda extends Command
{
    public function handle(): int
    {
        $validationLimit = (int) (DB::table('pengaturan')->where('kunci', 'batas_hari_validasi')->value('nilai') ?? 7);
        $reminderGap = (int) (DB::table('pengaturan')->where('kunci', 'jeda_pengingat_hari')->value('nilai') ?? 7);
        $cutoff = now()->subDays($validationLimit);
        $reminderCutoff = now()->subDays($reminderGap);
        $sent = 0;
        $activeSchoolYearId = TahunAjaran::query()->where('aktif', true)->value('id');
        if (! $activeSchoolYearId) {
            $this->info('Tidak ada tahun ajaran aktif; pengingat tidak dikirim.');

            return self::SUCCESS;
        }

        LaporanMingguan::query()
            ->with(['enrollment.sekolah.guru.user'])
            ->where('tahun_ajaran_id', $activeSchoolYearId)
            ->where('status_validasi', 'MENUNGGU')
            ->where('created_at', '<=', $cutoff)
            ->whereHas('enrollment', fn (Builder $query) => $query->where('status', 'AKTIF'))
            ->whereHas('siswi', fn (Builder $query) => $query->whereIn('status_pelacakan', ['AKTIF', 'DALAM_MUTASI']))
            ->chunkById(100, function ($reports) use ($cutoff, $reminderCutoff, &$sent): void {
                $recentReportIds = Pengingat::query()
                    ->where('jenis', 'PENGINGAT_VALIDASI')
                    ->whereIn('laporan_id', $reports->modelKeys())
                    ->where('dikirim_at', '>=', $reminderCutoff)
                    ->pluck('laporan_id')
                    ->all();

                foreach ($reports as $report) {
                    if (in_array($report->id, $recentReportIds, true)) {
                        continue;
                    }
                    $school = $report->enrollment?->sekolah;
                    $teacher = $school?->guru->first(fn (Guru $candidate): bool => $candidate->user?->status === 'AKTIF');
                    if (! $teacher || $report->status_validasi !== 'MENUNGGU' || $report->created_at->gt($cutoff)) {
                        continue;
                    }

                    DB::transaction(function () use ($report, $teacher, &$sent): void {
                        $lockedReport = LaporanMingguan::query()->lockForUpdate()->find($report->id);
                        if (! $lockedReport || $lockedReport->status_validasi !== 'MENUNGGU') {
                            return;
                        }
                        $recentReminderExists = Pengingat::query()
                            ->where('jenis', 'PENGINGAT_VALIDASI')
                            ->where('laporan_id', $lockedReport->id)
                            ->where('dikirim_at', '>=', now()->subDays((int) (DB::table('pengaturan')->where('kunci', 'jeda_pengingat_hari')->value('nilai') ?? 7)))
                            ->exists();
                        if ($recentReminderExists) {
                            return;
                        }

                        Pengingat::query()->create([
                            'jenis' => 'PENGINGAT_VALIDASI',
                            'penerima_user_id' => $teacher->user_id,
                            'pengirim_user_id' => null,
                            'laporan_id' => $lockedReport->id,
                            'dikirim_at' => now(),
                        ]);
                        DB::table('notifikasi')->insert([
                            'user_id' => $teacher->user_id,
                            'tipe' => 'VALIDASI_TERTUNDA',
                            'judul' => 'Validasi laporan tertunda',
                            'isi' => 'Ada laporan mingguan TTD yang menunggu validasi.',
                            'tautan' => '/pelaporan',
                            'created_at' => now(),
                        ]);
                        $sent++;
                    });
                }
            });

        $this->info("{$sent} pengingat validasi dikirim.");

        return self::SUCCESS;
    }
}
