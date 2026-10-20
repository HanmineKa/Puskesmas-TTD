<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSekolahRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('sekolah')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'npsn' => ['sometimes', 'required', 'digits:8', Rule::unique('sekolah', 'npsn')->ignore($this->route('sekolah'))],
            'nama' => ['sometimes', 'required', 'string', 'max:150'],
            'jenjang' => ['sometimes', 'required', 'in:SMP,SMA,SMK'],
            'alamat' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kelurahan_id' => ['sometimes', 'required', 'integer', 'exists:kelurahan,id'],
            'pustu_id' => [
                Rule::prohibitedIf($this->user()?->isAdminPustu() ?? true),
                Rule::when($this->user()?->isAdministrator(), ['sometimes', 'required']),
                'integer',
                Rule::exists('pustu', 'id')->where('aktif', true),
            ],
            'kontak' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'required', 'in:AKTIF,NONAKTIF'],
        ];
    }
}
