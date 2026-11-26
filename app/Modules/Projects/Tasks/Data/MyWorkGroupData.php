<?php

namespace App\Modules\Projects\Tasks\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MyWorkGroupData extends Data
{
    /**
     * @param  list<TaskData>  $tasks
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $tasks_count,
        public array $tasks,
    ) {}
}
