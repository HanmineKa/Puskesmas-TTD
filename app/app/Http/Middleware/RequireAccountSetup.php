<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAccountSetup
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->requiresSetup($user)) {
            return response()->json([
                'message' => 'Selesaikan pengamanan akun sebelum menggunakan fitur aplikasi.',
                'requires_account_setup' => true,
            ], 423);
        }

        return $next($request);
    }

    private function requiresSetup(User $user): bool
    {
        return (bool) $user->wajib_ganti_sandi;
    }
}
