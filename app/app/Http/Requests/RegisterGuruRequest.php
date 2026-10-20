<?php

namespace App\Http\Requests;

use App\Models\Guru;
use App\Models\User;
use App\Services\Security\BlindIndex;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterGuruRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sekolah_id' => ['required', 'integer', Rule::exists('sekolah', 'id')->where('status', 'AKTIF')],
            'nama' => ['required', 'string', 'max:150'],
            'nuptk' => ['required', 'digits:16'],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:15'],
            'dokumen_sk' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function after(Validator $validator, BlindIndex $blindIndex): array
    {
        return [function () use ($validator, $blindIndex): void {
            if ($validator->errors()->has('nuptk')) {
                return;
            }

            $identity = $this->string('nuptk')->toString();
            if (Guru::query()->where('nuptk_hash', $blindIndex->hash($identity))->exists()
                || User::query()->where('login_hash', $blindIndex->hash('NUPTK:'.$identity))->exists()) {
                $validator->errors()->add('nuptk', 'NUPTK sudah terdaftar.');
            }
        }];
    }
}
