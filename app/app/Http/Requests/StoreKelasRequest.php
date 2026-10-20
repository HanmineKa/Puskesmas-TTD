<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreKelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'GURU';
    }

    public function rules(): array
    {
        $schoolId = $this->user()?->guru?->sekolah_id;
        $activeYearId = DB::table('tahun_ajaran')->where('aktif', true)->value('id');

        return [
            'nama' => [
                'required', 'string', 'max:50',
                Rule::unique('kelas', 'nama')->where(fn ($query) => $query
                    ->where('sekolah_id', $schoolId)
                    ->where('tahun_ajaran_id', $activeYearId)),
            ],
            'tingkat' => ['required', 'integer', 'between:7,13'],
            'kelas_akhir' => ['sometimes', 'boolean'],
        ];
    }
}
