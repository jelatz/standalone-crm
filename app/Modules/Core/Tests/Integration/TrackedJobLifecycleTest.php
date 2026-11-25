<?php

use App\Modules\Core\AsyncJobs\Contracts\TrackedJob;
use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\AsyncJobs\Events\AsyncJobStatusUpdated;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

if (! class_exists('IntegrationSuccessTrackedJob')) {
    class IntegrationSuccessTrackedJob extends TrackedJob
    {
        /** @var list<array{run_id: string, status_during_process: string}> */
        public static array $processedSnapshots = [];

        public function __construct(
            public string $entityId,
            public string $note = 'default',
        ) {}

        protected function process(AsyncJobRun $run): void
        {
            self::$processedSnapshots[] = [
                'run_id' => (string) $run->id,
                'status_during_process' => (string) $run->fresh()?->status->value,
            ];
        }
    }
}

if (! class_exists('IntegrationFailingTrackedJob')) {
    class IntegrationFailingTrackedJob extends TrackedJob
    {
        public static bool $shouldFail = true;

        public function __construct(
            public string $errorMessage = 'Job explosion',
        ) {}

        protected function process(AsyncJobRun $run): void
        {
            if (self::$shouldFail) {
                throw new RuntimeException($this->errorMessage);
            }
        }
    }
}

$createIntegrationUser = static function (
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
};

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    IntegrationSuccessTrackedJob::$processedSnapshots = [];
    IntegrationFailingTrackedJob::$shouldFail = true;

    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('defers TrackedJob execution until outer DB::transaction commits due to afterCommit()', function () use ($createIntegrationUser): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-aftercommit']);
    $user = $createIntegrationUser($dept, 'EMP-JOB-AC', 'job.aftercommit@acme.com', RoleEnum::Sales);
    $subjectId = (string) Str::uuid7();

    /** @var AsyncJobRun|null $dispatchedRun */
    $dispatchedRun = null;

    DB::transaction(function () use ($subjectId, $user, &$dispatchedRun): void {
        $dispatchedRun = IntegrationSuccessTrackedJob::dispatchTracked(
            constructorArgs: [$subjectId, 'outer-tx'],
            subjectType: 'deal',
            subjectId: $subjectId,
            triggeredById: (string) $user->id,
        );

        expect($dispatchedRun->fresh()->status)->toBe(AsyncJobStatus::Queued)
            ->and(IntegrationSuccessTrackedJob::$processedSnapshots)->toBe([]);
    });

    expect($dispatchedRun)->not->toBeNull()
        ->and($dispatchedRun->fresh()->status)->toBe(AsyncJobStatus::Completed)
        ->and(IntegrationSuccessTrackedJob::$processedSnapshots)->toHaveCount(1);
});

it('tracks TrackedJob lifecycle queued -> running -> completed in DB and broadcasts minimal PrivateChannel events', function () use ($createIntegrationUser): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-jobs']);
    $user = $createIntegrationUser($dept, 'EMP-JOB-1', 'job.user@acme.com', RoleEnum::Sales);
    $subjectId = (string) Str::uuid7();

    Event::fake([AsyncJobStatusUpdated::class]);

    $run = IntegrationSuccessTrackedJob::dispatchTracked(
        constructorArgs: [$subjectId, 'sync-check'],
        subjectType: 'deal',
        subjectId: $subjectId,
        triggeredById: (string) $user->id,
        idempotencyKey: 'job-sync-key',
        payloadSummary: ['secret_note' => 'do-not-broadcast'],
    );

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(AsyncJobStatus::Completed)
        ->and($fresh->attempt)->toBe(1)
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->error_code)->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and(IntegrationSuccessTrackedJob::$processedSnapshots)->toBe([
            [
                'run_id' => (string) $run->id,
                'status_during_process' => AsyncJobStatus::Running->value,
            ],
        ]);

    Event::assertDispatchedTimes(AsyncJobStatusUpdated::class, 2);

    $dispatched = Event::dispatched(AsyncJobStatusUpdated::class);
    $firstEvent = $dispatched[0][0];
    $secondEvent = $dispatched[1][0];

    expect($firstEvent)->toBeInstanceOf(AsyncJobStatusUpdated::class)
        ->and($firstEvent->broadcastWhen())->toBeTrue()
        ->and($firstEvent->broadcastOn())->toHaveCount(1)
        ->and($firstEvent->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($firstEvent->broadcastOn()[0]->name)->toBe("private-user.{$user->id}")
        ->and($firstEvent->broadcastWith())->toBe([
            'async_job_run_id' => (string) $run->id,
            'status' => AsyncJobStatus::Running->value,
            'subject_type' => 'deal',
            'subject_id' => $subjectId,
            'error_code' => null,
        ])
        ->and($secondEvent->broadcastWith())->toBe([
            'async_job_run_id' => (string) $run->id,
            'status' => AsyncJobStatus::Completed->value,
            'subject_type' => 'deal',
            'subject_id' => $subjectId,
            'error_code' => null,
        ]);

    // System jobs without triggeredById suppress user channel broadcasting
    $systemRun = IntegrationSuccessTrackedJob::dispatchTracked(constructorArgs: [$subjectId, 'system']);
    $systemEvent = Event::dispatched(AsyncJobStatusUpdated::class)[2][0];
    expect($systemRun->fresh()->status)->toBe(AsyncJobStatus::Completed)
        ->and($systemEvent->broadcastWhen())->toBeFalse()
        ->and($systemEvent->broadcastOn())->toBe([]);
});

it('transitions TrackedJob to failed with truncated error metadata and increments attempt on retry', function () use ($createIntegrationUser): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-fail']);
    $user = $createIntegrationUser($dept, 'EMP-JOB-2', 'job.fail@acme.com', RoleEnum::Sales);
    $longMessage = str_repeat('X', 1500);

    Event::fake([AsyncJobStatusUpdated::class]);

    $thrown = null;
    try {
        IntegrationFailingTrackedJob::dispatchTracked(
            constructorArgs: [$longMessage],
            subjectType: 'deal',
            subjectId: (string) Str::uuid7(),
            triggeredById: (string) $user->id,
        );
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);

    $failedRun = AsyncJobRun::query()->latest('queued_at')->firstOrFail();
    expect($failedRun->status)->toBe(AsyncJobStatus::Failed)
        ->and($failedRun->attempt)->toBe(1)
        ->and($failedRun->error_code)->toBe('RuntimeException')
        ->and(mb_strlen((string) $failedRun->error_message))->toBeLessThanOrEqual(1003)
        ->and($failedRun->finished_at)->not->toBeNull();

    $dispatched = Event::dispatched(AsyncJobStatusUpdated::class);
    expect($dispatched)->toHaveCount(2)
        ->and($dispatched[0][0]->broadcastWith()['status'])->toBe(AsyncJobStatus::Running->value)
        ->and($dispatched[1][0]->broadcastWith()['status'])->toBe(AsyncJobStatus::Failed->value)
        ->and($dispatched[1][0]->broadcastWith()['error_code'])->toBe('RuntimeException');

    // Simulate queue worker retry of the same AsyncJobRun once transient failure resolves
    IntegrationFailingTrackedJob::$shouldFail = false;
    $retryJob = new IntegrationFailingTrackedJob($longMessage);
    $retryJob->asyncJobRunId = (string) $failedRun->id;
    $retryJob->handle();

    $recovered = $failedRun->fresh();
    expect($recovered->status)->toBe(AsyncJobStatus::Completed)
        ->and($recovered->attempt)->toBe(2)
        ->and($recovered->error_code)->toBeNull()
        ->and($recovered->error_message)->toBeNull()
        ->and($recovered->finished_at)->not->toBeNull();
});

it('handles concurrent first-time idempotency_key conflict via insertOrIgnore without UniqueConstraintViolationException', function (): void {
    $raceKey = 'concurrent-race-'.Str::uuid7();
    $winnerId = (string) Str::uuid7();

    $pgConfig = config('database.connections.pgsql');
    $secondPdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $pgConfig['host'],
            $pgConfig['port'],
            $pgConfig['database'],
        ),
        $pgConfig['username'],
        $pgConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $insertStmt = $secondPdo->prepare(
        'INSERT INTO async_job_runs (id, job_class, queue, status, attempt, idempotency_key, payload_summary, queued_at)
         VALUES (:id, :job_class, :queue, :status, 1, :idempotency_key, :payload_summary, NOW())'
    );
    $insertStmt->execute([
        ':id' => $winnerId,
        ':job_class' => IntegrationSuccessTrackedJob::class,
        ':queue' => 'default',
        ':status' => AsyncJobStatus::Queued->value,
        ':idempotency_key' => $raceKey,
        ':payload_summary' => '{}',
    ]);

    Queue::fake();

    $run = IntegrationSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-race'],
        idempotencyKey: $raceKey,
    );

    expect((string) $run->id)->toBe($winnerId)
        ->and($run->status)->toBe(AsyncJobStatus::Queued);
    Queue::assertNothingPushed();

    $freshKey = 'fresh-aftercommit-'.Str::uuid7();
    $freshRun = IntegrationSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-fresh'],
        idempotencyKey: $freshKey,
    );

    Queue::assertPushed(
        IntegrationSuccessTrackedJob::class,
        static fn (IntegrationSuccessTrackedJob $job): bool => $job->afterCommit === true
            && $job->asyncJobRunId === (string) $freshRun->id,
    );
});

it('guards TrackedJob::handle() against duplicate delivery when run is already Running or Completed', function (): void {
    $subjectId = (string) Str::uuid7();

    // 1. Already Completed -> duplicate delivery returns early without invoking process() or broadcasting
    $completedRun = IntegrationSuccessTrackedJob::dispatchTracked(
        constructorArgs: [$subjectId, 'completed-guard'],
    );
    expect($completedRun->fresh()->status)->toBe(AsyncJobStatus::Completed)
        ->and(IntegrationSuccessTrackedJob::$processedSnapshots)->toHaveCount(1);

    IntegrationSuccessTrackedJob::$processedSnapshots = [];
    Event::fake([AsyncJobStatusUpdated::class]);

    $duplicateForCompleted = new IntegrationSuccessTrackedJob($subjectId, 'completed-guard');
    $duplicateForCompleted->asyncJobRunId = (string) $completedRun->id;
    $duplicateForCompleted->handle();

    expect(IntegrationSuccessTrackedJob::$processedSnapshots)->toBe([])
        ->and($completedRun->fresh()->status)->toBe(AsyncJobStatus::Completed)
        ->and($completedRun->fresh()->attempt)->toBe(1);
    Event::assertNotDispatched(AsyncJobStatusUpdated::class);

    // 2. Already Running -> duplicate delivery returns early without invoking process() or broadcasting
    $runningStartedAt = now()->subSeconds(5);
    $runningRun = AsyncJobRun::query()->create([
        'job_class' => IntegrationSuccessTrackedJob::class,
        'queue' => 'default',
        'subject_type' => 'deal',
        'subject_id' => $subjectId,
        'status' => AsyncJobStatus::Running,
        'attempt' => 1,
        'payload_summary' => [],
        'queued_at' => now()->subSeconds(10),
        'started_at' => $runningStartedAt,
    ]);

    $duplicateForRunning = new IntegrationSuccessTrackedJob($subjectId, 'running-guard');
    $duplicateForRunning->asyncJobRunId = (string) $runningRun->id;
    $duplicateForRunning->handle();

    $freshRunning = $runningRun->fresh();
    expect(IntegrationSuccessTrackedJob::$processedSnapshots)->toBe([])
        ->and($freshRunning->status)->toBe(AsyncJobStatus::Running)
        ->and($freshRunning->attempt)->toBe(1)
        ->and($freshRunning->finished_at)->toBeNull();
    Event::assertNotDispatched(AsyncJobStatusUpdated::class);
});
