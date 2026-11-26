<?php

namespace App\Modules\Core\Sops\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SopDocumentData extends Data
{
    public function __construct(
        public string $module,
        public string $slug,
        public string $filename,
        public string $relative_path,
        public string $title,
        public int $size_bytes,
        public string $updated_at,
        public string $sha256,
        public string $content,
    ) {}
}
