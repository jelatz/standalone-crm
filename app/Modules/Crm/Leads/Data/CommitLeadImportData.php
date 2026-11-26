<?php

namespace App\Modules\Crm\Leads\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CommitLeadImportData extends Data
{
    /**
     * @param  list<ImportLeadRowPayloadData>  $rows
     */
    public function __construct(
        #[Required, ArrayType, Min(1), Max(1000), DataCollectionOf(ImportLeadRowPayloadData::class)]
        public array $rows,
    ) {}
}
