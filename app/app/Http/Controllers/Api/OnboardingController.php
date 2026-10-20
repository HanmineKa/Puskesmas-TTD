<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeTemporaryPasswordRequest;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function changeTemporaryPassword(
        ChangeTemporaryPasswordRequest $request,
        ActivityLogger $logger,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->forceFill([
            'password' => $request->validated('password'),
            'wajib_ganti_sandi' => false,
        ])->save();
        $request->session()->regenerate();
        $logger->login($user, 'GANTI_SANDI', $request);

        return response()->json(['message' => 'Kata sandi berhasil diperbarui.']);
    }
}
