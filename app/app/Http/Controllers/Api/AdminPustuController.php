<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminPustuRequest;
use App\Http\Requests\UpdateAdminPustuRequest;
use App\Models\AdminPustu;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPustuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return response()->json([
            'data' => AdminPustu::query()
                ->with(['pustu:id,nama', 'user:id,status,terakhir_login_at'])
                ->orderBy('nama')
                ->paginate(25),
        ]);
    }

    public function store(
        StoreAdminPustuRequest $request,
        BlindIndex $blindIndex,
        ActivityLogger $logger,
    ): JsonResponse {
        $values = $request->validated();
        $identityHash = $blindIndex->hash($values['jenis_identitas'].':'.$values['nomor_identitas']);
        $temporaryPassword = Str::password(15);

        $adminPustu = DB::transaction(function () use ($request, $values, $identityHash, $temporaryPassword, $logger): AdminPustu {
            $user = User::query()->create([
                'role' => 'ADMIN_PUSTU',
                'login_hash' => $identityHash,
                'password' => $temporaryPassword,
                'status' => 'AKTIF',
                'wajib_ganti_sandi' => true,
            ]);

            $adminPustu = AdminPustu::query()->create([
                'user_id' => $user->id,
                'pustu_id' => $values['pustu_id'],
                'nama' => $values['nama'],
                'jabatan' => $values['jabatan'] ?? null,
                'nomor_hp' => $values['nomor_hp'] ?? null,
                'jenis_identitas' => $values['jenis_identitas'],
                'nomor_identitas' => $values['nomor_identitas'],
                'nomor_identitas_hash' => $identityHash,
                'dibuat_oleh' => $request->user()->id,
            ]);

            $logger->audit(
                $request->user(), 'ADMIN_PUSTU_DIBUAT', 'AdminPustu', $adminPustu->id,
                ['akun' => ['after' => 'dibuat'], 'nomor_identitas' => ['after' => 'disimpan']],
                $request, 'KEAMANAN', $adminPustu->pustu_id,
            );
            $logger->securityAlert(
                'AKUN_PUSTU_DIBUAT',
                'Akun petugas baru',
                'Administrator telah membuat akun petugas Pustu baru.',
            );

            return $adminPustu;
        });

        return response()->json([
            'data' => $adminPustu->load('pustu:id,nama', 'user:id,status,wajib_ganti_sandi'),
            'kata_sandi_sementara' => $temporaryPassword,
            'message' => 'Simpan kata sandi sementara ini. Nilai ini hanya ditampilkan sekali.',
        ], 201);
    }

    public function show(Request $request, AdminPustu $adminPustu): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return response()->json([
            'data' => $adminPustu->load('pustu:id,nama', 'user:id,status,terakhir_login_at'),
        ]);
    }

    public function update(
        UpdateAdminPustuRequest $request,
        AdminPustu $adminPustu,
        ActivityLogger $logger,
    ): JsonResponse {
        $before = $adminPustu->only(['pustu_id', 'nama', 'jabatan', 'nomor_hp']);
        $before['status'] = $adminPustu->user->status;
        $values = $request->safe()->only(['pustu_id', 'nama', 'jabatan', 'nomor_hp']);

        DB::transaction(function () use ($request, $adminPustu, $values, $before, $logger): void {
            $adminPustu->update($values);
            $status = $request->validated('status');
            if ($status !== null) {
                $adminPustu->user()->update(['status' => $status]);
            }

            $after = $adminPustu->only(['pustu_id', 'nama', 'jabatan', 'nomor_hp']);
            $after['status'] = $adminPustu->user()->value('status');
            $logger->audit(
                $request->user(), 'ADMIN_PUSTU_DIPERBARUI', 'AdminPustu', $adminPustu->id,
                $this->changes($before, $after),
                $request, 'KEAMANAN', $adminPustu->pustu_id,
            );
        });

        return response()->json([
            'data' => $adminPustu->fresh()->load('pustu:id,nama', 'user:id,status'),
        ]);
    }

    public function destroy(Request $request, AdminPustu $adminPustu, ActivityLogger $logger): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);

        DB::transaction(function () use ($request, $adminPustu, $logger): void {
            $adminPustu->user()->update(['status' => 'NONAKTIF']);
            $logger->audit(
                $request->user(), 'ADMIN_PUSTU_DINONAKTIFKAN', 'AdminPustu', $adminPustu->id,
                ['status' => ['after' => 'NONAKTIF']], $request, 'KEAMANAN', $adminPustu->pustu_id,
            );
        });

        return response()->json(['message' => 'Akun petugas berhasil dinonaktifkan.']);
    }

    public function resetPassword(Request $request, AdminPustu $adminPustu, ActivityLogger $logger): JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $temporaryPassword = Str::password(15);

        DB::transaction(function () use ($request, $adminPustu, $temporaryPassword, $logger): void {
            $user = $adminPustu->user;
            $user->forceFill([
                'password' => $temporaryPassword,
                'wajib_ganti_sandi' => true,
            ])->save();
            $logger->login($user, 'RESET_SANDI', $request);
            $logger->audit(
                $request->user(), 'SANDI_ADMIN_PUSTU_DIRESET', 'AdminPustu', $adminPustu->id,
                ['password' => ['berubah' => true]], $request, 'KEAMANAN', $adminPustu->pustu_id,
            );
        });

        return response()->json([
            'kata_sandi_sementara' => $temporaryPassword,
            'message' => 'Simpan kata sandi sementara ini. Nilai ini hanya ditampilkan sekali.',
        ]);
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $key => $value) {
            if ($value !== $after[$key]) {
                $changes[$key] = ['before' => $value, 'after' => $after[$key]];
            }
        }

        return $changes;
    }
}
