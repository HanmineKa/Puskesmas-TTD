<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengaturanPelaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'ADMINISTRATOR' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'batas_hari_validasi' => ['required', 'integer', 'between:1,30'],
            'jeda_pengingat_hari' => ['required', 'integer', 'between:1,30'],
        ];
    }
}
