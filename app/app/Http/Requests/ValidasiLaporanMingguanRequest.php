<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidasiLaporanMingguanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'GURU' && $this->user()?->status === 'AKTIF';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status_validasi' => ['required', Rule::in(['VALID', 'TIDAK_VALID'])],
            'catatan_validasi' => ['required_if:status_validasi,TIDAK_VALID', 'prohibited_if:status_validasi,VALID', 'nullable', 'string', 'max:500'],
        ];
    }
}
