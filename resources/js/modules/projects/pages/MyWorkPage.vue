<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckSquare,
    ChevronDown,
    ChevronRight,
    Eye,
    FolderKanban,
    Link2,
    ListChecks,
    RefreshCw,
    Search,
    WifiOff,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import TaskDetailDrawer from '@/modules/projects/components/TaskDetailDrawer.vue';
import {
    OptimisticLockConflictError,
    projectsApi,
} from '@/modules/projects/api/projectsApi';
import {
    linkableHref,
    taskPriorityVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatEnumLabel, formatShortDate } from '@/modules/crm/utils/formatters';
import { useAuthStore } from '@/stores/auth.store';
import { useRealtimeStore } from '@/stores/realtime.store';

type MyWorkCategory = 'all' | 'urgent' | 'project' | 'crm';

const props = withDefaults(
    defineProps<{
        groups: App.Modules.Projects.Tasks.Data.MyWorkGroupData[];
        totalCount: number;
        priorities: string[];
        statuses: string[];
        canUpdateTasks?: boolean;
        canDeleteTasks?: boolean;
        initialState?: 'ready' | 'loading' | 'error';
        initialError?: string;
    }>(),
    {
        canUpdateTasks: undefined,
        canDeleteTasks: undefined,
        initialState: 'ready',
        initialError: '',
    },
);

const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();
const page = usePage<{ auth?: { user?: App.Modules.Core.Auth.Data.AuthenticatedUserData | null } }>();

const instanceId = useId();
const searchInputId = `my-work-search-${instanceId}`;

function cloneGroups(
    source: App.Modules.Projects.Tasks.Data.MyWorkGroupData[],
): App.Modules.Projects.Tasks.Data.MyWorkGroupData[] {
    return source.map((group) => ({
        key: group.key,
        label: group.label,
        tasks_count: group.tasks_count,
        tasks: group.tasks.map((t) => ({ ...t })),
    }));
}

const localGroups = ref<App.Modules.Projects.Tasks.Data.MyWorkGroupData[]>(
    cloneGroups(props.groups),
);
const collapsedGroups = ref<Record<string, boolean>>({});
const searchQuery = ref<string>('');
const activeCategory = ref<MyWorkCategory>('all');
const refreshing = ref<boolean>(props.initialState === 'loading');
const completingTaskIds = ref<Record<string, boolean>>({});
const errorMessage = ref<string>(
    props.initialState === 'error'
        ? (props.initialError || 'Unable to load your assigned tasks.')
        : '',
);

const inspectedTask = ref<App.Modules.Projects.Tasks.Data.TaskData | null>(null);
const drawerOpen = ref<boolean>(false);

watch(
    () => props.groups,
    (next) => {
        localGroups.value = cloneGroups(next);
    },
    { deep: true },
);

const activeUser = computed(() => authStore.user ?? page.props?.auth?.user ?? null);

const effectiveCanUpdate = computed(() => {
    if (props.canUpdateTasks !== undefined) {
        return props.canUpdateTasks;
    }
    return activeUser.value
        ? activeUser.value.permissions.includes('tasks.update')
        : false;
});

const effectiveCanDelete = computed(() => {
    if (props.canDeleteTasks !== undefined) {
        return props.canDeleteTasks;
    }
    return activeUser.value
        ? activeUser.value.permissions.includes('tasks.delete')
        : false;
});

const allOpenTasks = computed(() => localGroups.value.flatMap((g) => g.tasks));

const categoryCounts = computed(() => ({
    all: allOpenTasks.value.length,
    urgent: allOpenTasks.value.filter(
        (t) => t.priority === 'high' || t.priority === 'urgent',
    ).length,
    project: allOpenTasks.value.filter((t) => t.project_id !== null).length,
    crm: allOpenTasks.value.filter((t) => t.project_id === null).length,
}));

function matchesCategory(
    task: App.Modules.Projects.Tasks.Data.TaskData,
    category: MyWorkCategory,
): boolean {
    if (category === 'all') {
        return true;
    }
    if (category === 'urgent') {
        return task.priority === 'high' || task.priority === 'urgent';
    }
    if (category === 'project') {
        return task.project_id !== null;
    }
    if (category === 'crm') {
        return task.project_id === null;
    }
    return true;
}

function matchesSearch(task: App.Modules.Projects.Tasks.Data.TaskData, query: string): boolean {
    const q = query.trim().toLowerCase();
    if (!q) {
        return true;
    }
    const hay = `${task.title} ${task.project_code ?? ''} ${task.project_name ?? ''} ${task.linkable_type ?? ''}`.toLowerCase();
    return hay.includes(q);
}

const filteredGroups = computed(() =>
    localGroups.value.map((group) => {
        const filtered = group.tasks.filter(
            (task) =>
                matchesCategory(task, activeCategory.value) &&
                matchesSearch(task, searchQuery.value),
        );
        return {
            ...group,
            tasks: filtered,
            tasks_count: filtered.length,
        };
    }),
);

const totalFilteredCount = computed(() =>
    filteredGroups.value.reduce((sum, group) => sum + group.tasks.length, 0),
);

function groupAccentClasses(key: string): string {
    switch (key) {
        case 'overdue':
            return 'text-status-danger bg-status-danger/10';
        case 'today':
            return 'text-status-warning bg-status-warning/10';
        case 'this_week':
            return 'text-accent-primary bg-accent-primary/10';
        default:
            return 'text-secondary bg-subtle';
    }
}

function toggleGroupCollapsed(key: string): void {
    collapsedGroups.value = {
        ...collapsedGroups.value,
        [key]: !collapsedGroups.value[key],
    };
}

function refreshMyWork(): void {
    refreshing.value = true;
    errorMessage.value = '';
    router.reload({
        only: ['groups', 'totalCount'],
        onError: () => {
            errorMessage.value = 'Unable to refresh your task list.';
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function removeTaskFromGroups(taskId: string): void {
    localGroups.value = localGroups.value.map((group) => {
        const remaining = group.tasks.filter((t) => t.id !== taskId);
        return {
            ...group,
            tasks: remaining,
            tasks_count: remaining.length,
        };
    });
}

function updateTaskInGroups(updated: App.Modules.Projects.Tasks.Data.TaskData): void {
    if (updated.status === 'done' || updated.status === 'cancelled') {
        removeTaskFromGroups(updated.id);
        return;
    }
    localGroups.value = localGroups.value.map((group) => ({
        ...group,
        tasks: group.tasks.map((t) => (t.id === updated.id ? updated : t)),
    }));
}

async function handleQuickComplete(task: App.Modules.Projects.Tasks.Data.TaskData): Promise<void> {
    if (!effectiveCanUpdate.value || completingTaskIds.value[task.id]) {
        return;
    }

    errorMessage.value = '';
    const snapshot = cloneGroups(localGroups.value);
    completingTaskIds.value = { ...completingTaskIds.value, [task.id]: true };

    // Optimistic removal from open My Work groups
    removeTaskFromGroups(task.id);

    try {
        await projectsApi.updateTask(task.id, {
            expected_version: task.version,
            status: 'done',
        });

        if (inspectedTask.value?.id === task.id) {
            drawerOpen.value = false;
            inspectedTask.value = null;
        }
    } catch (error) {
        // Rollback optimistic completion on 409 conflict or error
        localGroups.value = snapshot;
        if (error instanceof OptimisticLockConflictError) {
            errorMessage.value = `${error.message} Refresh to load latest task state.`;
        } else {
            errorMessage.value =
                error instanceof Error ? error.message : 'Unable to mark task complete.';
        }
    } finally {
        const next = { ...completingTaskIds.value };
        delete next[task.id];
        completingTaskIds.value = next;
    }
}

function openTask(task: App.Modules.Projects.Tasks.Data.TaskData): void {
    inspectedTask.value = task;
    drawerOpen.value = true;
}

function handleDrawerTaskUpdated(updated: App.Modules.Projects.Tasks.Data.TaskData): void {
    inspectedTask.value = updated;
    updateTaskInGroups(updated);
}

function handleDrawerTaskDeleted(deletedId: string): void {
    removeTaskFromGroups(deletedId);
    if (inspectedTask.value?.id === deletedId) {
        inspectedTask.value = null;
        drawerOpen.value = false;
    }
}
</script>

<template>
    <Head title="My Work" />

    <div class="space-y-4">
        <!-- Offline Banner -->
        <div
            v-if="realtimeStore.status === 'disconnected' && realtimeStore.lastError"
            role="status"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2 text-[12px] text-primary"
        >
            <div class="flex items-center gap-2">
                <WifiOff class="h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <span>Realtime connection offline. Use Refresh to sync assigned tasks.</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshMyWork">Refresh</Button>
        </div>

        <!-- Header & Filter Strip -->
        <header class="rounded-[8px] border border-default bg-surface px-4 py-3 space-y-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <CheckSquare class="h-4 w-4 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        My Work
                    </h2>
                    <Badge variant="cobalt" data-testid="my-work-total-count">
                        {{ allOpenTasks.length }} open
                    </Badge>
                </div>

                <Button
                    variant="secondary"
                    size="sm"
                    :loading="refreshing"
                    data-testid="my-work-refresh-btn"
                    @click="refreshMyWork"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Refresh</span>
                </Button>
            </div>

            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div class="relative w-full md:max-w-sm">
                    <label :for="searchInputId" class="sr-only">Filter assigned tasks</label>
                    <Search
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Input
                        :id="searchInputId"
                        v-model="searchQuery"
                        placeholder="Filter assigned tasks, project codes, or CRM links..."
                        class="pl-8"
                    />
                </div>

                <div class="flex items-center gap-1 overflow-x-auto pb-0.5" role="tablist" aria-label="Filter assigned task category">
                    <button
                        v-for="tab in ([
                            { key: 'all', label: 'All', count: categoryCounts.all },
                            { key: 'urgent', label: 'High & Urgent', count: categoryCounts.urgent },
                            { key: 'project', label: 'Project Tasks', count: categoryCounts.project },
                            { key: 'crm', label: 'CRM Follow-ups', count: categoryCounts.crm },
                        ] as const)"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeCategory === tab.key ? 'true' : 'false'"
                        :data-testid="`my-work-filter-${tab.key}`"
                        :class="[
                            'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-[6px] border px-2.5 text-[12px] font-medium transition-colors cursor-pointer',
                            activeCategory === tab.key
                                ? 'border-accent-primary/40 bg-accent-primary/10 text-accent-primary'
                                : 'border-default bg-subtle text-secondary hover:text-primary',
                        ]"
                        @click="activeCategory = tab.key"
                    >
                        <span>{{ tab.label }}</span>
                        <span class="font-mono text-[10px] tabular-nums">{{ tab.count }}</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Error / Conflict Banner -->
        <div
            v-if="errorMessage"
            role="alert"
            data-testid="my-work-error-banner"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2.5 text-[12px] text-status-danger"
        >
            <div class="flex items-center gap-2">
                <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ errorMessage }}</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshMyWork">Retry</Button>
        </div>

        <!-- Loading Skeleton -->
        <div
            v-if="refreshing && allOpenTasks.length === 0"
            data-testid="my-work-loading"
            aria-busy="true"
            class="divide-y divide-default rounded-[8px] border border-default bg-surface"
        >
            <div v-for="idx in 4" :key="idx" class="flex h-9 items-center gap-3 px-4">
                <div class="h-3.5 w-3.5 rounded bg-subtle" />
                <div class="h-3 w-56 rounded bg-subtle" />
                <div class="ml-auto h-3 w-20 rounded bg-subtle" />
            </div>
        </div>

        <!-- Empty State -->
        <div
            v-else-if="totalFilteredCount === 0"
            data-testid="my-work-empty"
            class="rounded-[8px] border border-default bg-surface px-4 py-10 text-center space-y-2"
        >
            <p class="text-[13px] font-medium text-primary">
                {{
                    allOpenTasks.length === 0
                        ? 'All caught up — no open tasks assigned to you'
                        : 'No assigned tasks match your current filters'
                }}
            </p>
            <p class="text-[12px] text-secondary">
                Assigned project deliverables and CRM follow-up tasks are grouped here by due window.
            </p>
            <Button
                v-if="searchQuery || activeCategory !== 'all'"
                variant="secondary"
                size="sm"
                @click="
                    searchQuery = '';
                    activeCategory = 'all';
                "
            >
                Clear Filters
            </Button>
        </div>

        <!-- Grouped Task Sections -->
        <div v-else class="space-y-3">
            <section
                v-for="group in filteredGroups"
                :key="group.key"
                :data-testid="`my-work-group-${group.key}`"
                class="overflow-hidden rounded-[8px] border border-default bg-surface"
            >
                <button
                    type="button"
                    :aria-expanded="!collapsedGroups[group.key] ? 'true' : 'false'"
                    :class="[
                        'flex h-8 w-full items-center justify-between px-3.5 text-left transition-colors cursor-pointer',
                        groupAccentClasses(group.key),
                    ]"
                    @click="toggleGroupCollapsed(group.key)"
                >
                    <span class="flex items-center gap-2 text-[12px] font-semibold">
                        <ChevronRight
                            v-if="collapsedGroups[group.key]"
                            class="h-3.5 w-3.5"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <ChevronDown
                            v-else
                            class="h-3.5 w-3.5"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <span>{{ group.label }}</span>
                    </span>
                    <span
                        :data-testid="`my-work-group-count-${group.key}`"
                        class="rounded-full bg-surface/80 px-2 py-0.5 font-mono text-[11px] font-medium tabular-nums text-primary"
                    >
                        {{ group.tasks_count }}
                    </span>
                </button>

                <div
                    v-if="!collapsedGroups[group.key] && group.tasks.length === 0"
                    class="border-t border-default px-4 py-3 text-[12px] text-muted"
                >
                    No tasks in {{ group.label }}.
                </div>

                <ul
                    v-else-if="!collapsedGroups[group.key]"
                    class="divide-y divide-default border-t border-default"
                >
                    <li
                        v-for="task in group.tasks"
                        :key="task.id"
                        :data-testid="`my-work-task-row-${task.id}`"
                        class="flex h-9 items-center justify-between gap-3 px-3.5 transition-colors duration-150 hover:bg-subtle/60"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-2.5">
                            <input
                                :id="`my-work-done-${task.id}`"
                                type="checkbox"
                                :checked="task.status === 'done'"
                                :disabled="!effectiveCanUpdate || Boolean(completingTaskIds[task.id])"
                                :aria-label="`Mark ${task.title} complete`"
                                :data-testid="`my-work-complete-${task.id}`"
                                class="h-3.5 w-3.5 shrink-0 rounded border-default accent-accent-primary cursor-pointer disabled:cursor-not-allowed disabled:opacity-45"
                                @change="handleQuickComplete(task)"
                            />

                            <button
                                type="button"
                                :data-testid="`my-work-open-${task.id}`"
                                class="min-w-0 truncate text-left text-[13px] font-medium text-primary hover:text-accent-primary cursor-pointer"
                                @click="openTask(task)"
                            >
                                {{ task.title }}
                            </button>

                            <Badge :variant="taskPriorityVariant(task.priority)" class="shrink-0">
                                {{ formatEnumLabel(task.priority) }}
                            </Badge>

                            <!-- Context Pill: Project Code OR CRM Entity -->
                            <Link
                                v-if="task.project_id && task.project_code"
                                :href="`/app/projects/${task.project_id}`"
                                :data-testid="`my-work-project-pill-${task.id}`"
                                class="hidden sm:inline-flex shrink-0 items-center gap-1 rounded-full border border-default bg-subtle px-2 py-0.5 font-mono text-[11px] font-medium text-primary hover:border-accent-primary/40 hover:text-accent-primary"
                            >
                                <FolderKanban class="h-3 w-3 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ task.project_code }}</span>
                            </Link>

                            <Link
                                v-else-if="linkableHref(task.linkable_type, task.linkable_id)"
                                :href="linkableHref(task.linkable_type, task.linkable_id)!"
                                :data-testid="`my-work-crm-pill-${task.id}`"
                                class="hidden sm:inline-flex shrink-0 items-center gap-1 rounded-full border border-default bg-subtle px-2 py-0.5 text-[11px] font-medium text-primary hover:border-accent-primary/40 hover:text-accent-primary"
                            >
                                <Link2 class="h-3 w-3 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ formatEnumLabel(task.linkable_type) }}</span>
                            </Link>

                            <span
                                v-else-if="task.has_masked_linkable"
                                :data-testid="`my-work-masked-pill-${task.id}`"
                                class="hidden sm:inline-flex shrink-0 items-center rounded-full border border-default bg-subtle px-2 py-0.5 text-[11px] text-muted"
                            >
                                Restricted CRM link
                            </span>
                        </div>

                        <div class="flex shrink-0 items-center gap-2.5 text-[11px] text-muted">
                            <span
                                v-if="task.subtasks_count > 0"
                                class="hidden md:inline-flex items-center gap-0.5 font-mono tabular-nums"
                            >
                                <ListChecks class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ task.subtasks_count }}</span>
                            </span>
                            <span
                                v-if="task.is_watching || task.watchers_count > 0"
                                class="hidden md:inline-flex items-center gap-0.5 font-mono tabular-nums"
                            >
                                <Eye class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ task.watchers_count }}</span>
                            </span>
                            <span
                                :class="[
                                    'font-mono tabular-nums whitespace-nowrap',
                                    group.key === 'overdue' ? 'font-semibold text-status-danger' : 'text-secondary',
                                ]"
                            >
                                {{ formatShortDate(task.due_at) }}
                            </span>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Task Detail Slide-Over Drawer -->
        <TaskDetailDrawer
            :open="drawerOpen"
            :task="inspectedTask"
            :priorities="props.priorities"
            :statuses="props.statuses"
            :can-update="effectiveCanUpdate"
            :can-delete="effectiveCanDelete"
            :can-create-subtask="false"
            @update:open="drawerOpen = $event"
            @task-updated="handleDrawerTaskUpdated"
            @task-deleted="handleDrawerTaskDeleted"
        />
    </div>
</template>
