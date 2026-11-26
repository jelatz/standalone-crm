<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    Check,
    ChevronRight,
    Copy,
    Download,
    Kanban,
    LayoutList,
    Paperclip,
    Plus,
    RefreshCw,
    Save,
    SlidersHorizontal,
    Trash2,
    Users,
    WifiOff,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import ActivityTimeline from '@/modules/crm/components/ActivityTimeline.vue';
import CollaboratorsPanel from '@/modules/crm/components/CollaboratorsPanel.vue';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import ProjectKanbanBoard from '@/modules/projects/components/ProjectKanbanBoard.vue';
import TaskDetailDrawer from '@/modules/projects/components/TaskDetailDrawer.vue';
import TaskListTable from '@/modules/projects/components/TaskListTable.vue';
import {
    extractFirstFieldErrors,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import { useProjectBoardRealtime } from '@/modules/projects/composables/useProjectBoardRealtime';
import {
    projectHealthVariant,
    projectStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import {
    formatEnumLabel,
    formatFileSize,
    getInitials,
} from '@/modules/crm/utils/formatters';
import { useAuthStore } from '@/stores/auth.store';
import { useRealtimeStore } from '@/stores/realtime.store';

type WorkspaceTab = 'board' | 'list' | 'timeline' | 'collaborators';

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

const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();
const instanceId = useId();

const activeTab = ref<WorkspaceTab>('board');
const localProject = ref<App.Modules.Projects.Projects.Data.ProjectDetailData>({
    ...props.project,
    custom_fields: { ...(props.project.custom_fields ?? {}) },
});
const localStages = ref<App.Modules.Projects.Projects.Data.ProjectStageData[]>([
    ...props.stages,
]);
const localColumns = ref<App.Modules.Projects.Tasks.Data.TaskStageColumnData[]>([
    ...props.columns,
]);
const localTasks = ref<App.Modules.Projects.Tasks.Data.TaskData[]>([...props.tasks]);

const copiedCode = ref<boolean>(false);
const reloading = ref<boolean>(false);
const pageError = ref<string>('');

const inspectedTaskId = ref<string | null>(null);
const taskDrawerOpen = ref<boolean>(false);

const ownMutations = new Map<string, number>();

function syncWorkspaceFromProps(): void {
    localProject.value = {
        ...props.project,
        custom_fields: { ...(props.project.custom_fields ?? {}) },
    };
    localStages.value = [...props.stages];
    localColumns.value = [...props.columns];
    localTasks.value = [...props.tasks];
}

watch(
    () => [props.project, props.stages, props.columns, props.tasks] as const,
    () => {
        syncWorkspaceFromProps();
    },
    { deep: true },
);

const canMoveTasks = computed(() => props.can.update);
const canDeleteTasks = computed(
    () => props.can.delete || (props.can.update && authStore.hasPermission('tasks.delete')),
);

const inspectedTask = computed<App.Modules.Projects.Tasks.Data.TaskData | null>(() => {
    if (!inspectedTaskId.value) {
        return null;
    }
    return localTasks.value.find((t) => t.id === inspectedTaskId.value) ?? null;
});

const inspectedSubtasks = computed(() => {
    if (!inspectedTask.value) {
        return [];
    }
    return localTasks.value.filter((t) => t.parent_task_id === inspectedTask.value?.id);
});

function refreshWorkspace(): void {
    reloading.value = true;
    pageError.value = '';
    router.reload({
        only: ['project', 'stages', 'columns', 'tasks'],
        onError: () => {
            pageError.value = 'Unable to sync project workspace.';
        },
        onFinish: () => {
            reloading.value = false;
        },
    });
}

const { coViewers } = useProjectBoardRealtime({
    projectId: () => localProject.value.id,
    getTaskVersion: (taskId) => localTasks.value.find((t) => t.id === taskId)?.version,
    isOwnMutation: (taskId, version) => (ownMutations.get(taskId) ?? 0) >= version,
    onReloadNeeded: () => {
        if (typeof router?.reload === 'function') {
            router.reload({ only: ['project', 'stages', 'columns', 'tasks'] });
        }
    },
});

const visibleCoViewers = computed(() => coViewers.value.slice(0, 4));
const extraCoViewersCount = computed(() => Math.max(0, coViewers.value.length - 4));

async function copyProjectCode(): Promise<void> {
    try {
        await navigator.clipboard.writeText(localProject.value.code);
        copiedCode.value = true;
        setTimeout(() => {
            copiedCode.value = false;
        }, 1500);
    } catch {
        // Ignore clipboard errors in non-HTTPS / JSDOM contexts.
    }
}

function openTaskDrawer(task: App.Modules.Projects.Tasks.Data.TaskData): void {
    inspectedTaskId.value = task.id;
    taskDrawerOpen.value = true;
}

function rebuildColumnsFromTasks(
    tasks: App.Modules.Projects.Tasks.Data.TaskData[],
): App.Modules.Projects.Tasks.Data.TaskStageColumnData[] {
    return localStages.value.map((stage) => {
        const stageTasks = tasks
            .filter((t) => t.project_stage_id === stage.id)
            .sort((a, b) => a.board_position - b.board_position);
        return {
            stage: { ...stage, tasks_count: stageTasks.length },
            tasks_count: stageTasks.length,
            tasks: stageTasks,
        };
    });
}

function handleTaskUpdated(updated: App.Modules.Projects.Tasks.Data.TaskData): void {
    ownMutations.set(updated.id, Math.max(ownMutations.get(updated.id) ?? 0, updated.version));
    const exists = localTasks.value.some((t) => t.id === updated.id);
    localTasks.value = exists
        ? localTasks.value.map((t) => (t.id === updated.id ? updated : t))
        : [...localTasks.value, updated];
    localColumns.value = rebuildColumnsFromTasks(localTasks.value);
}

function handleTaskCreated(created: App.Modules.Projects.Tasks.Data.TaskData): void {
    ownMutations.set(created.id, Math.max(ownMutations.get(created.id) ?? 0, created.version));
    if (!localTasks.value.some((t) => t.id === created.id)) {
        localTasks.value = [...localTasks.value, created];
        localColumns.value = rebuildColumnsFromTasks(localTasks.value);
    }
}

function handleTaskDeleted(deletedId: string): void {
    localTasks.value = localTasks.value.filter(
        (t) => t.id !== deletedId && t.parent_task_id !== deletedId,
    );
    localColumns.value = rebuildColumnsFromTasks(localTasks.value);
    if (inspectedTaskId.value === deletedId) {
        inspectedTaskId.value = null;
        taskDrawerOpen.value = false;
    }
}

// Create Task Modal
const createTaskOpen = ref<boolean>(false);
const createTaskSubmitting = ref<boolean>(false);
const createTaskError = ref<string>('');
const createTaskFieldErrors = ref<Record<string, string>>({});
const createTaskForm = ref({
    title: '',
    project_stage_id: '',
    priority: 'medium' as App.Modules.Projects.Tasks.Enums.TaskPriorityEnum,
    assignee_id: '',
    due_at: '',
    estimated_minutes: '',
});

function openCreateTaskModal(): void {
    createTaskError.value = '';
    createTaskFieldErrors.value = {};
    createTaskForm.value = {
        title: '',
        project_stage_id: localStages.value[0]?.id ?? '',
        priority: 'medium',
        assignee_id: '',
        due_at: '',
        estimated_minutes: '',
    };
    createTaskOpen.value = true;
}

async function handleCreateTaskSubmit(): Promise<void> {
    if (!props.can.createTask || createTaskSubmitting.value) {
        return;
    }
    if (!createTaskForm.value.title.trim()) {
        createTaskFieldErrors.value = { title: 'Task title is required.' };
        createTaskError.value = 'Enter a task title before creating.';
        return;
    }

    const estRaw = createTaskForm.value.estimated_minutes.trim();
    const estVal = estRaw !== '' ? Number(estRaw) : null;

    createTaskSubmitting.value = true;
    createTaskError.value = '';
    createTaskFieldErrors.value = {};

    try {
        const created = await projectsApi.createTask({
            title: createTaskForm.value.title.trim(),
            project_id: localProject.value.id,
            project_stage_id: createTaskForm.value.project_stage_id || null,
            parent_task_id: null,
            linkable_type: null,
            linkable_id: null,
            assignee_id: createTaskForm.value.assignee_id || null,
            description_json: null,
            priority: createTaskForm.value.priority,
            status: null,
            due_at: createTaskForm.value.due_at.trim() || null,
            estimated_minutes: estVal !== null && Number.isInteger(estVal) ? estVal : null,
        });

        handleTaskCreated(created);
        createTaskOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createTaskFieldErrors.value = extractFirstFieldErrors(error.errors);
            createTaskError.value = error.message;
        } else {
            createTaskError.value =
                error instanceof Error ? error.message : 'Unable to create task.';
        }
    } finally {
        createTaskSubmitting.value = false;
    }
}

// Manage Stages Modal
interface StageDraftRow {
    key: string;
    id: string | null;
    name: string;
    category: App.Modules.Projects.Projects.Enums.ProjectStageCategoryEnum;
    wip_limit: string;
    tasks_count: number;
}

const stagesModalOpen = ref<boolean>(false);
const stageDrafts = ref<StageDraftRow[]>([]);
const stagesSubmitting = ref<boolean>(false);
const stagesError = ref<string>('');
let stageRowSeq = 0;

function openManageStagesModal(): void {
    stagesError.value = '';
    stageDrafts.value = localStages.value.map((s) => {
        stageRowSeq += 1;
        return {
            key: `stg-${stageRowSeq}`,
            id: s.id,
            name: s.name,
            category: s.category,
            wip_limit: s.wip_limit !== null ? String(s.wip_limit) : '',
            tasks_count: s.tasks_count,
        };
    });
    stagesModalOpen.value = true;
}

function addStageDraft(): void {
    stageRowSeq += 1;
    stageDrafts.value = [
        ...stageDrafts.value,
        {
            key: `stg-${stageRowSeq}`,
            id: null,
            name: '',
            category: 'in_progress',
            wip_limit: '',
            tasks_count: 0,
        },
    ];
}

function moveStageDraft(index: number, delta: -1 | 1): void {
    const nextIdx = index + delta;
    if (nextIdx < 0 || nextIdx >= stageDrafts.value.length) {
        return;
    }
    const copy = [...stageDrafts.value];
    const [item] = copy.splice(index, 1);
    copy.splice(nextIdx, 0, item);
    stageDrafts.value = copy;
}

function removeStageDraft(index: number): void {
    const target = stageDrafts.value[index];
    if (!target || target.tasks_count > 0) {
        return;
    }
    stageDrafts.value = stageDrafts.value.filter((_, idx) => idx !== index);
}

async function handleSaveStages(): Promise<void> {
    if (!props.can.manageStages || stagesSubmitting.value) {
        return;
    }

    if (stageDrafts.value.length === 0 || stageDrafts.value.some((s) => !s.name.trim())) {
        stagesError.value = 'Every stage must have a name, and at least one stage is required.';
        return;
    }

    stagesSubmitting.value = true;
    stagesError.value = '';

    try {
        const payload = stageDrafts.value.map((s, idx) => {
            const rawWip = s.wip_limit.trim();
            const parsedWip = rawWip !== '' ? Number(rawWip) : null;
            return {
                id: s.id,
                name: s.name.trim(),
                category: s.category,
                wip_limit: parsedWip !== null && Number.isInteger(parsedWip) && parsedWip >= 1 ? parsedWip : null,
                sort_order: idx + 1,
            };
        });

        const result = await projectsApi.manageStages(localProject.value.id, {
            stages: payload,
        });

        localStages.value = result.stages;
        localColumns.value = rebuildColumnsFromTasks(localTasks.value);
        stagesModalOpen.value = false;
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            const firstErr = Object.values(error.errors).flat()[0];
            stagesError.value = firstErr || error.message;
        } else {
            stagesError.value =
                error instanceof Error ? error.message : 'Unable to save project stages.';
        }
    } finally {
        stagesSubmitting.value = false;
    }
}

// Project Properties Editor (inside Collaborators / Settings tab)
const propForm = ref({
    name: localProject.value.name,
    status: localProject.value.status,
    health: localProject.value.health,
    owner_id: localProject.value.owner_id,
    account_manager_id: localProject.value.account_manager_id ?? '',
    start_date: localProject.value.start_date ?? '',
    target_end_date: localProject.value.target_end_date ?? '',
    custom_fields: { ...(localProject.value.custom_fields ?? {}) } as Record<string, any>,
});

watch(
    () => localProject.value,
    (next) => {
        propForm.value = {
            name: next.name,
            status: next.status,
            health: next.health,
            owner_id: next.owner_id,
            account_manager_id: next.account_manager_id ?? '',
            start_date: next.start_date ?? '',
            target_end_date: next.target_end_date ?? '',
            custom_fields: { ...(next.custom_fields ?? {}) },
        };
    },
    { deep: true },
);

const savingProject = ref<boolean>(false);
const projectSaveError = ref<string>('');
const projectSaveSuccess = ref<string>('');
const projectFieldErrors = ref<Record<string, string>>({});

async function handleSaveProjectProperties(): Promise<void> {
    if (!props.can.update || savingProject.value) {
        return;
    }

    if (!propForm.value.name.trim()) {
        projectFieldErrors.value = { name: 'Project name is required.' };
        projectSaveError.value = 'Enter a project name.';
        return;
    }

    savingProject.value = true;
    projectSaveError.value = '';
    projectSaveSuccess.value = '';
    projectFieldErrors.value = {};

    try {
        // Notice: never send company_id or primary_deal_id from properties editor when masked!
        const updated = await projectsApi.updateProject(localProject.value.id, {
            name: propForm.value.name.trim(),
            status: propForm.value.status,
            health: propForm.value.health,
            owner_id: propForm.value.owner_id,
            account_manager_id: propForm.value.account_manager_id || null,
            start_date: propForm.value.start_date || null,
            target_end_date: propForm.value.target_end_date || null,
            custom_fields: propForm.value.custom_fields,
        });

        localProject.value = updated;
        projectSaveSuccess.value = 'Project properties saved.';
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            projectFieldErrors.value = extractFirstFieldErrors(error.errors);
            projectSaveError.value = error.message;
        } else {
            projectSaveError.value =
                error instanceof Error ? error.message : 'Unable to update project properties.';
        }
    } finally {
        savingProject.value = false;
    }
}

const deleteProjectOpen = ref<boolean>(false);
const deletingProject = ref<boolean>(false);
const deleteProjectError = ref<string>('');

async function handleDeleteProject(): Promise<void> {
    if (!props.can.delete || deletingProject.value) {
        return;
    }

    deletingProject.value = true;
    deleteProjectError.value = '';

    try {
        await projectsApi.deleteProject(localProject.value.id);
        deleteProjectOpen.value = false;
        router.visit('/app/projects');
    } catch (error) {
        deleteProjectError.value =
            error instanceof Error ? error.message : 'Unable to delete project.';
    } finally {
        deletingProject.value = false;
    }
}
</script>

<template>
    <Head :title="`${localProject.code} — ${localProject.name}`" />

    <div class="space-y-4">
        <!-- Offline Banner -->
        <div
            v-if="realtimeStore.status === 'disconnected' && realtimeStore.lastError"
            role="status"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2 text-[12px] text-primary"
        >
            <div class="flex items-center gap-2">
                <WifiOff class="h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <span>Realtime presence offline. Use Sync to refresh board updates.</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshWorkspace">Sync</Button>
        </div>

        <!-- Workspace Header -->
        <header class="rounded-[8px] border border-default bg-surface px-4 pt-3.5 pb-0 space-y-3">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-1.5">
                    <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1.5 text-[12px] text-secondary">
                        <Link href="/app/projects" class="hover:text-primary">Projects</Link>
                        <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                        <button
                            type="button"
                            :title="copiedCode ? 'Copied code' : 'Copy project code'"
                            class="inline-flex items-center gap-1 rounded-[4px] border border-default bg-subtle px-1.5 py-0.5 font-mono text-[11px] font-semibold text-primary hover:bg-surface cursor-pointer"
                            @click="copyProjectCode"
                        >
                            <span>{{ localProject.code }}</span>
                            <Check v-if="copiedCode" class="h-3 w-3 text-status-success" :stroke-width="1.75" aria-hidden="true" />
                            <Copy v-else class="h-3 w-3 text-muted" :stroke-width="1.75" aria-hidden="true" />
                        </button>
                    </nav>

                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                            {{ localProject.name }}
                        </h2>
                        <Badge :variant="projectStatusVariant(localProject.status)">
                            {{ formatEnumLabel(localProject.status) }}
                        </Badge>
                        <Badge :variant="projectHealthVariant(localProject.health)">
                            {{ formatEnumLabel(localProject.health) }}
                        </Badge>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-secondary">
                        <span v-if="localProject.has_masked_company">
                            Account: <span class="text-muted">Restricted (outside your silo)</span>
                        </span>
                        <span v-else-if="localProject.company_id">
                            Account:
                            <Link
                                :href="`/app/crm/companies/${localProject.company_id}`"
                                class="font-medium text-accent-primary hover:underline"
                            >
                                {{ localProject.company_name }}
                            </Link>
                        </span>

                        <span v-if="localProject.has_masked_deal">
                            Deal: <span class="text-muted">Restricted (outside your silo)</span>
                        </span>
                        <span v-else-if="localProject.primary_deal_id">
                            Deal:
                            <Link
                                :href="`/app/crm/deals/${localProject.primary_deal_id}`"
                                class="font-medium text-accent-primary hover:underline"
                            >
                                {{ localProject.primary_deal_title }}
                            </Link>
                        </span>

                        <span v-if="localProject.deal_handoff_id">
                            <Link
                                :href="`/app/projects/handoffs/${localProject.deal_handoff_id}`"
                                class="font-medium text-accent-primary hover:underline"
                            >
                                View Handoff Brief
                            </Link>
                        </span>
                    </div>
                </div>

                <!-- Right Controls: Presence Co-Viewers + Actions -->
                <div class="flex flex-wrap shrink-0 items-center gap-2">
                    <div
                        v-if="coViewers.length > 0"
                        data-testid="project-co-viewers"
                        class="flex items-center gap-2 rounded-full border border-default bg-subtle px-2.5 py-1"
                    >
                        <div class="flex items-center -space-x-1.5">
                            <span
                                v-for="member in visibleCoViewers"
                                :key="member.id"
                                :title="member.name ?? 'Active viewer'"
                                class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-surface bg-surface font-mono text-[9px] font-semibold text-primary"
                            >
                                {{ getInitials(member.name, 'VM') }}
                            </span>
                            <span
                                v-if="extraCoViewersCount > 0"
                                class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-surface bg-subtle font-mono text-[9px] font-semibold text-secondary"
                            >
                                +{{ extraCoViewersCount }}
                            </span>
                        </div>
                        <span class="flex items-center gap-1 font-mono text-[11px] tabular-nums text-secondary">
                            <span class="h-1.5 w-1.5 rounded-full bg-status-success" aria-hidden="true" />
                            <span>{{ coViewers.length }} viewing</span>
                        </span>
                    </div>

                    <Button
                        v-if="props.can.manageStages"
                        variant="secondary"
                        size="sm"
                        data-testid="project-manage-stages-btn"
                        @click="openManageStagesModal"
                    >
                        <SlidersHorizontal class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Stages</span>
                    </Button>

                    <Button
                        v-if="props.can.createTask"
                        variant="primary"
                        size="sm"
                        data-testid="project-new-task-btn"
                        @click="openCreateTaskModal"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>New Task</span>
                    </Button>

                    <Button
                        variant="ghost"
                        size="sm"
                        :loading="reloading"
                        @click="refreshWorkspace"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <!-- Workspace Tabs -->
            <nav aria-label="Project workspace views" class="flex items-center gap-5 overflow-x-auto border-t border-default pt-1">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'board' ? 'true' : 'false'"
                    data-testid="project-tab-board"
                    :class="[
                        'inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-[12px] font-medium transition-colors cursor-pointer',
                        activeTab === 'board'
                            ? 'border-accent-primary text-accent-primary'
                            : 'border-transparent text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'board'"
                >
                    <Kanban class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Board</span>
                </button>

                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'list' ? 'true' : 'false'"
                    data-testid="project-tab-list"
                    :class="[
                        'inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-[12px] font-medium transition-colors cursor-pointer',
                        activeTab === 'list'
                            ? 'border-accent-primary text-accent-primary'
                            : 'border-transparent text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'list'"
                >
                    <LayoutList class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>List</span>
                    <Badge variant="neutral">{{ localTasks.length }}</Badge>
                </button>

                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'timeline' ? 'true' : 'false'"
                    data-testid="project-tab-timeline"
                    :class="[
                        'inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-[12px] font-medium transition-colors cursor-pointer',
                        activeTab === 'timeline'
                            ? 'border-accent-primary text-accent-primary'
                            : 'border-transparent text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'timeline'"
                >
                    <span>Timeline</span>
                </button>

                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'collaborators' ? 'true' : 'false'"
                    data-testid="project-tab-collaborators"
                    :class="[
                        'inline-flex h-9 shrink-0 items-center gap-1.5 border-b-2 text-[12px] font-medium transition-colors cursor-pointer',
                        activeTab === 'collaborators'
                            ? 'border-accent-primary text-accent-primary'
                            : 'border-transparent text-secondary hover:text-primary',
                    ]"
                    @click="activeTab = 'collaborators'"
                >
                    <Users class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Collaborators &amp; Details</span>
                    <Badge variant="neutral">{{ props.collaborators.length }}</Badge>
                </button>
            </nav>
        </header>

        <!-- Error Banner -->
        <div
            v-if="pageError"
            role="alert"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2 text-[12px] text-status-danger"
        >
            <div class="flex items-center gap-2">
                <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ pageError }}</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshWorkspace">Retry</Button>
        </div>

        <!-- Tab Panels -->
        <ProjectKanbanBoard
            v-if="activeTab === 'board'"
            :project-id="localProject.id"
            :columns="localColumns"
            :stages="localStages"
            :assignable-users="props.assignableUsers"
            :task-priorities="props.taskPriorities"
            :can-move-tasks="canMoveTasks"
            :can-create-task="props.can.createTask"
            :external-realtime="true"
            @open-task="openTaskDrawer"
            @task-moved="handleTaskUpdated"
            @task-created="handleTaskCreated"
        />

        <TaskListTable
            v-else-if="activeTab === 'list'"
            :tasks="localTasks"
            :loading="reloading"
            @open-task="openTaskDrawer"
            @retry="refreshWorkspace"
        />

        <ActivityTimeline
            v-else-if="activeTab === 'timeline'"
            linkable-type="project"
            :linkable-id="localProject.id"
            :initial-page="props.timeline"
            :can-create-note="false"
        />

        <div
            v-else
            class="grid grid-cols-1 gap-4 lg:grid-cols-12"
        >
            <!-- Project Properties -->
            <section class="lg:col-span-7 rounded-[8px] border border-default bg-surface p-4 space-y-3">
                <div class="flex items-center justify-between border-b border-default pb-2">
                    <h3 class="text-[13px] font-semibold text-primary">Project Properties</h3>
                    <Button
                        v-if="props.can.delete"
                        variant="ghost"
                        size="sm"
                        class="text-status-danger hover:bg-status-danger/10"
                        @click="deleteProjectOpen = true"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Delete Project</span>
                    </Button>
                </div>

                <div
                    v-if="projectSaveError"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ projectSaveError }}</span>
                </div>

                <div
                    v-if="projectSaveSuccess"
                    role="status"
                    class="rounded-[6px] border border-status-success/35 bg-status-success/10 px-3 py-2 text-[12px] text-status-success"
                >
                    {{ projectSaveSuccess }}
                </div>

                <form class="space-y-3" @submit.prevent="handleSaveProjectProperties">
                    <div>
                        <label :for="`prj-prop-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Project Name
                        </label>
                        <Input
                            :id="`prj-prop-name-${instanceId}`"
                            v-model="propForm.name"
                            :disabled="!props.can.update || savingProject"
                            :error-message="projectFieldErrors.name ?? ''"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label :for="`prj-prop-status-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Status
                            </label>
                            <select
                                :id="`prj-prop-status-${instanceId}`"
                                v-model="propForm.status"
                                :disabled="!props.can.update || savingProject"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option v-for="s in props.statuses" :key="s" :value="s">
                                    {{ formatEnumLabel(s) }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`prj-prop-health-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Health
                            </label>
                            <select
                                :id="`prj-prop-health-${instanceId}`"
                                v-model="propForm.health"
                                :disabled="!props.can.update || savingProject"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option v-for="hOpt in props.healthOptions" :key="hOpt" :value="hOpt">
                                    {{ formatEnumLabel(hOpt) }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`prj-prop-owner-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                PM / Owner
                            </label>
                            <select
                                :id="`prj-prop-owner-${instanceId}`"
                                v-model="propForm.owner_id"
                                :disabled="!props.can.update || savingProject"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option
                                    v-for="user in props.assignableUsers"
                                    :key="user.id"
                                    :value="user.id"
                                >
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`prj-prop-am-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Account Manager
                            </label>
                            <select
                                :id="`prj-prop-am-${instanceId}`"
                                v-model="propForm.account_manager_id"
                                :disabled="!props.can.update || savingProject"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="user in props.assignableUsers"
                                    :key="user.id"
                                    :value="user.id"
                                >
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label :for="`prj-prop-start-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Start Date
                            </label>
                            <Input
                                :id="`prj-prop-start-${instanceId}`"
                                v-model="propForm.start_date"
                                type="date"
                                class="font-mono tabular-nums"
                                :disabled="!props.can.update || savingProject"
                            />
                        </div>

                        <div>
                            <label :for="`prj-prop-end-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Target End Date
                            </label>
                            <Input
                                :id="`prj-prop-end-${instanceId}`"
                                v-model="propForm.target_end_date"
                                type="date"
                                class="font-mono tabular-nums"
                                :disabled="!props.can.update || savingProject"
                                :error-message="projectFieldErrors.target_end_date ?? ''"
                            />
                        </div>
                    </div>

                    <CustomFieldsForm
                        v-if="props.customFieldDefinitions.length > 0"
                        v-model="propForm.custom_fields"
                        :definitions="props.customFieldDefinitions"
                        :assignable-users="props.assignableUsers"
                        :errors="projectFieldErrors"
                        :disabled="!props.can.update || savingProject"
                        id-prefix="prj-detail-cf"
                    />

                    <div v-if="props.can.update" class="flex justify-end pt-1">
                        <Button type="submit" variant="primary" size="md" :loading="savingProject">
                            <Save class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>Save Properties</span>
                        </Button>
                    </div>
                </form>
            </section>

            <!-- Collaborators & Clean Attachments -->
            <div class="lg:col-span-5 space-y-4">
                <CollaboratorsPanel
                    entity-type="project"
                    :entity-id="localProject.id"
                    :collaborators="props.collaborators"
                    :assignable-users="props.assignableUsers"
                    :access-levels="props.collaboratorAccessLevels"
                    :role-labels="props.collaboratorRoleLabels"
                    :can-manage="props.can.manageCollaborators"
                />

                <section aria-label="Clean attachments" class="rounded-[8px] border border-default bg-surface p-3.5 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-primary">
                            <Paperclip class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <span>Attachments</span>
                        </h3>
                        <Badge variant="neutral">{{ props.attachments.length }}</Badge>
                    </div>

                    <p
                        v-if="props.attachments.length === 0"
                        class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted"
                    >
                        No clean scanned attachments linked.
                    </p>

                    <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
                        <li
                            v-for="file in props.attachments"
                            :key="file.id"
                            class="flex items-center justify-between gap-2 px-2.5 py-2 text-[12px]"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium text-primary">{{ file.original_filename }}</p>
                                <p class="font-mono text-[10px] tabular-nums text-muted">
                                    {{ formatFileSize(file.size_bytes) }}
                                </p>
                            </div>
                            <a
                                :href="file.download_url"
                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] border border-default bg-subtle text-secondary hover:text-primary"
                                :aria-label="`Download ${file.original_filename}`"
                            >
                                <Download class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </a>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- Task Detail Slide-Over Drawer -->
        <TaskDetailDrawer
            :open="taskDrawerOpen"
            :task="inspectedTask"
            :subtasks="inspectedSubtasks"
            :assignable-users="props.assignableUsers"
            :priorities="props.taskPriorities"
            :statuses="props.taskStatuses"
            :can-update="canMoveTasks"
            :can-delete="canDeleteTasks"
            :can-create-subtask="props.can.createTask"
            @update:open="taskDrawerOpen = $event"
            @task-updated="handleTaskUpdated"
            @task-created="handleTaskCreated"
            @task-deleted="handleTaskDeleted"
        />

        <!-- New Task Dialog -->
        <Dialog
            :open="createTaskOpen"
            title="New Project Task"
            description="Create a task directly on this project board."
            max-width-class="max-w-md"
            @update:open="createTaskOpen = $event"
        >
            <form class="space-y-3" @submit.prevent="handleCreateTaskSubmit">
                <div
                    v-if="createTaskError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ createTaskError }}
                </div>

                <div>
                    <label :for="`new-task-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                        Title <span class="text-status-danger">*</span>
                    </label>
                    <Input
                        :id="`new-task-title-${instanceId}`"
                        v-model="createTaskForm.title"
                        placeholder="Configure production SSO metadata"
                        :disabled="createTaskSubmitting"
                        :error-message="createTaskFieldErrors.title ?? ''"
                    />
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label :for="`new-task-stage-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Stage
                        </label>
                        <select
                            :id="`new-task-stage-${instanceId}`"
                            v-model="createTaskForm.project_stage_id"
                            :disabled="createTaskSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option v-for="s in localStages" :key="s.id" :value="s.id">
                                {{ s.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`new-task-prio-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Priority
                        </label>
                        <select
                            :id="`new-task-prio-${instanceId}`"
                            v-model="createTaskForm.priority"
                            :disabled="createTaskSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option v-for="p in props.taskPriorities" :key="p" :value="p">
                                {{ formatEnumLabel(p) }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label :for="`new-task-assignee-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Assignee
                        </label>
                        <select
                            :id="`new-task-assignee-${instanceId}`"
                            v-model="createTaskForm.assignee_id"
                            :disabled="createTaskSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Unassigned</option>
                            <option v-for="u in props.assignableUsers" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`new-task-due-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Due Date
                        </label>
                        <Input
                            :id="`new-task-due-${instanceId}`"
                            v-model="createTaskForm.due_at"
                            type="date"
                            class="font-mono tabular-nums"
                            :disabled="createTaskSubmitting"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="createTaskSubmitting"
                        @click="createTaskOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="sm" :loading="createTaskSubmitting">
                        Create Task
                    </Button>
                </div>
            </form>
        </Dialog>

        <!-- Manage Stages Dialog -->
        <Dialog
            :open="stagesModalOpen"
            title="Manage Project Stages"
            description="Rename, reorder, configure WIP limits, or remove empty stages."
            max-width-class="max-w-xl"
            @update:open="stagesModalOpen = $event"
        >
            <form class="space-y-3" @submit.prevent="handleSaveStages">
                <div
                    v-if="stagesError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ stagesError }}
                </div>

                <div class="max-h-[55vh] overflow-y-auto space-y-2 pr-1">
                    <div
                        v-for="(row, idx) in stageDrafts"
                        :key="row.key"
                        class="grid grid-cols-12 items-center gap-2 rounded-[6px] border border-default bg-subtle/40 p-2"
                    >
                        <div class="col-span-5">
                            <label :for="`stg-name-${row.key}`" class="sr-only">Stage name</label>
                            <Input
                                :id="`stg-name-${row.key}`"
                                v-model="row.name"
                                placeholder="Stage name"
                                :disabled="stagesSubmitting"
                            />
                        </div>
                        <div class="col-span-3">
                            <label :for="`stg-cat-${row.key}`" class="sr-only">Stage category</label>
                            <select
                                :id="`stg-cat-${row.key}`"
                                v-model="row.category"
                                :disabled="stagesSubmitting"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary"
                            >
                                <option v-for="cat in props.stageCategories" :key="cat" :value="cat">
                                    {{ formatEnumLabel(cat) }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label :for="`stg-wip-${row.key}`" class="sr-only">WIP limit</label>
                            <Input
                                :id="`stg-wip-${row.key}`"
                                v-model="row.wip_limit"
                                type="number"
                                placeholder="WIP"
                                class="font-mono tabular-nums"
                                :disabled="stagesSubmitting"
                            />
                        </div>
                        <div class="col-span-2 flex items-center justify-end gap-1">
                            <button
                                type="button"
                                aria-label="Move stage up"
                                :disabled="idx === 0 || stagesSubmitting"
                                class="inline-flex h-6 w-6 items-center justify-center rounded border border-default bg-surface text-secondary hover:text-primary disabled:opacity-40 cursor-pointer"
                                @click="moveStageDraft(idx, -1)"
                            >
                                <ArrowUp class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                aria-label="Move stage down"
                                :disabled="idx === stageDrafts.length - 1 || stagesSubmitting"
                                class="inline-flex h-6 w-6 items-center justify-center rounded border border-default bg-surface text-secondary hover:text-primary disabled:opacity-40 cursor-pointer"
                                @click="moveStageDraft(idx, 1)"
                            >
                                <ArrowDown class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                            <button
                                type="button"
                                aria-label="Remove empty stage"
                                :disabled="row.tasks_count > 0 || stagesSubmitting"
                                :title="row.tasks_count > 0 ? 'Stage contains tasks' : 'Remove stage'"
                                class="inline-flex h-6 w-6 items-center justify-center rounded border border-default bg-surface text-muted hover:text-status-danger disabled:opacity-35 cursor-pointer"
                                @click="removeStageDraft(idx)"
                            >
                                <Trash2 class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-default pt-3">
                    <Button type="button" variant="secondary" size="sm" :disabled="stagesSubmitting" @click="addStageDraft">
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Add Stage</span>
                    </Button>

                    <div class="flex items-center gap-2">
                        <Button type="button" variant="secondary" size="sm" :disabled="stagesSubmitting" @click="stagesModalOpen = false">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" size="sm" :loading="stagesSubmitting">
                            Save Stages
                        </Button>
                    </div>
                </div>
            </form>
        </Dialog>

        <!-- Delete Project Confirmation -->
        <Dialog
            :open="deleteProjectOpen"
            title="Delete Project"
            :description="`Are you sure you want to delete ${localProject.code} (${localProject.name})?`"
            max-width-class="max-w-md"
            @update:open="deleteProjectOpen = $event"
        >
            <div class="space-y-4">
                <div
                    v-if="deleteProjectError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ deleteProjectError }}
                </div>
                <div class="flex items-center justify-end gap-2">
                    <Button variant="secondary" :disabled="deletingProject" @click="deleteProjectOpen = false">
                        Cancel
                    </Button>
                    <Button variant="danger" :loading="deletingProject" @click="handleDeleteProject">
                        Confirm Delete
                    </Button>
                </div>
            </div>
        </Dialog>
    </div>
</template>
