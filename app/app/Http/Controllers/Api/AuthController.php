<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        BlindIndex $blindIndex,
        ActivityLogger $logger,
    ): JsonResponse {
        $identity = $request->string('identitas')->toString();
        $candidates = User::query()
            ->whereIn('login_hash', $blindIndex->hashesForLogin($identity))
            ->orderBy('id')
            ->get();
        $password = $request->string('password')->toString();
        $user = $candidates->first(fn (User $candidate): bool => Hash::check($password, $candidate->password));
        $failedCandidate = $user ?? $candidates->first();

        if ($failedCandidate?->terkunci_sampai?->isFuture()) {
            $logger->login($failedCandidate, 'AKUN_TERKUNCI', $request);

            return response()->json(['message' => 'Akun terkunci sementara. Coba kembali beberapa menit lagi.'], 423);
        }

        if (! $user) {
            if ($failedCandidate) {
                $failedCandidate->login_gagal++;

                if ($failedCandidate->login_gagal >= 5) {
                    $failedCandidate->terkunci_sampai = now()->addMinutes(15);
                    $failedCandidate->login_gagal = 0;
                    $logger->login($failedCandidate, 'AKUN_TERKUNCI', $request);
                    $logger->securityAlert(
                        'LOGIN_GAGAL_BERULANG',
                        'Peringatan keamanan',
                        'Sebuah akun dikunci setelah beberapa kali percobaan masuk gagal.',
                    );
                }

                $failedCandidate->save();
            }

            $logger->login($failedCandidate, 'LOGIN_GAGAL', $request);

            return response()->json(['message' => 'Identitas atau kata sandi tidak sesuai.'], 422);
        }

        if ($user->status !== 'AKTIF') {
            $logger->login($user, 'LOGIN_GAGAL', $request);

            return response()->json(['message' => 'Akun belum aktif. Hubungi petugas yang berwenang.'], 403);
        }

        if (in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true) && $user->wajib_ganti_sandi) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $logger->login($user, 'LOGIN_BERHASIL', $request);

            return response()->json([
                'requires_account_setup' => true,
                'setup_required' => ['GANTI_SANDI'],
                'user' => $this->serializeUser($user),
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'login_gagal' => 0,
            'terkunci_sampai' => null,
            'terakhir_login_at' => now(),
        ])->save();

        $logger->login($user, 'LOGIN_BERHASIL', $request);

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function logout(Request $request, ActivityLogger $logger): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $logger->login($user, 'LOGOUT', $request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Anda berhasil keluar.']);
    }

    /** @return array<string, mixed> */
    private function serializeUser(User $user): array
    {
        return [
            ...$user->only(['id', 'role', 'username', 'status', 'wajib_ganti_sandi']),
            'nama_pengguna' => $user->namaPengguna(),
        ];
    }
}
