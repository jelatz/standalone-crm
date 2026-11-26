<?php

namespace App\Modules\Crm\Timeline\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TimelinePageData extends Data
{
    /**
     * @param  list<ActivityData>  $items
     */
    public function __construct(
        public array $items,
        public ?string $next_cursor,
        public bool $has_more,
    ) {}
}
