<?php

namespace App\Services\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Laravel\Facades\Image;

class PrivatePhotoStorage
{
    private const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function storeEvidence(UploadedFile $file): string
    {
        if (! $file->isValid()
            || ! in_array($file->getMimeType(), self::ACCEPTED_MIME_TYPES, true)
            || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'foto' => 'Foto harus berupa JPG, PNG, atau WebP dengan ukuran maksimal 5 MB.',
            ]);
        }

        $jpeg = Image::decodePath($file->getRealPath())
            ->orient()
            ->cover(1024, 1024)
            ->removeProfile()
            ->encodeUsingMediaType('image/jpeg', quality: 85)
            ->toString();

        $path = 'bukti-ttd/'.Str::uuid().'.jpg.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($jpeg));

        return $path;
    }

    public function retrieveEvidence(string $path): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($path));
    }
}
