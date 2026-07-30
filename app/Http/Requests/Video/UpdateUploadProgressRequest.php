<?php

namespace App\Http\Requests\Video;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUploadProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the percentage is accepted. `status` is deliberately not settable here:
     * the client knows how many bytes it has sent, but only Cloudflare's webhook
     * knows whether the result is playable.
     */
    public function rules(): array
    {
        return [
            'processing_percentage' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
