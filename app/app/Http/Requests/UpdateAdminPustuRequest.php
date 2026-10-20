<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminPustuRequest extends FormRequest
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
            'pustu_id' => ['sometimes', 'integer', Rule::exists('pustu', 'id')->where('aktif', true)],
            'nama' => ['sometimes', 'required', 'string', 'max:150'],
            'jabatan' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nomor_hp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'required', 'in:AKTIF,NONAKTIF'],
        ];
    }
}
