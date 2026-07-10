<?php

namespace App\Http\Requests\Video;

use Illuminate\Foundation\Http\FormRequest;

class CreateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_name' => ['nullable', 'string', 'max:100'],
            'comment' => ['required', 'string', 'max:2000'],
            'timestamp_seconds' => ['required', 'integer', 'min:0'],
        ];
    }
}
