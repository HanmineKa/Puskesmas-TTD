<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMutasiSiswiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['GURU', 'ADMINISTRATOR', 'ADMIN_PUSTU'], true);
    }

    public function rules(): array
    {
        return [
            'enrollment_id' => ['required', 'integer', Rule::exists('enrollment', 'id')->where('status', 'AKTIF')],
            'jenis' => ['required', Rule::in([
                'PINDAH_SEKOLAH_SATU_PUSTU', 'PINDAH_ANTAR_PUSTU', 'KELUAR_WILAYAH',
                'NAIK_JENJANG', 'LULUS', 'PUTUS_SEKOLAH',
            ])],
            'sekolah_tujuan_id' => ['nullable', 'integer', Rule::exists('sekolah', 'id')->where('status', 'AKTIF')],
            'kelas_tujuan_id' => ['nullable', 'integer', Rule::exists('kelas', 'id')],
            'tgl_efektif' => ['required', 'date'],
            'alasan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
