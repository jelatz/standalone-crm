<?php

namespace App\Modules\Crm\Deals\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SyncDealContactsData extends Data
{
    /**
     * @param  list<DealStakeholderInputData>  $contacts
     */
    public function __construct(
        #[Required, ArrayType, DataCollectionOf(DealStakeholderInputData::class)]
        public array $contacts,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'contacts' => ['present', 'array'],
            'contacts.*.contact_id' => ['required', 'uuid', 'distinct'],
        ];
    }
}
