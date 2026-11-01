<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDistribusiSekolahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'ADMIN_PUSTU' && $this->user()?->status === 'AKTIF';
    }

    public function rules(): array
    {
        return [
            'sekolah_id' => ['required', 'integer', 'exists:sekolah,id'],
            'guru_penerima_id' => ['required', 'integer', 'exists:guru,id'],
            'tgl_serah_terima' => ['required', 'date', 'before_or_equal:today'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.batch_id' => ['required', 'integer', 'distinct', 'exists:batch_ttd,id'],
            'items.*.jumlah_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }
}
