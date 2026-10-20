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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->enum('role', ['ADMINISTRATOR', 'ADMIN_PUSTU', 'GURU', 'SISWI']);
            $table->char('login_hash', 64)->unique();
            $table->string('username', 50)->nullable()->unique();
            $table->string('password');
            $table->boolean('wajib_ganti_sandi')->default(false);
            $table->enum('status', ['AKTIF', 'MENUNGGU_VERIFIKASI', 'DITOLAK', 'NONAKTIF'])
                ->default('MENUNGGU_VERIFIKASI');
            $table->unsignedTinyInteger('login_gagal')->default(0);
            $table->dateTime('terkunci_sampai')->nullable();
            $table->dateTime('terakhir_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
