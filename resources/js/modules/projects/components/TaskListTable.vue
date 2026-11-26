<script setup lang="ts">
import { computed, h, ref, useId } from 'vue';
import { Eye, ListChecks, Search } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import Input from '@/components/ui/Input.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import {
    taskPriorityVariant,
    taskStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatEnumLabel, formatShortDate } from '@/modules/crm/utils/formatters';

type TaskRow = App.Modules.Projects.Tasks.Data.TaskData;

const props = withDefaults(
    defineProps<{
        tasks: TaskRow[];
        loading?: boolean;
        error?: string | null;
    }>(),
    {
        loading: false,
        error: null,
    },
);

const emit = defineEmits<{
    openTask: [task: TaskRow];
    retry: [];
}>();

const instanceId = useId();
const searchInputId = `task-list-search-${instanceId}`;
const statusFilterId = `task-list-status-${instanceId}`;

const searchDraft = ref<string>('');
const statusDraft = ref<string>('');

const filteredTasks = computed(() => {
    const q = searchDraft.value.trim().toLowerCase();
    return props.tasks.filter((task) => {
        if (statusDraft.value && task.status !== statusDraft.value) {
            return false;
        }
        if (q !== '') {
            const hay = `${task.title} ${task.assignee_name ?? ''} ${task.project_stage_name ?? ''}`.toLowerCase();
            if (!hay.includes(q)) {
                return false;
            }
        }
        return true;
    });
});

const columnHelper = createDataTableColumnHelper<TaskRow>();

const columns = [
    columnHelper.accessor('title', {
        id: 'title',
        header: 'Task',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'flex min-w-0 max-w-[320px] items-center gap-2' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'truncate text-left font-medium text-primary hover:text-accent-primary cursor-pointer',
                        onClick: (event: Event) => {
                            event.stopPropagation();
                            emit('openTask', row);
                        },
                    },
                    row.title,
                ),
                row.parent_task_id
                    ? h(
                          'span',
                          {
                              class: 'shrink-0 rounded border border-default bg-subtle px-1 py-0.5 font-mono text-[10px] text-secondary',
                          },
                          'Subtask',
                      )
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('project_stage_name', {
        id: 'project_stage_name',
        header: 'Stage',
        cell: (info) =>
            h('span', { class: 'text-[12px] text-secondary' }, info.getValue() ?? 'Unstaged'),
    }),
    columnHelper.accessor('status', {
        id: 'status',
        header: 'Status',
        cell: (info) =>
            h(
                Badge,
                { variant: taskStatusVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('priority', {
        id: 'priority',
        header: 'Priority',
        cell: (info) =>
            h(
                Badge,
                { variant: taskPriorityVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('assignee_name', {
        id: 'assignee_name',
        header: 'Assignee',
        cell: (info) =>
            h('span', { class: 'block max-w-[140px] truncate text-[12px] text-secondary' }, info.getValue() ?? 'Unassigned'),
    }),
    columnHelper.accessor('subtasks_count', {
        id: 'subtasks_count',
        header: 'Meta',
        meta: { align: 'right', numeric: true },
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'inline-flex items-center justify-end gap-2 text-muted' }, [
                row.subtasks_count > 0
                    ? h('span', { class: 'inline-flex items-center gap-0.5' }, [
                          h(ListChecks, { class: 'h-3 w-3', strokeWidth: 1.75 }),
                          String(row.subtasks_count),
                      ])
                    : null,
                row.watchers_count > 0
                    ? h('span', { class: 'inline-flex items-center gap-0.5' }, [
                          h(Eye, { class: 'h-3 w-3', strokeWidth: 1.75 }),
                          String(row.watchers_count),
                      ])
                    : null,
                row.subtasks_count === 0 && row.watchers_count === 0 ? '—' : null,
            ]);
        },
    }),
    columnHelper.accessor('due_at', {
        id: 'due_at',
        header: 'Due Date',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
];
</script>

<template>
    <DataTable
        :columns="columns"
        :data="filteredTasks"
        :loading="props.loading"
        :error="props.error"
        :clickable-rows="true"
        :row-key="(row) => row.id"
        empty-title="No project tasks match"
        empty-description="Add a task on the Board tab or clear your active list filter."
        test-id="project-task-list-table"
        @row-click="emit('openTask', $event)"
        @retry="emit('retry')"
    >
        <template #toolbar>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-56">
                    <label :for="searchInputId" class="sr-only">Search project tasks</label>
                    <Search
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Input
                        :id="searchInputId"
                        v-model="searchDraft"
                        placeholder="Filter task list..."
                        class="pl-8"
                    />
                </div>

                <div class="w-36">
                    <label :for="statusFilterId" class="sr-only">Filter by status</label>
                    <select
                        :id="statusFilterId"
                        v-model="statusDraft"
                        class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <option value="">All statuses</option>
                        <option value="todo">To Do</option>
                        <option value="in_progress">In Progress</option>
                        <option value="blocked">Blocked</option>
                        <option value="review">Review</option>
                        <option value="done">Done</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
        </template>
    </DataTable>
</template>
