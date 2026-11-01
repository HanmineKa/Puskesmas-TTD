<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatchTtdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'ADMINISTRATOR' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'nomor_batch' => ['required', 'string', 'max:50', 'unique:batch_ttd,nomor_batch'],
            'tgl_kadaluwarsa' => ['required', 'date', 'after_or_equal:tgl_terima'],
            'jumlah_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'isi_per_box' => ['required', 'integer', 'min:1', 'max:1000'],
            'tgl_terima' => ['required', 'date', 'before_or_equal:today'],
            'sumber' => ['nullable', 'string', 'max:150'],
        ];
    }
}
