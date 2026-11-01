<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenerimaanPaketSiswiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'GURU' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'siswi_id' => ['required', 'integer', 'exists:siswi,id'],
            'distribusi_sekolah_id' => ['required', 'integer', 'exists:distribusi_sekolah,id'],
            'batch_id' => ['required', 'integer', 'exists:batch_ttd,id'],
            'tgl_diterima' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
