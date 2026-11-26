<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Handoffs\Data\ApproveHandoffData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Jobs\ProvisionProjectFromHandoffJob;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffStateMachine;
use App\Modules\Projects\Handoffs\Support\HandoffPayloadNormalizer;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class ApproveDealHandoffAction
{
    public function __construct(
        protected HandoffPayloadNormalizer $normalizer,
        protected RecordActivityAction $recordActivity,
        protected BroadcastHandoffStatusAction $broadcastStatus,
    ) {}

    public function execute(User $actor, DealHandoff $handoff, ApproveHandoffData $data): DealHandoff
    {
        Gate::forUser($actor)->authorize('approve', $handoff);

        $template = ProjectTemplate::query()
            ->whereKey($data->project_template_id)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'project_template_id' => ['Selected project template must exist and be active.'],
            ]);
        }

        $pm = User::query()
            ->whereKey($data->assigned_pm_id)
            ->where('status', UserStatusEnum::Active->value)
            ->first();

        if ($pm === null || ! $pm->checkPermissionTo(PermissionEnum::ProjectsUpdate->value)) {
            throw ValidationException::withMessages([
                'assigned_pm_id' => ['Assigned project manager must be an active employee with projects.update permission.'],
            ]);
        }

        $explicitAmProvided = ! $data->assigned_am_id instanceof Optional;
        $validatedExplicitAmId = $explicitAmProvided
            ? $this->normalizer->validateOptionalActiveUser(
                $data->assigned_am_id,
                'assigned_am_id',
                'Assigned account manager must be an active employee.',
            )
            : null;

        $projectName = ! $data->project_name instanceof Optional && $data->project_name !== null && trim($data->project_name) !== ''
            ? Str::limit(trim($data->project_name), 200, '')
            : null;

        $approved = DB::transaction(function () use (
            $actor,
            $handoff,
            $template,
            $pm,
            $data,
            $explicitAmProvided,
            $validatedExplicitAmId,
            $projectName,
        ): DealHandoff {
            $lockedHandoff = DealHandoff::query()
                ->whereKey($handoff->id)
                ->lockForUpdate()
                ->firstOrFail();

            DealHandoffStateMachine::assertCanTransition(
                $lockedHandoff->status,
                DealHandoffStatusEnum::Approved,
            );

            $effectiveAmId = $explicitAmProvided
                ? $validatedExplicitAmId
                : ($lockedHandoff->assigned_am_id !== null
                    ? $this->normalizer->validateOptionalActiveUser(
                        (string) $lockedHandoff->assigned_am_id,
                        'assigned_am_id',
                        'Assigned account manager must be an active employee.',
                    )
                    : null);

            $effectiveKickoffDate = ! $data->target_kickoff_date instanceof Optional
                ? $data->target_kickoff_date
                : $lockedHandoff->target_kickoff_date?->format('Y-m-d');

            $effectiveFeedback = $lockedHandoff->review_feedback;
            if (! $data->review_feedback instanceof Optional) {
                $trimmedFeedback = $data->review_feedback !== null ? trim($data->review_feedback) : '';
                $effectiveFeedback = $trimmedFeedback !== '' ? $trimmedFeedback : null;
            }

            $lockedHandoff->forceFill([
                'status' => DealHandoffStatusEnum::Approved,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
                'assigned_pm_id' => $pm->id,
                'assigned_am_id' => $effectiveAmId,
                'project_template_id' => $template->id,
                'target_kickoff_date' => $effectiveKickoffDate,
                'review_feedback' => $effectiveFeedback,
            ])->save();

            $metadata = [
                'handoff_id' => (string) $lockedHandoff->id,
                'project_template_id' => (string) $template->id,
                'assigned_pm_id' => (string) $pm->id,
                'assigned_am_id' => $effectiveAmId,
            ];

            if ($projectName !== null) {
                $metadata['project_name'] = $projectName;
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::HandoffApproved,
                sourceType: $lockedHandoff->getMorphClass(),
                sourceId: (string) $lockedHandoff->id,
                targets: [
                    'deal' => (string) $lockedHandoff->deal_id,
                    'company' => (string) $lockedHandoff->company_id,
                ],
                summary: 'Approved deal handoff for project provisioning',
                metadata: $metadata,
            );

            $this->broadcastStatus->afterCommitToStakeholders($lockedHandoff);

            $handoffId = (string) $lockedHandoff->id;
            $dealId = (string) $lockedHandoff->deal_id;
            $actorId = (string) $actor->id;

            DB::afterCommit(static function () use ($handoffId, $dealId, $actorId): void {
                ProvisionProjectFromHandoffJob::dispatchTracked(
                    constructorArgs: ['handoffId' => $handoffId],
                    subjectType: 'deal_handoff',
                    subjectId: $handoffId,
                    triggeredById: $actorId,
                    idempotencyKey: "handoff-provision:{$handoffId}",
                    payloadSummary: ['deal_id' => $dealId],
                    queue: 'default',
                );
            });

            return $lockedHandoff->refresh();
        });

        return $approved->refresh();
    }
}
