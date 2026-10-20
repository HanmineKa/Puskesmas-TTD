<?php

namespace App\Http\Requests;

use App\Models\Sekolah;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSekolahRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sekolah::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'npsn' => ['required', 'digits:8', 'unique:sekolah,npsn'],
            'nama' => ['required', 'string', 'max:150'],
            'jenjang' => ['required', 'in:SMP,SMA,SMK'],
            'alamat' => ['nullable', 'string', 'max:2000'],
            'kelurahan_id' => ['required', 'integer', 'exists:kelurahan,id'],
            'pustu_id' => [
                Rule::prohibitedIf($this->user()?->isAdminPustu() ?? true),
                Rule::when($this->user()?->isAdministrator(), ['required']),
                'nullable',
                'integer',
                Rule::exists('pustu', 'id')->where('aktif', true),
            ],
            'kontak' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'npsn.digits' => 'NPSN harus terdiri dari 8 angka.',
            'npsn.unique' => 'NPSN sudah terdaftar.',
            'jenjang.in' => 'Jenjang harus SMP, SMA, atau SMK.',
            'kelurahan_id.exists' => 'Kelurahan yang dipilih tidak ditemukan.',
            'pustu_id.required' => 'Pustu pembina wajib dipilih.',
        ];
    }
}
