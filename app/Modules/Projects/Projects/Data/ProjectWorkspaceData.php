<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Core\Attachments\Data\AttachmentSummaryData;
use App\Modules\Core\Rbac\Data\EntityCollaboratorData;
use App\Modules\Core\Users\Data\UserSummaryData;
use App\Modules\Crm\CustomFields\Data\CustomFieldDefinitionData;
use App\Modules\Crm\Timeline\Data\TimelinePageData;
use App\Modules\Projects\Tasks\Data\TaskData;
use App\Modules\Projects\Tasks\Data\TaskStageColumnData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectWorkspaceData extends Data
{
    /**
     * @param  list<ProjectStageData>  $stages
     * @param  list<TaskStageColumnData>  $columns
     * @param  list<TaskData>  $tasks
     * @param  list<EntityCollaboratorData>  $collaborators
     * @param  list<AttachmentSummaryData>  $attachments
     * @param  list<CustomFieldDefinitionData>  $customFieldDefinitions
     * @param  list<UserSummaryData>  $assignableUsers
     * @param  list<string>  $collaboratorAccessLevels
     * @param  list<string>  $collaboratorRoleLabels
     * @param  list<string>  $statuses
     * @param  list<string>  $healthOptions
     * @param  list<string>  $stageCategories
     * @param  list<string>  $taskPriorities
     * @param  list<string>  $taskStatuses
     * @param  array{update: bool, delete: bool, manageStages: bool, manageCollaborators: bool, createTask: bool}  $can
     */
    public function __construct(
        public ProjectDetailData $project,
        public array $stages,
        public array $columns,
        public array $tasks,
        public array $collaborators,
        public array $attachments,
        public array $customFieldDefinitions,
        public TimelinePageData $timeline,
        public array $assignableUsers,
        public array $collaboratorAccessLevels,
        public array $collaboratorRoleLabels,
        public array $statuses,
        public array $healthOptions,
        public array $stageCategories,
        public array $taskPriorities,
        public array $taskStatuses,
        public array $can,
    ) {}
}
