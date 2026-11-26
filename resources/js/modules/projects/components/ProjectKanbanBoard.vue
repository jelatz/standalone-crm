<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    Calendar,
    Eye,
    ListChecks,
    Plus,
    RefreshCw,
    Search,
    Users,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import {
    OptimisticLockConflictError,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import { useProjectBoardRealtime } from '@/modules/projects/composables/useProjectBoardRealtime';
import { taskPriorityVariant } from '@/modules/projects/utils/projectFormatters';
import { formatEnumLabel, formatShortDate, getInitials } from '@/modules/crm/utils/formatters';

type ColumnData = App.Modules.Projects.Tasks.Data.TaskStageColumnData;
type TaskItem = App.Modules.Projects.Tasks.Data.TaskData;

const props = withDefaults(
    defineProps<{
        projectId: string;
        columns: ColumnData[];
        stages: App.Modules.Projects.Projects.Data.ProjectStageData[];
        assignableUsers?: App.Modules.Core.Users.Data.UserSummaryData[];
        taskPriorities?: string[];
        canMoveTasks?: boolean;
        canCreateTask?: boolean;
        externalRealtime?: boolean;
    }>(),
    {
        assignableUsers: () => [],
        taskPriorities: () => ['low', 'medium', 'high', 'urgent'],
        canMoveTasks: true,
        canCreateTask: false,
        externalRealtime: false,
    },
);

const emit = defineEmits<{
    openTask: [task: TaskItem];
    taskMoved: [task: TaskItem];
    taskCreated: [task: TaskItem];
}>();

const instanceId = useId();
const searchInputId = `project-board-search-${instanceId}`;
const assigneeFilterId = `project-board-assignee-${instanceId}`;
const priorityFilterId = `project-board-priority-${instanceId}`;

function cloneColumns(source: ColumnData[]): ColumnData[] {
    return source.map((col) => ({
        stage: { ...col.stage },
        tasks_count: col.tasks_count,
        tasks: col.tasks.map((task) => ({
            ...task,
            watcher_ids: [...(task.watcher_ids ?? [])],
        })),
    }));
}

function recalculateColumnCounts(columns: ColumnData[]): ColumnData[] {
    return columns.map((col) => ({
        ...col,
        tasks_count: col.tasks.length,
        stage: {
            ...col.stage,
            tasks_count: col.tasks.length,
        },
    }));
}

const localColumns = ref<ColumnData[]>(cloneColumns(props.columns));

watch(
    () => props.columns,
    (nextCols) => {
        localColumns.value = cloneColumns(nextCols);
    },
    { deep: true },
);

const searchQuery = ref<string>('');
const assigneeFilter = ref<string>('');
const priorityFilter = ref<string>('');

const movingTaskId = ref<string | null>(null);
const draggedTaskId = ref<string | null>(null);
const dragOverStageId = ref<string | null>(null);
const conflictMessage = ref<string>('');
const boardError = ref<string>('');
const refreshingBoard = ref<boolean>(false);

// Inline quick-add per column
const quickAddStageId = ref<string | null>(null);
const quickAddTitle = ref<string>('');
const quickAddSubmitting = ref<boolean>(false);

const ownMutations = new Map<string, number>();

function recordOwnMutation(taskId: string, version: number): void {
    ownMutations.set(taskId, Math.max(ownMutations.get(taskId) ?? 0, version));
}

function findTaskInBoard(taskId: string): TaskItem | null {
    for (const col of localColumns.value) {
        const found = col.tasks.find((item) => item.id === taskId);
        if (found) {
            return found;
        }
    }
    return null;
}

function refreshBoardFromServer(): void {
    refreshingBoard.value = true;
    router.reload({
        only: ['columns', 'tasks', 'stages', 'project'],
        onFinish: () => {
            refreshingBoard.value = false;
            conflictMessage.value = '';
            boardError.value = '';
        },
    });
}

const { coViewers } = useProjectBoardRealtime({
    projectId: () => props.projectId,
    enabled: () => !props.externalRealtime,
    getTaskVersion: (taskId) => findTaskInBoard(taskId)?.version,
    isOwnMutation: (taskId, version) => (ownMutations.get(taskId) ?? 0) >= version,
    isBusy: () => movingTaskId.value !== null,
    onReloadNeeded: () => {
        if (typeof router?.reload === 'function') {
            router.reload({ only: ['columns', 'tasks', 'stages', 'project'] });
        }
    },
});

const allStages = computed(() =>
    props.stages.length > 0 ? props.stages : localColumns.value.map((col) => col.stage),
);

function taskMatchesFilters(task: TaskItem): boolean {
    const q = searchQuery.value.trim().toLowerCase();
    if (q !== '') {
        const hay = `${task.title} ${task.assignee_name ?? ''}`.toLowerCase();
        if (!hay.includes(q)) {
            return false;
        }
    }
    if (assigneeFilter.value !== '' && task.assignee_id !== assigneeFilter.value) {
        return false;
    }
    if (priorityFilter.value !== '' && task.priority !== priorityFilter.value) {
        return false;
    }
    return true;
}

function visibleTasksForColumn(col: ColumnData): TaskItem[] {
    return col.tasks.filter(taskMatchesFilters);
}

function wipBadgeClasses(col: ColumnData): string {
    const limit = col.stage.wip_limit;
    if (limit === null || limit === undefined) {
        return 'border-default bg-surface text-secondary';
    }
    if (col.tasks_count > limit) {
        return 'border-status-danger/40 bg-status-danger/10 text-status-danger';
    }
    if (col.tasks_count >= limit) {
        return 'border-status-warning/40 bg-status-warning/10 text-status-warning';
    }
    return 'border-default bg-surface text-secondary';
}

function applyTaskMoveInColumns(
    columns: ColumnData[],
    taskId: string,
    targetStage: App.Modules.Projects.Projects.Data.ProjectStageData,
    updatedTask?: TaskItem,
    insertIndex?: number,
): ColumnData[] {
    let movingTask: TaskItem | null = null;
    const stripped = columns.map((col) => {
        const idx = col.tasks.findIndex((item) => item.id === taskId);
        if (idx !== -1) {
            movingTask = { ...col.tasks[idx] };
        }
        return {
            ...col,
            tasks: col.tasks.filter((item) => item.id !== taskId),
        };
    });

    if (!movingTask) {
        return columns;
    }

    const baseTask: TaskItem = movingTask;
    const nextTask: TaskItem = updatedTask
        ? { ...updatedTask }
        : {
              ...baseTask,
              project_stage_id: targetStage.id,
              project_stage_name: targetStage.name,
              project_stage_category: targetStage.category,
          };

    const placed = stripped.map((col) => {
        if (col.stage.id !== targetStage.id) {
            return col;
        }
        const nextTasks = [...col.tasks];
        if (insertIndex !== undefined && insertIndex >= 0 && insertIndex <= nextTasks.length) {
            nextTasks.splice(insertIndex, 0, nextTask);
        } else {
            nextTasks.push(nextTask);
        }
        return {
            ...col,
            tasks: nextTasks,
        };
    });

    return recalculateColumnCounts(placed);
}

async function moveTask(
    task: TaskItem,
    targetStageId: string,
    neighbors: { beforeTaskId?: string | null; afterTaskId?: string | null; insertIndex?: number } = {},
): Promise<void> {
    if (!props.canMoveTasks || movingTaskId.value !== null) {
        return;
    }

    const targetStage = allStages.value.find((s) => s.id === targetStageId);
    if (!targetStage) {
        return;
    }

    const beforeTaskId =
        neighbors.beforeTaskId && neighbors.beforeTaskId !== task.id
            ? neighbors.beforeTaskId
            : null;
    const afterTaskId =
        neighbors.afterTaskId &&
        neighbors.afterTaskId !== task.id &&
        neighbors.afterTaskId !== beforeTaskId
            ? neighbors.afterTaskId
            : null;

    if (task.project_stage_id === targetStage.id && beforeTaskId === null && afterTaskId === null) {
        return;
    }

    conflictMessage.value = '';
    boardError.value = '';

    const snapshot = cloneColumns(localColumns.value);
    localColumns.value = applyTaskMoveInColumns(
        localColumns.value,
        task.id,
        targetStage,
        undefined,
        neighbors.insertIndex,
    );
    movingTaskId.value = task.id;

    try {
        const updated = await projectsApi.moveTask(task.id, {
            target_stage_id: targetStage.id,
            expected_version: task.version,
            before_task_id: beforeTaskId,
            after_task_id: afterTaskId,
        });

        recordOwnMutation(updated.id, updated.version);
        localColumns.value = applyTaskMoveInColumns(
            localColumns.value,
            task.id,
            targetStage,
            updated,
            neighbors.insertIndex,
        );
        emit('taskMoved', updated);
    } catch (error) {
        // Always rollback optimistic state on 409 / 422 / network error
        localColumns.value = snapshot;

        if (error instanceof OptimisticLockConflictError) {
            conflictMessage.value = error.message;
        } else if (error instanceof ValidationRequestError) {
            const firstErr = Object.values(error.errors).flat()[0];
            boardError.value = firstErr || error.message;
        } else if (error instanceof Error) {
            boardError.value = error.message;
        } else {
            boardError.value = 'Unable to move task to selected stage.';
        }
    } finally {
        movingTaskId.value = null;
    }
}

function handleStageSelectChange(task: TaskItem, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const nextStageId = select.value;
    if (nextStageId && nextStageId !== task.project_stage_id) {
        void moveTask(task, nextStageId);
    }
}

function onDragStart(task: TaskItem, event: DragEvent): void {
    if (!props.canMoveTasks) {
        event.preventDefault();
        return;
    }
    draggedTaskId.value = task.id;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', task.id);
    }
}

function onDragEnd(): void {
    draggedTaskId.value = null;
    dragOverStageId.value = null;
}

function onColumnDragOver(stageId: string, event: DragEvent): void {
    if (!props.canMoveTasks) {
        return;
    }
    event.preventDefault();
    dragOverStageId.value = stageId;
}

function onColumnDrop(column: ColumnData, event: DragEvent): void {
    event.preventDefault();
    const taskId = draggedTaskId.value || event.dataTransfer?.getData('text/plain') || '';
    draggedTaskId.value = null;
    dragOverStageId.value = null;

    if (!taskId) {
        return;
    }
    const task = findTaskInBoard(taskId);
    if (!task) {
        return;
    }

    const filtered = column.tasks.filter((t) => t.id !== task.id);
    const lastTask = filtered[filtered.length - 1] ?? null;
    void moveTask(task, column.stage.id, {
        beforeTaskId: lastTask?.id ?? null,
        afterTaskId: null,
        insertIndex: filtered.length,
    });
}

function onCardDrop(column: ColumnData, targetTask: TaskItem, targetIndex: number, event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    const taskId = draggedTaskId.value || event.dataTransfer?.getData('text/plain') || '';
    draggedTaskId.value = null;
    dragOverStageId.value = null;

    if (!taskId || taskId === targetTask.id) {
        return;
    }
    const task = findTaskInBoard(taskId);
    if (!task) {
        return;
    }

    const filtered = column.tasks.filter((t) => t.id !== task.id);
    const adjustedIndex = filtered.findIndex((t) => t.id === targetTask.id);
    const prevTask = adjustedIndex > 0 ? filtered[adjustedIndex - 1] : null;

    void moveTask(task, column.stage.id, {
        beforeTaskId: prevTask?.id ?? null,
        afterTaskId: targetTask.id,
        insertIndex: adjustedIndex >= 0 ? adjustedIndex : targetIndex,
    });
}

function openQuickAdd(stageId: string): void {
    quickAddStageId.value = stageId;
    quickAddTitle.value = '';
    boardError.value = '';
}

async function submitQuickAdd(stageId: string): Promise<void> {
    if (!props.canCreateTask || quickAddSubmitting.value) {
        return;
    }
    const title = quickAddTitle.value.trim();
    if (!title) {
        return;
    }

    quickAddSubmitting.value = true;
    boardError.value = '';

    try {
        const created = await projectsApi.createTask({
            title,
            project_id: props.projectId,
            project_stage_id: stageId,
            parent_task_id: null,
            linkable_type: null,
            linkable_id: null,
            assignee_id: null,
            description_json: null,
            priority: 'medium',
            status: null,
            due_at: null,
            estimated_minutes: null,
        });

        recordOwnMutation(created.id, created.version);
        localColumns.value = recalculateColumnCounts(
            localColumns.value.map((col) =>
                col.stage.id === stageId
                    ? { ...col, tasks: [...col.tasks, created] }
                    : col,
            ),
        );
        quickAddStageId.value = null;
        quickAddTitle.value = '';
        emit('taskCreated', created);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            const firstErr = Object.values(error.errors).flat()[0];
            boardError.value = firstErr || error.message;
        } else {
            boardError.value =
                error instanceof Error ? error.message : 'Unable to create task.';
        }
    } finally {
        quickAddSubmitting.value = false;
    }
}
</script>

<template>
    <div data-testid="project-kanban-board" class="space-y-3">
        <!-- Filter & Co-Viewer Strip -->
        <div
            class="flex flex-wrap items-center justify-between gap-2.5 rounded-[8px] border border-default bg-surface px-3.5 py-2"
        >
            <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1">
                <div class="relative w-full sm:w-56">
                    <label :for="searchInputId" class="sr-only">Filter board tasks</label>
                    <Search
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Input
                        :id="searchInputId"
                        v-model="searchQuery"
                        placeholder="Filter tasks or assignees..."
                        class="pl-8"
                    />
                </div>

                <div v-if="props.assignableUsers.length > 0" class="w-40">
                    <label :for="assigneeFilterId" class="sr-only">Filter by assignee</label>
                    <select
                        :id="assigneeFilterId"
                        v-model="assigneeFilter"
                        class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <option value="">All assignees</option>
                        <option
                            v-for="user in props.assignableUsers"
                            :key="user.id"
                            :value="user.id"
                        >
                            {{ user.name }}
                        </option>
                    </select>
                </div>

                <div class="w-36">
                    <label :for="priorityFilterId" class="sr-only">Filter by priority</label>
                    <select
                        :id="priorityFilterId"
                        v-model="priorityFilter"
                        class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <option value="">All priorities</option>
                        <option
                            v-for="prio in props.taskPriorities"
                            :key="prio"
                            :value="prio"
                        >
                            {{ formatEnumLabel(prio) }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <div
                    v-if="!props.externalRealtime && coViewers.length > 0"
                    data-testid="project-board-presence-strip"
                    class="inline-flex items-center gap-1.5 rounded-full border border-default bg-subtle px-2.5 py-1 text-[11px] font-medium text-secondary"
                >
                    <Users class="h-3 w-3 text-status-success" :stroke-width="1.75" aria-hidden="true" />
                    <span class="font-mono tabular-nums">{{ coViewers.length }} viewing</span>
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    :loading="refreshingBoard"
                    @click="refreshBoardFromServer"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Sync</span>
                </Button>
            </div>
        </div>

        <!-- 409 Conflict Banner -->
        <div
            v-if="conflictMessage"
            role="alert"
            data-testid="project-kanban-conflict-banner"
            class="flex flex-col gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2.5 text-[13px] text-primary sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Concurrent update detected (409 Conflict)</p>
                    <p class="text-[12px] text-secondary">{{ conflictMessage }}</p>
                </div>
            </div>
            <Button
                variant="secondary"
                size="sm"
                :loading="refreshingBoard"
                data-testid="project-kanban-conflict-refresh"
                @click="refreshBoardFromServer"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Refresh board</span>
            </Button>
        </div>

        <!-- 422 / General Error Banner -->
        <div
            v-if="boardError"
            role="alert"
            data-testid="project-kanban-error-banner"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2 text-[12px] text-status-danger"
        >
            <div class="flex items-center gap-2">
                <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ boardError }}</span>
            </div>
            <button
                type="button"
                class="text-[11px] font-medium underline cursor-pointer"
                @click="boardError = ''"
            >
                Dismiss
            </button>
        </div>

        <!-- Hairline Kanban Grid -->
        <div class="overflow-x-auto pb-2">
            <div class="flex min-w-max items-stretch divide-x divide-default rounded-[8px] border border-default bg-surface">
                <section
                    v-for="column in localColumns"
                    :key="column.stage.id"
                    :data-testid="`project-kanban-column-${column.stage.id}`"
                    :aria-label="`${column.stage.name} stage (${column.tasks_count} tasks)`"
                    :class="[
                        'flex w-[276px] shrink-0 flex-col transition-colors duration-150',
                        dragOverStageId === column.stage.id ? 'bg-accent-primary/5' : 'bg-surface',
                    ]"
                    @dragover="onColumnDragOver(column.stage.id, $event)"
                    @drop="onColumnDrop(column, $event)"
                >
                    <!-- Column Header -->
                    <header class="flex items-center justify-between gap-2 border-b border-default bg-subtle px-3 py-2.5">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <h3 class="truncate text-[12px] font-semibold text-primary">
                                {{ column.stage.name }}
                            </h3>
                        </div>
                        <span
                            :data-testid="`project-kanban-column-count-${column.stage.id}`"
                            :class="[
                                'rounded-full border px-2 py-0.5 font-mono text-[11px] font-medium tabular-nums',
                                wipBadgeClasses(column),
                            ]"
                        >
                            {{
                                column.stage.wip_limit !== null
                                    ? `${column.tasks_count} / ${column.stage.wip_limit}`
                                    : column.tasks_count
                            }}
                        </span>
                    </header>

                    <!-- Column Cards -->
                    <div class="flex-1 space-y-2 bg-canvas/50 p-2 min-h-[260px]">
                        <div
                            v-if="visibleTasksForColumn(column).length === 0"
                            class="flex h-20 items-center justify-center rounded-[6px] border border-dashed border-default px-3 text-center text-[11px] text-muted"
                        >
                            No tasks in {{ column.stage.name }}
                        </div>

                        <article
                            v-for="(task, taskIdx) in visibleTasksForColumn(column)"
                            :key="task.id"
                            :data-testid="`project-task-card-${task.id}`"
                            :draggable="props.canMoveTasks"
                            :class="[
                                'rounded-[6px] border border-default bg-surface p-2.5 space-y-2 transition-colors duration-150',
                                movingTaskId === task.id ? 'opacity-60' : 'hover:border-secondary/45',
                                draggedTaskId === task.id ? 'opacity-40' : '',
                            ]"
                            @dragstart="onDragStart(task, $event)"
                            @dragend="onDragEnd"
                            @dragover.prevent
                            @drop="onCardDrop(column, task, taskIdx, $event)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <button
                                    type="button"
                                    :data-testid="`project-task-open-${task.id}`"
                                    class="min-w-0 flex-1 text-left text-[13px] font-medium leading-snug text-primary hover:text-accent-primary cursor-pointer"
                                    @click="emit('openTask', task)"
                                >
                                    <span class="line-clamp-2">{{ task.title }}</span>
                                </button>
                                <Badge :variant="taskPriorityVariant(task.priority)">
                                    {{ formatEnumLabel(task.priority) }}
                                </Badge>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-muted">
                                <span
                                    v-if="task.parent_task_id"
                                    class="rounded border border-default bg-subtle px-1.5 py-0.5 font-mono text-[10px] text-secondary"
                                >
                                    Subtask
                                </span>
                                <span
                                    v-if="task.subtasks_count > 0"
                                    class="inline-flex items-center gap-1 font-mono tabular-nums"
                                >
                                    <ListChecks class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                    <span>{{ task.subtasks_count }}</span>
                                </span>
                                <span
                                    v-if="task.watchers_count > 0"
                                    class="inline-flex items-center gap-1 font-mono tabular-nums"
                                >
                                    <Eye class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                    <span>{{ task.watchers_count }}</span>
                                </span>
                                <span
                                    v-if="task.due_at"
                                    class="ml-auto inline-flex items-center gap-1 font-mono tabular-nums"
                                >
                                    <Calendar class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                    <span>{{ formatShortDate(task.due_at) }}</span>
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-2 border-t border-default pt-2">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span
                                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-default bg-subtle font-mono text-[10px] font-semibold text-primary"
                                        aria-hidden="true"
                                    >
                                        {{ getInitials(task.assignee_name, 'UN') }}
                                    </span>
                                    <span class="truncate text-[11px] text-secondary">
                                        {{ task.assignee_name ?? 'Unassigned' }}
                                    </span>
                                </div>

                                <div v-if="props.canMoveTasks" class="shrink-0">
                                    <label :for="`task-stage-select-${task.id}`" class="sr-only">
                                        Move stage for {{ task.title }}
                                    </label>
                                    <select
                                        :id="`task-stage-select-${task.id}`"
                                        :data-testid="`project-task-stage-select-${task.id}`"
                                        :value="task.project_stage_id ?? ''"
                                        :disabled="movingTaskId === task.id"
                                        class="h-6 max-w-[116px] rounded-[4px] border border-default bg-subtle px-1.5 text-[11px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                                        @change="handleStageSelectChange(task, $event)"
                                    >
                                        <option
                                            v-for="stageOption in allStages"
                                            :key="stageOption.id"
                                            :value="stageOption.id"
                                        >
                                            {{ stageOption.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </article>

                        <!-- Column Quick-Add Task -->
                        <div v-if="props.canCreateTask" class="pt-1">
                            <form
                                v-if="quickAddStageId === column.stage.id"
                                class="space-y-1.5 rounded-[6px] border border-default bg-surface p-2"
                                @submit.prevent="submitQuickAdd(column.stage.id)"
                            >
                                <label :for="`quick-add-${column.stage.id}`" class="sr-only">
                                    New task title in {{ column.stage.name }}
                                </label>
                                <Input
                                    :id="`quick-add-${column.stage.id}`"
                                    v-model="quickAddTitle"
                                    placeholder="Task title..."
                                    :disabled="quickAddSubmitting"
                                    @keydown.escape.prevent="quickAddStageId = null"
                                />
                                <div class="flex items-center justify-end gap-1.5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="quickAddSubmitting"
                                        @click="quickAddStageId = null"
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        size="sm"
                                        :loading="quickAddSubmitting"
                                        :disabled="!quickAddTitle.trim()"
                                    >
                                        Add
                                    </Button>
                                </div>
                            </form>

                            <button
                                v-else
                                type="button"
                                :data-testid="`kanban-quick-add-${column.stage.id}`"
                                class="flex h-7 w-full items-center gap-1.5 rounded-[6px] px-2 text-[11px] font-medium text-secondary hover:bg-surface hover:text-primary cursor-pointer"
                                @click="openQuickAdd(column.stage.id)"
                            >
                                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                <span>Add task</span>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
