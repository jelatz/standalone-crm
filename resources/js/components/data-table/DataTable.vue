<script setup lang="ts" generic="TData extends RowData">
import { computed, ref, shallowRef, watch } from 'vue';
import {
    FlexRender,
    useTable,
    type RowData,
    type SortingState,
    type Updater,
} from '@tanstack/vue-table';
import { AlertCircle, ArrowDown, ArrowUp, ArrowUpDown, RefreshCw } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import {
    dataTableFeatures,
    type DataTableColumnDef,
    type DataTableColumnMeta,
    type DataTableFeatures,
} from './tableFeatures';

const props = withDefaults(
    defineProps<{
        columns: ReadonlyArray<DataTableColumnDef<TData, any>>;
        data: ReadonlyArray<TData>;
        loading?: boolean;
        error?: string | null;
        emptyTitle?: string;
        emptyDescription?: string;
        manualSorting?: boolean;
        sorting?: SortingState;
        rowKey?: (row: TData, index: number) => string;
        clickableRows?: boolean;
        testId?: string;
    }>(),
    {
        loading: false,
        error: null,
        emptyTitle: 'No records found',
        emptyDescription: 'Adjust your filters or create a new record to populate this view.',
        manualSorting: false,
        sorting: undefined,
        rowKey: undefined,
        clickableRows: false,
        testId: 'crm-data-table',
    },
);

const emit = defineEmits<{
    retry: [];
    'update:sorting': [sorting: SortingState];
    rowClick: [row: TData];
}>();

const internalSorting = ref<SortingState>([]);

const activeSorting = computed<SortingState>(() =>
    props.sorting !== undefined ? props.sorting : internalSorting.value,
);

const tableData = shallowRef<ReadonlyArray<TData>>(props.data);
const tableColumns = shallowRef<ReadonlyArray<DataTableColumnDef<TData, any>>>(props.columns);
const tableState = shallowRef<{ sorting: SortingState }>({ sorting: activeSorting.value });
const isManualSorting = shallowRef<boolean>(props.manualSorting);

watch(
    () => props.data,
    (next) => {
        tableData.value = next;
    },
);

watch(
    () => props.columns,
    (next) => {
        tableColumns.value = next;
    },
);

watch(
    activeSorting,
    (next) => {
        tableState.value = { sorting: next };
    },
);

watch(
    () => props.manualSorting,
    (next) => {
        isManualSorting.value = next;
    },
);

const table = useTable<DataTableFeatures, TData>({
    features: dataTableFeatures,
    columns: tableColumns,
    data: tableData,
    state: tableState,
    manualSorting: isManualSorting,
    getRowId: props.rowKey
        ? (originalRow, index) => props.rowKey!(originalRow, index)
        : undefined,
    onSortingChange: (updater: Updater<SortingState>) => {
        const current = activeSorting.value;
        const next = typeof updater === 'function' ? updater(current) : updater;
        if (props.sorting === undefined) {
            internalSorting.value = next;
        }
        emit('update:sorting', next);
    },
});

const rows = computed(() => table.getRowModel().rows);

function getColumnMeta(meta: unknown): DataTableColumnMeta {
    if (typeof meta === 'object' && meta !== null) {
        return meta as DataTableColumnMeta;
    }
    return {};
}

function isRightAligned(meta: unknown): boolean {
    const parsed = getColumnMeta(meta);
    return parsed.align === 'right' || parsed.numeric === true;
}

function getAriaSort(canSort: boolean, sorted: false | 'asc' | 'desc'): 'ascending' | 'descending' | 'none' | undefined {
    if (!canSort) {
        return undefined;
    }
    if (sorted === 'asc') {
        return 'ascending';
    }
    if (sorted === 'desc') {
        return 'descending';
    }
    return 'none';
}
</script>

<template>
    <div
        :data-testid="props.testId"
        class="overflow-hidden rounded-[8px] border border-default bg-surface"
    >
        <!-- Optional Toolbar / Actions Header -->
        <div
            v-if="$slots.toolbar || $slots.actions"
            class="flex flex-col gap-2 border-b border-default bg-surface px-3.5 py-2.5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0 flex-1">
                <slot name="toolbar" />
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <!-- Loading Skeleton -->
        <div
            v-if="props.loading"
            data-testid="data-table-loading"
            class="divide-y divide-default"
            aria-busy="true"
            aria-label="Loading table rows"
        >
            <div class="flex h-8 items-center gap-4 bg-subtle px-3.5">
                <div class="h-2.5 w-24 rounded bg-default/70" />
                <div class="h-2.5 w-32 rounded bg-default/70" />
                <div class="h-2.5 w-20 rounded bg-default/70 ml-auto" />
            </div>
            <div
                v-for="rowIndex in 5"
                :key="rowIndex"
                class="flex h-8 items-center gap-4 px-3.5"
            >
                <div class="h-3 w-40 rounded bg-subtle" />
                <div class="h-3 flex-1 rounded bg-subtle" />
                <div class="h-3 w-20 rounded bg-subtle" />
            </div>
        </div>

        <!-- Error State -->
        <div
            v-else-if="props.error"
            role="alert"
            data-testid="data-table-error"
            class="m-3.5 flex flex-col gap-2.5 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3.5 py-3 text-[13px] text-status-danger sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2 min-w-0">
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="font-medium">Unable to load records</p>
                    <p class="text-[12px] opacity-90 break-words">{{ props.error }}</p>
                </div>
            </div>
            <Button
                variant="secondary"
                size="sm"
                data-testid="data-table-retry"
                @click="emit('retry')"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Retry</span>
            </Button>
        </div>

        <!-- Empty State -->
        <div
            v-else-if="rows.length === 0"
            data-testid="data-table-empty"
            class="px-4 py-8 text-center"
        >
            <slot name="empty">
                <p class="text-[13px] font-medium text-primary">
                    {{ props.emptyTitle }}
                </p>
                <p class="mt-1 text-[12px] text-secondary">
                    {{ props.emptyDescription }}
                </p>
            </slot>
        </div>

        <!-- Compact 32px Data Grid -->
        <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                        class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary"
                    >
                        <th
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            scope="col"
                            :aria-sort="getAriaSort(header.column.getCanSort(), header.column.getIsSorted())"
                            :class="[
                                'px-3 py-1.5 font-medium whitespace-nowrap select-none',
                                isRightAligned(header.column.columnDef.meta) ? 'text-right' : 'text-left',
                                getColumnMeta(header.column.columnDef.meta).headerClass,
                            ]"
                        >
                            <template v-if="!header.isPlaceholder">
                                <button
                                    v-if="header.column.getCanSort()"
                                    type="button"
                                    :data-testid="`sort-header-${header.column.id}`"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-[4px] py-0.5 text-[11px] font-medium text-secondary transition-colors duration-150 hover:text-primary cursor-pointer',
                                        isRightAligned(header.column.columnDef.meta) ? 'flex-row-reverse' : '',
                                    ]"
                                    @click="header.column.getToggleSortingHandler()?.($event)"
                                >
                                    <FlexRender :header="header" />
                                    <ArrowUp
                                        v-if="header.column.getIsSorted() === 'asc'"
                                        class="h-3 w-3 text-accent-primary"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <ArrowDown
                                        v-else-if="header.column.getIsSorted() === 'desc'"
                                        class="h-3 w-3 text-accent-primary"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="h-3 w-3 opacity-55"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                </button>
                                <span v-else>
                                    <FlexRender :header="header" />
                                </span>
                            </template>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-default text-[13px]">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        :class="[
                            'h-8 transition-colors duration-150 hover:bg-subtle/60',
                            props.clickableRows ? 'cursor-pointer' : '',
                        ]"
                        @click="props.clickableRows ? emit('rowClick', row.original) : undefined"
                    >
                        <td
                            v-for="cell in row.getAllCells()"
                            :key="cell.id"
                            :class="[
                                'px-3 py-1.5 align-middle',
                                isRightAligned(cell.column.columnDef.meta)
                                    ? 'text-right font-mono text-[12px] tabular-nums whitespace-nowrap'
                                    : '',
                                getColumnMeta(cell.column.columnDef.meta).cellClass,
                            ]"
                        >
                            <FlexRender :cell="cell" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Optional Footer / Pagination -->
        <div
            v-if="$slots.footer"
            class="border-t border-default bg-surface px-3.5 py-2"
        >
            <slot name="footer" />
        </div>
    </div>
</template>
