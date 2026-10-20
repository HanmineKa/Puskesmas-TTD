<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmMutasiSiswiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'ADMIN_PUSTU';
    }

    public function rules(): array
    {
        return [
            'kelas_tujuan_id' => ['required', 'integer', Rule::exists('kelas', 'id')],
        ];
    }
}
