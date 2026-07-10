<?php

namespace App\Http\Requests\Video;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'privacy' => ['sometimes', 'required', Rule::in(['private', 'unlisted', 'password', 'disabled'])],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
        ];
    }
}
