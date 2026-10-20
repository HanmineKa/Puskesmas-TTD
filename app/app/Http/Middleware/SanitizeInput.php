<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    private const EXCLUDED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($this->sanitize($request->all()));

        return $next($request);
    }

    private function sanitize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array((string) $key, self::EXCLUDED_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $input[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $input[$key] = trim(strip_tags($value));
            }
        }

        return $input;
    }
}
