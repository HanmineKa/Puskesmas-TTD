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
        if (! Schema::hasColumn('users', 'wajib_ganti_sandi')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('wajib_ganti_sandi')->default(false)->after('password');
            });
        }

        $addAuditCategory = ! Schema::hasColumn('audit_log', 'kategori');
        $addAuditPustuId = ! Schema::hasColumn('audit_log', 'pustu_id');

        if ($addAuditCategory || $addAuditPustuId) {
            Schema::table('audit_log', function (Blueprint $table) use ($addAuditCategory, $addAuditPustuId) {
                if ($addAuditCategory) {
                    $table->enum('kategori', ['OPERASIONAL', 'KEAMANAN'])->default('KEAMANAN');
                }
                if ($addAuditPustuId) {
                    $table->unsignedBigInteger('pustu_id')->nullable();
                    $table->index(['pustu_id', 'kategori', 'created_at']);
                }
            });
        }

        Schema::create('pustu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puskesmas_utama_id')->constrained('puskesmas_utama');
            $table->string('nama', 150);
            $table->text('alamat')->nullable();
            $table->string('kontak', 50)->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('admin_pustu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->string('nama', 150);
            $table->string('jabatan')->nullable();
            $table->string('nomor_hp')->nullable();
            $table->enum('jenis_identitas', ['NIP', 'NIK']);
            $table->text('nomor_identitas');
            $table->char('nomor_identitas_hash', 64)->unique();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
            $table->index('pustu_id');
        });

        Schema::create('kecamatan', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('kode', 30)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('kelurahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kecamatan_id')->constrained('kecamatan');
            $table->string('nama', 150);
            $table->string('kode', 30)->nullable();
            $table->timestamps();
            $table->unique(['kecamatan_id', 'nama']);
            $table->index('kode');
        });

        Schema::create('wilayah_kerja_pustu', function (Blueprint $table) {
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->foreignId('kelurahan_id')->constrained('kelurahan');
            $table->primary(['pustu_id', 'kelurahan_id']);
            $table->index('kelurahan_id');
        });

        Schema::create('sekolah', function (Blueprint $table) {
            $table->id();
            $table->char('npsn', 8)->unique();
            $table->string('nama', 150);
            $table->enum('jenjang', ['SMP', 'SMA', 'SMK']);
            $table->text('alamat')->nullable();
            $table->foreignId('kelurahan_id')->constrained('kelurahan');
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->string('kontak', 50)->nullable();
            $table->enum('status', ['AKTIF', 'NONAKTIF'])->default('AKTIF');
            $table->timestamps();
            $table->index(['pustu_id', 'status']);
            $table->index('kelurahan_id');
        });

        Schema::create('sekolah_pustu_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sekolah_id')->constrained('sekolah');
            $table->foreignId('pustu_id')->constrained('pustu');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->foreignId('diubah_oleh')->constrained('users');
            $table->timestamps();
            $table->index(['sekolah_id', 'tgl_mulai']);
        });

        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->id();
            $table->string('label', 9)->unique();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai');
            $table->boolean('aktif')->default(false);
            $table->timestamps();
            $table->index(['aktif', 'tgl_mulai']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreign('pustu_id')->references('id')->on('pustu')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['pustu_id']);
        });

        Schema::dropIfExists('tahun_ajaran');
        Schema::dropIfExists('sekolah_pustu_history');
        Schema::dropIfExists('sekolah');
        Schema::dropIfExists('wilayah_kerja_pustu');
        Schema::dropIfExists('kelurahan');
        Schema::dropIfExists('kecamatan');
        Schema::dropIfExists('admin_pustu');
        Schema::dropIfExists('pustu');
    }
};
