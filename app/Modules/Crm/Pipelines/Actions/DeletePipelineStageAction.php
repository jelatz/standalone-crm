<?php

namespace App\Modules\Crm\Pipelines\Actions;

use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use App\Modules\Crm\Pipelines\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletePipelineStageAction
{
    public function execute(Pipeline $pipeline, PipelineStage $stage): void
    {
        if ((string) $stage->pipeline_id !== (string) $pipeline->id) {
            abort(404);
        }

        DB::transaction(function () use ($stage): void {
            if (Deal::query()->where('stage_id', $stage->id)->exists()) {
                throw ValidationException::withMessages([
                    'stage' => ['Cannot delete a pipeline stage that still has active deals.'],
                ]);
            }

            if (Deal::withTrashed()->where('stage_id', $stage->id)->exists()) {
                throw ValidationException::withMessages([
                    'stage' => ['Cannot delete a pipeline stage referenced by archived deals.'],
                ]);
            }

            $stage->delete();
        });
    }
}
