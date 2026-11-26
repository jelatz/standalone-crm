<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowUpRight,
    CheckCircle2,
    CheckSquare,
    Clock,
    FolderKanban,
    MessageSquareWarning,
    RefreshCw,
    Square,
    XCircle,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import RichJsonRenderer from '@/modules/crm/components/RichJsonRenderer.vue';
import {
    extractFirstFieldErrors,
    projectsApi,
    ValidationRequestError,
} from '@/modules/projects/api/projectsApi';
import {
    handoffStatusLabel,
    handoffStatusVariant,
    projectHealthVariant,
    projectStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatDateTime, formatEnumLabel, formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';

export type ReviewableHandoff =
    | App.Modules.Projects.Handoffs.Data.DealHandoffDetailData
    | App.Modules.Projects.Handoffs.Data.DealHandoffSummaryData;

function isDetailHandoff(
    handoff: ReviewableHandoff,
): handoff is App.Modules.Projects.Handoffs.Data.DealHandoffDetailData {
    return 'scope_notes_json' in handoff;
}

const props = withDefaults(
    defineProps<{
        handoff: ReviewableHandoff | null;
        templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[];
        assignablePms: App.Modules.Core.Users.Data.UserSummaryData[];
        assignableAms?: App.Modules.Core.Users.Data.UserSummaryData[];
        canApprove?: boolean;
        canRequestChanges?: boolean;
        canReject?: boolean;
        canViewProject?: boolean;
        showFullDetailLink?: boolean;
    }>(),
    {
        assignableAms: () => [],
        canApprove: false,
        canRequestChanges: false,
        canReject: false,
        canViewProject: true,
        showFullDetailLink: true,
    },
);

const emit = defineEmits<{
    updated: [handoff: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData];
    refreshRequested: [];
}>();

const instanceId = useId();
const templateSelectId = `approve-template-${instanceId}`;
const pmSelectId = `approve-pm-${instanceId}`;
const amSelectId = `approve-am-${instanceId}`;
const projectNameId = `approve-project-name-${instanceId}`;
const kickoffDateId = `approve-kickoff-${instanceId}`;
const approvalNoteId = `approve-note-${instanceId}`;
const reviewModalFeedbackId = `review-modal-feedback-${instanceId}`;

const selectedTemplateId = ref<string>('');
const selectedPmId = ref<string>('');
const selectedAmId = ref<string>('');
const projectName = ref<string>('');
const targetKickoffDate = ref<string>('');
const approvalNote = ref<string>('');

const submittingApprove = ref<boolean>(false);
const actionError = ref<string>('');
const actionSuccess = ref<string>('');
const fieldErrors = ref<Record<string, string>>({});

// Request Changes / Reject Modal
const reviewModalMode = ref<'request_changes' | 'reject' | null>(null);
const reviewModalFeedback = ref<string>('');
const reviewModalError = ref<string>('');
const submittingDecision = ref<boolean>(false);

const dealTitle = computed(() => {
    if (!props.handoff) {
        return '';
    }
    return isDetailHandoff(props.handoff)
        ? (props.handoff.deal?.title ?? 'Deal Handoff')
        : (props.handoff.deal_title ?? 'Deal Handoff');
});

const companyName = computed(() => {
    if (!props.handoff) {
        return null;
    }
    return isDetailHandoff(props.handoff)
        ? (props.handoff.company?.name ?? null)
        : props.handoff.company_name;
});

const dealAmountCents = computed(() => {
    if (!props.handoff) {
        return null;
    }
    return isDetailHandoff(props.handoff)
        ? (props.handoff.deal?.amount_cents ?? null)
        : props.handoff.deal_amount_cents;
});

const dealCurrency = computed(() => {
    if (!props.handoff) {
        return 'USD';
    }
    return isDetailHandoff(props.handoff)
        ? (props.handoff.deal?.currency ?? 'USD')
        : (props.handoff.deal_currency ?? 'USD');
});

const canReviewPending = computed(
    () =>
        props.handoff !== null &&
        props.handoff.status === 'pending_review' &&
        (props.canApprove || props.canRequestChanges || props.canReject),
);

function hydrateForm(): void {
    actionError.value = '';
    actionSuccess.value = '';
    fieldErrors.value = {};
    approvalNote.value = '';

    if (!props.handoff) {
        return;
    }

    selectedTemplateId.value =
        props.handoff.template?.id ?? props.templates[0]?.id ?? '';
    selectedPmId.value =
        props.handoff.assigned_pm?.id ?? props.assignablePms[0]?.id ?? '';
    selectedAmId.value = props.handoff.assigned_am?.id ?? '';
    targetKickoffDate.value = props.handoff.target_kickoff_date ?? '';

    const defaultTitle = companyName.value
        ? `${companyName.value} — ${dealTitle.value}`
        : dealTitle.value;
    projectName.value = defaultTitle.slice(0, 200);
}

watch(
    () => props.handoff?.id,
    () => {
        hydrateForm();
    },
    { immediate: true },
);

async function handleApprove(): Promise<void> {
    if (!props.handoff || !props.canApprove || submittingApprove.value) {
        return;
    }

    actionError.value = '';
    actionSuccess.value = '';
    fieldErrors.value = {};

    const nextFieldErrors: Record<string, string> = {};
    if (!selectedTemplateId.value.trim()) {
        nextFieldErrors.project_template_id = 'Select a project template.';
    }
    if (!selectedPmId.value.trim()) {
        nextFieldErrors.assigned_pm_id = 'Assign a project manager.';
    }
    if (Object.keys(nextFieldErrors).length > 0) {
        fieldErrors.value = nextFieldErrors;
        actionError.value = 'Select both a project template and an assigned project manager before approving.';
        return;
    }

    submittingApprove.value = true;

    try {
        const updated = await projectsApi.approveHandoff(props.handoff.id, {
            project_template_id: selectedTemplateId.value,
            assigned_pm_id: selectedPmId.value,
            assigned_am_id: selectedAmId.value.trim() || null,
            project_name: projectName.value.trim() || null,
            target_kickoff_date: targetKickoffDate.value.trim() || null,
            review_feedback: approvalNote.value.trim() || null,
        });

        actionSuccess.value = 'Handoff approved and project provisioning queued.';
        emit('updated', updated);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            fieldErrors.value = extractFirstFieldErrors(error.errors);
            actionError.value = error.message;
        } else {
            actionError.value =
                error instanceof Error ? error.message : 'Unable to approve handoff.';
        }
    } finally {
        submittingApprove.value = false;
    }
}

function openDecisionModal(mode: 'request_changes' | 'reject'): void {
    reviewModalMode.value = mode;
    reviewModalFeedback.value = '';
    reviewModalError.value = '';
}

async function handleDecisionSubmit(): Promise<void> {
    if (!props.handoff || !reviewModalMode.value || submittingDecision.value) {
        return;
    }

    const trimmed = reviewModalFeedback.value.trim();
    if (!trimmed) {
        reviewModalError.value = 'Reviewer feedback is required.';
        return;
    }

    submittingDecision.value = true;
    reviewModalError.value = '';
    actionError.value = '';

    try {
        const updated =
            reviewModalMode.value === 'request_changes'
                ? await projectsApi.requestChanges(props.handoff.id, { review_feedback: trimmed })
                : await projectsApi.rejectHandoff(props.handoff.id, { review_feedback: trimmed });

        reviewModalMode.value = null;
        reviewModalFeedback.value = '';
        actionSuccess.value =
            updated.status === 'changes_requested'
                ? 'Changes requested from sales owner.'
                : 'Handoff rejected.';
        emit('updated', updated);
    } catch (error) {
        if (error instanceof ValidationRequestError) {
            const firstErr = extractFirstFieldErrors(error.errors);
            reviewModalError.value = firstErr.review_feedback ?? error.message;
        } else {
            reviewModalError.value =
                error instanceof Error ? error.message : 'Unable to save review decision.';
        }
    } finally {
        submittingDecision.value = false;
    }
}
</script>

<template>
    <aside
        data-testid="handoff-review-panel"
        aria-label="Handoff review panel"
        class="rounded-[8px] border border-default bg-surface"
    >
        <!-- Empty Selection State -->
        <div
            v-if="!props.handoff"
            data-testid="handoff-review-empty"
            class="px-5 py-12 text-center space-y-1.5"
        >
            <p class="text-[13px] font-medium text-primary">Select a handoff to inspect</p>
            <p class="text-[12px] text-secondary">
                Choose a row from the intake queue to review scope, assign a project manager, and provision delivery.
            </p>
        </div>

        <div v-else class="divide-y divide-default">
            <!-- Header -->
            <header class="px-4 py-3 space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 space-y-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-[14px] font-semibold text-primary">
                                {{ dealTitle }}
                            </h3>
                            <Badge :variant="handoffStatusVariant(props.handoff.status)">
                                {{ handoffStatusLabel(props.handoff.status) }}
                            </Badge>
                        </div>
                        <p class="truncate text-[12px] text-secondary">
                            {{ companyName ?? 'Client organization' }}
                            <template v-if="dealAmountCents !== null">
                                ·
                                <span class="font-mono font-medium tabular-nums text-primary">
                                    {{ formatMoneyCents(dealAmountCents, dealCurrency) }}
                                </span>
                            </template>
                        </p>
                    </div>

                    <Link
                        v-if="props.showFullDetailLink"
                        :href="`/app/projects/handoffs/${props.handoff.id}`"
                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-[6px] border border-default bg-subtle px-2 text-[11px] font-medium text-primary hover:bg-surface"
                    >
                        <span>Full Brief</span>
                        <ArrowUpRight class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                    </Link>
                </div>

                <!-- Key Metadata Strip -->
                <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 rounded-[6px] border border-default bg-subtle/50 px-3 py-2 text-[11px]">
                    <div>
                        <dt class="text-muted">Submitted By</dt>
                        <dd class="truncate font-medium text-primary">
                            {{ props.handoff.submitted_by?.name ?? 'Sales Rep' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Target Kickoff</dt>
                        <dd class="font-mono tabular-nums text-primary">
                            {{ formatShortDate(props.handoff.target_kickoff_date) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Account Manager</dt>
                        <dd class="truncate font-medium text-primary">
                            {{ props.handoff.assigned_am?.name ?? 'Unassigned' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Submitted At</dt>
                        <dd class="font-mono tabular-nums text-primary">
                            {{ formatDateTime(props.handoff.submitted_at) }}
                        </dd>
                    </div>
                </dl>
            </header>

            <!-- Provisioned Project or Queued Banner -->
            <div v-if="props.handoff.provisioned_project" class="px-4 py-3">
                <div
                    data-testid="handoff-provisioned-project-card"
                    class="flex items-center justify-between gap-3 rounded-[6px] border border-status-success/35 bg-status-success/10 px-3 py-2.5"
                >
                    <div class="min-w-0 space-y-0.5">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <FolderKanban class="h-3.5 w-3.5 text-status-success" :stroke-width="1.75" aria-hidden="true" />
                            <span class="font-mono text-[11px] font-semibold text-primary">
                                {{ props.handoff.provisioned_project.code }}
                            </span>
                            <Badge :variant="projectStatusVariant(props.handoff.provisioned_project.status)">
                                {{ formatEnumLabel(props.handoff.provisioned_project.status) }}
                            </Badge>
                            <Badge :variant="projectHealthVariant(props.handoff.provisioned_project.health)">
                                {{ formatEnumLabel(props.handoff.provisioned_project.health) }}
                            </Badge>
                        </div>
                        <p class="truncate text-[12px] font-medium text-primary">
                            {{ props.handoff.provisioned_project.name }}
                        </p>
                    </div>
                    <Link
                        v-if="props.canViewProject"
                        :href="`/app/projects/${props.handoff.provisioned_project.id}`"
                        class="inline-flex h-7 shrink-0 items-center rounded-[6px] bg-accent-primary px-2.5 text-[12px] font-medium text-accent-contrast hover:bg-accent-hover"
                    >
                        Open Project
                    </Link>
                </div>
            </div>

            <div v-else-if="props.handoff.status === 'approved'" class="px-4 py-3">
                <div class="flex items-center justify-between gap-2 rounded-[6px] border border-accent-primary/30 bg-accent-primary/10 px-3 py-2.5 text-[12px]">
                    <div class="flex items-center gap-2 text-primary">
                        <Clock class="h-4 w-4 shrink-0 text-accent-primary" :stroke-width="1.75" aria-hidden="true" />
                        <span>Approval recorded — project provisioning job queued.</span>
                    </div>
                    <Button variant="secondary" size="sm" @click="emit('refreshRequested')">
                        <RefreshCw class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                        <span>Refresh</span>
                    </Button>
                </div>
            </div>

            <!-- Scope & Checklist (when Detail DTO loaded) -->
            <div v-if="isDetailHandoff(props.handoff)" class="px-4 py-3 space-y-3">
                <div class="space-y-1.5">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-secondary">
                        Delivery Scope Notes
                    </h4>
                    <div class="rounded-[6px] border border-default bg-subtle/40 px-3 py-2.5">
                        <RichJsonRenderer :document="props.handoff.scope_notes_json" />
                    </div>
                </div>

                <div v-if="props.handoff.handoff_checklist.length > 0" class="space-y-1.5">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-secondary">
                        Handoff Checklist
                    </h4>
                    <ul class="divide-y divide-default rounded-[6px] border border-default">
                        <li
                            v-for="(item, idx) in props.handoff.handoff_checklist"
                            :key="`${item.label}-${idx}`"
                            class="flex items-center gap-2 px-3 py-1.5 text-[12px]"
                        >
                            <CheckSquare
                                v-if="item.checked"
                                class="h-3.5 w-3.5 shrink-0 text-status-success"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <Square
                                v-else
                                class="h-3.5 w-3.5 shrink-0 text-muted"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span :class="item.checked ? 'text-primary' : 'text-secondary'">
                                {{ item.label }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Existing Review Feedback -->
            <div v-if="props.handoff.review_feedback" class="px-4 py-3">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-secondary">
                    Reviewer Feedback
                </p>
                <p class="rounded-[6px] border border-default bg-subtle px-3 py-2 text-[12px] text-primary">
                    {{ props.handoff.review_feedback }}
                </p>
            </div>

            <!-- Action Alerts -->
            <div v-if="actionError || actionSuccess" class="px-4 py-3 space-y-2">
                <div
                    v-if="actionError"
                    role="alert"
                    data-testid="handoff-review-error"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ actionError }}</span>
                </div>
                <div
                    v-if="actionSuccess"
                    role="status"
                    class="flex items-center gap-2 rounded-[6px] border border-status-success/35 bg-status-success/10 px-3 py-2 text-[12px] text-status-success"
                >
                    <CheckCircle2 class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ actionSuccess }}</span>
                </div>
            </div>

            <!-- Approve & Provision + Review Decision Controls -->
            <div v-if="canReviewPending" class="px-4 py-3.5 space-y-3">
                <form
                    v-if="props.canApprove"
                    data-testid="handoff-approve-form"
                    class="space-y-3"
                    @submit.prevent="handleApprove"
                >
                    <h4 class="text-[12px] font-semibold text-primary">
                        Approve &amp; Provision Project
                    </h4>

                    <div>
                        <label :for="templateSelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                            Project Template <span class="text-status-danger">*</span>
                        </label>
                        <select
                            :id="templateSelectId"
                            v-model="selectedTemplateId"
                            data-testid="handoff-approve-template"
                            :disabled="submittingApprove"
                            :aria-invalid="fieldErrors.project_template_id ? 'true' : undefined"
                            :class="[
                                'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50',
                                fieldErrors.project_template_id ? 'border-status-danger' : 'border-default',
                            ]"
                        >
                            <option value="">Select template...</option>
                            <option
                                v-for="tpl in props.templates"
                                :key="tpl.id"
                                :value="tpl.id"
                            >
                                {{ tpl.name }} ({{ tpl.tasks_count }} tasks)
                            </option>
                        </select>
                        <p
                            v-if="fieldErrors.project_template_id"
                            role="alert"
                            class="mt-1 text-[12px] text-status-danger"
                        >
                            {{ fieldErrors.project_template_id }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <div>
                            <label :for="pmSelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Project Manager <span class="text-status-danger">*</span>
                            </label>
                            <select
                                :id="pmSelectId"
                                v-model="selectedPmId"
                                data-testid="handoff-approve-pm"
                                :disabled="submittingApprove"
                                :aria-invalid="fieldErrors.assigned_pm_id ? 'true' : undefined"
                                :class="[
                                    'h-8 w-full rounded-[6px] border bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50',
                                    fieldErrors.assigned_pm_id ? 'border-status-danger' : 'border-default',
                                ]"
                            >
                                <option value="">Assign PM...</option>
                                <option
                                    v-for="pm in props.assignablePms"
                                    :key="pm.id"
                                    :value="pm.id"
                                >
                                    {{ pm.name }}
                                </option>
                            </select>
                            <p
                                v-if="fieldErrors.assigned_pm_id"
                                role="alert"
                                class="mt-1 text-[12px] text-status-danger"
                            >
                                {{ fieldErrors.assigned_pm_id }}
                            </p>
                        </div>

                        <div>
                            <label :for="amSelectId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Account Manager
                            </label>
                            <select
                                :id="amSelectId"
                                v-model="selectedAmId"
                                data-testid="handoff-approve-am"
                                :disabled="submittingApprove"
                                class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="am in props.assignableAms"
                                    :key="am.id"
                                    :value="am.id"
                                >
                                    {{ am.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <div>
                            <label :for="projectNameId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Project Name
                            </label>
                            <Input
                                :id="projectNameId"
                                v-model="projectName"
                                :disabled="submittingApprove"
                                :error-message="fieldErrors.project_name ?? ''"
                            />
                        </div>
                        <div>
                            <label :for="kickoffDateId" class="mb-1 block text-[12px] font-medium text-secondary">
                                Target Kickoff
                            </label>
                            <Input
                                :id="kickoffDateId"
                                v-model="targetKickoffDate"
                                type="date"
                                class="font-mono tabular-nums"
                                :disabled="submittingApprove"
                                :error-message="fieldErrors.target_kickoff_date ?? ''"
                            />
                        </div>
                    </div>

                    <div>
                        <label :for="approvalNoteId" class="mb-1 block text-[12px] font-medium text-secondary">
                            Approval Note (optional)
                        </label>
                        <textarea
                            :id="approvalNoteId"
                            v-model="approvalNote"
                            rows="2"
                            :disabled="submittingApprove"
                            placeholder="Optional provisioning instructions..."
                            class="w-full rounded-[6px] border border-default bg-surface px-2.5 py-1.5 text-[13px] text-primary placeholder:text-muted focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                        />
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        class="w-full"
                        :loading="submittingApprove"
                        data-testid="handoff-approve-submit"
                    >
                        Approve &amp; Provision
                    </Button>
                </form>

                <!-- Secondary Decision Buttons -->
                <div
                    v-if="props.canRequestChanges || props.canReject"
                    class="flex flex-wrap items-center gap-2 border-t border-default pt-3"
                >
                    <Button
                        v-if="props.canRequestChanges"
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="flex-1"
                        :disabled="submittingApprove || submittingDecision"
                        data-testid="handoff-open-request-changes"
                        @click="openDecisionModal('request_changes')"
                    >
                        <MessageSquareWarning class="h-3.5 w-3.5 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                        <span>Request Changes</span>
                    </Button>
                    <Button
                        v-if="props.canReject"
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="flex-1 text-status-danger"
                        :disabled="submittingApprove || submittingDecision"
                        data-testid="handoff-open-reject"
                        @click="openDecisionModal('reject')"
                    >
                        <XCircle class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Reject</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Request Changes / Reject Feedback Dialog -->
        <Dialog
            :open="reviewModalMode !== null"
            :title="reviewModalMode === 'reject' ? 'Reject Deal Handoff' : 'Request Handoff Changes'"
            :description="
                reviewModalMode === 'reject'
                    ? 'Provide a clear reason for rejecting this handoff. Sales can submit a new handoff later if needed.'
                    : 'Explain what scope details or documentation the sales owner needs to update before approval.'
            "
            max-width-class="max-w-md"
            @update:open="!$event ? (reviewModalMode = null) : undefined"
        >
            <form
                data-testid="handoff-decision-form"
                class="space-y-3"
                @submit.prevent="handleDecisionSubmit"
            >
                <div
                    v-if="reviewModalError"
                    role="alert"
                    data-testid="handoff-decision-error"
                    class="flex items-start gap-2 rounded-[6px] border border-status-danger/35 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
                >
                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ reviewModalError }}</span>
                </div>

                <div>
                    <label
                        :for="reviewModalFeedbackId"
                        class="mb-1 block text-[12px] font-medium text-secondary"
                    >
                        Review Feedback <span class="text-status-danger">*</span>
                    </label>
                    <textarea
                        :id="reviewModalFeedbackId"
                        v-model="reviewModalFeedback"
                        rows="3"
                        :disabled="submittingDecision"
                        :aria-invalid="reviewModalError ? 'true' : undefined"
                        placeholder="Specify missing SOW terms, capacity constraints, or kickoff clarifications..."
                        :class="[
                            'w-full rounded-[6px] border bg-surface px-2.5 py-1.5 text-[13px] text-primary placeholder:text-muted focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50',
                            reviewModalError ? 'border-status-danger' : 'border-default',
                        ]"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="submittingDecision"
                        @click="reviewModalMode = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :variant="reviewModalMode === 'reject' ? 'danger' : 'primary'"
                        size="sm"
                        :loading="submittingDecision"
                        data-testid="handoff-decision-submit"
                    >
                        {{ reviewModalMode === 'reject' ? 'Confirm Reject' : 'Send Feedback' }}
                    </Button>
                </div>
            </form>
        </Dialog>
    </aside>
</template>
