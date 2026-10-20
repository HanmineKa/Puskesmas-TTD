<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyGuruRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true);
    }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', 'in:DISETUJUI,DITOLAK'],
            'alasan_penolakan' => ['required_if:keputusan,DITOLAK', 'nullable', 'string', 'max:500'],
        ];
    }
}
