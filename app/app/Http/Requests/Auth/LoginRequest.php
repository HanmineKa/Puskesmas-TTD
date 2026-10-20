<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identitas' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:15'],
        ];
    }
}
