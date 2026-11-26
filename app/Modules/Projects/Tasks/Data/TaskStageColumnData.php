<?php

namespace App\Modules\Projects\Tasks\Data;

use App\Modules\Projects\Projects\Data\ProjectStageData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TaskStageColumnData extends Data
{
    /**
     * @param  list<TaskData>  $tasks
     */
    public function __construct(
        public ProjectStageData $stage,
        public int $tasks_count,
        public array $tasks,
    ) {}
}
