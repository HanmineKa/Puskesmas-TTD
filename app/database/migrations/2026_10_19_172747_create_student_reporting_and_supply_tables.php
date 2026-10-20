<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->string('nama', 50);
            $table->unsignedTinyInteger('tingkat');
            $table->boolean('kelas_akhir')->default(false);
            $table->timestamps();
            $table->unique(['sekolah_id', 'tahun_ajaran_id', 'nama']);
        });

        Schema::create('guru', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->text('nama');
            $table->text('nuptk');
            $table->char('nuptk_hash', 64)->unique();
            $table->text('dokumen_sk')->nullable();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users');
            $table->dateTime('tgl_verifikasi')->nullable();
            $table->text('alasan_penolakan')->nullable();
            $table->timestamps();
            $table->index('sekolah_id');
        });

        Schema::create('token_sekolah', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->string('kode', 30)->unique();
            $table->boolean('aktif')->default(true);
            if (DB::getDriverName() === 'mysql') {
                $table->string('aktif_kunci', 40)->nullable()
                    ->storedAs("IF(aktif = 1, CONCAT(sekolah_id, '-', tahun_ajaran_id), NULL)")
                    ->unique();
            } else {
                $table->string('aktif_kunci', 40)->nullable()
                    ->storedAs("CASE WHEN aktif = 1 THEN CAST(sekolah_id AS TEXT) || '-' || CAST(tahun_ajaran_id AS TEXT) ELSE NULL END")
                    ->unique();
            }
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('siswi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->text('nisn');
            $table->char('nisn_hash', 64)->unique();
            $table->text('nama');
            $table->enum('status_pelacakan', ['AKTIF', 'DALAM_MUTASI', 'LULUS', 'KELUAR_WILAYAH', 'PUTUS_SEKOLAH'])->default('AKTIF');
            $table->dateTime('persetujuan_data_at');
            $table->string('versi_persetujuan', 20);
            $table->timestamps();
        });

        Schema::create('enrollment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('siswi_id')->constrained('siswi');
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->foreignId('kelas_id')->constrained('kelas');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->date('tgl_masuk');
            $table->date('tgl_keluar')->nullable();
            $table->enum('status', ['AKTIF', 'SELESAI'])->default('AKTIF');
            $table->enum('jenis_keluar', [
                'NAIK_KELAS', 'PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU',
                'KELUAR_WILAYAH', 'NAIK_JENJANG', 'LULUS', 'PUTUS_SEKOLAH',
            ])->nullable();
            $table->unsignedBigInteger('mutasi_id')->nullable();
            $table->unsignedBigInteger('aktif_siswi_id')->nullable()
                ->storedAs("CASE WHEN status = 'AKTIF' THEN siswi_id ELSE NULL END")
                ->unique();
            $table->timestamps();
            $table->index(['sekolah_id', 'tahun_ajaran_id', 'status']);
            $table->index(['pustu_id', 'tahun_ajaran_id']);
        });

        Schema::create('mutasi_siswi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('siswi_id')->constrained('siswi');
            $table->enum('jenis', [
                'PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU', 'KELUAR_WILAYAH',
                'NAIK_JENJANG', 'LULUS', 'PUTUS_SEKOLAH',
            ]);
            $table->foreignId('enrollment_asal_id')->constrained('enrollment');
            $table->foreignId('sekolah_asal_id')->constrained('sekolah');
            $table->foreignId('pustu_asal_id')->constrained('pustu');
            $table->foreignId('sekolah_tujuan_id')->nullable()->constrained('sekolah');
            $table->foreignId('pustu_tujuan_id')->nullable()->constrained('pustu');
            $table->foreignId('kelas_tujuan_id')->nullable()->constrained('kelas');
            $table->enum('status', ['PENDING', 'DISETUJUI', 'DITOLAK', 'SELESAI'])->default('PENDING');
            $table->date('tgl_efektif');
            $table->text('alasan')->nullable();
            $table->foreignId('diajukan_oleh')->constrained('users');
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users');
            $table->dateTime('tgl_keputusan')->nullable();
            $table->text('catatan_penolakan')->nullable();
            $table->timestamps();
            $table->index(['status', 'pustu_tujuan_id']);
            $table->index('siswi_id');
        });

        Schema::table('enrollment', function (Blueprint $table): void {
            $table->foreign('mutasi_id')->references('id')->on('mutasi_siswi')->restrictOnDelete();
        });

        Schema::create('pengecualian_medis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('siswi_id')->constrained('siswi');
            $table->text('detail');
            $table->foreignId('ditandai_oleh')->constrained('users');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->timestamps();
        });

        Schema::create('alasan_tidak_minum', function (Blueprint $table): void {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 100);
            $table->enum('kategori', ['MEDIS', 'EFEK_SAMPING', 'LAINNYA']);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('laporan_mingguan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('siswi_id')->constrained('siswi');
            $table->foreignId('enrollment_id')->constrained('enrollment');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->unsignedTinyInteger('minggu_ke');
            $table->enum('status_minum', ['SUDAH', 'TIDAK']);
            $table->dateTime('waktu_minum')->nullable();
            $table->string('foto_path', 255)->nullable();
            $table->foreignId('alasan_id')->nullable()->constrained('alasan_tidak_minum');
            $table->text('catatan')->nullable();
            $table->enum('status_validasi', ['MENUNGGU', 'VALID', 'TIDAK_VALID'])->nullable();
            $table->foreignId('divalidasi_oleh')->nullable()->constrained('guru');
            $table->dateTime('tgl_validasi')->nullable();
            $table->text('catatan_validasi')->nullable();
            $table->timestamps();
            $table->unique(['siswi_id', 'tahun_ajaran_id', 'minggu_ke']);
            $table->index('enrollment_id');
            $table->index(['status_validasi', 'created_at']);
        });

        Schema::create('pengingat', function (Blueprint $table): void {
            $table->id();
            $table->enum('jenis', ['PENGINGAT_MINUM', 'PENGINGAT_VALIDASI']);
            $table->foreignId('penerima_user_id')->constrained('users');
            $table->foreignId('pengirim_user_id')->nullable()->constrained('users');
            $table->foreignId('laporan_id')->nullable()->constrained('laporan_mingguan');
            $table->dateTime('dikirim_at');
        });

        Schema::create('batch_ttd', function (Blueprint $table): void {
            $table->id();
            $table->string('nomor_batch', 50)->unique();
            $table->date('tgl_kadaluwarsa');
            $table->unsignedInteger('jumlah_box')->nullable();
            $table->unsignedSmallInteger('isi_per_box')->default(100);
            $table->unsignedInteger('jumlah_masuk');
            $table->date('tgl_terima');
            $table->string('sumber', 150)->nullable();
            $table->foreignId('dicatat_oleh')->constrained('users');
            $table->timestamps();
        });

        Schema::create('distribusi_pustu', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('batch_ttd');
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->unsignedInteger('jumlah');
            $table->enum('status', ['PENDING', 'COMPLETED', 'DIBATALKAN'])->default('PENDING');
            $table->foreignId('dikirim_oleh')->constrained('users');
            $table->dateTime('tgl_kirim');
            $table->foreignId('dikonfirmasi_oleh')->nullable()->constrained('users');
            $table->dateTime('tgl_konfirmasi')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('stok_pustu', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->foreignId('batch_id')->constrained('batch_ttd');
            $table->unsignedInteger('jumlah');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['pustu_id', 'batch_id']);
        });
        DB::statement('ALTER TABLE stok_pustu ADD CONSTRAINT stok_pustu_jumlah_check CHECK (jumlah >= 0)');

        Schema::create('distribusi_sekolah', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->unsignedSmallInteger('tahun');
            $table->unsignedInteger('nomor_urut');
            $table->string('nomor_dokumen', 60);
            $table->date('tgl_serah_terima');
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->foreignId('guru_penerima_id')->constrained('guru');
            $table->foreignId('dikirim_oleh')->constrained('users');
            $table->string('nama_petugas', 150);
            $table->string('nama_guru', 150);
            $table->string('nama_sekolah', 150);
            $table->text('keterangan')->nullable();
            $table->enum('status', ['PENDING', 'COMPLETED', 'DIBATALKAN'])->default('PENDING');
            $table->dateTime('tgl_kirim');
            $table->foreignId('dikonfirmasi_oleh')->nullable()->constrained('users');
            $table->dateTime('tgl_konfirmasi')->nullable();
            $table->timestamps();
            $table->unique(['pustu_id', 'tahun', 'nomor_urut']);
            $table->index(['sekolah_id', 'status']);
        });

        Schema::create('distribusi_sekolah_item', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribusi_sekolah_id')->constrained('distribusi_sekolah');
            $table->foreignId('batch_id')->constrained('batch_ttd');
            $table->string('nama_barang', 150)->default('Tablet Tambah Darah (TTD)');
            $table->unsignedInteger('jumlah_box');
            $table->unsignedSmallInteger('isi_per_box')->default(100);
            $table->unsignedInteger('jumlah_tablet');
            $table->string('keterangan', 255)->nullable();
            $table->timestamps();
            $table->unique(['distribusi_sekolah_id', 'batch_id']);
        });

        Schema::create('penerimaan_paket_siswi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('siswi_id')->constrained('siswi');
            $table->foreignId('enrollment_id')->constrained('enrollment');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('distribusi_sekolah_id')->nullable()->constrained('distribusi_sekolah');
            $table->foreignId('batch_id')->constrained('batch_ttd');
            $table->unsignedSmallInteger('jumlah_tablet')->default(52);
            $table->date('tgl_diterima');
            $table->foreignId('dicatat_oleh')->constrained('users');
            $table->timestamps();
            $table->unique(['siswi_id', 'tahun_ajaran_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_paket_siswi');
        Schema::dropIfExists('distribusi_sekolah_item');
        Schema::dropIfExists('distribusi_sekolah');
        Schema::dropIfExists('stok_pustu');
        Schema::dropIfExists('distribusi_pustu');
        Schema::dropIfExists('batch_ttd');
        Schema::dropIfExists('pengingat');
        Schema::dropIfExists('laporan_mingguan');
        Schema::dropIfExists('alasan_tidak_minum');
        Schema::dropIfExists('pengecualian_medis');
        Schema::table('enrollment', function (Blueprint $table): void {
            $table->dropForeign(['mutasi_id']);
        });
        Schema::dropIfExists('mutasi_siswi');
        Schema::dropIfExists('enrollment');
        Schema::dropIfExists('siswi');
        Schema::dropIfExists('token_sekolah');
        Schema::dropIfExists('guru');
        Schema::dropIfExists('kelas');
    }
};
