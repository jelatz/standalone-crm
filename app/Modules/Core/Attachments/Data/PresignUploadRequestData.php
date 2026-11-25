<?php

namespace App\Modules\Core\Attachments\Data;

use Closure;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PresignUploadRequestData extends Data
{
    public const int MAX_SIZE_BYTES = 52_428_800;

    /**
     * @var list<string>
     */
    public const array ALLOWED_EXACT_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
        'text/plain',
        'text/csv',
        'application/zip',
    ];

    public const string OPENXML_MIME_PATTERN = '/^application\/vnd\.openxmlformats-officedocument\.[a-z0-9.+-]+$/D';

    public function __construct(
        #[Required, StringType, Min(1), Max(255)]
        public string $filename,
        #[Required, StringType, Max(127)]
        public string $mime_type,
        #[Required, IntegerType, Min(1), Max(self::MAX_SIZE_BYTES)]
        public int $size_bytes,
        #[Required, StringType, Regex('/^[a-fA-F0-9]{64}$/D')]
        public string $sha256,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'mime_type' => [
                'required',
                'string',
                'max:127',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! self::isAllowedMimeType($value)) {
                        $fail('The selected '.$attribute.' is not an allowed file type.');
                    }
                },
            ],
        ];
    }

    public static function isAllowedMimeType(string $mimeType): bool
    {
        $normalized = strtolower(trim($mimeType));

        if (in_array($normalized, self::ALLOWED_EXACT_MIME_TYPES, true)) {
            return true;
        }

        return preg_match(self::OPENXML_MIME_PATTERN, $normalized) === 1;
    }
}
