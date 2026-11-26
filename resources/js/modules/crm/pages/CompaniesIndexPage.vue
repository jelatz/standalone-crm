<script setup lang="ts">
import { computed, h, ref, useId } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type SortingState } from '@tanstack/vue-table';
import { AlertCircle, Building2, Plus, RotateCcw, Search } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import { formatEnumLabel, formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';

type CompanyRow = App.Modules.Crm.Companies.Data.CompanyListItemData;

const props = defineProps<{
    companies: {
        data: CompanyRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Crm.Companies.Data.CompanyFilterData;
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    lifecycleStages: string[];
    can: {
        create: boolean;
    };
}>();

const instanceId = useId();
const searchInputId = `companies-search-${instanceId}`;
const stageSelectId = `companies-stage-${instanceId}`;
const ownerSelectId = `companies-owner-${instanceId}`;

const searchDraft = ref<string>(props.filters.search ?? '');
const stageDraft = ref<string>(props.filters.lifecycle_stage ?? '');
const ownerDraft = ref<string>(props.filters.owner_id ?? '');
const customFilterDraft = ref<Record<string, string>>({
    ...(Object.fromEntries(
        Object.entries(props.filters.custom_fields ?? {}).map(([k, v]) => [k, v == null ? '' : String(v)]),
    )),
});
const navigating = ref<boolean>(false);

const serverSortFields = new Set(['name', 'lifecycle_stage', 'annual_revenue_cents', 'updated_at', 'created_at']);

const tableSorting = computed<SortingState>(() => {
    if (!props.filters.sort_by || !serverSortFields.has(props.filters.sort_by)) {
        return [];
    }
    return [
        {
            id: props.filters.sort_by,
            desc: props.filters.sort_dir !== 'asc',
        },
    ];
});

function lifecycleVariant(stage: string): BadgeVariant {
    switch (stage) {
        case 'active_client':
            return 'success';
        case 'prospect':
            return 'cobalt';
        case 'partner':
            return 'warning';
        case 'churned':
            return 'danger';
        default:
            return 'neutral';
    }
}

function buildFilterParams(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    const cleanCustom: Record<string, string> = {};
    for (const [key, val] of Object.entries(customFilterDraft.value)) {
        if (val !== '' && val !== undefined && val !== null) {
            cleanCustom[key] = val;
        }
    }

    const base: Record<string, unknown> = {
        search: searchDraft.value.trim() || undefined,
        lifecycle_stage: stageDraft.value || undefined,
        owner_id: ownerDraft.value || undefined,
        custom_fields: Object.keys(cleanCustom).length > 0 ? cleanCustom : undefined,
        sort_by: props.filters.sort_by ?? 'updated_at',
        sort_dir: props.filters.sort_dir ?? 'desc',
        page: 1,
        ...overrides,
    };

    return Object.fromEntries(Object.entries(base).filter(([, v]) => v !== undefined));
}

function applyFilters(overrides: Record<string, unknown> = {}): void {
    navigating.value = true;
    router.get('/app/crm/companies', buildFilterParams(overrides) as Record<string, any>, {
        preserveState: true,
        replace: true,
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function resetFilters(): void {
    searchDraft.value = '';
    stageDraft.value = '';
    ownerDraft.value = '';
    customFilterDraft.value = {};
    applyFilters({
        search: undefined,
        lifecycle_stage: undefined,
        owner_id: undefined,
        custom_fields: undefined,
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
        Boolean(stageDraft.value) ||
        Boolean(ownerDraft.value) ||
        Object.values(customFilterDraft.value).some((v) => v !== ''),
);

const columnHelper = createDataTableColumnHelper<CompanyRow>();

const columns = [
    columnHelper.accessor('name', {
        id: 'name',
        header: 'Company',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0 max-w-[240px]' }, [
                h(
                    Link,
                    {
                        href: `/app/crm/companies/${row.id}`,
                        class: 'block truncate font-semibold text-primary hover:text-accent-primary',
                    },
                    () => row.name,
                ),
                row.domain
                    ? h('span', { class: 'block truncate font-mono text-[11px] text-muted' }, row.domain)
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('lifecycle_stage', {
        id: 'lifecycle_stage',
        header: 'Stage',
        cell: (info) =>
            h(
                Badge,
                { variant: lifecycleVariant(info.getValue()) },
                () => formatEnumLabel(info.getValue()),
            ),
    }),
    columnHelper.accessor('industry', {
        id: 'industry',
        header: 'Industry',
        enableSorting: false,
        cell: (info) =>
            h('span', { class: 'block max-w-[160px] truncate text-[12px] text-secondary' }, info.getValue() || '—'),
    }),
    columnHelper.accessor('annual_revenue_cents', {
        id: 'annual_revenue_cents',
        header: 'Annual Revenue',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatMoneyCents(info.getValue()),
    }),
    columnHelper.accessor('contacts_count', {
        id: 'contacts_count',
        header: 'Contacts',
        enableSorting: false,
        meta: { align: 'right', numeric: true },
        cell: (info) => String(info.getValue()),
    }),
    columnHelper.accessor('deals_count', {
        id: 'deals_count',
        header: 'Deals',
        enableSorting: false,
        meta: { align: 'right', numeric: true },
        cell: (info) => String(info.getValue()),
    }),
    columnHelper.accessor('owner_name', {
        id: 'owner_name',
        header: 'Owner',
        enableSorting: false,
        cell: (info) =>
            h('span', { class: 'block max-w-[140px] truncate text-[12px] text-secondary' }, info.getValue() || '—'),
    }),
    columnHelper.accessor('updated_at', {
        id: 'updated_at',
        header: 'Updated',
        meta: { align: 'right', numeric: true },
        cell: (info) => formatShortDate(info.getValue()),
    }),
];

// Create Company Modal
const createOpen = ref<boolean>(false);
const createSubmitting = ref<boolean>(false);
const createErrorBanner = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});

const createForm = ref({
    name: '',
    domain: '',
    lifecycle_stage: 'lead' as App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum,
    industry: '',
    employee_range: '',
    annual_revenue_dollars: '',
    account_manager_id: '',
    custom_fields: {} as Record<string, any>,
});

function openCreateModal(): void {
    createErrorBanner.value = '';
    createFieldErrors.value = {};
    createForm.value = {
        name: '',
        domain: '',
        lifecycle_stage: (props.lifecycleStages[0] as App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum) || 'lead',
        industry: '',
        employee_range: '',
        annual_revenue_dollars: '',
        account_manager_id: '',
        custom_fields: {},
    };
    createOpen.value = true;
}

async function handleCreateSubmit(): Promise<void> {
    if (createSubmitting.value) {
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

    const revenueDollars = createForm.value.annual_revenue_dollars.trim();
    const annualRevenueCents =
        revenueDollars !== '' && Number.isFinite(Number(revenueDollars))
            ? Math.round(Number(revenueDollars) * 100)
            : null;

    try {
        const created = await crmApi.createCompany({
            name: createForm.value.name.trim(),
            domain: createForm.value.domain.trim() || null,
            lifecycle_stage: createForm.value.lifecycle_stage,
            industry: createForm.value.industry.trim() || null,
            employee_range: createForm.value.employee_range.trim() || null,
            annual_revenue_cents: annualRevenueCents,
            billing_address: {},
            account_manager_id: createForm.value.account_manager_id || null,
            custom_fields: cleanCustom,
        });

        createOpen.value = false;
        router.visit(`/app/crm/companies/${created.id}`);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createErrorBanner.value = error.message;
        } else {
            createErrorBanner.value = error instanceof Error ? error.message : 'Unable to create company.';
        }
    } finally {
        createSubmitting.value = false;
    }
}

const paginationSummary = computed(() => {
    const { current_page, per_page, total } = props.companies;
    if (total === 0) {
        return '0 companies';
    }
    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);
    return `${start}–${end} of ${total}`;
});
</script>

<template>
    <Head title="Companies" />

    <div class="space-y-4">
        <!-- Header Bar -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <Building2 class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        Companies
                    </h2>
                    <Badge variant="neutral">{{ props.companies.total }}</Badge>
                </div>
                <p class="mt-0.5 text-[12px] text-secondary">
                    Silo-scoped enterprise accounts, revenue benchmarks, and filterable custom metadata.
                </p>
            </div>

            <Button
                v-if="props.can.create"
                variant="primary"
                size="md"
                data-testid="create-company-cta"
                @click="openCreateModal"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>New Company</span>
            </Button>
        </div>

        <!-- Companies DataTable with Filter Toolbar -->
        <DataTable
            :columns="columns"
            :data="props.companies.data"
            :loading="navigating"
            :manual-sorting="true"
            :sorting="tableSorting"
            :row-key="(row) => row.id"
            empty-title="No matching companies"
            empty-description="No visible companies match your current search or custom field filters."
            test-id="companies-data-table"
            @update:sorting="handleSortingChange"
        >
            <template #toolbar>
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="applyFilters({ page: 1 })"
                >
                    <div class="w-full sm:w-56">
                        <label :for="searchInputId" class="sr-only">Search companies</label>
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <Input
                                :id="searchInputId"
                                v-model="searchDraft"
                                placeholder="Search name or domain..."
                                class="pl-8"
                            />
                        </div>
                    </div>

                    <div class="w-[calc(50%-0.25rem)] sm:w-40">
                        <label :for="stageSelectId" class="sr-only">Lifecycle stage</label>
                        <select
                            :id="stageSelectId"
                            v-model="stageDraft"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            @change="applyFilters({ page: 1 })"
                        >
                            <option value="">All stages</option>
                            <option
                                v-for="stage in props.lifecycleStages"
                                :key="stage"
                                :value="stage"
                            >
                                {{ formatEnumLabel(stage) }}
                            </option>
                        </select>
                    </div>

                    <div class="w-[calc(50%-0.25rem)] sm:w-44">
                        <label :for="ownerSelectId" class="sr-only">Owner</label>
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

                    <div
                        v-for="filterDef in props.filterDefinitions"
                        :key="filterDef.id"
                        class="w-full sm:w-40"
                    >
                        <label :for="`cf-filter-${filterDef.key}`" class="sr-only">
                            {{ filterDef.label }}
                        </label>
                        <select
                            v-if="filterDef.field_type === 'select' || filterDef.field_type === 'multi_select'"
                            :id="`cf-filter-${filterDef.key}`"
                            v-model="customFilterDraft[filterDef.key]"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            @change="applyFilters({ page: 1 })"
                        >
                            <option value="">{{ filterDef.label }}: Any</option>
                            <option
                                v-for="opt in (filterDef.options ?? [])"
                                :key="typeof opt === 'string' ? opt : String(opt.value)"
                                :value="typeof opt === 'string' ? opt : String(opt.value)"
                            >
                                {{ typeof opt === 'string' ? opt : String(opt.label ?? opt.value) }}
                            </option>
                        </select>
                        <select
                            v-else-if="filterDef.field_type === 'boolean'"
                            :id="`cf-filter-${filterDef.key}`"
                            v-model="customFilterDraft[filterDef.key]"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            @change="applyFilters({ page: 1 })"
                        >
                            <option value="">{{ filterDef.label }}: Any</option>
                            <option value="true">{{ filterDef.label }}: Yes</option>
                            <option value="false">{{ filterDef.label }}: No</option>
                        </select>
                        <Input
                            v-else
                            :id="`cf-filter-${filterDef.key}`"
                            v-model="customFilterDraft[filterDef.key]"
                            :placeholder="filterDef.label"
                        />
                    </div>

                    <Button type="submit" variant="secondary" size="md">
                        Apply
                    </Button>

                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="md"
                        @click="resetFilters"
                    >
                        <RotateCcw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Reset</span>
                    </Button>
                </form>
            </template>

            <template #footer>
                <div class="flex items-center justify-between gap-2 text-[12px]">
                    <span class="font-mono tabular-nums text-secondary">
                        {{ paginationSummary }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.companies.current_page <= 1"
                            @click="applyFilters({ page: props.companies.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <span class="px-2 font-mono text-[11px] tabular-nums text-secondary">
                            {{ props.companies.current_page }} / {{ props.companies.last_page }}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.companies.current_page >= props.companies.last_page"
                            @click="applyFilters({ page: props.companies.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </template>
        </DataTable>

        <!-- Create Company Modal -->
        <Dialog
            :open="createOpen"
            title="New Company"
            description="Create a silo-scoped account record with lifecycle stage and custom metadata."
            max-width-class="max-w-lg"
            @update:open="createOpen = $event"
        >
            <form class="space-y-3.5" @submit.prevent="handleCreateSubmit">
                <div
                    v-if="createErrorBanner"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ createErrorBanner }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label :for="`create-company-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Company Name <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`create-company-name-${instanceId}`"
                            v-model="createForm.name"
                            placeholder="Northwind Logistics"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-company-domain-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Domain
                        </label>
                        <Input
                            :id="`create-company-domain-${instanceId}`"
                            v-model="createForm.domain"
                            placeholder="northwind.internal"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.domain ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-company-stage-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Lifecycle Stage
                        </label>
                        <select
                            :id="`create-company-stage-${instanceId}`"
                            v-model="createForm.lifecycle_stage"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option
                                v-for="stage in props.lifecycleStages"
                                :key="stage"
                                :value="stage"
                            >
                                {{ formatEnumLabel(stage) }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`create-company-industry-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Industry
                        </label>
                        <Input
                            :id="`create-company-industry-${instanceId}`"
                            v-model="createForm.industry"
                            placeholder="Freight & Cold Chain"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.industry ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-company-revenue-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Annual Revenue (USD)
                        </label>
                        <Input
                            :id="`create-company-revenue-${instanceId}`"
                            v-model="createForm.annual_revenue_dollars"
                            type="number"
                            placeholder="4500000"
                            class="font-mono tabular-nums"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.annual_revenue_cents ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-company-range-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Employee Range
                        </label>
                        <Input
                            :id="`create-company-range-${instanceId}`"
                            v-model="createForm.employee_range"
                            placeholder="250-500"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.employee_range ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-company-am-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Account Manager
                        </label>
                        <select
                            :id="`create-company-am-${instanceId}`"
                            v-model="createForm.account_manager_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
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
                </div>

                <CustomFieldsForm
                    v-if="props.customFieldDefinitions.length > 0"
                    v-model="createForm.custom_fields"
                    :definitions="props.customFieldDefinitions"
                    :assignable-users="props.assignableUsers"
                    :errors="createFieldErrors"
                    :disabled="createSubmitting"
                    id-prefix="create-company-cf"
                />

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="createSubmitting" @click="createOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="createSubmitting">
                        Create Company
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
