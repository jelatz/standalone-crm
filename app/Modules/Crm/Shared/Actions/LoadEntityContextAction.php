<?php

namespace App\Modules\Crm\Shared\Actions;

use App\Modules\Core\Attachments\Data\AttachmentSummaryData;
use App\Modules\Core\Attachments\Enums\AttachmentScanStatus;
use App\Modules\Core\Attachments\Models\AttachmentLink;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Data\UserSummaryData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Data\CustomFieldDefinitionData;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\CustomFields\Models\CustomFieldDefinition;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Database\Eloquent\Model;

class LoadEntityContextAction
{
    public function __construct(
        protected VisibleScopeResolver $scopeResolver,
    ) {}

    /**
     * Load active employees visible for assignment in the viewer's silo (or all active users when sharing).
     *
     * @return list<UserSummaryData>
     */
    public function assignableUsers(User $viewer, bool $includeAllActive = false): array
    {
        $scope = $this->scopeResolver->for($viewer);

        if (! $includeAllActive && ! $scope->isUnrestrictedAdmin && $scope->siloUserIds === []) {
            return [];
        }

        $query = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->orderBy('name');

        if (! $includeAllActive && ! $scope->isUnrestrictedAdmin) {
            $query->whereIn('id', $scope->siloUserIds);
        }

        return $query
            ->get(['id', 'name', 'email', 'employee_code', 'job_title'])
            ->map(static fn (User $user): UserSummaryData => UserSummaryData::fromModel($user))
            ->values()
            ->all();
    }

    /**
     * @return list<EntityCollaboratorData>
     */
    public function collaborators(Model $entity): array
    {
        return EntityCollaborator::query()
            ->with([
                'user' => static fn ($q) => $q->withTrashed()->select(['id', 'name', 'email']),
                'grantedBy' => static fn ($q) => $q->withTrashed()->select(['id', 'name']),
            ])
            ->where('collaboratable_type', $entity->getMorphClass())
            ->where('collaboratable_id', (string) $entity->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(static fn (EntityCollaborator $collaborator): EntityCollaboratorData => EntityCollaboratorData::fromModel($collaborator))
            ->values()
            ->all();
    }

    /**
     * @return list<AttachmentSummaryData>
     */
    public function cleanAttachments(Model $entity): array
    {
        return AttachmentLink::query()
            ->with('attachment.uploadedBy:id,name')
            ->where('attachable_type', $entity->getMorphClass())
            ->where('attachable_id', (string) $entity->getKey())
            ->whereHas('attachment', static fn ($q) => $q->where('scan_status', AttachmentScanStatus::Clean->value))
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (AttachmentLink $link): AttachmentSummaryData => AttachmentSummaryData::fromLink($link))
            ->values()
            ->all();
    }

    /**
     * @return list<CustomFieldDefinitionData>
     */
    public function customFieldDefinitions(
        CustomFieldEntityTypeEnum $entityType,
        bool $filterableOnly = false,
    ): array {
        return CustomFieldDefinition::query()
            ->where('entity_type', $entityType->value)
            ->where('is_active', true)
            ->whereNull('pipeline_id')
            ->when($filterableOnly, static fn ($q) => $q->where('is_filterable', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(static fn (CustomFieldDefinition $definition): CustomFieldDefinitionData => CustomFieldDefinitionData::fromModel($definition))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     accessLevels: list<string>,
     *     roleLabels: list<string>
     * }
     */
    public function collaboratorOptions(): array
    {
        return [
            'accessLevels' => array_map(
                static fn (CollaboratorAccessLevel $level): string => $level->value,
                CollaboratorAccessLevel::cases(),
            ),
            'roleLabels' => array_map(
                static fn (CollaboratorRoleLabel $label): string => $label->value,
                CollaboratorRoleLabel::cases(),
            ),
        ];
    }
}
