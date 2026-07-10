<?php

namespace App\Http\Requests\Video;

use Illuminate\Foundation\Http\FormRequest;

class CreateUploadUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
