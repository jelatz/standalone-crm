<script setup lang="ts">
import { computed, h, ref, useId } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type SortingState } from '@tanstack/vue-table';
import { AlertCircle, Plus, RotateCcw, Search, Trash2, Users } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/data-table/DataTable.vue';
import { createDataTableColumnHelper } from '@/components/data-table/tableFeatures';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import { formatShortDate } from '@/modules/crm/utils/formatters';

type ContactRow = App.Modules.Crm.Contacts.Data.ContactListItemData;

const props = defineProps<{
    contacts: {
        data: ContactRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Crm.Contacts.Data.ContactFilterData;
    filterDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{
        id: string;
        name: string;
        domain: string | null;
    }>;
    can: {
        create: boolean;
    };
}>();

const instanceId = useId();
const searchInputId = `contacts-search-${instanceId}`;
const companySelectId = `contacts-company-${instanceId}`;
const ownerSelectId = `contacts-owner-${instanceId}`;

const searchDraft = ref<string>(props.filters.search ?? '');
const companyDraft = ref<string>(props.filters.company_id ?? '');
const ownerDraft = ref<string>(props.filters.owner_id ?? '');
const customFilterDraft = ref<Record<string, string>>({
    ...(Object.fromEntries(
        Object.entries(props.filters.custom_fields ?? {}).map(([k, v]) => [k, v == null ? '' : String(v)]),
    )),
});
const navigating = ref<boolean>(false);

const serverSortFields = new Set(['full_name', 'first_name', 'last_name', 'primary_email', 'updated_at', 'created_at']);

const tableSorting = computed<SortingState>(() => {
    if (!props.filters.sort_by || !serverSortFields.has(props.filters.sort_by)) {
        return [];
    }
    return [{ id: props.filters.sort_by, desc: props.filters.sort_dir !== 'asc' }];
});

function buildFilterParams(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    const cleanCustom: Record<string, string> = {};
    for (const [key, val] of Object.entries(customFilterDraft.value)) {
        if (val !== '' && val !== undefined && val !== null) {
            cleanCustom[key] = val;
        }
    }

    const base: Record<string, unknown> = {
        search: searchDraft.value.trim() || undefined,
        company_id: companyDraft.value || undefined,
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
    router.get('/app/crm/contacts', buildFilterParams(overrides) as Record<string, any>, {
        preserveState: true,
        replace: true,
        onFinish: () => {
            navigating.value = false;
        },
    });
}

function resetFilters(): void {
    searchDraft.value = '';
    companyDraft.value = '';
    ownerDraft.value = '';
    customFilterDraft.value = {};
    applyFilters({
        search: undefined,
        company_id: undefined,
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
        Boolean(companyDraft.value) ||
        Boolean(ownerDraft.value) ||
        Object.values(customFilterDraft.value).some((v) => v !== ''),
);

const columnHelper = createDataTableColumnHelper<ContactRow>();

const columns = [
    columnHelper.accessor('full_name', {
        id: 'full_name',
        header: 'Contact',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'flex min-w-0 max-w-[240px] items-center gap-2' }, [
                h(
                    Link,
                    {
                        href: `/app/crm/contacts/${row.id}`,
                        class: 'truncate font-semibold text-primary hover:text-accent-primary',
                    },
                    () => row.full_name,
                ),
                row.is_primary_contact ? h(Badge, { variant: 'cobalt' }, () => 'Primary') : null,
            ]);
        },
    }),
    columnHelper.accessor('primary_email', {
        id: 'primary_email',
        header: 'Primary Email',
        cell: (info) => {
            const row = info.row.original;
            return h('div', { class: 'min-w-0' }, [
                h('span', { class: 'font-mono text-[12px] text-primary' }, info.getValue() || '—'),
                row.secondary_emails.length > 0
                    ? h('span', { class: 'ml-1.5 font-mono text-[11px] text-muted' }, `+${row.secondary_emails.length}`)
                    : null,
            ]);
        },
    }),
    columnHelper.accessor('company_name', {
        id: 'company_name',
        header: 'Company',
        enableSorting: false,
        cell: (info) => {
            const row = info.row.original;
            if (!row.company_id || !row.company_name) {
                return h('span', { class: 'text-[12px] text-muted' }, '—');
            }
            return h(
                Link,
                {
                    href: `/app/crm/companies/${row.company_id}`,
                    class: 'block max-w-[180px] truncate text-[12px] text-secondary hover:text-primary',
                },
                () => row.company_name,
            );
        },
    }),
    columnHelper.accessor('job_title', {
        id: 'job_title',
        header: 'Role / Title',
        enableSorting: false,
        cell: (info) =>
            h('span', { class: 'block max-w-[160px] truncate text-[12px] text-secondary' }, info.getValue() || '—'),
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

// Create Contact Modal
const createOpen = ref<boolean>(false);
const createSubmitting = ref<boolean>(false);
const createErrorBanner = ref<string>('');
const createFieldErrors = ref<Record<string, string>>({});

const createForm = ref({
    company_id: '',
    first_name: '',
    last_name: '',
    primary_email: '',
    secondary_emails: [] as string[],
    phones: [] as string[],
    job_title: '',
    is_primary_contact: false,
    custom_fields: {} as Record<string, any>,
});

function openCreateModal(): void {
    createErrorBanner.value = '';
    createFieldErrors.value = {};
    createForm.value = {
        company_id: '',
        first_name: '',
        last_name: '',
        primary_email: '',
        secondary_emails: [],
        phones: [],
        job_title: '',
        is_primary_contact: false,
        custom_fields: {},
    };
    createOpen.value = true;
}

function addSecondaryEmail(): void {
    createForm.value.secondary_emails.push('');
}

function removeSecondaryEmail(index: number): void {
    createForm.value.secondary_emails.splice(index, 1);
}

function addPhone(): void {
    createForm.value.phones.push('');
}

function removePhone(index: number): void {
    createForm.value.phones.splice(index, 1);
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

    const cleanSecondaryEmails = createForm.value.secondary_emails
        .map((email) => email.trim())
        .filter((email) => email !== '');

    const cleanPhones = createForm.value.phones
        .map((phone) => phone.trim())
        .filter((phone) => phone !== '');

    try {
        const created = await crmApi.createContact({
            company_id: createForm.value.company_id || null,
            first_name: createForm.value.first_name.trim(),
            last_name: createForm.value.last_name.trim(),
            primary_email: createForm.value.primary_email.trim() || null,
            secondary_emails: cleanSecondaryEmails,
            phones: cleanPhones,
            job_title: createForm.value.job_title.trim() || null,
            is_primary_contact: createForm.value.is_primary_contact,
            custom_fields: cleanCustom,
        });

        createOpen.value = false;
        router.visit(`/app/crm/contacts/${created.id}`);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            createFieldErrors.value = extractFirstFieldErrors(error.errors);
            createErrorBanner.value = error.message;
        } else {
            createErrorBanner.value = error instanceof Error ? error.message : 'Unable to create contact.';
        }
    } finally {
        createSubmitting.value = false;
    }
}

const paginationSummary = computed(() => {
    const { current_page, per_page, total } = props.contacts;
    if (total === 0) {
        return '0 contacts';
    }
    const start = (current_page - 1) * per_page + 1;
    const end = Math.min(current_page * per_page, total);
    return `${start}–${end} of ${total}`;
});
</script>

<template>
    <Head title="Contacts" />

    <div class="space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <Users class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        Contacts
                    </h2>
                    <Badge variant="neutral">{{ props.contacts.total }}</Badge>
                </div>
                <p class="mt-0.5 text-[12px] text-secondary">
                    Stakeholder directory with multi-address email matching, phone lists, and silo scoping.
                </p>
            </div>

            <Button
                v-if="props.can.create"
                variant="primary"
                size="md"
                data-testid="create-contact-cta"
                @click="openCreateModal"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>New Contact</span>
            </Button>
        </div>

        <DataTable
            :columns="columns"
            :data="props.contacts.data"
            :loading="navigating"
            :manual-sorting="true"
            :sorting="tableSorting"
            :row-key="(row) => row.id"
            empty-title="No matching contacts"
            empty-description="No visible contacts match your search, company, or custom field filters."
            test-id="contacts-data-table"
            @update:sorting="handleSortingChange"
        >
            <template #toolbar>
                <form
                    class="flex flex-wrap items-end gap-2"
                    @submit.prevent="applyFilters({ page: 1 })"
                >
                    <div class="w-full sm:w-56">
                        <label :for="searchInputId" class="sr-only">Search contacts</label>
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <Input
                                :id="searchInputId"
                                v-model="searchDraft"
                                placeholder="Search name or email..."
                                class="pl-8"
                            />
                        </div>
                    </div>

                    <div class="w-[calc(50%-0.25rem)] sm:w-44">
                        <label :for="companySelectId" class="sr-only">Company</label>
                        <select
                            :id="companySelectId"
                            v-model="companyDraft"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                            @change="applyFilters({ page: 1 })"
                        >
                            <option value="">All companies</option>
                            <option
                                v-for="company in props.companies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}
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
                        <label :for="`contact-cf-filter-${filterDef.key}`" class="sr-only">
                            {{ filterDef.label }}
                        </label>
                        <select
                            v-if="filterDef.field_type === 'select' || filterDef.field_type === 'multi_select'"
                            :id="`contact-cf-filter-${filterDef.key}`"
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
                        <Input
                            v-else
                            :id="`contact-cf-filter-${filterDef.key}`"
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
                            :disabled="props.contacts.current_page <= 1"
                            @click="applyFilters({ page: props.contacts.current_page - 1 })"
                        >
                            Previous
                        </Button>
                        <span class="px-2 font-mono text-[11px] tabular-nums text-secondary">
                            {{ props.contacts.current_page }} / {{ props.contacts.last_page }}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="props.contacts.current_page >= props.contacts.last_page"
                            @click="applyFilters({ page: props.contacts.current_page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </template>
        </DataTable>

        <!-- Create Contact Modal -->
        <Dialog
            :open="createOpen"
            title="New Contact"
            description="Create a contact with primary/secondary emails, phone numbers, and company association."
            max-width-class="max-w-lg"
            @update:open="createOpen = $event"
        >
            <form class="max-h-[75vh] overflow-y-auto space-y-3.5 pr-1" @submit.prevent="handleCreateSubmit">
                <div
                    v-if="createErrorBanner"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ createErrorBanner }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label :for="`create-contact-first-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            First Name <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`create-contact-first-${instanceId}`"
                            v-model="createForm.first_name"
                            placeholder="Hannah"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.first_name ?? ''"
                        />
                    </div>
                    <div>
                        <label :for="`create-contact-last-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Last Name <span class="text-status-danger">*</span>
                        </label>
                        <Input
                            :id="`create-contact-last-${instanceId}`"
                            v-model="createForm.last_name"
                            placeholder="Kowalski"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.last_name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`create-contact-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Company
                        </label>
                        <select
                            :id="`create-contact-company-${instanceId}`"
                            v-model="createForm.company_id"
                            :disabled="createSubmitting"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Independent / Unlinked</option>
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
                        <label :for="`create-contact-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Job Title
                        </label>
                        <Input
                            :id="`create-contact-title-${instanceId}`"
                            v-model="createForm.job_title"
                            placeholder="VP of Supply Chain"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.job_title ?? ''"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <label :for="`create-contact-email-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Primary Email
                        </label>
                        <Input
                            :id="`create-contact-email-${instanceId}`"
                            v-model="createForm.primary_email"
                            type="email"
                            placeholder="h.kowalski@northwind.internal"
                            :disabled="createSubmitting"
                            :error-message="createFieldErrors.primary_email ?? ''"
                        />
                    </div>
                </div>

                <!-- Secondary Emails -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[12px] font-medium text-secondary">Secondary Emails</span>
                        <Button type="button" variant="ghost" size="sm" @click="addSecondaryEmail">
                            <Plus class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                            <span>Add Email</span>
                        </Button>
                    </div>
                    <div
                        v-for="(_, index) in createForm.secondary_emails"
                        :key="`sec-email-${index}`"
                        class="flex items-center gap-2"
                    >
                        <label :for="`create-sec-email-${instanceId}-${index}`" class="sr-only">
                            Secondary email {{ index + 1 }}
                        </label>
                        <div class="flex-1">
                            <Input
                                :id="`create-sec-email-${instanceId}-${index}`"
                                v-model="createForm.secondary_emails[index]"
                                type="email"
                                placeholder="alias@northwind.internal"
                                :disabled="createSubmitting"
                            />
                        </div>
                        <button
                            type="button"
                            aria-label="Remove secondary email"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-[6px] text-secondary hover:bg-subtle hover:text-status-danger cursor-pointer"
                            @click="removeSecondaryEmail(index)"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <!-- Phones -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[12px] font-medium text-secondary">Phone Numbers</span>
                        <Button type="button" variant="ghost" size="sm" @click="addPhone">
                            <Plus class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                            <span>Add Phone</span>
                        </Button>
                    </div>
                    <div
                        v-for="(_, index) in createForm.phones"
                        :key="`phone-${index}`"
                        class="flex items-center gap-2"
                    >
                        <label :for="`create-phone-${instanceId}-${index}`" class="sr-only">
                            Phone number {{ index + 1 }}
                        </label>
                        <div class="flex-1">
                            <Input
                                :id="`create-phone-${instanceId}-${index}`"
                                v-model="createForm.phones[index]"
                                type="tel"
                                placeholder="+1-206-555-0148"
                                :disabled="createSubmitting"
                            />
                        </div>
                        <button
                            type="button"
                            aria-label="Remove phone number"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-[6px] text-secondary hover:bg-subtle hover:text-status-danger cursor-pointer"
                            @click="removePhone(index)"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <label
                    :for="`create-contact-primary-${instanceId}`"
                    class="flex items-center gap-2 text-[12px] text-primary cursor-pointer"
                >
                    <input
                        :id="`create-contact-primary-${instanceId}`"
                        v-model="createForm.is_primary_contact"
                        type="checkbox"
                        :disabled="createSubmitting"
                        class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                    />
                    <span>Primary contact for company</span>
                </label>

                <CustomFieldsForm
                    v-if="props.customFieldDefinitions.length > 0"
                    v-model="createForm.custom_fields"
                    :definitions="props.customFieldDefinitions"
                    :assignable-users="props.assignableUsers"
                    :errors="createFieldErrors"
                    :disabled="createSubmitting"
                    id-prefix="create-contact-cf"
                />

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" :disabled="createSubmitting" @click="createOpen = false">
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" :loading="createSubmitting">
                        Create Contact
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
