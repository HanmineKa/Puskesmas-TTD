<?php

namespace App\Services\Reports;

use App\Models\Enrollment;
use App\Models\LaporanMingguan;
use App\Models\Sekolah;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class LaporanRekapService
{
    public function options(User $user): array
    {
        $pustuId = $this->pustuId($user);
        $schoolQuery = Sekolah::query()->with('kelurahan:id,nama');

        if ($pustuId !== null) {
            $historicalSchoolIds = Enrollment::query()
                ->select('sekolah_id')
                ->where('pustu_id', $pustuId)
                ->distinct();
            $schoolQuery->where(function (Builder $query) use ($pustuId, $historicalSchoolIds): void {
                $query->where('pustu_id', $pustuId)->orWhereIn('id', $historicalSchoolIds);
            });
        }

        $schools = $schoolQuery->orderBy('nama')->get(['id', 'nama', 'kelurahan_id']);

        return [
            'tahun_ajaran' => TahunAjaran::query()->orderByDesc('tgl_mulai')->get(['id', 'label', 'aktif']),
            'sekolah' => $schools->map(fn (Sekolah $school): array => [
                'id' => $school->id,
                'nama' => $school->nama,
                'kelurahan_id' => $school->kelurahan_id,
            ]),
            'kelurahan' => $schools->pluck('kelurahan')
                ->filter()
                ->unique('id')
                ->sortBy('nama')
                ->values()
                ->map(fn ($kelurahan): array => ['id' => $kelurahan->id, 'nama' => $kelurahan->nama]),
        ];
    }

    public function build(User $user, array $filters, bool $includeIdentity = false): array
    {
        $pustuId = $this->pustuId($user);
        $schoolYear = isset($filters['tahun_ajaran_id'])
            ? TahunAjaran::query()->findOrFail($filters['tahun_ajaran_id'])
            : TahunAjaran::query()->where('aktif', true)->first();

        if (! $schoolYear) {
            return $this->emptyReport();
        }

        $period = $this->period($schoolYear, isset($filters['bulan']) ? (int) $filters['bulan'] : null);
        $studentColumns = $includeIdentity
            ? ['id', 'nama', 'nisn', 'status_pelacakan']
            : ['id', 'status_pelacakan'];
        $enrollments = collect();

        if ($period['minggu'] !== []) {
            $query = Enrollment::query()
                ->with([
                    'siswi' => fn (Relation $relation) => $relation->select($studentColumns),
                    'sekolah:id,nama,kelurahan_id',
                    'sekolah.kelurahan:id,nama',
                    'kelas:id,nama',
                    'pustu:id,nama',
                ])
                ->where('tahun_ajaran_id', $schoolYear->id)
                ->whereDate('tgl_masuk', '<=', $period['akhir']->toDateString())
                ->where(fn (Builder $builder) => $builder
                    ->whereNull('tgl_keluar')
                    ->orWhereDate('tgl_keluar', '>=', $period['awal']->toDateString()));

            if ($pustuId !== null) {
                $query->where('pustu_id', $pustuId);
            }
            if (isset($filters['sekolah_id'])) {
                $query->where('sekolah_id', $filters['sekolah_id']);
            }
            if (isset($filters['kelurahan_id'])) {
                $query->whereHas('sekolah', fn (Builder $builder) => $builder->where('kelurahan_id', $filters['kelurahan_id']));
            }

            $enrollments = $query->orderBy('sekolah_id')->orderBy('kelas_id')->get();
        }

        $reports = $enrollments->isEmpty()
            ? collect()
            : LaporanMingguan::query()
                ->with('alasan:id,nama,kategori')
                ->where('tahun_ajaran_id', $schoolYear->id)
                ->whereIn('minggu_ke', $period['minggu'])
                ->whereIn('enrollment_id', $enrollments->modelKeys())
                ->get()
                ->groupBy('enrollment_id');

        $yearStart = CarbonImmutable::parse($schoolYear->tgl_mulai)->startOfDay();
        $yearEnd = CarbonImmutable::parse($schoolYear->tgl_selesai)->endOfDay();
        $validationLimit = (int) (DB::table('pengaturan')->where('kunci', 'batas_hari_validasi')->value('nilai') ?? 7);
        $overdueCutoff = now()->subDays($validationLimit);
        $summary = $this->newBucket();
        $schoolBuckets = [];
        $pustuBuckets = [];
        $reasonBuckets = [];
        $rows = [];

        foreach ($enrollments as $enrollment) {
            $eligibleWeeks = $this->eligibleWeeks($enrollment, $period['minggu'], $yearStart, $yearEnd);
            $studentReports = ($reports->get($enrollment->id) ?? collect())
                ->filter(fn (LaporanMingguan $report): bool => in_array((int) $report->minggu_ke, $eligibleWeeks, true))
                ->values();
            $adherentReports = $studentReports->filter(fn (LaporanMingguan $report): bool => $report->status_minum === 'SUDAH'
                && in_array($report->status_validasi, ['MENUNGGU', 'VALID'], true)
            );
            $tabletWeeks = $studentReports->where('status_minum', 'SUDAH')->count();
            $validCount = $studentReports->where('status_validasi', 'VALID')->count();
            $pendingCount = $studentReports->where('status_validasi', 'MENUNGGU')->count();
            $invalidCount = $studentReports->where('status_validasi', 'TIDAK_VALID')->count();
            $overdueCount = $studentReports->filter(fn (LaporanMingguan $report): bool => $report->status_validasi === 'MENUNGGU'
                && $report->created_at?->lessThanOrEqualTo($overdueCutoff)
            )->count();
            $school = $enrollment->sekolah;
            $pustu = $enrollment->pustu;
            $row = [
                'siswi_id' => $enrollment->siswi_id,
                'pustu_id' => $enrollment->pustu_id,
                'pustu' => $pustu?->nama ?? 'Tidak diketahui',
                'sekolah_id' => $enrollment->sekolah_id,
                'sekolah' => $school?->nama ?? 'Tidak diketahui',
                'kelurahan_id' => $school?->kelurahan_id,
                'kelurahan' => $school?->kelurahan?->nama ?? 'Tidak diketahui',
                'kelas_id' => $enrollment->kelas_id,
                'kelas' => $enrollment->kelas?->nama ?? 'Tidak diketahui',
                'status_siswi' => $this->studentStatusLabel($enrollment->siswi?->status_pelacakan),
                'jumlah_minggu_diharapkan' => count($eligibleWeeks),
                'jumlah_minggu_dilaporkan' => $studentReports->count(),
                'jumlah_minggu_sudah' => $tabletWeeks,
                'jumlah_minggu_patut' => $adherentReports->count(),
                'persentase_kepatuhan' => count($eligibleWeeks) === 0
                    ? 0
                    : round($adherentReports->count() / count($eligibleWeeks) * 100, 1),
                'alasan_utama' => $this->mainReason($studentReports),
                'valid' => $studentReports->where('status_validasi', 'VALID')->count(),
                'menunggu' => $studentReports->where('status_validasi', 'MENUNGGU')->count(),
                'tidak_valid' => $studentReports->where('status_validasi', 'TIDAK_VALID')->count(),
                'validasi_tertunda' => $overdueCount,
            ];

            if ($includeIdentity) {
                $row['nama'] = $enrollment->siswi?->nama;
                $row['nisn'] = $enrollment->siswi?->nisn;
            }

            $rows[] = $row;
            $this->addToBucket($summary, $row);
            $schoolBuckets[$enrollment->sekolah_id] ??= $this->newBucket($row['sekolah'], $row['kelurahan']);
            $pustuBuckets[$enrollment->pustu_id] ??= $this->newBucket($row['pustu']);
            $this->addToBucket($schoolBuckets[$enrollment->sekolah_id], $row);
            $this->addToBucket($pustuBuckets[$enrollment->pustu_id], $row);

            foreach ($studentReports->where('status_minum', 'TIDAK') as $report) {
                if (! $report->alasan) {
                    continue;
                }

                $reasonBuckets[$report->alasan_id] ??= [
                    'nama' => $report->alasan->nama,
                    'kategori' => $report->alasan->kategori,
                    'jumlah' => 0,
                ];
                $reasonBuckets[$report->alasan_id]['jumlah']++;
            }
        }

        $comparisons = fn (array $buckets): array => collect($buckets)
            ->map(fn (array $bucket): array => $this->serializeBucket($bucket))
            ->sortBy('nama')
            ->values()
            ->all();

        return [
            'periode' => $period['label'],
            'ringkasan' => $this->serializeBucket($summary),
            'alasan' => collect($reasonBuckets)->sortByDesc('jumlah')->values()->all(),
            'perbandingan_sekolah' => $comparisons($schoolBuckets),
            'perbandingan_pustu' => $comparisons($pustuBuckets),
            'baris' => $rows,
        ];
    }

    public function assertFilterScope(User $user, array $filters): void
    {
        $pustuId = $this->pustuId($user);
        if ($pustuId === null) {
            return;
        }

        if (isset($filters['sekolah_id'])) {
            $schoolId = (int) $filters['sekolah_id'];
            $allowed = Sekolah::query()->whereKey($schoolId)->where('pustu_id', $pustuId)->exists()
                || Enrollment::query()->where('sekolah_id', $schoolId)->where('pustu_id', $pustuId)->exists();
            abort_unless($allowed, 404);
        }

        if (isset($filters['kelurahan_id'])) {
            $villageId = (int) $filters['kelurahan_id'];
            $allowed = Sekolah::query()->where('kelurahan_id', $villageId)->where('pustu_id', $pustuId)->exists()
                || Enrollment::query()->where('pustu_id', $pustuId)
                    ->whereHas('sekolah', fn (Builder $builder) => $builder->where('kelurahan_id', $villageId))
                    ->exists();
            abort_unless($allowed, 404);
        }
    }

    private function period(TahunAjaran $schoolYear, ?int $month): array
    {
        $yearStart = CarbonImmutable::parse($schoolYear->tgl_mulai)->startOfDay();
        $yearEnd = CarbonImmutable::parse($schoolYear->tgl_selesai)->endOfDay();
        $periodStart = $yearStart;
        $periodEnd = $yearEnd;
        $monthStart = null;

        if ($month !== null) {
            $calendarYear = (int) $yearStart->format('Y');
            if ($month < (int) $yearStart->format('n')) {
                $calendarYear++;
            }
            $monthStart = CarbonImmutable::create($calendarYear, $month, 1)->startOfDay();
            $monthEnd = $monthStart->endOfMonth()->endOfDay();
            $periodStart = $monthStart > $yearStart ? $monthStart : $yearStart;
            $periodEnd = $monthEnd < $yearEnd ? $monthEnd : $yearEnd;
        }

        $days = (int) $yearStart->diffInDays($yearEnd) + 1;
        $weekCount = min(52, (int) ceil($days / 7));
        $weeks = [];

        if ($periodStart <= $periodEnd) {
            for ($week = 1; $week <= $weekCount; $week++) {
                [$weekStart, $weekEnd] = $this->weekBounds($week, $yearStart, $yearEnd);
                if ($weekStart <= $periodEnd && $weekEnd >= $periodStart) {
                    $weeks[] = $week;
                }
            }
        }

        $firstWeek = $weeks[0] ?? null;
        $lastWeek = $weeks === [] ? null : $weeks[array_key_last($weeks)];
        $queryStart = $firstWeek === null ? $periodStart : $this->weekBounds($firstWeek, $yearStart, $yearEnd)[0];
        $queryEnd = $lastWeek === null ? $periodEnd : $this->weekBounds($lastWeek, $yearStart, $yearEnd)[1];
        $label = $monthStart === null
            ? 'Tahun ajaran '.$schoolYear->label
            : 'Bulan '.$monthStart->locale('id')->translatedFormat('F Y').' · Tahun ajaran '.$schoolYear->label;

        return ['awal' => $queryStart, 'akhir' => $queryEnd, 'minggu' => $weeks, 'label' => $label];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function weekBounds(int $week, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): array
    {
        $start = $yearStart->addDays(($week - 1) * 7);
        $end = $start->addDays(6)->endOfDay();

        return [$start, $end > $yearEnd ? $yearEnd : $end];
    }

    /** @return array<int, int> */
    private function eligibleWeeks(Enrollment $enrollment, array $weeks, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): array
    {
        $joinedAt = CarbonImmutable::parse($enrollment->tgl_masuk)->startOfDay();
        $leftAt = $enrollment->tgl_keluar ? CarbonImmutable::parse($enrollment->tgl_keluar)->endOfDay() : null;

        return array_values(array_filter($weeks, function (int $week) use ($joinedAt, $leftAt, $yearStart, $yearEnd): bool {
            [$weekStart, $weekEnd] = $this->weekBounds($week, $yearStart, $yearEnd);

            return $joinedAt <= $weekEnd && ($leftAt === null || $leftAt >= $weekStart);
        }));
    }

    private function mainReason($reports): ?string
    {
        return $reports->where('status_minum', 'TIDAK')
            ->filter(fn (LaporanMingguan $report): bool => $report->alasan !== null)
            ->groupBy(fn (LaporanMingguan $report): int => $report->alasan_id)
            ->map(fn ($items): array => ['nama' => $items->first()->alasan->nama, 'jumlah' => $items->count()])
            ->sortByDesc('jumlah')
            ->first()['nama'] ?? null;
    }

    private function studentStatusLabel(?string $status): string
    {
        return match ($status) {
            'AKTIF' => 'Aktif',
            'DALAM_MUTASI' => 'Dalam Mutasi',
            'LULUS' => 'Lulus',
            'KELUAR_WILAYAH' => 'Keluar Wilayah',
            'PUTUS_SEKOLAH' => 'Putus Sekolah',
            default => 'Tidak diketahui',
        };
    }

    /** @return array<string, mixed> */
    private function newBucket(string $name = '', string $village = ''): array
    {
        return [
            'nama' => $name,
            'kelurahan' => $village,
            'siswi' => [],
            'siswi_melapor' => [],
            'minggu_diharapkan' => 0,
            'minggu_sudah' => 0,
            'minggu_patut' => 0,
            'valid' => 0,
            'menunggu' => 0,
            'tidak_valid' => 0,
            'validasi_tertunda' => 0,
        ];
    }

    private function addToBucket(array &$bucket, array $row): void
    {
        $bucket['siswi'][$row['siswi_id']] = true;
        if ($row['jumlah_minggu_dilaporkan'] > 0) {
            $bucket['siswi_melapor'][$row['siswi_id']] = true;
        }
        $bucket['minggu_diharapkan'] += $row['jumlah_minggu_diharapkan'];
        $bucket['minggu_sudah'] += $row['jumlah_minggu_sudah'];
        $bucket['minggu_patut'] += $row['jumlah_minggu_patut'];
        $bucket['valid'] += $row['valid'];
        $bucket['menunggu'] += $row['menunggu'];
        $bucket['tidak_valid'] += $row['tidak_valid'];
        $bucket['validasi_tertunda'] += $row['validasi_tertunda'];
    }

    private function serializeBucket(array $bucket): array
    {
        $studentCount = count($bucket['siswi']);
        $expectedWeeks = $bucket['minggu_diharapkan'];

        return [
            'nama' => $bucket['nama'],
            'kelurahan' => $bucket['kelurahan'],
            'jumlah_siswi' => $studentCount,
            'jumlah_siswi_melapor' => count($bucket['siswi_melapor']),
            'cakupan_penerimaan' => $studentCount === 0 ? 0 : round(count($bucket['siswi_melapor']) / $studentCount * 100, 1),
            'jumlah_minggu_diharapkan' => $expectedWeeks,
            'jumlah_minggu_sudah' => $bucket['minggu_sudah'],
            'jumlah_minggu_patut' => $bucket['minggu_patut'],
            'tingkat_kepatuhan' => $expectedWeeks === 0 ? 0 : round($bucket['minggu_patut'] / $expectedWeeks * 100, 1),
            'valid' => $bucket['valid'],
            'menunggu' => $bucket['menunggu'],
            'tidak_valid' => $bucket['tidak_valid'],
            'validasi_tertunda' => $bucket['validasi_tertunda'],
        ];
    }

    private function pustuId(User $user): ?int
    {
        if ($user->isAdministrator()) {
            return null;
        }

        abort_unless($user->isAdminPustu() && $user->status === 'AKTIF' && $user->adminPustu?->pustu_id, 403);

        return (int) $user->adminPustu->pustu_id;
    }

    private function emptyReport(): array
    {
        return [
            'periode' => 'Belum ada tahun ajaran aktif',
            'ringkasan' => $this->serializeBucket($this->newBucket()),
            'alasan' => [],
            'perbandingan_sekolah' => [],
            'perbandingan_pustu' => [],
            'baris' => [],
        ];
    }
}
