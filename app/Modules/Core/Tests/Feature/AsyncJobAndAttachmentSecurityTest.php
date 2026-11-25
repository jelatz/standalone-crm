<?php

use App\Modules\Core\AsyncJobs\Contracts\TrackedJob;
use App\Modules\Core\AsyncJobs\Enums\AsyncJobStatus;
use App\Modules\Core\AsyncJobs\Events\AsyncJobStatusUpdated;
use App\Modules\Core\AsyncJobs\Models\AsyncJobRun;
use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use App\Modules\Core\Attachments\Policies\AttachmentPolicy;
use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

if (! class_exists('TestSuccessTrackedJob')) {
    class TestSuccessTrackedJob extends TrackedJob
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

if (! class_exists('TestFailingTrackedJob')) {
    class TestFailingTrackedJob extends TrackedJob
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

if (! class_exists('TestSiloAttachmentRecord')) {
    class TestSiloAttachmentRecord extends Model
    {
        use HasSiloVisibility;
        use HasUuidV7;

        protected $table = 'silo_attachment_test_records';

        protected $guarded = [];
    }
}

if (! class_exists('TestSiloAttachmentRecordPolicy')) {
    class TestSiloAttachmentRecordPolicy
    {
        public function view(User $viewer, TestSiloAttachmentRecord $record): bool
        {
            return TestSiloAttachmentRecord::query()
                ->whereKey($record->id)
                ->visibleTo($viewer)
                ->exists();
        }
    }
}

function createAttachmentTestUser(
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    TestSuccessTrackedJob::$processedSnapshots = [];
    TestFailingTrackedJob::$shouldFail = true;

    Schema::dropIfExists('silo_attachment_test_records');
    Schema::create('silo_attachment_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->string('title');
        $table->timestampsTz();
    });

    Relation::morphMap([
        'silo_attachment_record' => TestSiloAttachmentRecord::class,
    ]);
    Gate::policy(TestSiloAttachmentRecord::class, TestSiloAttachmentRecordPolicy::class);

    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('verifies PostgreSQL tables, indexes, morph map entries, AttachmentPolicy registration, and web+auth route middleware', function (): void {
    expect(Schema::hasTable('async_job_runs'))->toBeTrue()
        ->and(Schema::hasTable('attachments'))->toBeTrue()
        ->and(Schema::hasTable('attachment_links'))->toBeTrue();

    $jobIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'async_job_runs'"))
        ->pluck('indexname')
        ->all();
    expect($jobIndexes)->toContain('idx_job_subject', 'idx_job_status_queue');

    $linkIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'attachment_links'"))
        ->pluck('indexname')
        ->all();
    expect($linkIndexes)->toContain('idx_attachment_links_target');

    expect(Relation::getMorphedModel('attachment'))->toBe(Attachment::class)
        ->and(Relation::getMorphedModel('async_job_run'))->toBe(AsyncJobRun::class)
        ->and(Gate::getPolicyFor(Attachment::class))->toBeInstanceOf(AttachmentPolicy::class);

    $presignRoute = Route::getRoutes()->getByName('attachments.presign');
    $downloadRoute = Route::getRoutes()->getByName('attachments.download');

    expect($presignRoute)->not->toBeNull()
        ->and($presignRoute->gatherMiddleware())->toContain('web', 'auth')
        ->and($downloadRoute)->not->toBeNull()
        ->and($downloadRoute->gatherMiddleware())->toContain('web', 'auth');
});

it('dispatches TrackedJob to target queue in queued state without executing prematurely', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-queued']);
    $user = createAttachmentTestUser($dept, 'EMP-JOB-Q', 'job.queued@acme.com', RoleEnum::Sales);
    $subjectId = (string) Str::uuid7();

    Queue::fake();

    $queuedRun = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entityId' => $subjectId, 'note' => 'queued-check'],
        subjectType: 'deal',
        subjectId: $subjectId,
        triggeredById: (string) $user->id,
        idempotencyKey: 'job-queued-key',
        payloadSummary: ['source' => 'unit-test'],
        queue: 'imports',
    );

    expect($queuedRun)->toBeInstanceOf(AsyncJobRun::class)
        ->and(Str::isUuid($queuedRun->id))->toBeTrue()
        ->and($queuedRun->status)->toBe(AsyncJobStatus::Queued)
        ->and($queuedRun->attempt)->toBe(1)
        ->and($queuedRun->queue)->toBe('imports')
        ->and($queuedRun->subject_type)->toBe('deal')
        ->and($queuedRun->subject_id)->toBe($subjectId)
        ->and((string) $queuedRun->triggered_by_id)->toBe((string) $user->id)
        ->and((string) $queuedRun->triggeredBy?->id)->toBe((string) $user->id)
        ->and($queuedRun->payload_summary)->toBe(['source' => 'unit-test'])
        ->and($queuedRun->queued_at)->not->toBeNull()
        ->and($queuedRun->started_at)->toBeNull()
        ->and($queuedRun->finished_at)->toBeNull()
        ->and(TestSuccessTrackedJob::$processedSnapshots)->toBe([]);

    Queue::assertPushedOn('imports', TestSuccessTrackedJob::class);
});

it('tracks TrackedJob lifecycle queued -> running -> completed in DB and broadcasts minimal PrivateChannel events', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-jobs']);
    $user = createAttachmentTestUser($dept, 'EMP-JOB-1', 'job.user@acme.com', RoleEnum::Sales);
    $subjectId = (string) Str::uuid7();

    Event::fake([AsyncJobStatusUpdated::class]);

    $run = TestSuccessTrackedJob::dispatchTracked(
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
        ->and(TestSuccessTrackedJob::$processedSnapshots)->toBe([
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
    $systemRun = TestSuccessTrackedJob::dispatchTracked(constructorArgs: [$subjectId, 'system']);
    $systemEvent = Event::dispatched(AsyncJobStatusUpdated::class)[2][0];
    expect($systemRun->fresh()->status)->toBe(AsyncJobStatus::Completed)
        ->and($systemEvent->broadcastWhen())->toBeFalse()
        ->and($systemEvent->broadcastOn())->toBe([]);
});

it('transitions TrackedJob to failed with truncated error metadata and increments attempt on retry', function (): void {
    $dept = Department::query()->create(['name' => 'Ops', 'slug' => 'ops-fail']);
    $user = createAttachmentTestUser($dept, 'EMP-JOB-2', 'job.fail@acme.com', RoleEnum::Sales);
    $longMessage = str_repeat('X', 1500);

    Event::fake([AsyncJobStatusUpdated::class]);

    $thrown = null;
    try {
        TestFailingTrackedJob::dispatchTracked(
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
    TestFailingTrackedJob::$shouldFail = false;
    $retryJob = new TestFailingTrackedJob($longMessage);
    $retryJob->asyncJobRunId = (string) $failedRun->id;
    $retryJob->handle();

    $recovered = $failedRun->fresh();
    expect($recovered->status)->toBe(AsyncJobStatus::Completed)
        ->and($recovered->attempt)->toBe(2)
        ->and($recovered->error_code)->toBeNull()
        ->and($recovered->error_message)->toBeNull()
        ->and($recovered->finished_at)->not->toBeNull();
});

it('deduplicates TrackedJob by idempotency_key while queued/running/completed and permits re-dispatch after failure', function (): void {
    Queue::fake();

    $key = 'sync-mailbox-001';
    $first = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-1'],
        idempotencyKey: $key,
    );

    // Queued -> returns existing without duplicate push
    $second = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-1'],
        idempotencyKey: $key,
    );
    expect((string) $second->id)->toBe((string) $first->id);
    Queue::assertPushed(TestSuccessTrackedJob::class, 1);

    // Running -> returns existing without duplicate push
    $first->forceFill(['status' => AsyncJobStatus::Running, 'started_at' => now()])->save();
    $third = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-1'],
        idempotencyKey: $key,
    );
    expect((string) $third->id)->toBe((string) $first->id);
    Queue::assertPushed(TestSuccessTrackedJob::class, 1);

    // Completed -> returns existing without duplicate push
    $first->forceFill(['status' => AsyncJobStatus::Completed, 'finished_at' => now()])->save();
    $fourth = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-1'],
        idempotencyKey: $key,
    );
    expect((string) $fourth->id)->toBe((string) $first->id);
    Queue::assertPushed(TestSuccessTrackedJob::class, 1);

    // Failed -> resets run to queued and re-dispatches
    $first->forceFill([
        'status' => AsyncJobStatus::Failed,
        'error_code' => 'RuntimeException',
        'error_message' => 'Boom',
        'finished_at' => now(),
    ])->save();

    $retried = TestSuccessTrackedJob::dispatchTracked(
        constructorArgs: ['entity-1'],
        idempotencyKey: $key,
    );

    expect((string) $retried->id)->toBe((string) $first->id)
        ->and($retried->status)->toBe(AsyncJobStatus::Queued)
        ->and($retried->error_code)->toBeNull()
        ->and($retried->error_message)->toBeNull()
        ->and($retried->finished_at)->toBeNull();
    Queue::assertPushed(TestSuccessTrackedJob::class, 2);
});

it('issues presigned upload URLs for allowed MIME types, enforces 50MB max and MIME allowlist, and sanitizes object keys', function (): void {
    $dept = Department::query()->create(['name' => 'Sales', 'slug' => 'sales-upload']);
    $user = createAttachmentTestUser($dept, 'EMP-UP-1', 'uploader@acme.com', RoleEnum::Sales);
    $sha256 = hash('sha256', 'sample-pdf-bytes');

    Config::set('filesystems.disks.s3.bucket', 'crm-private-bucket');

    $fakeDisk = Storage::fake('s3');
    $fakeDisk->buildTemporaryUploadUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options): array {
        return [
            'url' => 'https://s3.internal.test/upload/'.$path.'?exp='.$expiration->getTimestamp(),
            'headers' => [
                'Host' => ['s3.internal.test'],
                'Content-Type' => [$options['ContentType'] ?? 'application/octet-stream'],
            ],
        ];
    });

    // Unauthenticated rejected
    $this->postJson('/api/internal/attachments/presign', [
        'filename' => 'quote.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => $sha256,
    ])->assertUnauthorized();

    // Valid upload with path traversal characters in filename
    $response = $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
        'filename' => '../../etc/Q4 Proposal (Final).pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 52_428_800, // Exact 50MB boundary allowed
        'sha256' => strtoupper($sha256),
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'attachment_id',
            'upload_url',
            'headers',
            'object_key',
            'expires_at',
        ]);

    $payload = $response->json();
    expect($payload['object_key'])->toStartWith('uploads/'.now()->format('Y/m').'/'.$payload['attachment_id'].'/')
        ->and($payload['object_key'])->not->toContain('..')
        ->and($payload['headers'])->toBe([
            'Host' => 's3.internal.test',
            'Content-Type' => 'application/pdf',
        ]);

    $attachment = Attachment::query()->findOrFail($payload['attachment_id']);
    expect((string) $attachment->uploaded_by_id)->toBe((string) $user->id)
        ->and($attachment->disk)->toBe('s3')
        ->and($attachment->bucket)->toBe('crm-private-bucket')
        ->and($attachment->object_key)->toBe($payload['object_key'])
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size_bytes)->toBe(52_428_800)
        ->and($attachment->sha256)->toBe($sha256)
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean);

    // OpenXML Office MIME type allowed + default Storage::fake('s3') fallback headers
    Storage::fake('s3');
    $docxResponse = $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
        'filename' => 'handoff-checklist.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size_bytes' => 2048,
        'sha256' => $sha256,
    ])->assertCreated();

    expect($docxResponse->json('headers'))->toBe([
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    // Oversized (> 50MB) rejected with 422
    $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
        'filename' => 'huge.zip',
        'mime_type' => 'application/zip',
        'size_bytes' => 52_428_801,
        'sha256' => $sha256,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size_bytes');

    // Zero-byte rejected with 422
    $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
        'filename' => 'empty.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 0,
        'sha256' => $sha256,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size_bytes');

    // Dangerous MIME types rejected with 422
    foreach (['text/html', 'application/x-msdownload', 'image/svg+xml', 'application/javascript'] as $badMime) {
        $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
            'filename' => 'payload.bin',
            'mime_type' => $badMime,
            'size_bytes' => 512,
            'sha256' => $sha256,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('mime_type');
    }

    // Invalid SHA256 rejected with 422
    $this->actingAs($user)->postJson('/api/internal/attachments/presign', [
        'filename' => 'doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'sha256' => 'not-a-valid-sha256',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('sha256');
});

it('enforces silo isolation, entity_collaborators sharing, link binding, and quarantine denial on attachment downloads', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 'revenue-dl']);

    $admin = createAttachmentTestUser($dept, 'EMP-DL-ADM', 'admin.dl@acme.com', RoleEnum::Admin);
    $directorA = createAttachmentTestUser($dept, 'EMP-DL-DIRA', 'dir.a.dl@acme.com', RoleEnum::Director);
    $ownerA = createAttachmentTestUser($dept, 'EMP-DL-REPA', 'rep.a.dl@acme.com', RoleEnum::Sales, $directorA);
    $repB = createAttachmentTestUser($dept, 'EMP-DL-REPB', 'rep.b.dl@acme.com', RoleEnum::Sales);
    $collabUser = createAttachmentTestUser($dept, 'EMP-DL-COL', 'collab.dl@acme.com', RoleEnum::ProjectManager);

    $recordA = TestSiloAttachmentRecord::query()->create([
        'owner_id' => $ownerA->id,
        'title' => 'Silo A Secret Deal',
    ]);
    $recordB = TestSiloAttachmentRecord::query()->create([
        'owner_id' => $repB->id,
        'title' => 'Silo B Unlinked Deal',
    ]);

    $attachment = Attachment::query()->create([
        'uploaded_by_id' => $ownerA->id,
        'disk' => 's3',
        'bucket' => 'crm-private-bucket',
        'object_key' => 'uploads/2026/11/'.Str::uuid7().'/contract.pdf',
        'original_filename' => 'MSA "Final".pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 4096,
        'sha256' => hash('sha256', 'contract-bytes'),
        'scan_status' => AttachmentScanStatus::Clean,
        'created_at' => now(),
    ]);

    AttachmentLink::query()->create([
        'attachment_id' => $attachment->id,
        'attachable_type' => 'silo_attachment_record',
        'attachable_id' => $recordA->id,
        'created_at' => now(),
    ]);

    $capturedOptions = null;
    $fakeDisk = Storage::fake('s3');
    $fakeDisk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use (&$capturedOptions): string {
        $capturedOptions = ['path' => $path, 'expiration' => $expiration, 'options' => $options];

        return 'https://s3.internal.test/download/'.$path.'?exp='.$expiration->getTimestamp();
    });

    $validQuery = http_build_query([
        'context_type' => 'silo_attachment_record',
        'context_id' => $recordA->id,
    ]);

    // Guest redirected to login
    $this->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertRedirect('/login');

    // Silo A owner receives 302 to signed URL with attachment Content-Disposition
    $ownerResponse = $this->actingAs($ownerA)->get("/attachments/{$attachment->id}/download?{$validQuery}");
    $ownerResponse->assertRedirect();
    expect($ownerResponse->headers->get('Location'))->toStartWith('https://s3.internal.test/download/'.$attachment->object_key)
        ->and($capturedOptions['options']['ResponseContentDisposition'] ?? '')->toStartWith('attachment; filename=');

    // Silo A director (ancestor in hierarchy) receives 302
    $this->actingAs($directorA)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertRedirect();

    // Admin receives 302
    $this->actingAs($admin)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertRedirect();

    // Silo B user (no hierarchy or collaborator access) receives 403
    $this->actingAs($repB)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertForbidden();

    // IDOR check: Silo B user passes their OWN unlinked recordB as context_id -> 403
    $unlinkedQuery = http_build_query([
        'context_type' => 'silo_attachment_record',
        'context_id' => $recordB->id,
    ]);
    $this->actingAs($repB)
        ->get("/attachments/{$attachment->id}/download?{$unlinkedQuery}")
        ->assertForbidden();

    // Explicit entity_collaborators grant allows collabUser -> 302
    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $recordA,
        user: $collabUser,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::ProjectManager,
        grantedBy: $directorA,
    );

    $this->actingAs($collabUser)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertRedirect();

    // Revoking collaborator immediately revokes download access -> 403
    app(RevokeEntityCollaboratorAction::class)->execute($recordA, $collabUser);

    $this->actingAs($collabUser)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertForbidden();

    // Quarantined attachment denied even for owner and admin -> 403
    $attachment->forceFill(['scan_status' => AttachmentScanStatus::Quarantined])->save();

    $this->actingAs($ownerA)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->get("/attachments/{$attachment->id}/download?{$validQuery}")
        ->assertForbidden();

    // Malformed attachment route UUID returns 404 (not PostgreSQL 500)
    $this->actingAs($ownerA)
        ->get("/attachments/not-a-uuid/download?{$validQuery}")
        ->assertNotFound();

    // Missing or malformed context params return 422 JSON validation errors
    $this->actingAs($ownerA)
        ->getJson("/attachments/{$attachment->id}/download?context_type=silo_attachment_record&context_id=not-a-uuid")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('context_id');

    // Verify Attachment and AttachmentLink relationships resolve cleanly
    expect((string) $attachment->uploadedBy?->id)->toBe((string) $ownerA->id)
        ->and($attachment->links)->toHaveCount(1)
        ->and((string) $attachment->links->first()?->attachment?->id)->toBe((string) $attachment->id)
        ->and((string) $attachment->links->first()?->attachable?->id)->toBe((string) $recordA->id);
});
