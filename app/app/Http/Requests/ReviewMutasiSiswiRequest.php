<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMutasiSiswiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true);
    }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', Rule::in(['DISETUJUI', 'DITOLAK'])],
            'catatan_penolakan' => ['required_if:keputusan,DITOLAK', 'nullable', 'string', 'max:500'],
            'kelas_tujuan_id' => ['nullable', 'integer', Rule::exists('kelas', 'id')],
        ];
    }
}
