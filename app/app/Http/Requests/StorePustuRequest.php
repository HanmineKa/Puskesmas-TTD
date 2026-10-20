<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePustuRequest extends FormRequest
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
            'nama' => ['required', 'string', 'max:150'],
            'alamat' => ['nullable', 'string', 'max:2000'],
            'kontak' => ['nullable', 'string', 'max:50'],
            'aktif' => ['sometimes', 'boolean'],
            'wilayah_kelurahan_ids' => ['sometimes', 'array', 'max:500'],
            'wilayah_kelurahan_ids.*' => ['integer', Rule::exists('kelurahan', 'id')],
        ];
    }
}
