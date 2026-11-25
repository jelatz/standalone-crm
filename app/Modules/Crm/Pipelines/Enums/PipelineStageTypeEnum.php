<?php

namespace App\Modules\Crm\Pipelines\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PipelineStageTypeEnum: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
