<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    Eye,
    EyeOff,
    FolderKanban,
    Link2,
    Plus,
    RefreshCw,
    Save,
    Trash2,
    X,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import RichJsonRenderer from '@/modules/crm/components/RichJsonRenderer.vue';
import {
    extractFirstFieldErrors,
    OptimisticLockConflictError,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import {
    linkableHref,
    taskPriorityVariant,
    taskStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatEnumLabel, formatShortDate } from '@/modules/crm/utils/formatters';

const DEFAULT_PRIORITIES = ['low', 'medium', 'high', 'urgent'];
const DEFAULT_STATUSES = ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'];

const props = withDefaults(
    defineProps<{
        open: boolean;
        task: App.Modules.Projects.Tasks.Data.TaskData | null;
        subtasks?: App.Modules.Projects.Tasks.Data.TaskData[];
        assignableUsers?: App.Modules.Core.Users.Data.UserSummaryData[];
        priorities?: string[];
        statuses?: string[];
        canUpdate?: boolean;
        canDelete?: boolean;
        canCreateSubtask?: boolean;
        portalDisabled?: boolean;
    }>(),
    {
        subtasks: () => [],
        assignableUsers: () => [],
        priorities: () => ['low', 'medium', 'high', 'urgent'],
        statuses: () => ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'],
        canUpdate: false,
        canDelete: false,
        canCreateSubtask: false,
        portalDisabled: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    taskUpdated: [task: App.Modules.Projects.Tasks.Data.TaskData];
    taskCreated: [task: App.Modules.Projects.Tasks.Data.TaskData];
    taskDeleted: [taskId: string];
}>();

const instanceId = useId();
const titleInputId = `task-drawer-title-${instanceId}`;
const statusSelectId = `task-drawer-status-${instanceId}`;
const prioritySelectId = `task-drawer-priority-${instanceId}`;
const assigneeSelectId = `task-drawer-assignee-${instanceId}`;
const dueDateInputId = `task-drawer-due-${instanceId}`;
const estimatedMinutesId = `task-drawer-est-${instanceId}`;
const newSubtaskInputId = `task-drawer-new-subtask-${instanceId}`;

const localTask = ref<App.Modules.Projects.Tasks.Data.TaskData | null>(
    props.task ? { ...props.task } : null,
);
const localSubtasks = ref<App.Modules.Projects.Tasks.Data.TaskData[]>([...props.subtasks]);

const form = ref({
    title: '',
    status: 'todo' as App.Modules.Projects.Tasks.Enums.TaskStatusEnum,
    priority: 'medium' as App.Modules.Projects.Tasks.Enums.TaskPriorityEnum,
    assignee_id: '',
    due_at: '',
    estimated_minutes: '',
});

const saving = ref<boolean>(false);
const togglingWatcher = ref<boolean>(false);
const deleting = ref<boolean>(false);
const confirmDelete = ref<boolean>(false);
const addingSubtask = ref<boolean>(false);
const busySubtaskId = ref<string | null>(null);
const newSubtaskTitle = ref<string>('');

const errorBanner = ref<string>('');
const successBanner = ref<string>('');
const conflictEntity = ref<App.Modules.Projects.Tasks.Data.TaskData | null>(null);
const conflictMessage = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

function toDateInputValue(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const match = iso.match(/^\d{4}-\d{2}-\d{2}/);
    return match ? match[0] : '';
}

function hydrateFromTask(task: App.Modules.Projects.Tasks.Data.TaskData | null): void {
    localTask.value = task ? { ...task } : null;
    errorBanner.value = '';
    successBanner.value = '';
    conflictEntity.value = null;
    conflictMessage.value = '';
    fieldErrors.value = {};
    confirmDelete.value = false;
    newSubtaskTitle.value = '';

    if (!task) {
        return;
    }

    form.value = {
        title: task.title,
        status: task.status,
        priority: task.priority,
        assignee_id: task.assignee_id ?? '',
        due_at: toDateInputValue(task.due_at),
        estimated_minutes:
            task.estimated_minutes !== null && task.estimated_minutes !== undefined
                ? String(task.estimated_minutes)
                : '',
    };
}

watch(
    () => [props.open, props.task] as const,
    ([isOpen, nextTask]) => {
        if (isOpen) {
            hydrateFromTask(nextTask);
        }
    },
    { immediate: true, deep: true },
);

watch(
    () => props.subtasks,
    (nextSubtasks) => {
        localSubtasks.value = [...nextSubtasks];
    },
    { immediate: true, deep: true },
);

const priorityOptions = computed(() =>
    props.priorities.length > 0 ? props.priorities : DEFAULT_PRIORITIES,
);
const statusOptions = computed(() =>
    props.statuses.length > 0 ? props.statuses : DEFAULT_STATUSES,
);

const crmLink = computed(() =>
    localTask.value
        ? linkableHref(localTask.value.linkable_type, localTask.value.linkable_id)
        : null,
);

const canHaveSubtasks = computed(
    () => localTask.value !== null && localTask.value.parent_task_id === null,
);

async function handleSave(): Promise<void> {
    if (!localTask.value || !props.canUpdate || saving.value) {
        return;
    }

    const trimmedTitle = form.value.title.trim();
    if (!trimmedTitle) {
        fieldErrors.value = { title: 'Task title cannot be empty.' };
        errorBanner.value = 'Enter a task title before saving.';
        return;
    }

    const estRaw = form.value.estimated_minutes.trim();
    const estNumber = estRaw !== '' ? Number(estRaw) : null;
    if (estNumber !== null && (!Number.isInteger(estNumber) || estNumber < 1)) {
        fieldErrors.value = { estimated_minutes: 'Estimated minutes must be a positive whole number.' };
        errorBanner.value = 'Review the highlighted fields.';
        return;
    }

    saving.value = true;
    errorBanner.value = '';
    successBanner.value = '';
    conflictEntity.value = null;
    conflictMessage.value = '';
    fieldErrors.value = {};

    try {
        const updated = await projectsApi.updateTask(localTask.value.id, {
            expected_version: localTask.value.version,
            title: trimmedTitle,
            status: form.value.status,
            priority: form.value.priority,
            assignee_id: form.value.assignee_id.trim() || null,
            due_at: form.value.due_at.trim() || null,
            estimated_minutes: estNumber,
        });

        hydrateFromTask(updated);
        successBanner.value = `Saved (v${updated.version}).`;
        emit('taskUpdated', updated);
    } catch (error) {
        if (error instanceof OptimisticLockConflictError) {
            conflictMessage.value = error.message;
            const candidate = error.entity as Partial<App.Modules.Projects.Tasks.Data.TaskData>;
            conflictEntity.value =
                candidate && typeof candidate.id === 'string' && typeof candidate.version === 'number'
                    ? (candidate as App.Modules.Projects.Tasks.Data.TaskData)
                    : null;
        } else if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            errorBanner.value = error.message;
        } else {
            errorBanner.value =
                error instanceof Error ? error.message : 'Unable to save task changes.';
        }
    } finally {
        saving.value = false;
    }
}

function applyConflictLatest(): void {
    if (!conflictEntity.value) {
        return;
    }
    const latest = conflictEntity.value;
    hydrateFromTask(latest);
    emit('taskUpdated', latest);
}

async function handleToggleWatcher(): Promise<void> {
    if (!localTask.value || togglingWatcher.value) {
        return;
    }

    togglingWatcher.value = true;
    errorBanner.value = '';

    try {
        const updated = await projectsApi.toggleWatcher(localTask.value.id, {
            user_id: null,
            watching: !localTask.value.is_watching,
        });
        localTask.value = {
            ...localTask.value,
            is_watching: updated.is_watching,
            watchers_count: updated.watchers_count,
            watcher_ids: updated.watcher_ids,
        };
        emit('taskUpdated', updated);
    } catch (error) {
        errorBanner.value =
            error instanceof Error ? error.message : 'Unable to update watcher subscription.';
    } finally {
        togglingWatcher.value = false;
    }
}

async function handleAddSubtask(): Promise<void> {
    if (!localTask.value || !canHaveSubtasks.value || !props.canCreateSubtask || addingSubtask.value) {
        return;
    }

    const title = newSubtaskTitle.value.trim();
    if (!title) {
        return;
    }

    addingSubtask.value = true;
    errorBanner.value = '';

    try {
        const created = await projectsApi.createTask({
            title,
            project_id: localTask.value.project_id,
            project_stage_id: localTask.value.project_stage_id,
            parent_task_id: localTask.value.id,
            linkable_type: null,
            linkable_id: null,
            assignee_id: null,
            description_json: null,
            priority: 'medium',
            status: 'todo',
            due_at: null,
            estimated_minutes: null,
        });

        localSubtasks.value = [...localSubtasks.value, created];
        localTask.value = {
            ...localTask.value,
            subtasks_count: localTask.value.subtasks_count + 1,
        };
        newSubtaskTitle.value = '';
        emit('taskCreated', created);
        emit('taskUpdated', localTask.value);
    } catch (error) {
        errorBanner.value =
            error instanceof Error ? error.message : 'Unable to create subtask.';
    } finally {
        addingSubtask.value = false;
    }
}

async function handleToggleSubtaskDone(subtask: App.Modules.Projects.Tasks.Data.TaskData): Promise<void> {
    if (!props.canUpdate || busySubtaskId.value) {
        return;
    }

    const nextStatus: App.Modules.Projects.Tasks.Enums.TaskStatusEnum =
        subtask.status === 'done' ? 'todo' : 'done';
    busySubtaskId.value = subtask.id;
    errorBanner.value = '';

    try {
        const updated = await projectsApi.updateTask(subtask.id, {
            expected_version: subtask.version,
            status: nextStatus,
        });
        localSubtasks.value = localSubtasks.value.map((item) =>
            item.id === updated.id ? updated : item,
        );
        emit('taskUpdated', updated);
    } catch (error) {
        errorBanner.value =
            error instanceof Error ? error.message : 'Unable to update subtask status.';
    } finally {
        busySubtaskId.value = null;
    }
}

async function handleDeleteTask(): Promise<void> {
    if (!localTask.value || !props.canDelete || deleting.value) {
        return;
    }

    deleting.value = true;
    errorBanner.value = '';

    try {
        await projectsApi.deleteTask(localTask.value.id, localTask.value.version);
        const deletedId = localTask.value.id;
        emit('taskDeleted', deletedId);
        emit('update:open', false);
    } catch (error) {
        errorBanner.value =
            error instanceof Error ? error.message : 'Unable to delete task.';
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <DialogRoot :open="props.open" @update:open="emit('update:open', $event)">
        <DialogPortal :disabled="props.portalDisabled">
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/45 transition-opacity duration-150 ease-out"
            />
            <DialogContent
                data-testid="task-detail-drawer"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[460px] flex-col border-l border-default bg-surface text-primary shadow-xl focus:outline-none"
            >
                <header class="flex items-start justify-between gap-3 border-b border-default px-4 py-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <DialogTitle class="truncate text-[14px] font-semibold text-primary">
                                {{ localTask?.title ?? 'Task Details' }}
                            </DialogTitle>
                            <Badge v-if="localTask" variant="neutral" data-testid="task-drawer-version">
                                v{{ localTask.version }}
                            </Badge>
                            <Badge v-if="localTask" :variant="taskPriorityVariant(localTask.priority)">
                                {{ formatEnumLabel(localTask.priority) }}
                            </Badge>
                        </div>
                        <DialogDescription class="flex flex-wrap items-center gap-2 text-[11px] text-secondary">
                            <span v-if="localTask?.project_code" class="font-mono font-medium">
                                {{ localTask.project_code }}
                            </span>
                            <span v-if="localTask?.project_stage_name">
                                · {{ localTask.project_stage_name }}
                            </span>
                            <span v-if="localTask?.parent_task_id">
                                · Subtask
                            </span>
                        </DialogDescription>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <Button
                            v-if="localTask"
                            type="button"
                            variant="secondary"
                            size="sm"
                            :loading="togglingWatcher"
                            data-testid="task-watcher-toggle"
                            @click="handleToggleWatcher"
                        >
                            <Eye v-if="localTask.is_watching" class="h-3.5 w-3.5 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                            <EyeOff v-else class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <span class="font-mono tabular-nums">
                                {{ localTask.is_watching ? 'Watching' : 'Watch' }} ({{ localTask.watchers_count }})
                            </span>
                        </Button>

                        <DialogClose
                            type="button"
                            aria-label="Close task drawer"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                        >
                            <X class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                        </DialogClose>
                    </div>
                </header>

                <div v-if="localTask" class="flex-1 overflow-y-auto px-4 py-4 space-y-4">
                    <!-- 409 Conflict Banner -->
                    <div
                        v-if="conflictMessage"
                        role="alert"
                        data-testid="task-drawer-conflict-banner"
                        class="flex flex-col gap-2 rounded-[6px] border border-status-warning/45 bg-status-warning/10 px-3 py-2.5 text-[12px] text-primary"
                    >
                        <div class="flex items-start gap-2">
                            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                            <div>
                                <p class="font-semibold">Version Conflict (409)</p>
                                <p class="text-secondary">{{ conflictMessage }}</p>
                            </div>
                        </div>
                        <div v-if="conflictEntity" class="flex justify-end">
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                data-testid="task-drawer-load-latest"
                                @click="applyConflictLatest"
                            >
                                <RefreshCw class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>Load latest (v{{ conflictEntity.version }})</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Error / Success Banners -->
                    <div
                        v-if="errorBanner"
                        role="alert"
                        data-testid="task-drawer-error"
                        class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ errorBanner }}</span>
                    </div>

                    <div
                        v-if="successBanner"
                        role="status"
                        class="flex items-center gap-2 rounded-[6px] border border-status-success/35 bg-status-success/10 px-3 py-2 text-[12px] text-status-success"
                    >
                        <CheckCircle2 class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                        <span>{{ successBanner }}</span>
                    </div>

                    <!-- Linked Context Pills -->
                    <div
                        v-if="localTask.project_id || crmLink || localTask.has_masked_linkable"
                        class="flex flex-wrap items-center gap-2 rounded-[6px] border border-default bg-subtle/40 px-3 py-2 text-[12px]"
                    >
                        <Link
                            v-if="localTask.project_id"
                            :href="`/app/projects/${localTask.project_id}`"
                            class="inline-flex items-center gap-1 font-medium text-accent-primary hover:underline"
                        >
                            <FolderKanban class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span class="font-mono">{{ localTask.project_code }}</span>
                            <span v-if="localTask.project_name" class="truncate text-secondary">
                                · {{ localTask.project_name }}
                            </span>
                        </Link>

                        <Link
                            v-if="crmLink"
                            :href="crmLink"
                            class="inline-flex items-center gap-1 font-medium text-accent-primary hover:underline"
                        >
                            <Link2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>Linked {{ formatEnumLabel(localTask.linkable_type) }}</span>
                        </Link>
                        <span
                            v-else-if="localTask.has_masked_linkable"
                            class="text-secondary"
                        >
                            Linked CRM record (outside your silo)
                        </span>
                    </div>

                    <!-- Task Properties Form -->
                    <form
                        id="task-drawer-form"
                        data-testid="task-drawer-form"
                        class="space-y-3"
                        @submit.prevent="handleSave"
                    >
                        <div>
                            <label :for="titleInputId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Title
                            </label>
                            <Input
                                :id="titleInputId"
                                v-model="form.title"
                                :disabled="!props.canUpdate || saving"
                                :error-message="fieldErrors.title ?? ''"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label :for="statusSelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Status
                                </label>
                                <select
                                    :id="statusSelectId"
                                    v-model="form.status"
                                    :disabled="!props.canUpdate || saving"
                                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                                >
                                    <option
                                        v-for="opt in statusOptions"
                                        :key="opt"
                                        :value="opt"
                                    >
                                        {{ formatEnumLabel(opt) }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label :for="prioritySelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Priority
                                </label>
                                <select
                                    :id="prioritySelectId"
                                    v-model="form.priority"
                                    :disabled="!props.canUpdate || saving"
                                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                                >
                                    <option
                                        v-for="opt in priorityOptions"
                                        :key="opt"
                                        :value="opt"
                                    >
                                        {{ formatEnumLabel(opt) }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label :for="dueDateInputId" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Due Date
                                </label>
                                <Input
                                    :id="dueDateInputId"
                                    v-model="form.due_at"
                                    type="date"
                                    class="font-mono tabular-nums"
                                    :disabled="!props.canUpdate || saving"
                                    :error-message="fieldErrors.due_at ?? ''"
                                />
                            </div>

                            <div>
                                <label :for="estimatedMinutesId" class="mb-1 block text-[12px] font-medium text-secondary">
                                    Estimate (min)
                                </label>
                                <Input
                                    :id="estimatedMinutesId"
                                    v-model="form.estimated_minutes"
                                    type="number"
                                    placeholder="60"
                                    class="font-mono tabular-nums"
                                    :disabled="!props.canUpdate || saving"
                                    :error-message="fieldErrors.estimated_minutes ?? ''"
                                />
                            </div>
                        </div>

                        <div>
                            <label :for="assigneeSelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Assignee
                            </label>
                            <select
                                v-if="props.assignableUsers.length > 0"
                                :id="assigneeSelectId"
                                v-model="form.assignee_id"
                                :disabled="!props.canUpdate || saving"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="user in props.assignableUsers"
                                    :key="user.id"
                                    :value="user.id"
                                >
                                    {{ user.name }} ({{ user.employee_code }})
                                </option>
                            </select>
                            <p v-else class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-primary">
                                {{ localTask.assignee_name ?? 'Unassigned' }}
                            </p>
                        </div>

                        <div v-if="props.canUpdate" class="flex items-center justify-end pt-1">
                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                :loading="saving"
                                data-testid="task-drawer-save"
                            >
                                <Save class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Save Changes</span>
                            </Button>
                        </div>
                    </form>

                    <!-- Description -->
                    <section v-if="localTask.description_json" class="space-y-1.5 border-t border-default pt-3">
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-secondary">
                            Description
                        </h4>
                        <div class="rounded-[6px] border border-default bg-subtle/40 px-3 py-2">
                            <RichJsonRenderer :document="localTask.description_json" />
                        </div>
                    </section>

                    <!-- 1-Level Subtasks Section -->
                    <section
                        v-if="canHaveSubtasks"
                        data-testid="task-subtasks-section"
                        class="space-y-2 border-t border-default pt-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-[12px] font-semibold text-primary">
                                Subtasks
                            </h4>
                            <span class="font-mono text-[11px] tabular-nums text-muted">
                                {{ localSubtasks.filter((s) => s.status === 'done').length }} /
                                {{ Math.max(localSubtasks.length, localTask.subtasks_count) }}
                            </span>
                        </div>

                        <p
                            v-if="localSubtasks.length === 0"
                            class="rounded-[6px] border border-dashed border-default px-3 py-2.5 text-[12px] text-muted"
                        >
                            No subtasks yet. Subtasks are capped at one level deep.
                        </p>

                        <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
                            <li
                                v-for="subtask in localSubtasks"
                                :key="subtask.id"
                                class="flex items-center justify-between gap-2 px-2.5 py-2 text-[12px]"
                            >
                                <label
                                    :for="`subtask-check-${subtask.id}`"
                                    class="flex min-w-0 flex-1 items-center gap-2 cursor-pointer"
                                >
                                    <input
                                        :id="`subtask-check-${subtask.id}`"
                                        type="checkbox"
                                        :checked="subtask.status === 'done'"
                                        :disabled="!props.canUpdate || busySubtaskId === subtask.id"
                                        class="h-3.5 w-3.5 shrink-0 rounded border-default accent-accent-primary"
                                        @change="handleToggleSubtaskDone(subtask)"
                                    />
                                    <span
                                        :class="[
                                            'truncate',
                                            subtask.status === 'done'
                                                ? 'line-through text-muted'
                                                : 'text-primary',
                                        ]"
                                    >
                                        {{ subtask.title }}
                                    </span>
                                </label>
                                <Badge :variant="taskStatusVariant(subtask.status)">
                                    {{ formatEnumLabel(subtask.status) }}
                                </Badge>
                            </li>
                        </ul>

                        <form
                            v-if="props.canCreateSubtask"
                            class="flex items-center gap-2 pt-1"
                            @submit.prevent="handleAddSubtask"
                        >
                            <label :for="newSubtaskInputId" class="sr-only">
                                Add subtask title
                            </label>
                            <div class="min-w-0 flex-1">
                                <Input
                                    :id="newSubtaskInputId"
                                    v-model="newSubtaskTitle"
                                    placeholder="Add 1-level subtask..."
                                    :disabled="addingSubtask"
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="secondary"
                                size="sm"
                                :loading="addingSubtask"
                                :disabled="!newSubtaskTitle.trim()"
                                data-testid="task-add-subtask-btn"
                            >
                                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Add</span>
                            </Button>
                        </form>
                    </section>

                    <!-- Metadata Footer -->
                    <dl class="grid grid-cols-2 gap-2 border-t border-default pt-3 text-[11px] text-secondary">
                        <div>
                            <dt class="text-muted">Created By</dt>
                            <dd class="font-medium text-primary">
                                {{ localTask.creator_name ?? 'Team Member' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Updated</dt>
                            <dd class="font-mono tabular-nums text-primary">
                                {{ formatShortDate(localTask.updated_at) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Drawer Footer (Delete) -->
                <footer
                    v-if="localTask && props.canDelete"
                    class="flex items-center justify-between gap-2 border-t border-default bg-surface px-4 py-3"
                >
                    <div v-if="!confirmDelete" class="flex w-full justify-end">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-status-danger hover:bg-status-danger/10 hover:text-status-danger"
                            @click="confirmDelete = true"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>Delete Task</span>
                        </Button>
                    </div>
                    <div v-else class="flex w-full items-center justify-between gap-2">
                        <span class="text-[12px] font-medium text-status-danger">
                            Delete this task?
                        </span>
                        <div class="flex items-center gap-1.5">
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                :disabled="deleting"
                                @click="confirmDelete = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="danger"
                                size="sm"
                                :loading="deleting"
                                data-testid="task-drawer-confirm-delete"
                                @click="handleDeleteTask"
                            >
                                Confirm
                            </Button>
                        </div>
                    </div>
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
