<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['log_login', 'audit_log', 'log_akses_data'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Log bersifat append-only'");
            DB::unprepared("CREATE TRIGGER {$table}_append_only_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Log bersifat append-only'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['log_login', 'audit_log', 'log_akses_data'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only_delete");
        }
    }
};
