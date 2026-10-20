<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadUlangLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'SISWI' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
