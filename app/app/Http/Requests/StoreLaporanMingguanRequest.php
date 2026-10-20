<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaporanMingguanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'SISWI' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'status_minum' => ['required', Rule::in(['SUDAH', 'TIDAK'])],
            'waktu_minum' => ['required_if:status_minum,SUDAH', 'nullable', 'date', 'before_or_equal:now'],
            'foto' => ['required_if:status_minum,SUDAH', 'prohibited_if:status_minum,TIDAK', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alasan_id' => [
                'required_if:status_minum,TIDAK',
                'prohibited_if:status_minum,SUDAH',
                'nullable',
                'integer',
                Rule::exists('alasan_tidak_minum', 'id')->where('aktif', true),
            ],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
