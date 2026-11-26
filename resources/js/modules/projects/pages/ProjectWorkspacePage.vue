<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

const props = defineProps<{
    project: App.Modules.Projects.Projects.Data.ProjectDetailData;
    stages: App.Modules.Projects.Projects.Data.ProjectStageData[];
    columns: App.Modules.Projects.Tasks.Data.TaskStageColumnData[];
    tasks: App.Modules.Projects.Tasks.Data.TaskData[];
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    statuses: string[];
    healthOptions: string[];
    stageCategories: string[];
    taskPriorities: string[];
    taskStatuses: string[];
    can: {
        update: boolean;
        delete: boolean;
        manageStages: boolean;
        manageCollaborators: boolean;
        createTask: boolean;
    };
}>();
</script>

<template>
    <Head :title="`${props.project.code} — ${props.project.name}`" />

    <div class="space-y-4 rounded-[8px] border border-default bg-surface p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <Link
                    href="/app/projects"
                    class="text-[12px] font-medium text-secondary hover:text-primary"
                >
                    Back to Projects
                </Link>
                <h1 class="mt-1 text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    <span class="font-mono text-secondary">{{ props.project.code }}</span>
                    — {{ props.project.name }}
                </h1>
                <p class="text-[13px] text-secondary">
                    {{ props.project.company_name ?? 'Delivery Workspace' }}
                </p>
            </div>
            <Badge variant="cobalt">{{ props.project.status }}</Badge>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div
                v-for="column in props.columns"
                :key="column.stage.id"
                class="rounded-[6px] border border-default bg-subtle p-3"
            >
                <div class="flex items-center justify-between gap-2 text-[12px] font-semibold text-primary">
                    <span>{{ column.stage.name }}</span>
                    <Badge variant="neutral">{{ column.tasks_count }}</Badge>
                </div>
            </div>
        </div>
    </div>
</template>
