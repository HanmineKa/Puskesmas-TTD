<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('puskesmas_utama', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->text('alamat')->nullable();
            $table->string('kontak', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('administrator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->foreignId('puskesmas_utama_id')->constrained('puskesmas_utama');
            $table->string('nama', 150);
            $table->timestamps();
        });

        Schema::create('notifikasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('tipe', 50);
            $table->string('judul', 150);
            $table->text('isi');
            $table->string('tautan', 255)->nullable();
            $table->dateTime('dibaca_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'dibaca_at']);
        });

        Schema::create('pengaturan', function (Blueprint $table) {
            $table->string('kunci', 60)->primary();
            $table->string('nilai', 255);
            $table->foreignId('diubah_oleh')->nullable()->constrained('users');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('log_login', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->enum('peristiwa', [
                'LOGIN_BERHASIL', 'LOGIN_GAGAL', 'LOGOUT', 'AKUN_TERKUNCI',
                'GANTI_SANDI', 'RESET_SANDI', 'DUA_FAKTOR_AKTIF', 'DUA_FAKTOR_GAGAL',
            ]);
            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('role', 20);
            $table->string('aksi', 60);
            $table->enum('kategori', ['OPERASIONAL', 'KEAMANAN'])->default('KEAMANAN');
            $table->unsignedBigInteger('pustu_id')->nullable();
            $table->string('objek_tipe', 50)->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->json('perubahan')->nullable();
            $table->string('ip_address', 45);
            $table->char('request_id', 36)->nullable();
            $table->char('hash_sebelumnya', 64)->nullable();
            $table->char('hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->index(['objek_tipe', 'objek_id']);
            $table->index(['pustu_id', 'kategori', 'created_at']);
        });

        Schema::create('log_akses_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('role', 20);
            $table->enum('jenis_akses', [
                'BUKA_DETAIL_SISWI', 'BUKA_DETAIL_GURU', 'LIHAT_FOTO', 'UNDUH_FOTO', 'EKSPOR',
            ]);
            $table->string('objek_tipe', 50)->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->json('filter')->nullable();
            $table->unsignedInteger('jumlah_baris')->nullable();
            $table->string('ip_address', 45);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_akses_data');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('log_login');
        Schema::dropIfExists('pengaturan');
        Schema::dropIfExists('notifikasi');
        Schema::dropIfExists('administrator');
        Schema::dropIfExists('puskesmas_utama');
    }
};
