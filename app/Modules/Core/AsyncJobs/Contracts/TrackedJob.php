<?php

namespace App\Modules\Core\AsyncJobs\Contracts;

use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\AsyncJobs\Events\AsyncJobStatusUpdated;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionParameter;
use Throwable;

abstract class TrackedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $asyncJobRunId = '';

    public function __construct(string $asyncJobRunId = '')
    {
        $this->asyncJobRunId = $asyncJobRunId;
    }

    /**
     * @param  array<int|string, mixed>  $constructorArgs
     * @param  array<string, mixed>  $payloadSummary
     */
    public static function dispatchTracked(
        array $constructorArgs,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $triggeredById = null,
        ?string $idempotencyKey = null,
        array $payloadSummary = [],
        string $queue = 'default',
    ): AsyncJobRun {
        $normalizedKey = $idempotencyKey === '' ? null : $idempotencyKey;

        /** @var array{0: AsyncJobRun, 1: bool} $result */
        $result = DB::transaction(static function () use (
            $subjectType,
            $subjectId,
            $triggeredById,
            $normalizedKey,
            $payloadSummary,
            $queue,
        ): array {
            $jobClass = Str::limit(static::class, 180, '');
            $normalizedQueue = Str::limit($queue, 64, '');

            if ($normalizedKey !== null) {
                $encodedPayload = $payloadSummary === []
                    ? '{}'
                    : json_encode($payloadSummary, JSON_THROW_ON_ERROR);

                $inserted = DB::table('async_job_runs')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'job_class' => $jobClass,
                    'queue' => $normalizedQueue,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'triggered_by_id' => $triggeredById,
                    'status' => AsyncJobStatus::Queued->value,
                    'attempt' => 1,
                    'idempotency_key' => $normalizedKey,
                    'payload_summary' => $encodedPayload,
                    'queued_at' => now(),
                ]);

                $existing = AsyncJobRun::query()
                    ->where('idempotency_key', $normalizedKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($inserted > 0) {
                    return [$existing, true];
                }

                if ($existing->status !== AsyncJobStatus::Failed) {
                    return [$existing, false];
                }

                $existing->forceFill([
                    'job_class' => $jobClass,
                    'queue' => $normalizedQueue,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'triggered_by_id' => $triggeredById,
                    'status' => AsyncJobStatus::Queued,
                    'attempt' => 1,
                    'payload_summary' => $payloadSummary,
                    'error_code' => null,
                    'error_message' => null,
                    'queued_at' => now(),
                    'started_at' => null,
                    'finished_at' => null,
                ])->save();

                return [$existing, true];
            }

            $run = AsyncJobRun::query()->create([
                'job_class' => $jobClass,
                'queue' => $normalizedQueue,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'triggered_by_id' => $triggeredById,
                'status' => AsyncJobStatus::Queued,
                'attempt' => 1,
                'idempotency_key' => null,
                'payload_summary' => $payloadSummary,
                'queued_at' => now(),
            ]);

            return [$run, true];
        });

        [$run, $shouldDispatch] = $result;

        if ($shouldDispatch) {
            $job = static::instantiateTrackedJob($run, $constructorArgs);
            $job->onQueue($queue);
            $job->afterCommit();
            dispatch($job);
        }

        return $run;
    }

    public function handle(): void
    {
        if ($this->asyncJobRunId === '') {
            throw new LogicException('TrackedJob must be dispatched with an AsyncJobRun ID.');
        }

        /** @var AsyncJobRun|null $run */
        $run = DB::transaction(function (): ?AsyncJobRun {
            $lockedRun = AsyncJobRun::query()
                ->whereKey($this->asyncJobRunId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedRun->status, [AsyncJobStatus::Queued, AsyncJobStatus::Failed], true)) {
                return null;
            }

            $attempt = $lockedRun->started_at !== null
                ? max($this->attempts(), $lockedRun->attempt + 1)
                : max($this->attempts(), $lockedRun->attempt);

            $lockedRun->forceFill([
                'status' => AsyncJobStatus::Running,
                'attempt' => $attempt,
                'started_at' => now(),
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return $lockedRun;
        });

        if ($run === null) {
            return;
        }

        event(new AsyncJobStatusUpdated($run));

        try {
            $this->process($run);

            $run->forceFill([
                'status' => AsyncJobStatus::Completed,
                'finished_at' => now(),
            ])->save();

            event(new AsyncJobStatusUpdated($run));
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => AsyncJobStatus::Failed,
                'error_code' => Str::limit(class_basename($e), 64, ''),
                'error_message' => Str::limit($e->getMessage(), 1000),
                'finished_at' => now(),
            ])->save();

            event(new AsyncJobStatusUpdated($run));

            throw $e;
        }
    }

    abstract protected function process(AsyncJobRun $run): void;

    /**
     * @param  array<int|string, mixed>  $constructorArgs
     */
    protected static function instantiateTrackedJob(AsyncJobRun $run, array $constructorArgs): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $runId = (string) $run->id;

        if ($constructor === null) {
            /** @var static $job */
            $job = new static;
            $job->asyncJobRunId = $runId;

            return $job;
        }

        $parameters = $constructor->getParameters();
        $paramNames = array_map(
            static fn (ReflectionParameter $param): string => $param->getName(),
            $parameters,
        );

        if ($parameters !== [] && $paramNames[0] === 'asyncJobRunId') {
            if (array_is_list($constructorArgs)) {
                /** @var static $job */
                $job = new static($runId, ...$constructorArgs);

                return $job;
            }

            /** @var static $job */
            $job = new static(...[...$constructorArgs, 'asyncJobRunId' => $runId]);

            return $job;
        }

        if (in_array('asyncJobRunId', $paramNames, true) && ! array_is_list($constructorArgs)) {
            /** @var static $job */
            $job = new static(...[...$constructorArgs, 'asyncJobRunId' => $runId]);

            return $job;
        }

        /** @var static $job */
        $job = new static(...$constructorArgs);
        $job->asyncJobRunId = $runId;

        return $job;
    }
}
