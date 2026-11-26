<script setup lang="ts">
import { computed, h, ref, useId, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type SortingState } from '@tanstack/vue-table';
import {
    AlertCircle,
    FolderKanban,
    Plus,
    RotateCcw,
    Search,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import {
    extractFirstFieldErrors,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import {
    projectHealthVariant,
    projectStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatEnumLabel, formatShortDate } from '@/modules/crm/utils/formatters';

type ProjectRow = App.Modules.Projects.Projects.Data.ProjectSummaryData;

const props = defineProps<{
    projects: {
        data: ProjectRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Projects.Projects.Data.ProjectFilterData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{
        id: string;
        name: string;
        domain: string | null;
    }>;
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    statuses: string[];
    healthOptions: string[];
    can: {
        create: boolean;
    };
}>();

const instanceId = useId();
const searchInputId = `projects-search-${instanceId}`;
const statusSelectId = `projects-status-${instanceId}`;
const healthSelectId = `projects-health-${instanceId}`;
const ownerSelectId = `projects-owner-${instanceId}`;

const searchDraft = ref<string>(props.filters.search ?? '');
const statusDraft = ref<string>(props.filters.status ?? '');
const healthDraft = ref<string>(props.filters.health ?? '');
const ownerDraft = ref<string>(props.filters.owner_id ?? '');
const navigating = ref<boolean>(false);
const loadError = ref<string | null>(null);

watch(
    () => props.filters,
    (next) => {
        searchDraft.value = next.search ?? '';
        statusDraft.value = next.status ?? '';
        healthDraft.value = next.health ?? '';
        ownerDraft.value = next.owner_id ?? '';
    },
);

const serverSortFields = new Set([
    'code',
    'name',
    'status',
    'health',
    'start_date',
    'target_end_date',
    'updated_at',
    'created_at',
]);

const tableSorting = computed<SortingState>(() => {
    if (!props.filters.sort_by || !serverSortFields.has(props.filters.sort_by)) {
        return [];
    }
    return [{ id: props.filters.sort_by, desc: props.filters.sort_dir !== 'asc' }];
});

function buildFilterParams(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    const base: Record<string, unknown> = {
        search: searchDraft.value.trim() || undefined,
        status: statusDraft.value || undefined,
        health: healthDraft.value || undefined,
        owner_id: ownerDraft.value || undefined,
        sort_by: props.filters.sort_by ?? 'updated_at',
        sort_dir: props.filters.sort_dir ?? 'desc',
        page: 1,
        ...overrides,
    };
    return Object.fromEntries(Object.entries(base).filter(([, v]) => v !== undefined));
}

function applyFilters(overrides: Record<string, unknown> = {}): void {
    navigating.value = true;
    loadError.value = null;
    router.get('/app/projects', buildFilterParams(overrides) as Record<string, any>, {
        preserveState: true,
        replace: true,
        onError: () => {
            loadError.value = 'Unable to load projects list.';
        },
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function resetFilters(): void {
    searchDraft.value = '';
    statusDraft.value = '';
    healthDraft.value = '';
    ownerDraft.value = '';
    applyFilters({
        search: undefined,
        status: undefined,
        health: undefined,
        owner_id: undefined,
        page: 1,
    });
}

function handleSortingChange(next: SortingState): void {
    const first = next[0];
    if (!first || !serverSortFields.has(first.id)) {
        applyFilters({ sort_by: 'updated_at', sort_dir: 'desc', page: 1 });
        return;
    }
    applyFilters({
        sort_by: first.id,
        sort_dir: first.desc ? 'desc' : 'asc',
        page: 1,
    });
}

const hasActiveFilters = computed(
    () =>
        Boolean(searchDraft.value.trim()) ||
        Boolean(statusDraft.value) ||
        Boolean(healthDraft.value) ||
        Boolean(ownerDraft.value),
);

const paginationSummary = computed(() => {
    const { current_page, per_page, total } = props.projects;
    if (total === 0) {
        return '0 projects';
    }
    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);
    return `${start}–${end} of ${total}`;
});

const columnHelper = createDataTableColumnHelper<ProjectRow>();

const columns = [
    columnHelper.accessor('code', {
        id: 'code',
        header: 'Code',
        cell: (info) =>
            h(
                Link,
                {
                    href: `/app/projects/${info.row.original.id}`,
                    class: 'font-mono text-[12px] font-semibold text-accent-primary hover:underline',
                },
                () => info.getValue(),
            ),
    }),
    columnHelper.accessor('name', {
        id: 'name',
        header: 'Project',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[260px]' }, [
                h(
                    Link,
                    {
                        href: `/app/projects/${row.id}`,
                        class: 'block truncate font-semibold text-primary hover:text-accent-primary',
                    },
                    () => row.name,
                ),
                h(
                    'span',
                    { class: 'block truncate text-[11px] text-secondary' },
                    row.has_masked_company
                        ? 'Restricted account'
                        : (row.company_name ?? 'Internal project'),
                ),
            ]);
        },
    }),
    columnHelper.accessor('status', {
        id: 'status',
        header: 'Status',
        cell: (info) =>
            h(
                Badge,
                { variant: projectStatusVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('health', {
        id: 'health',
        header: 'Health',
        cell: (info) =>
            h(
                Badge,
                { variant: projectHealthVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('owner_name', {
        id: 'owner_name',
        header: 'PM / Owner',
        enableSorting: false,
        cell: (info) =>
            h('span', { class: 'block max-w-[140px] truncate text-[12px] text-secondary' }, info.getValue() ?? '—'),
    }),
    columnHelper.accessor('tasks_count', {
        id: 'tasks_count',
        header: 'Tasks',
        enableSorting: false,
        meta: { align: 'right', numeric: true },
        cell: (info) =>
            `${info.row.original.completed_tasks_count} / ${info.getValue()}`,
    }),
    columnHelper.accessor('target_end_date', {
        id: 'target_end_date',
        header: 'Target End',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
];

// Create Project Modal
const createOpen = ref<boolean>(false);
const createSubmitting = ref<boolean>(false);
const createErrorBanner = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});

const createForm = ref({
    name: '',
    company_id: '',
    owner_id: '',
    account_manager_id: '',
    status: 'planning' as App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
    health: 'on_track' as App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
    start_date: '',
    target_end_date: '',
    custom_fields: {} as Record<string, any>,
});

function openCreateModal(): void {
    createErrorBanner.value = '';
    createFieldErrors.value = {};
    createForm.value = {
        name: '',
        company_id: '',
        owner_id: '',
        account_manager_id: '',
        status: 'planning',
        health: 'on_track',
        start_date: '',
        target_end_date: '',
        custom_fields: {},
    };
    createOpen.value = true;
}

async function handleCreateSubmit(): Promise<void> {
    if (createSubmitting.value || !props.can.create) {
        return;
    }

    if (!createForm.value.name.trim()) {
        createFieldErrors.value = { name: 'Project name is required.' };
        createErrorBanner.value = 'Enter a project name before creating.';
        return;
    }

    createSubmitting.value = true;
    createErrorBanner.value = '';
    createFieldErrors.value = {};

    const cleanCustom: Record<string, any> = {};
    for (const [key, val] of Object.entries(createForm.value.custom_fields)) {
        if (val !== null && val !== undefined && val !== '') {
            cleanCustom[key] = val;
        }
    }

    try {
        const created = await projectsApi.createProject({
            name: createForm.value.name.trim(),
            company_id: createForm.value.company_id || null,
            primary_deal_id: null,
            owner_id: createForm.value.owner_id || null,
            account_manager_id: createForm.value.account_manager_id || null,
            department_id: null,
            description_json: null,
            status: createForm.value.status,
            health: createForm.value.health,
            start_date: createForm.value.start_date || null,
            target_end_date: createForm.value.target_end_date || null,
            custom_fields: cleanCustom,
            stages: null,
        });

        createOpen.value = false;
        router.visit(`/app/projects/${created.id}`);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createErrorBanner.value = error.message;
        } else {
            createErrorBanner.value =
                error instanceof Error ? error.message : 'Unable to create project.';
        }
    } finally {
        createSubmitting.value = false;
    }
}
</script>

<template>
    <Head title="Projects" />

    <div class="space-y-4">
        <!-- Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 min-w-0">
                <FolderKanban class="h-4 w-4 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Projects Directory
                </h2>
                <Badge variant="neutral">{{ props.projects.total }}</Badge>
            </div>

            <Button
                v-if="props.can.create"
                variant="primary"
                size="md"
                data-testid="create-project-cta"
                @click="openCreateModal"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>New Project</span>
            </Button>
        </div>

        <!-- Filters -->
        <form
            class="flex flex-wrap items-end gap-2 rounded-[8px] border border-default bg-surface p-3"
            @submit.prevent="applyFilters({ page: 1 })"
        >
            <div class="w-full sm:w-56">
                <label :for="searchInputId" class="sr-only">Search projects</label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Input
                        :id="searchInputId"
                        v-model="searchDraft"
                        placeholder="Search code or project name..."
                        class="pl-8"
                    />
                </div>
            </div>

            <div class="w-[calc(50%-0.25rem)] sm:w-36">
                <label :for="statusSelectId" class="sr-only">Project status</label>
                <select
                    :id="statusSelectId"
                    v-model="statusDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All statuses</option>
                    <option v-for="s in props.statuses" :key="s" :value="s">
                        {{ formatEnumLabel(s) }}
                    </option>
                </select>
            </div>

            <div class="w-[calc(50%-0.25rem)] sm:w-36">
                <label :for="healthSelectId" class="sr-only">Project health</label>
                <select
                    :id="healthSelectId"
                    v-model="healthDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All health</option>
                    <option v-for="hOpt in props.healthOptions" :key="hOpt" :value="hOpt">
                        {{ formatEnumLabel(hOpt) }}
                    </option>
                </select>
            </div>

            <div class="w-full sm:w-44">
                <label :for="ownerSelectId" class="sr-only">Project owner</label>
                <select
                    :id="ownerSelectId"
                    v-model="ownerDraft"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    @change="applyFilters({ page: 1 })"
                >
                    <option value="">All owners</option>
                    <option
                        v-for="user in props.assignableUsers"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }}
                    </option>
                </select>
            </div>

            <Button type="submit" variant="secondary" size="md">Apply</Button>

            <Button v-if="hasActiveFilters" variant="ghost" size="md" @click="resetFilters">
                <RotateCcw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Reset</span>
            </Button>
        </form>

        <!-- Data Table -->
        <DataTable
            :columns="columns"
            :data="props.projects.data"
            :loading="navigating"
            :error="loadError"
            :manual-sorting="true"
            :sorting="tableSorting"
            :row-key="(row) => row.id"
            empty-title="No visible projects"
            empty-description="Approved deal handoffs provision projects automatically, or create a manual project."
            test-id="projects-data-table"
            @update:sorting="handleSortingChange"
            @retry="applyFilters({ page: props.projects.current_page })"
        >
            <template #footer>
                <div class="flex items-center justify-between gap-2 text-[12px]">
                    <span class="font-mono tabular-nums text-secondary">
                        {{ paginationSummary }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.projects.current_page <= 1"
                            @click="applyFilters({ page: props.projects.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <span class="px-2 font-mono text-[11px] tabular-nums text-secondary">
                            {{ props.projects.current_page }} / {{ props.projects.last_page }}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.projects.current_page >= props.projects.last_page"
                            @click="applyFilters({ page: props.projects.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </template>
        </DataTable>

        <!-- New Manual Project Dialog -->
        <Dialog
            :open="createOpen"
            title="New Manual Project"
            description="Create a delivery workspace with default Kanban stages."
            max-width-class="max-w-lg"
            @update:open="createOpen = $event"
        >
            <form class="max-h-[75vh] overflow-y-auto space-y-3.5 pr-1" @submit.prevent="handleCreateSubmit">
                <div
                    v-if="createErrorBanner"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ createErrorBanner }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label :for="`create-prj-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Project Name <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`create-prj-name-${instanceId}`"
                            v-model="createForm.name"
                            placeholder="Northwind Logistics — WMS Deployment"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-prj-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Client Company
                        </label>
                        <select
                            :id="`create-prj-company-${instanceId}`"
                            v-model="createForm.company_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Internal / Unlinked</option>
                            <option
                                v-for="company in props.companies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-prj-owner-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Project Owner
                        </label>
                        <select
                            :id="`create-prj-owner-${instanceId}`"
                            v-model="createForm.owner_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Assign to me</option>
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
                        <label :for="`create-prj-status-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Status
                        </label>
                        <select
                            :id="`create-prj-status-${instanceId}`"
                            v-model="createForm.status"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option v-for="s in props.statuses" :key="s" :value="s">
                                {{ formatEnumLabel(s) }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-prj-health-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Health
                        </label>
                        <select
                            :id="`create-prj-health-${instanceId}`"
                            v-model="createForm.health"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option v-for="hOpt in props.healthOptions" :key="hOpt" :value="hOpt">
                                {{ formatEnumLabel(hOpt) }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-prj-start-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Start Date
                        </label>
                        <Input
                            :id="`create-prj-start-${instanceId}`"
                            v-model="createForm.start_date"
                            type="date"
                            class="font-mono tabular-nums"
                            :disabled="createSubmitting"
                        />
                    </div>

                    <div>
                        <label :for="`create-prj-end-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Target End Date
                        </label>
                        <Input
                            :id="`create-prj-end-${instanceId}`"
                            v-model="createForm.target_end_date"
                            type="date"
                            class="font-mono tabular-nums"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.target_end_date ?? ''"
                        />
                    </div>
                </div>

                <CustomFieldsForm
                    v-if="props.customFieldDefinitions.length > 0"
                    v-model="createForm.custom_fields"
                    :definitions="props.customFieldDefinitions"
                    :assignable-users="props.assignableUsers"
                    :errors="createFieldErrors"
                    :disabled="createSubmitting"
                    id-prefix="create-prj-cf"
                />

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        size="md"
                        :disabled="createSubmitting"
                        @click="createOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        :loading="createSubmitting"
                        data-testid="create-project-submit"
                    >
                        Create Project
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
