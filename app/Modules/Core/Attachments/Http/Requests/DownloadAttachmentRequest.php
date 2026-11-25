<?php

namespace App\Modules\Core\Attachments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'context_type' => ['required', 'string', 'max:32'],
            'context_id' => ['required', 'uuid'],
        ];
    }
}
