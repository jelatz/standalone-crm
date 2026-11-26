<?php

namespace App\Modules\Projects\Handoffs\Jobs;

use App\Modules\Core\AsyncJobs\Contracts\TrackedJob;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Projects\Handoffs\Actions\ProvisionProjectFromHandoffAction;

class ProvisionProjectFromHandoffJob extends TrackedJob
{
    public int $tries = 3;

    public function __construct(
        public readonly string $handoffId,
        string $asyncJobRunId = '',
    ) {
        parent::__construct($asyncJobRunId);
    }

    protected function process(AsyncJobRun $run): void
    {
        app(ProvisionProjectFromHandoffAction::class)->execute($this->handoffId);
    }
}
