<?php

namespace App\Services\Logging;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityLogger
{
    private const SENSITIVE_FIELDS = [
        'nama', 'nisn', 'nisn_hash', 'nuptk', 'nuptk_hash', 'nomor_identitas',
        'nomor_identitas_hash', 'password', 'token', 'alasan',
        'detail', 'catatan', 'dokumen_sk', 'foto_path',
    ];

    public function login(?User $user, string $event, Request $request): void
    {
        DB::table('log_login')->insert([
            'user_id' => $user?->id,
            'peristiwa' => $event,
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'created_at' => now(),
        ]);
    }

    public function audit(
        ?User $user,
        string $action,
        ?string $objectType,
        ?int $objectId,
        array $changes,
        Request $request,
        string $category = 'KEAMANAN',
        ?int $pustuId = null,
    ): void {
        $safeChanges = [];

        foreach ($changes as $field => $values) {
            $safeChanges[$field] = $this->isSensitiveField((string) $field)
                ? ['berubah' => true]
                : $values;
        }

        DB::table('audit_log')->insert([
            'user_id' => $user?->id,
            'role' => $user?->role ?? 'SISTEM',
            'aksi' => $action,
            'kategori' => $category,
            'pustu_id' => $pustuId,
            'objek_tipe' => $objectType,
            'objek_id' => $objectId,
            'perubahan' => $safeChanges === [] ? null : json_encode($safeChanges),
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'request_id' => $request->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }

    public function dataAccess(
        User $user,
        string $type,
        ?string $objectType,
        ?int $objectId,
        Request $request,
        ?array $filters = null,
        ?int $rowCount = null,
    ): void {
        DB::table('log_akses_data')->insert([
            'user_id' => $user->id,
            'role' => $user->role,
            'jenis_akses' => $type,
            'objek_tipe' => $objectType,
            'objek_id' => $objectId,
            'filter' => $filters === null ? null : json_encode($this->redactSensitiveValues($filters)),
            'jumlah_baris' => $rowCount,
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'created_at' => now(),
        ]);
    }

    public function securityAlert(string $type, string $title, string $message): void
    {
        $administratorIds = User::query()
            ->where('role', 'ADMINISTRATOR')
            ->where('status', 'AKTIF')
            ->pluck('id');

        if ($administratorIds->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('notifikasi')->insert($administratorIds->map(fn (int $userId): array => [
            'user_id' => $userId,
            'tipe' => $type,
            'judul' => $title,
            'isi' => $message,
            'dibaca_at' => null,
            'created_at' => $now,
        ])->all());
    }

    private function redactSensitiveValues(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = $this->isSensitiveField((string) $key)
                ? ['disamarkan' => true]
                : (is_array($value) ? $this->redactSensitiveValues($value) : $value);
        }

        return $redacted;
    }

    private function isSensitiveField(string $field): bool
    {
        $field = mb_strtolower($field);

        foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
            if ($field === $sensitiveField || str_ends_with($field, '_'.$sensitiveField)) {
                return true;
            }
        }

        return false;
    }
}
