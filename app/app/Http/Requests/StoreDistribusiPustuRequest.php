<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDistribusiPustuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'ADMINISTRATOR' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:batch_ttd,id'],
            'pustu_id' => ['required', 'integer', 'exists:pustu,id'],
            'jumlah' => ['required', 'integer', 'min:1'],
            'tgl_kirim' => ['required', 'date', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
