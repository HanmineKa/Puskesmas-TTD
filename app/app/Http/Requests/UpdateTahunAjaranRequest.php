<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTahunAjaranRequest extends FormRequest
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
            'label' => ['sometimes', 'required', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('tahun_ajaran', 'label')->ignore($this->route('tahun_ajaran'))],
            'tgl_mulai' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'tgl_selesai' => ['sometimes', 'required', 'date_format:Y-m-d', 'after:tgl_mulai'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }
}
