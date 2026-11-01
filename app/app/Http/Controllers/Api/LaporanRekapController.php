<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportLaporanRequest;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Reports\LaporanRekapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanRekapController extends Controller
{
    public function options(Request $request, LaporanRekapService $reports): JsonResponse
    {
        $user = $this->authorizedUser($request);

        return response()->json(['data' => $reports->options($user)]);
    }

    public function index(Request $request, LaporanRekapService $reports): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $filters = $request->validate([
            'tahun_ajaran_id' => ['nullable', 'integer', 'exists:tahun_ajaran,id'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'sekolah_id' => ['nullable', 'integer', 'exists:sekolah,id'],
            'kelurahan_id' => ['nullable', 'integer', 'exists:kelurahan,id'],
        ]);
        $reports->assertFilterScope($user, $filters);

        return response()->json($reports->build($user, $filters));
    }

    public function export(
        ExportLaporanRequest $request,
        LaporanRekapService $reports,
        ActivityLogger $logger,
    ): StreamedResponse {
        $user = $this->authorizedUser($request);
        $values = $request->validated();
        $isIdentityExport = $values['jenis'] === 'IDENTITAS';

        abort_unless(! $isIdentityExport || $user->isAdministrator(), 403);

        if ($isIdentityExport && ! Hash::check($values['kata_sandi'], $user->password)) {
            throw ValidationException::withMessages([
                'kata_sandi' => 'Kata sandi yang dimasukkan tidak sesuai.',
            ]);
        }

        $filters = array_diff_key($values, array_flip(['jenis', 'kata_sandi']));
        $reports->assertFilterScope($user, $filters);
        $result = $reports->build($user, $filters, $isIdentityExport);
        $rows = $isIdentityExport ? $result['baris'] : $result['perbandingan_sekolah'];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($isIdentityExport ? 'Data Siswi' : 'Rekap Sekolah');

        if ($isIdentityExport) {
            $headings = [
                'Sekolah', 'Kelurahan', 'Kelas', 'Nama Siswi', 'NISN', 'Status Siswi',
                'Minggu Diharapkan', 'Minggu Tercatat Sudah Minum', 'Minggu Patuh',
                'Tingkat Kepatuhan (%)', 'Alasan Utama Tidak Minum', 'Valid', 'Menunggu',
                'Tidak Valid', 'Validasi Tertunda',
            ];
            $sheetRows = array_map(fn (array $row): array => [
                $row['sekolah'], $row['kelurahan'], $row['kelas'], $row['nama'], $row['nisn'],
                $row['status_siswi'], $row['jumlah_minggu_diharapkan'], $row['jumlah_minggu_sudah'],
                $row['jumlah_minggu_patut'], $row['persentase_kepatuhan'], $row['alasan_utama'],
                $row['valid'], $row['menunggu'], $row['tidak_valid'], $row['validasi_tertunda'],
            ], $rows);
        } else {
            $headings = [
                'Sekolah', 'Kelurahan', 'Jumlah Siswi', 'Siswi Melapor', 'Cakupan Penerimaan (%)',
                'Minggu Diharapkan', 'Minggu Tercatat Sudah Minum', 'Minggu Patuh',
                'Tingkat Kepatuhan (%)', 'Valid', 'Menunggu', 'Tidak Valid', 'Validasi Tertunda',
            ];
            $sheetRows = array_map(fn (array $row): array => [
                $row['nama'], $row['kelurahan'], $row['jumlah_siswi'], $row['jumlah_siswi_melapor'],
                $row['cakupan_penerimaan'], $row['jumlah_minggu_diharapkan'], $row['jumlah_minggu_sudah'],
                $row['jumlah_minggu_patut'], $row['tingkat_kepatuhan'], $row['valid'], $row['menunggu'],
                $row['tidak_valid'], $row['validasi_tertunda'],
            ], $rows);
        }

        $this->writeSheet($sheet, $headings, $sheetRows);
        $reasonSheet = $spreadsheet->createSheet();
        $reasonSheet->setTitle('Alasan Tidak Minum');
        $this->writeSheet($reasonSheet, ['Alasan', 'Kategori', 'Jumlah'], array_map(
            fn (array $reason): array => [$reason['nama'], $reason['kategori'], $reason['jumlah']],
            $result['alasan'],
        ));

        $logger->dataAccess($user, 'EKSPOR', 'LaporanMingguan', null, $request, $filters, count($rows));

        return response()->streamDownload(
            static fn () => (new Xlsx($spreadsheet))->save('php://output'),
            'laporan-ttd.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true)
                && $user->status === 'AKTIF',
            403,
        );

        return $user;
    }

    /** @param array<int, string> $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function writeSheet(Worksheet $sheet, array $headings, array $rows): void
    {
        $this->writeRow($sheet, 1, $headings);

        foreach ($rows as $index => $row) {
            $this->writeRow($sheet, $index + 2, $row);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /** @param array<int, mixed> $values */
    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $cell = $column.$row;

            if (is_int($value) || is_float($value)) {
                $sheet->setCellValue($cell, $value);
            } else {
                $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
    }
}
