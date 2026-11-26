<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRightLeft,
    Briefcase,
    Building2,
    CheckCircle2,
    ChevronRight,
    Download,
    Paperclip,
    Save,
    Trash2,
    User,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import ActivityTimeline from '@/modules/crm/components/ActivityTimeline.vue';
import CollaboratorsPanel from '@/modules/crm/components/CollaboratorsPanel.vue';
import ConvertLeadDialog from '@/modules/crm/components/ConvertLeadDialog.vue';
import CustomFieldsForm from '@/modules/crm/components/CustomFieldsForm.vue';
import { crmApi, extractFirstFieldErrors, ValidationRequestError } from '@/modules/crm/api/crmApi';
import {
    formatDateTime,
    formatEnumLabel,
    formatFileSize,
    formatMoneyCents,
} from '@/modules/crm/utils/formatters';

const props = defineProps<{
    lead: App.Modules.Crm.Leads.Data.LeadDetailData;
    convertedCompany: {
        id: string;
        name: string;
        domain: string | null;
    } | null;
    convertedContact: {
        id: string;
        full_name: string;
        primary_email: string | null;
    } | null;
    convertedDeal: {
        id: string;
        title: string;
        amount_cents: number;
        currency: string;
    } | null;
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[];
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    timeline: App.Modules.Crm.Timeline.Data.TimelinePageData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{
        id: string;
        name: string;
        domain: string | null;
    }>;
    pipelines: App.Modules.Crm.Pipelines.Data.PipelineData[];
    statuses: string[];
    sources: string[];
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        convert: boolean;
        manageCollaborators: boolean;
        createCompany: boolean;
        createContact: boolean;
        createDeal: boolean;
    };
}>();

const instanceId = useId();
const localLead = ref<App.Modules.Crm.Leads.Data.LeadDetailData>({
    ...props.lead,
    custom_fields: { ...(props.lead.custom_fields ?? {}) },
});

const editableStatuses = computed(() =>
    props.statuses.filter((status) => status !== 'converted'),
);

function buildLeadFormState(source: App.Modules.Crm.Leads.Data.LeadDetailData) {
    return {
        first_name: source.first_name,
        last_name: source.last_name,
        email: source.email ?? '',
        phone: source.phone ?? '',
        job_title: source.job_title ?? '',
        company_name: source.company_name ?? '',
        website: source.website ?? '',
        status: source.status,
        source: source.source,
        estimated_value_dollars:
            source.estimated_value_cents !== null && source.estimated_value_cents !== undefined
                ? String((source.estimated_value_cents / 100).toFixed(2).replace(/\.00$/, ''))
                : '',
        currency: source.currency || 'USD',
        disqualified_reason: source.disqualified_reason ?? '',
        custom_fields: { ...(source.custom_fields ?? {}) } as Record<string, any>,
    };
}

const form = ref(buildLeadFormState(localLead.value));

function syncLocalFromLead(updated: App.Modules.Crm.Leads.Data.LeadDetailData): void {
    localLead.value = {
        ...updated,
        custom_fields: { ...(updated.custom_fields ?? {}) },
    };
    form.value = buildLeadFormState(updated);
}

watch(
    () => props.lead,
    (nextLead) => syncLocalFromLead(nextLead),
    { deep: true },
);

const saving = ref<boolean>(false);
const saveError = ref<string>('');
const saveSuccess = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

const convertDialogOpen = ref<boolean>(false);
const deleteDialogOpen = ref<boolean>(false);
const deleting = ref<boolean>(false);
const deleteError = ref<string>('');

function leadStatusVariant(status: App.Modules.Crm.Leads.Enums.LeadStatusEnum): BadgeVariant {
    switch (status) {
        case 'qualified':
        case 'converted':
            return 'success';
        case 'contacted':
        case 'working':
            return 'warning';
        case 'unqualified':
            return 'danger';
        default:
            return 'cobalt';
    }
}

async function handleSave(): Promise<void> {
    if (!props.can.update || saving.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';
    saveSuccess.value = '';
    fieldErrors.value = {};

    const valueRaw = form.value.estimated_value_dollars.trim();
    const estimatedValueCents =
        valueRaw !== '' && Number.isFinite(Number(valueRaw))
            ? Math.max(0, Math.round(Number(valueRaw) * 100))
            : null;

    const isAlreadyConverted = localLead.value.status === 'converted';

    try {
        const updated = await crmApi.updateLead(localLead.value.id, {
            first_name: form.value.first_name.trim(),
            last_name: form.value.last_name.trim(),
            email: form.value.email.trim() || null,
            phone: form.value.phone.trim() || null,
            job_title: form.value.job_title.trim() || null,
            company_name: form.value.company_name.trim() || null,
            website: form.value.website.trim() || null,
            ...(isAlreadyConverted ? {} : { status: form.value.status }),
            source: form.value.source,
            estimated_value_cents: estimatedValueCents,
            currency: form.value.currency.trim().toUpperCase() || 'USD',
            disqualified_reason:
                form.value.status === 'unqualified' || form.value.disqualified_reason.trim() !== ''
                    ? form.value.disqualified_reason.trim() || null
                    : null,
            custom_fields: form.value.custom_fields,
        });

        syncLocalFromLead(updated);
        saveSuccess.value = 'Lead details saved.';
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            saveError.value = error.message;
        } else {
            saveError.value = error instanceof Error ? error.message : 'Unable to save lead.';
        }
    } finally {
        saving.value = false;
    }
}

function handleConverted(result: App.Modules.Crm.Leads.Data.ConvertLeadResultData): void {
    syncLocalFromLead(result.lead);
    if (result.deal_id) {
        router.visit(`/app/crm/deals/${result.deal_id}`);
        return;
    }

    router.reload({
        only: ['lead', 'convertedCompany', 'convertedContact', 'convertedDeal', 'timeline', 'can'],
    });
}

async function handleDelete(): Promise<void> {
    if (!props.can.delete || deleting.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = '';

    try {
        await crmApi.deleteLead(localLead.value.id);
        deleteDialogOpen.value = false;
        router.visit('/app/crm/leads');
    } catch (error) {
        deleteError.value = error instanceof Error ? error.message : 'Unable to delete lead.';
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <Head :title="localLead.full_name" />

    <div class="space-y-4">
        <!-- Header Context Bar -->
        <header class="flex flex-col gap-2 rounded-[8px] border border-default bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Breadcrumb" class="flex items-center gap-1 text-[12px] text-secondary">
                    <Link href="/app/crm/leads" class="hover:text-primary">Leads</Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate font-medium text-primary">{{ localLead.full_name }}</span>
                </nav>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ localLead.full_name }}
                    </h2>
                    <Badge :variant="leadStatusVariant(localLead.status)">
                        {{ formatEnumLabel(localLead.status) }}
                    </Badge>
                    <Badge variant="neutral">
                        {{ formatEnumLabel(localLead.source) }}
                    </Badge>
                    <span v-if="localLead.company_name" class="text-[12px] text-secondary">
                        {{ localLead.company_name }}
                    </span>
                    <span
                        v-if="localLead.estimated_value_cents !== null"
                        class="font-mono text-[12px] font-medium tabular-nums text-primary"
                    >
                        {{ formatMoneyCents(localLead.estimated_value_cents, localLead.currency) }}
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Button
                    v-if="props.can.convert && props.can.createContact && localLead.status !== 'converted'"
                    variant="primary"
                    size="sm"
                    data-testid="open-convert-lead-cta"
                    @click="convertDialogOpen = true"
                >
                    <ArrowRightLeft class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Convert Lead</span>
                </Button>

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

        <!-- 3-Pane Detail Workbench -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <!-- Left 280px Properties Pane -->
            <aside aria-label="Lead properties" class="w-full shrink-0 rounded-[8px] border border-default bg-surface p-3.5 space-y-3 lg:w-[280px]">
                <div class="flex items-center justify-between border-b border-default pb-2">
                    <h3 class="text-[13px] font-semibold text-primary">Lead Details</h3>
                    <span class="font-mono text-[11px] text-muted truncate max-w-[130px]">
                        {{ localLead.owner_name ?? 'Owner' }}
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
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label :for="`ld-first-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                First Name
                            </label>
                            <Input
                                :id="`ld-first-${instanceId}`"
                                v-model="form.first_name"
                                required
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.first_name ?? ''"
                            />
                        </div>
                        <div>
                            <label :for="`ld-last-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Last Name
                            </label>
                            <Input
                                :id="`ld-last-${instanceId}`"
                                v-model="form.last_name"
                                required
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.last_name ?? ''"
                            />
                        </div>
                    </div>

                    <div>
                        <label :for="`ld-email-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Email
                        </label>
                        <Input
                            :id="`ld-email-${instanceId}`"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            :spellcheck="false"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.email ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`ld-phone-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Phone
                        </label>
                        <Input
                            :id="`ld-phone-${instanceId}`"
                            v-model="form.phone"
                            type="tel"
                            autocomplete="tel"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.phone ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`ld-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Job Title
                        </label>
                        <Input
                            :id="`ld-title-${instanceId}`"
                            v-model="form.job_title"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.job_title ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`ld-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Prospect Company
                        </label>
                        <Input
                            :id="`ld-company-${instanceId}`"
                            v-model="form.company_name"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.company_name ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`ld-website-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Website / Domain
                        </label>
                        <Input
                            :id="`ld-website-${instanceId}`"
                            v-model="form.website"
                            :spellcheck="false"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.website ?? ''"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label :for="`ld-status-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Status
                            </label>
                            <div
                                v-if="localLead.status === 'converted'"
                                :id="`ld-status-${instanceId}`"
                                class="flex h-8 items-center rounded-[6px] border border-default bg-subtle px-2.5 text-[12px] font-medium text-status-success"
                            >
                                Converted
                            </div>
                            <select
                                v-else
                                :id="`ld-status-${instanceId}`"
                                v-model="form.status"
                                :disabled="!props.can.update || saving"
                                :aria-invalid="fieldErrors.status ? 'true' : undefined"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option
                                    v-for="status in editableStatuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ formatEnumLabel(status) }}
                                </option>
                            </select>
                            <p v-if="fieldErrors.status" role="alert" class="mt-1 text-[12px] text-status-danger">
                                {{ fieldErrors.status }}
                            </p>
                        </div>

                        <div>
                            <label :for="`ld-source-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Source
                            </label>
                            <select
                                :id="`ld-source-${instanceId}`"
                                v-model="form.source"
                                :disabled="!props.can.update || saving"
                                :aria-invalid="fieldErrors.source ? 'true' : undefined"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option
                                    v-for="source in props.sources"
                                    :key="source"
                                    :value="source"
                                >
                                    {{ formatEnumLabel(source) }}
                                </option>
                            </select>
                            <p v-if="fieldErrors.source" role="alert" class="mt-1 text-[12px] text-status-danger">
                                {{ fieldErrors.source }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <label :for="`ld-value-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Est. Value
                            </label>
                            <Input
                                :id="`ld-value-${instanceId}`"
                                v-model="form.estimated_value_dollars"
                                type="number"
                                min="0"
                                step="0.01"
                                class="font-mono tabular-nums"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.estimated_value_cents ?? ''"
                            />
                        </div>
                        <div>
                            <label :for="`ld-currency-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Currency
                            </label>
                            <Input
                                :id="`ld-currency-${instanceId}`"
                                v-model="form.currency"
                                maxlength="3"
                                class="font-mono uppercase"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.currency ?? ''"
                            />
                        </div>
                    </div>

                    <div v-if="form.status === 'unqualified' || form.disqualified_reason">
                        <label :for="`ld-disqualified-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Disqualified Reason
                        </label>
                        <Input
                            :id="`ld-disqualified-${instanceId}`"
                            v-model="form.disqualified_reason"
                            placeholder="Budget frozen / non-ICP"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.disqualified_reason ?? ''"
                        />
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
                            id-prefix="lead-detail-cf"
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

            <!-- Center Pane: Activity Timeline -->
            <div class="min-w-0 flex-1">
                <ActivityTimeline
                    linkable-type="lead"
                    :linkable-id="localLead.id"
                    :initial-page="props.timeline"
                    :can-create-note="props.can.update && localLead.status !== 'converted'"
                />
            </div>

            <!-- Right 280px Pane: Conversion Summary/Prompt, Collaborators & Attachments -->
            <aside aria-label="Conversion, collaborators, and attachments" class="w-full shrink-0 space-y-4 lg:w-[280px]">
                <!-- Converted Summary Card -->
                <section
                    v-if="localLead.status === 'converted'"
                    aria-label="Conversion summary"
                    data-testid="lead-conversion-summary"
                    class="rounded-[8px] border border-default bg-surface p-3.5 space-y-3"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-default pb-2">
                        <h3 class="text-[13px] font-semibold text-primary">Conversion Summary</h3>
                        <Badge variant="success">Converted</Badge>
                    </div>

                    <p v-if="localLead.converted_at" class="font-mono text-[11px] tabular-nums text-secondary">
                        Converted {{ formatDateTime(localLead.converted_at) }}
                    </p>

                    <div class="space-y-2.5 text-[12px]">
                        <div class="space-y-1">
                            <span class="block text-[11px] font-medium uppercase tracking-wider text-secondary">
                                Company
                            </span>
                            <div v-if="props.convertedCompany" class="flex items-center gap-1.5 min-w-0">
                                <Building2 class="h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <Link
                                    :href="`/app/crm/companies/${props.convertedCompany.id}`"
                                    class="truncate font-semibold text-accent-primary hover:underline"
                                >
                                    {{ props.convertedCompany.name }}
                                </Link>
                            </div>
                            <p
                                v-else-if="localLead.has_masked_converted_company"
                                data-testid="lead-masked-converted-company"
                                class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-secondary"
                            >
                                Restricted (outside your silo)
                            </p>
                            <Link
                                v-else-if="localLead.converted_company_id"
                                :href="`/app/crm/companies/${localLead.converted_company_id}`"
                                class="inline-flex items-center gap-1.5 font-medium text-accent-primary hover:underline"
                            >
                                <Building2 class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                                <span>View Company</span>
                            </Link>
                            <p v-else class="text-muted">—</p>
                        </div>

                        <div class="space-y-1">
                            <span class="block text-[11px] font-medium uppercase tracking-wider text-secondary">
                                Contact
                            </span>
                            <div v-if="props.convertedContact" class="flex items-center gap-1.5 min-w-0">
                                <User class="h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                <Link
                                    :href="`/app/crm/contacts/${props.convertedContact.id}`"
                                    class="truncate font-semibold text-accent-primary hover:underline"
                                >
                                    {{ props.convertedContact.full_name }}
                                </Link>
                            </div>
                            <p
                                v-else-if="localLead.has_masked_converted_contact"
                                data-testid="lead-masked-converted-contact"
                                class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-secondary"
                            >
                                Restricted (outside your silo)
                            </p>
                            <Link
                                v-else-if="localLead.converted_contact_id"
                                :href="`/app/crm/contacts/${localLead.converted_contact_id}`"
                                class="inline-flex items-center gap-1.5 font-medium text-accent-primary hover:underline"
                            >
                                <User class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                                <span>View Contact</span>
                            </Link>
                            <p v-else class="text-muted">—</p>
                        </div>

                        <div class="space-y-1">
                            <span class="block text-[11px] font-medium uppercase tracking-wider text-secondary">
                                Deal
                            </span>
                            <div v-if="props.convertedDeal" class="space-y-0.5 min-w-0">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <Briefcase class="h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                                    <Link
                                        :href="`/app/crm/deals/${props.convertedDeal.id}`"
                                        class="truncate font-semibold text-accent-primary hover:underline"
                                    >
                                        {{ props.convertedDeal.title }}
                                    </Link>
                                </div>
                                <p class="pl-5 font-mono text-[11px] tabular-nums text-secondary">
                                    {{ formatMoneyCents(props.convertedDeal.amount_cents, props.convertedDeal.currency) }}
                                </p>
                            </div>
                            <p
                                v-else-if="localLead.has_masked_converted_deal"
                                data-testid="lead-masked-converted-deal"
                                class="rounded-[6px] border border-default bg-subtle px-2.5 py-1.5 text-[12px] text-secondary"
                            >
                                Restricted (outside your silo)
                            </p>
                            <Link
                                v-else-if="localLead.converted_deal_id"
                                :href="`/app/crm/deals/${localLead.converted_deal_id}`"
                                class="inline-flex items-center gap-1.5 font-medium text-accent-primary hover:underline"
                            >
                                <Briefcase class="h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                                <span>View Deal</span>
                            </Link>
                            <p v-else class="text-muted">No initial deal created.</p>
                        </div>
                    </div>
                </section>

                <!-- Qualification & Conversion Prompt Card -->
                <section
                    v-else
                    aria-label="Qualification and conversion"
                    data-testid="lead-qualification-card"
                    class="rounded-[8px] border border-default bg-surface p-3.5 space-y-3"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-default pb-2">
                        <h3 class="text-[13px] font-semibold text-primary">Qualification</h3>
                        <Badge :variant="leadStatusVariant(localLead.status)">
                            {{ formatEnumLabel(localLead.status) }}
                        </Badge>
                    </div>

                    <p class="text-[12px] text-secondary">
                        Converting this lead provisions a Company, links a Contact record, and optionally opens a Deal in your sales pipeline.
                    </p>

                    <Button
                        v-if="props.can.convert && props.can.createContact"
                        variant="primary"
                        size="sm"
                        class="w-full"
                        @click="convertDialogOpen = true"
                    >
                        <ArrowRightLeft class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Convert Lead</span>
                    </Button>
                </section>

                <!-- Collaborators Panel -->
                <CollaboratorsPanel
                    entity-type="lead"
                    :entity-id="localLead.id"
                    :collaborators="props.collaborators"
                    :assignable-users="props.assignableUsers"
                    :access-levels="props.collaboratorAccessLevels"
                    :role-labels="props.collaboratorRoleLabels"
                    :can-manage="props.can.manageCollaborators"
                />

                <!-- Clean Attachments -->
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

        <!-- Convert Lead Modal -->
        <ConvertLeadDialog
            :open="convertDialogOpen"
            :lead="localLead"
            :companies="props.companies"
            :pipelines="props.pipelines"
            :can-create-company="props.can.createCompany"
            :can-create-deal="props.can.createDeal"
            @update:open="convertDialogOpen = $event"
            @converted="handleConverted"
        />

        <!-- Delete Lead Confirmation Dialog -->
        <Dialog
            :open="deleteDialogOpen"
            title="Delete Lead"
            :description="`Are you sure you want to delete ${localLead.full_name}?`"
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
