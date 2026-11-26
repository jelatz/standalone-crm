<script setup lang="ts">
import { ref, useId } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Building2,
    CheckCircle2,
    ChevronRight,
    Download,
    Paperclip,
    Plus,
    Save,
    Trash2,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
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
    contact: App.Modules.Crm.Contacts.Data.ContactDetailData;
    company: {
        id: string;
        name: string;
        domain: string | null;
        lifecycle_stage: string;
    } | null;
    deals: App.Modules.Crm.Deals.Data.RelatedDealData[];
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
    collaboratorAccessLevels: string[];
    collaboratorRoleLabels: string[];
    can: {
        update: boolean;
        delete: boolean;
        manageCollaborators: boolean;
    };
}>();

const instanceId = useId();
const localContact = ref<App.Modules.Crm.Contacts.Data.ContactDetailData>({
    ...props.contact,
    custom_fields: { ...(props.contact.custom_fields ?? {}) },
});

function formatPhoneEntry(phone: unknown): string {
    if (typeof phone === 'string') {
        return phone;
    }
    if (phone && typeof phone === 'object' && 'number' in phone) {
        return String((phone as { number: unknown }).number ?? '');
    }
    return '';
}

const form = ref({
    company_id: localContact.value.company_id ?? '',
    first_name: localContact.value.first_name,
    last_name: localContact.value.last_name,
    primary_email: localContact.value.primary_email ?? '',
    secondary_emails: [...localContact.value.secondary_emails],
    phones: localContact.value.phones.map(formatPhoneEntry).filter((p) => p !== ''),
    job_title: localContact.value.job_title ?? '',
    is_primary_contact: localContact.value.is_primary_contact,
    custom_fields: { ...(localContact.value.custom_fields ?? {}) } as Record<string, any>,
});

const saving = ref<boolean>(false);
const saveError = ref<string>('');
const saveSuccess = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

const deleteDialogOpen = ref<boolean>(false);
const deleting = ref<boolean>(false);
const deleteError = ref<string>('');

function addSecondaryEmail(): void {
    form.value.secondary_emails.push('');
}

function removeSecondaryEmail(index: number): void {
    form.value.secondary_emails.splice(index, 1);
}

function addPhone(): void {
    form.value.phones.push('');
}

function removePhone(index: number): void {
    form.value.phones.splice(index, 1);
}

async function handleSave(): Promise<void> {
    if (!props.can.update || saving.value) {
        return;
    }

    saving.value = true;
    saveError.value = '';
    saveSuccess.value = '';
    fieldErrors.value = {};

    const cleanSecondaryEmails = form.value.secondary_emails
        .map((email) => email.trim())
        .filter((email) => email !== '');

    const cleanPhones = form.value.phones
        .map((phone) => phone.trim())
        .filter((phone) => phone !== '');

    try {
        const updated = await crmApi.updateContact(localContact.value.id, {
            company_id: form.value.company_id || null,
            first_name: form.value.first_name.trim(),
            last_name: form.value.last_name.trim(),
            primary_email: form.value.primary_email.trim() || null,
            secondary_emails: cleanSecondaryEmails,
            phones: cleanPhones,
            job_title: form.value.job_title.trim() || null,
            is_primary_contact: form.value.is_primary_contact,
            custom_fields: form.value.custom_fields,
        });

        localContact.value = updated;
        form.value.secondary_emails = [...updated.secondary_emails];
        form.value.phones = updated.phones.map(formatPhoneEntry).filter((p) => p !== '');
        saveSuccess.value = 'Contact details saved.';
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            saveError.value = error.message;
        } else {
            saveError.value = error instanceof Error ? error.message : 'Unable to save contact.';
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
        await crmApi.deleteContact(localContact.value.id);
        deleteDialogOpen.value = false;
        router.visit('/app/crm/contacts');
    } catch (error) {
        deleteError.value = error instanceof Error ? error.message : 'Unable to delete contact.';
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <Head :title="localContact.full_name" />

    <div class="space-y-4">
        <header class="flex flex-col gap-2 rounded-[8px] border border-default bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Breadcrumb" class="flex items-center gap-1 text-[12px] text-secondary">
                    <Link href="/app/crm/contacts" class="hover:text-primary">Contacts</Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate font-medium text-primary">{{ localContact.full_name }}</span>
                </nav>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ localContact.full_name }}
                    </h2>
                    <Badge v-if="localContact.is_primary_contact" variant="cobalt">Primary Contact</Badge>
                    <span v-if="localContact.job_title" class="text-[12px] text-secondary">
                        {{ localContact.job_title }}
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

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <!-- Left 280px Properties Pane -->
            <aside aria-label="Contact properties" class="w-full shrink-0 rounded-[8px] border border-default bg-surface p-3.5 space-y-3 lg:w-[280px]">
                <div class="flex items-center justify-between border-b border-default pb-2">
                    <h3 class="text-[13px] font-semibold text-primary">Contact Details</h3>
                    <span class="font-mono text-[11px] text-muted">
                        {{ localContact.owner_name ?? 'Owner' }}
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
                            <label :for="`ct-first-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                First Name
                            </label>
                            <Input
                                :id="`ct-first-${instanceId}`"
                                v-model="form.first_name"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.first_name ?? ''"
                            />
                        </div>
                        <div>
                            <label :for="`ct-last-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                                Last Name
                            </label>
                            <Input
                                :id="`ct-last-${instanceId}`"
                                v-model="form.last_name"
                                :disabled="!props.can.update || saving"
                                :error-message="fieldErrors.last_name ?? ''"
                            />
                        </div>
                    </div>

                    <div v-if="props.can.update">
                        <label :for="`ct-company-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Company
                        </label>
                        <select
                            :id="`ct-company-${instanceId}`"
                            v-model="form.company_id"
                            :disabled="saving"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                        >
                            <option value="">Independent</option>
                            <option
                                v-for="companyOption in props.companies"
                                :key="companyOption.id"
                                :value="companyOption.id"
                            >
                                {{ companyOption.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label :for="`ct-title-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Job Title
                        </label>
                        <Input
                            :id="`ct-title-${instanceId}`"
                            v-model="form.job_title"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.job_title ?? ''"
                        />
                    </div>

                    <div>
                        <label :for="`ct-primary-email-${instanceId}`" class="mb-1 block text-[12px] font-medium text-secondary">
                            Primary Email
                        </label>
                        <Input
                            :id="`ct-primary-email-${instanceId}`"
                            v-model="form.primary_email"
                            type="email"
                            :disabled="!props.can.update || saving"
                            :error-message="fieldErrors.primary_email ?? ''"
                        />
                    </div>

                    <!-- Multi-Email Manager -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-[12px] font-medium text-secondary">Secondary Emails</span>
                            <button
                                v-if="props.can.update"
                                type="button"
                                class="inline-flex items-center gap-1 text-[11px] font-medium text-accent-primary hover:text-accent-hover cursor-pointer"
                                @click="addSecondaryEmail"
                            >
                                <Plus class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>Add</span>
                            </button>
                        </div>

                        <p v-if="form.secondary_emails.length === 0" class="text-[11px] text-muted">
                            None configured.
                        </p>
                        <div
                            v-for="(_, index) in form.secondary_emails"
                            :key="`ct-sec-${index}`"
                            class="flex items-center gap-1.5"
                        >
                            <label :for="`ct-sec-email-${instanceId}-${index}`" class="sr-only">
                                Secondary email {{ index + 1 }}
                            </label>
                            <div class="min-w-0 flex-1">
                                <Input
                                    :id="`ct-sec-email-${instanceId}-${index}`"
                                    v-model="form.secondary_emails[index]"
                                    type="email"
                                    :disabled="!props.can.update || saving"
                                />
                            </div>
                            <button
                                v-if="props.can.update"
                                type="button"
                                aria-label="Remove secondary email"
                                class="inline-flex h-8 w-7 shrink-0 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-status-danger cursor-pointer"
                                @click="removeSecondaryEmail(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <!-- Phone Numbers -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-[12px] font-medium text-secondary">Phone Numbers</span>
                            <button
                                v-if="props.can.update"
                                type="button"
                                class="inline-flex items-center gap-1 text-[11px] font-medium text-accent-primary hover:text-accent-hover cursor-pointer"
                                @click="addPhone"
                            >
                                <Plus class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>Add</span>
                            </button>
                        </div>

                        <p v-if="form.phones.length === 0" class="text-[11px] text-muted">
                            None configured.
                        </p>
                        <div
                            v-for="(_, index) in form.phones"
                            :key="`ct-phone-${index}`"
                            class="flex items-center gap-1.5"
                        >
                            <label :for="`ct-phone-${instanceId}-${index}`" class="sr-only">
                                Phone number {{ index + 1 }}
                            </label>
                            <div class="min-w-0 flex-1">
                                <Input
                                    :id="`ct-phone-${instanceId}-${index}`"
                                    v-model="form.phones[index]"
                                    type="tel"
                                    :disabled="!props.can.update || saving"
                                />
                            </div>
                            <button
                                v-if="props.can.update"
                                type="button"
                                aria-label="Remove phone number"
                                class="inline-flex h-8 w-7 shrink-0 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-status-danger cursor-pointer"
                                @click="removePhone(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <label
                        :for="`ct-primary-flag-${instanceId}`"
                        class="flex items-center gap-2 text-[12px] text-primary cursor-pointer"
                    >
                        <input
                            :id="`ct-primary-flag-${instanceId}`"
                            v-model="form.is_primary_contact"
                            type="checkbox"
                            :disabled="!props.can.update || saving"
                            class="h-3.5 w-3.5 rounded border-default accent-accent-primary"
                        />
                        <span>Primary company contact</span>
                    </label>

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
                            id-prefix="contact-detail-cf"
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

            <!-- Center Pane: Linked Company/Deals + Activity Timeline -->
            <div class="min-w-0 flex-1 space-y-4">
                <section
                    aria-label="Linked company and deals"
                    class="grid grid-cols-1 gap-3 rounded-[8px] border border-default bg-surface p-3.5 sm:grid-cols-2"
                >
                    <div class="space-y-1">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-secondary">
                            Linked Company
                        </p>
                        <div v-if="props.company" class="flex items-center gap-2">
                            <Building2 class="h-4 w-4 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                            <Link
                                :href="`/app/crm/companies/${props.company.id}`"
                                class="truncate text-[13px] font-semibold text-primary hover:text-accent-primary"
                            >
                                {{ props.company.name }}
                            </Link>
                            <Badge variant="neutral">{{ formatEnumLabel(props.company.lifecycle_stage) }}</Badge>
                        </div>
                        <p v-else class="text-[12px] text-muted">
                            No visible company associated.
                        </p>
                    </div>

                    <div class="space-y-1">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-secondary">
                            Associated Deals ({{ props.deals.length }})
                        </p>
                        <p v-if="props.deals.length === 0" class="text-[12px] text-muted">
                            No visible deals linked to this contact.
                        </p>
                        <ul v-else class="space-y-1">
                            <li
                                v-for="deal in props.deals"
                                :key="deal.id"
                                class="flex items-center justify-between gap-2 text-[12px]"
                            >
                                <Link
                                    :href="`/app/crm/deals/${deal.id}`"
                                    class="truncate font-medium text-primary hover:text-accent-primary"
                                >
                                    {{ deal.title }}
                                </Link>
                                <span class="shrink-0 font-mono tabular-nums text-secondary">
                                    {{ formatMoneyCents(deal.amount_cents, deal.currency) }} · {{ formatShortDate(deal.expected_close_date) }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <ActivityTimeline
                    linkable-type="contact"
                    :linkable-id="localContact.id"
                    :initial-page="props.timeline"
                    :can-create-note="props.can.update"
                />
            </div>

            <!-- Right 280px Pane: Collaborators & Attachments -->
            <aside aria-label="Sharing and attachments" class="w-full shrink-0 space-y-4 lg:w-[280px]">
                <CollaboratorsPanel
                    entity-type="contact"
                    :entity-id="localContact.id"
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

        <Dialog
            :open="deleteDialogOpen"
            title="Delete Contact"
            :description="`Are you sure you want to delete ${localContact.full_name}?`"
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
