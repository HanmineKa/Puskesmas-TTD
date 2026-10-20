<?php

namespace App\Services\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateDocumentStorage
{
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function store(UploadedFile $file): string
    {
        $mimeType = $file->getMimeType();
        if (! $file->isValid()
            || ! array_key_exists($mimeType, self::MIME_EXTENSIONS)
            || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'dokumen_sk' => 'Dokumen harus berupa PDF, JPG, atau PNG dengan ukuran maksimal 5 MB.',
            ]);
        }

        $path = 'dokumen-guru/'.Str::uuid().'.'.self::MIME_EXTENSIONS[$mimeType].'.enc';
        $content = file_get_contents($file->getRealPath());
        if (! is_string($content)) {
            throw ValidationException::withMessages(['dokumen_sk' => 'Dokumen tidak dapat dibaca.']);
        }

        Storage::disk('local')->put($path, Crypt::encryptString($content));

        return $path;
    }

    public function retrieve(string $path): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($path));
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }
}
