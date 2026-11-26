<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    ChevronRight,
    Download,
    Paperclip,
    Save,
    Trash2,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import ActivityTimeline from '@/modules/crm/components/ActivityTimeline.vue';
import CollaboratorsPanel from '@/modules/crm/components/CollaboratorsPanel.vue';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import {
    formatEnumLabel,
    formatFileSize,
    formatMoneyCents,
    formatShortDate,
} from '@/modules/crm/utils/formatters';

const props = defineProps<{
    company: App.Modules.Crm.Companies.Data.CompanyDetailData;
    contacts: App.Modules.Crm.Contacts.Data.ContactListItemData[];
    deals: App.Modules.Crm.Deals.Data.RelatedDealData[];
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    lifecycleStages: string[];
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        manageCollaborators: boolean;
    };
}>();

const instanceId = useId();
const localCompany = ref<App.Modules.Crm.Companies.Data.CompanyDetailData>({
    ...props.company,
    custom_fields: { ...(props.company.custom_fields ?? {}) },
});

const activeRelationTab = ref<'contacts' | 'deals'>('contacts');
const saving = ref<boolean>(false);
const saveError = ref<string>('');
const saveSuccess = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

const form = ref({
    name: localCompany.value.name,
    domain: localCompany.value.domain ?? '',
    lifecycle_stage: localCompany.value.lifecycle_stage,
    industry: localCompany.value.industry ?? '',
    employee_range: localCompany.value.employee_range ?? '',
    annual_revenue_dollars:
        localCompany.value.annual_revenue_cents !== null
            ? String((localCompany.value.annual_revenue_cents / 100).toFixed(2).replace(/\.00$/, ''))
            : '',
    account_manager_id: localCompany.value.account_manager_id ?? '',
    custom_fields: { ...(localCompany.value.custom_fields ?? {}) } as Record<string, any>,
});

const deleteDialogOpen = ref<boolean>(false);
const deleting = ref<boolean>(false);
const deleteError = ref<string>('');

const stageBadgeVariant = computed<BadgeVariant>(() => {
    switch (localCompany.value.lifecycle_stage) {
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
});

async function handleSave(): Promise<void> {
    if (!props.can.update || saving.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';
    saveSuccess.value = '';
    fieldErrors.value = {};

    const revenueRaw = form.value.annual_revenue_dollars.trim();
    const annualRevenueCents =
        revenueRaw !== '' && Number.isFinite(Number(revenueRaw))
            ? Math.round(Number(revenueRaw) * 100)
            : null;

    try {
        const updated = await crmApi.updateCompany(localCompany.value.id, {
            name: form.value.name.trim(),
            domain: form.value.domain.trim() || null,
            lifecycle_stage: form.value.lifecycle_stage,
            industry: form.value.industry.trim() || null,
            employee_range: form.value.employee_range.trim() || null,
            annual_revenue_cents: annualRevenueCents,
            billing_address: localCompany.value.billing_address ?? {},
            account_manager_id: form.value.account_manager_id || null,
            custom_fields: form.value.custom_fields,
        });

        localCompany.value = updated;
        saveSuccess.value = 'Company properties updated.';
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            saveError.value = error.message;
        } else {
            saveError.value = error instanceof Error ? error.message : 'Unable to save company changes.';
        }
    } finally {
        saving.value = false;
    }
}

async function handleDelete(): Promise<void> {
    if (!props.can.delete || deleting.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = '';

    try {
        await crmApi.deleteCompany(localCompany.value.id);
        deleteDialogOpen.value = false;
        router.visit('/app/crm/companies');
    } catch (error) {
        deleteError.value = error instanceof Error ? error.message : 'Unable to delete company.';
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <Head :title="localCompany.name" />

    <div class="space-y-4">
        <!-- Breadcrumb & Header -->
        <header class="flex flex-col gap-2 rounded-[8px] border border-default bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Breadcrumb" class="flex items-center gap-1 text-[12px] text-secondary">
                    <Link href="/app/crm/companies" class="hover:text-primary">Companies</Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate font-medium text-primary">{{ localCompany.name }}</span>
                </nav>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ localCompany.name }}
                    </h2>
                    <Badge :variant="stageBadgeVariant">
                        {{ formatEnumLabel(localCompany.lifecycle_stage) }}
                    </Badge>
                    <span v-if="localCompany.domain" class="font-mono text-[12px] text-secondary">
                        {{ localCompany.domain }}
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button
                    v-if="props.can.delete"
                    variant="ghost"
                    size="sm"
                    class="text-status-danger hover:bg-status-danger/10 hover:text-status-danger"
                    @click="deleteDialogOpen = true"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Delete</span>
                </Button>
            </div>
        </header>

        <!-- 3-Pane / Responsive Layout -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <!-- Left 280px Pane: Properties & Custom Fields -->
            <aside aria-label="Company properties" class="w-full shrink-0 rounded-[8px] border border-default bg-surface p-3.5 space-y-3 lg:w-[280px]">
                <div class="flex items-center justify-between border-b border-default pb-2">
                    <h3 class="text-[13px] font-semibold text-primary">Account Properties</h3>
                    <span class="font-mono text-[11px] text-muted">
                        {{ localCompany.owner_name ?? 'Owner' }}
                    </span>
                </div>

                <div
                    v-if="saveError"
                    role="alert"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-2.5 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ saveError }}</span>
                </div>

                <div
                    v-if="saveSuccess"
                    role="status"
                    class="flex items-center gap-2 rounded-[6px] border border-status-success/30 bg-status-success/10 px-2.5 py-2 text-[12px] text-status-success"
                >
                    <CheckCircle2 class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ saveSuccess }}</span>
                </div>

                <form class="space-y-3" @submit.prevent="handleSave">
                    <div>
                        <label :for="`co-name-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Company Name
                        </label>
                        <Input
                            :id="`co-name-${instanceId}`"
                            v-model="form.name"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`co-domain-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Domain
                        </label>
                        <Input
                            :id="`co-domain-${instanceId}`"
                            v-model="form.domain"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.domain ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`co-stage-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Lifecycle Stage
                        </label>
                        <select
                            :id="`co-stage-${instanceId}`"
                            v-model="form.lifecycle_stage"
                            :disabled="!props.can.update || saving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
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
                        <label :for="`co-industry-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Industry
                        </label>
                        <Input
                            :id="`co-industry-${instanceId}`"
                            v-model="form.industry"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.industry ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`co-range-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Employee Range
                        </label>
                        <Input
                            :id="`co-range-${instanceId}`"
                            v-model="form.employee_range"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.employee_range ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`co-revenue-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Annual Revenue (USD)
                        </label>
                        <Input
                            :id="`co-revenue-${instanceId}`"
                            v-model="form.annual_revenue_dollars"
                            type="number"
                            class="font-mono tabular-nums"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.annual_revenue_cents ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`co-am-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Account Manager
                        </label>
                        <select
                            :id="`co-am-${instanceId}`"
                            v-model="form.account_manager_id"
                            :disabled="!props.can.update || saving"
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

                    <div v-if="props.customFieldDefinitions.length > 0" class="border-t border-default pt-3">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-secondary">
                            Custom Fields
                        </p>
                        <CustomFieldsForm
                            v-model="form.custom_fields"
                            :definitions="props.customFieldDefinitions"
                            :assignable-users="props.assignableUsers"
                            :errors="fieldErrors"
                            :disabled="!props.can.update || saving"
                            id-prefix="company-detail-cf"
                        />
                    </div>

                    <Button
                        v-if="props.can.update"
                        type="submit"
                        variant="primary"
                        size="md"
                        class="w-full"
                        :loading="saving"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Save Changes</span>
                    </Button>
                </form>
            </aside>

            <!-- Center Pane: Related Contacts/Deals + Unified Timeline -->
            <div class="min-w-0 flex-1 space-y-4">
                <!-- Related Contacts & Deals Tabs -->
                <section
                    aria-label="Related CRM records"
                    class="overflow-hidden rounded-[8px] border border-default bg-surface"
                >
                    <div class="flex items-center justify-between border-b border-default bg-subtle px-3.5 py-2">
                        <div class="flex items-center gap-1" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="activeRelationTab === 'contacts' ? 'true' : 'false'"
                                :class="[
                                    'h-7 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                                    activeRelationTab === 'contacts'
                                        ? 'bg-surface text-primary border border-default'
                                        : 'text-secondary hover:text-primary',
                                ]"
                                @click="activeRelationTab = 'contacts'"
                            >
                                Contacts ({{ props.contacts.length }})
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="activeRelationTab === 'deals' ? 'true' : 'false'"
                                :class="[
                                    'h-7 rounded-[4px] px-2.5 text-[12px] font-medium transition-colors duration-150 cursor-pointer',
                                    activeRelationTab === 'deals'
                                        ? 'bg-surface text-primary border border-default'
                                        : 'text-secondary hover:text-primary',
                                ]"
                                @click="activeRelationTab = 'deals'"
                            >
                                Deals ({{ props.deals.length }})
                            </button>
                        </div>
                    </div>

                    <div v-if="activeRelationTab === 'contacts'">
                        <p v-if="props.contacts.length === 0" class="px-4 py-5 text-[12px] text-muted">
                            No visible contacts linked to this company.
                        </p>
                        <ul v-else class="divide-y divide-default text-[13px]">
                            <li
                                v-for="contact in props.contacts"
                                :key="contact.id"
                                class="flex h-9 items-center justify-between gap-3 px-4"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <Link
                                        :href="`/app/crm/contacts/${contact.id}`"
                                        class="truncate font-medium text-primary hover:text-accent-primary"
                                    >
                                        {{ contact.full_name }}
                                    </Link>
                                    <Badge v-if="contact.is_primary_contact" variant="cobalt">Primary</Badge>
                                    <span v-if="contact.job_title" class="hidden sm:inline truncate text-[12px] text-secondary">
                                        {{ contact.job_title }}
                                    </span>
                                </div>
                                <span class="shrink-0 font-mono text-[12px] text-secondary">
                                    {{ contact.primary_email ?? 'No email' }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <div v-else>
                        <p v-if="props.deals.length === 0" class="px-4 py-5 text-[12px] text-muted">
                            No visible deals linked to this company.
                        </p>
                        <ul v-else class="divide-y divide-default text-[13px]">
                            <li
                                v-for="deal in props.deals"
                                :key="deal.id"
                                class="flex h-9 items-center justify-between gap-3 px-4"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <Link
                                        :href="`/app/crm/deals/${deal.id}`"
                                        class="truncate font-medium text-primary hover:text-accent-primary"
                                    >
                                        {{ deal.title }}
                                    </Link>
                                    <Badge variant="neutral">{{ deal.stage_name ?? 'Stage' }}</Badge>
                                </div>
                                <div class="flex shrink-0 items-center gap-3 font-mono text-[12px] tabular-nums">
                                    <span class="font-semibold text-primary">
                                        {{ formatMoneyCents(deal.amount_cents, deal.currency) }}
                                    </span>
                                    <span class="hidden sm:inline text-muted">
                                        {{ formatShortDate(deal.expected_close_date) }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Unified Activity Timeline -->
                <ActivityTimeline
                    linkable-type="company"
                    :linkable-id="localCompany.id"
                    :initial-page="props.timeline"
                    :can-create-note="props.can.update"
                />
            </div>

            <!-- Right 280px Pane: Collaborators & Clean Attachments -->
            <aside aria-label="Sharing and attachments" class="w-full shrink-0 space-y-4 lg:w-[280px]">
                <CollaboratorsPanel
                    entity-type="company"
                    :entity-id="localCompany.id"
                    :collaborators="props.collaborators"
                    :assignable-users="props.assignableUsers"
                    :access-levels="props.collaboratorAccessLevels"
                    :role-labels="props.collaboratorRoleLabels"
                    :can-manage="props.can.manageCollaborators"
                />

                <section
                    aria-label="Clean attachments"
                    class="rounded-[8px] border border-default bg-surface p-3.5 space-y-2.5"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-primary">
                            <Paperclip class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <span>Attachments</span>
                        </h3>
                        <Badge variant="neutral">{{ props.attachments.length }}</Badge>
                    </div>

                    <p v-if="props.attachments.length === 0" class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted">
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
            </aside>
        </div>

        <!-- Delete Confirmation Dialog -->
        <Dialog
            :open="deleteDialogOpen"
            title="Delete Company"
            :description="`Are you sure you want to archive ${localCompany.name}? This action is audited.`"
            max-width-class="max-w-md"
            @update:open="deleteDialogOpen = $event"
        >
            <div class="space-y-4">
                <div
                    v-if="deleteError"
                    role="alert"
                    class="rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    {{ deleteError }}
                </div>
                <div class="flex items-center justify-end gap-2">
                    <Button variant="secondary" :disabled="deleting" @click="deleteDialogOpen = false">
                        Cancel
                    </Button>
                    <Button variant="danger" :loading="deleting" @click="handleDelete">
                        Confirm Delete
                    </Button>
                </div>
            </div>
        </Dialog>
    </div>
</template>
