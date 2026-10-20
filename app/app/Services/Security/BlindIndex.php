<?php

namespace App\Services\Security;

use RuntimeException;

class BlindIndex
{
    /** @return list<string> */
    public function hashesForLogin(string $identity): array
    {
        $identity = mb_strtolower(trim($identity));

        $prefixes = match (true) {
            preg_match('/^\d{10}$/', $identity) === 1 => ['NISN:'],
            preg_match('/^\d{18}$/', $identity) === 1 => ['NIP:'],
            preg_match('/^\d{16}$/', $identity) === 1 => ['NUPTK:', 'NIK:'],
            default => ['USERNAME:'],
        };

        return array_map(fn (string $prefix): string => $this->hash($prefix.$identity), $prefixes);
    }

    public function hash(string $identity): string
    {
        $configuredKey = (string) config('security.blind_index_key');
        $key = str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true)
            : $configuredKey;

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Kunci blind index harus berupa secret acak minimal 32 byte.');
        }

        return hash_hmac('sha256', mb_strtolower(trim($identity)), $key);
    }
}
