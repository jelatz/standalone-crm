<?php

namespace App\Modules\Core\Attachments\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PresignUploadResponseData extends Data
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $attachment_id,
        public string $upload_url,
        public array $headers,
        public string $object_key,
        public string $expires_at,
    ) {}
}
