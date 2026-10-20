<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengecualianMedisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'GURU' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'siswi_id' => ['required', 'integer', 'exists:siswi,id'],
            'detail' => ['required', 'string', 'max:1000'],
            'tgl_mulai' => ['required', 'date'],
            'tgl_selesai' => ['nullable', 'date', 'after_or_equal:tgl_mulai'],
        ];
    }
}
