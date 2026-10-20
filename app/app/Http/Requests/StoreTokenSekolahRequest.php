<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenSekolahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'GURU';
    }

    public function rules(): array
    {
        return [
            'tahun_ajaran_id' => ['required', 'integer', Rule::exists('tahun_ajaran', 'id')->where('aktif', true)],
        ];
    }
}
