<?php

namespace App\Modules\Projects\Handoffs\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ReviewHandoffData extends Data
{
    public function __construct(
        public string $review_feedback,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'review_feedback' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
