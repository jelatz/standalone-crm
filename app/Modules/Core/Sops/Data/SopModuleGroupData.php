<?php

namespace App\Modules\Core\Sops\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SopModuleGroupData extends Data
{
    /**
     * @param  list<SopSummaryData>  $sops
     */
    public function __construct(
        public string $module,
        public string $label,
        public array $sops,
    ) {}
}
