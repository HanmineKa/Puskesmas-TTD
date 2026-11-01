<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true)
            && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'jenis' => ['required', Rule::in(['IDENTITAS', 'AGREGAT'])],
            'tahun_ajaran_id' => ['nullable', 'integer', 'exists:tahun_ajaran,id'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'sekolah_id' => ['nullable', 'integer', 'exists:sekolah,id'],
            'kelurahan_id' => ['nullable', 'integer', 'exists:kelurahan,id'],
            'kata_sandi' => ['required_if:jenis,IDENTITAS', 'nullable', 'string', 'min:8', 'max:15'],
        ];
    }
}
