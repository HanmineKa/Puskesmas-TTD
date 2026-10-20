<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterSiswiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150'],
            'nisn' => ['required', 'digits:10'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:15'],
            'kode_token' => ['required', 'string', 'max:30'],
            'kelas_id' => ['required', 'integer', Rule::exists('kelas', 'id')],
            'persetujuan_data' => ['accepted'],
            'versi_persetujuan' => ['required', 'string', 'max:20'],
        ];
    }
}
