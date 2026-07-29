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

        // return [
        //     'title' => ['nullable', 'string', 'max:255'],
        //     'description' => ['nullable', 'string'],
        //     'duration_seconds' => ['nullable', 'integer', 'min:0'],
        //     'size_bytes' => ['nullable', 'integer', 'min:0'],
        // ];

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
            'file_type' => ['nullable', 'string', 'max:150'],

            'max_duration_seconds' => [
                'nullable',
                'integer',
                'min:1',
                'max:86400',
            ],
        ];
    }
}
