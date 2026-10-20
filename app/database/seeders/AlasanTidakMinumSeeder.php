<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlasanTidakMinumSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $reasons = [
            ['kode' => 'MENSTRUASI_NYERI', 'nama' => 'Nyeri menstruasi', 'kategori' => 'MEDIS'],
            ['kode' => 'MAAG_GERD', 'nama' => 'Gangguan lambung', 'kategori' => 'MEDIS'],
            ['kode' => 'EFEK_SAMPING', 'nama' => 'Efek samping', 'kategori' => 'EFEK_SAMPING'],
            ['kode' => 'LUPA', 'nama' => 'Lupa minum', 'kategori' => 'LAINNYA'],
            ['kode' => 'OBAT_HILANG', 'nama' => 'Tablet hilang', 'kategori' => 'LAINNYA'],
        ];

        foreach ($reasons as $reason) {
            DB::table('alasan_tidak_minum')->updateOrInsert(
                ['kode' => $reason['kode']],
                [...$reason, 'aktif' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
