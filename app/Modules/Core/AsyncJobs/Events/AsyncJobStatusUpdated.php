<?php

namespace App\Modules\Core\AsyncJobs\Events;

use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class AsyncJobStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly AsyncJobRun $asyncJobRun;

    public readonly string $asyncJobRunId;

    public readonly AsyncJobStatus $status;

    public readonly ?string $subjectType;

    public readonly ?string $subjectId;

    public readonly ?string $triggeredById;

    public readonly ?string $errorCode;

    public function __construct(AsyncJobRun $asyncJobRun)
    {
        $this->asyncJobRun = clone $asyncJobRun;
        $this->asyncJobRunId = (string) $asyncJobRun->id;
        $this->status = $asyncJobRun->status;
        $this->subjectType = $asyncJobRun->subject_type;
        $this->subjectId = $asyncJobRun->subject_id !== null ? (string) $asyncJobRun->subject_id : null;
        $this->triggeredById = $asyncJobRun->triggered_by_id !== null ? (string) $asyncJobRun->triggered_by_id : null;
        $this->errorCode = $asyncJobRun->error_code;
    }

    public function broadcastWhen(): bool
    {
        return $this->triggeredById !== null;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->triggeredById === null) {
            return [];
        }

        return [
            new PrivateChannel('user.'.$this->triggeredById),
        ];
    }

    /**
     * @return array{
     *     async_job_run_id: string,
     *     status: string,
     *     subject_type: ?string,
     *     subject_id: ?string,
     *     error_code: ?string,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'async_job_run_id' => $this->asyncJobRunId,
            'status' => $this->status->value,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'error_code' => $this->errorCode,
        ];
    }
}
