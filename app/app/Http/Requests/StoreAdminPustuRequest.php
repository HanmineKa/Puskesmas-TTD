<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Security\BlindIndex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdminPustuRequest extends FormRequest
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
            'pustu_id' => ['required', 'integer', Rule::exists('pustu', 'id')->where('aktif', true)],
            'nama' => ['required', 'string', 'max:150'],
            'jabatan' => ['nullable', 'string', 'max:100'],
            'nomor_hp' => ['nullable', 'string', 'max:30'],
            'jenis_identitas' => ['required', 'in:NIP,NIK'],
            'nomor_identitas' => [
                'required', 'string',
                $this->input('jenis_identitas') === 'NIP' ? 'digits:18' : 'digits:16',
            ],
        ];
    }

    public function after(Validator $validator, BlindIndex $blindIndex): array
    {
        return [function () use ($validator, $blindIndex): void {
            if ($validator->errors()->hasAny(['jenis_identitas', 'nomor_identitas'])) {
                return;
            }

            $hash = $blindIndex->hash($this->string('jenis_identitas').':'.$this->string('nomor_identitas'));

            if (User::query()->where('login_hash', $hash)->exists()) {
                $validator->errors()->add('nomor_identitas', 'Nomor identitas sudah digunakan.');
            }
        }];
    }
}
