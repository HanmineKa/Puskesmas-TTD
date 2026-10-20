<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKelurahanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'kecamatan_id' => ['required', 'integer', 'exists:kecamatan,id'],
            'nama' => [
                'required', 'string', 'max:150',
                Rule::unique('kelurahan', 'nama')->where('kecamatan_id', $this->input('kecamatan_id')),
            ],
            'kode' => ['nullable', 'string', 'max:30'],
        ];
    }
}
