<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTahunAjaranRequest extends FormRequest
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
            'label' => ['required', 'regex:/^\d{4}\/\d{4}$/', 'unique:tahun_ajaran,label'],
            'tgl_mulai' => ['required', 'date_format:Y-m-d'],
            'tgl_selesai' => ['required', 'date_format:Y-m-d', 'after:tgl_mulai'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }
}
