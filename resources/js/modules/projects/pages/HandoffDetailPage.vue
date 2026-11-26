<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    CheckSquare,
    ChevronRight,
    Edit3,
    RefreshCw,
    Square,
    WifiOff,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import RichJsonRenderer from '@/modules/crm/components/RichJsonRenderer.vue';
import HandoffReviewPanel from '@/modules/projects/components/HandoffReviewPanel.vue';
import HandoffSubmitDrawer from '@/modules/projects/components/HandoffSubmitDrawer.vue';
import {
    handoffStatusLabel,
    handoffStatusVariant,
} from '@/modules/projects/utils/projectFormatters';
import { formatDateTime, formatMoneyCents, formatShortDate } from '@/modules/crm/utils/formatters';
import { useRealtimeStore } from '@/stores/realtime.store';

const props = withDefaults(
    defineProps<{
        handoff: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData;
        templates: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData[];
        assignablePms: App.Modules.Core.Users.Data.UserSummaryData[];
        assignableAms: App.Modules.Core.Users.Data.UserSummaryData[];
        can: {
            resubmit: boolean;
            requestChanges: boolean;
            reject: boolean;
            approve: boolean;
            viewProject: boolean;
        };
    }>(),
    {},
);

const realtimeStore = useRealtimeStore();
const localHandoff = ref<App.Modules.Projects.Handoffs.Data.DealHandoffDetailData>(props.handoff);
const resubmitDrawerOpen = ref<boolean>(false);
const refreshing = ref<boolean>(false);
const pageError = ref<string>('');

watch(
    () => props.handoff,
    (next) => {
        localHandoff.value = next;
    },
    { deep: true },
);

const dealTarget = computed(() => ({
    id: localHandoff.value.deal_id,
    title: localHandoff.value.deal?.title ?? 'Deal Handoff',
    amount_cents: localHandoff.value.deal?.amount_cents ?? null,
    currency: localHandoff.value.deal?.currency ?? 'USD',
    company_name: localHandoff.value.company?.name ?? null,
    account_manager_id: localHandoff.value.assigned_am?.id ?? null,
}));

function refreshFromServer(): void {
    refreshing.value = true;
    pageError.value = '';
    router.reload({
        onError: () => {
            pageError.value = 'Unable to refresh handoff details.';
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function handleUpdated(updated: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData): void {
    localHandoff.value = updated;
    if (typeof router?.reload === 'function') {
        router.reload({ only: ['unreadCounts'] });
    }
}
</script>

<template>
    <Head :title="`Handoff — ${localHandoff.deal?.title ?? 'Deal Brief'}`" />

    <div class="space-y-4">
        <!-- Offline / Reconnecting Banner -->
        <div
            v-if="realtimeStore.status === 'disconnected' && realtimeStore.lastError"
            role="status"
            data-testid="handoff-detail-offline-banner"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2 text-[12px] text-primary"
        >
            <div class="flex items-center gap-2">
                <WifiOff class="h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <span>Realtime connection unavailable. Use Sync to refresh updates.</span>
            </div>
            <Button variant="secondary" size="sm" @click="refreshFromServer">
                Sync
            </Button>
        </div>

        <!-- Header -->
        <header class="flex flex-col gap-3 rounded-[8px] border border-default bg-surface px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1 text-[12px] text-secondary">
                    <Link href="/app/projects/handoffs" class="hover:text-primary">
                        Handoff Intake
                    </Link>
                    <ChevronRight class="h-3.5 w-3.5 text-muted" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate font-medium text-primary">
                        {{ localHandoff.deal?.title ?? 'Deal Handoff' }}
                    </span>
                </nav>

                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ localHandoff.deal?.title ?? 'Deal Handoff Brief' }}
                    </h2>
                    <Badge :variant="handoffStatusVariant(localHandoff.status)">
                        {{ handoffStatusLabel(localHandoff.status) }}
                    </Badge>
                    <span
                        v-if="localHandoff.deal"
                        class="font-mono text-[14px] font-semibold tabular-nums text-primary"
                    >
                        {{ formatMoneyCents(localHandoff.deal.amount_cents, localHandoff.deal.currency) }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap shrink-0 items-center gap-2">
                <Link
                    :href="`/app/crm/deals/${localHandoff.deal_id}`"
                    class="inline-flex h-8 items-center rounded-[6px] border border-default bg-surface px-3 text-[12px] font-medium text-primary hover:bg-subtle"
                >
                    View Won Deal
                </Link>

                <Button
                    v-if="localHandoff.status === 'changes_requested' && props.can.resubmit"
                    variant="primary"
                    size="sm"
                    data-testid="handoff-open-resubmit"
                    @click="resubmitDrawerOpen = true"
                >
                    <Edit3 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Edit &amp; Resubmit</span>
                </Button>

                <Button
                    variant="ghost"
                    size="sm"
                    :loading="refreshing"
                    @click="refreshFromServer"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Sync</span>
                </Button>
            </div>
        </header>

        <div
            v-if="pageError"
            role="alert"
            class="flex items-center gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2 text-[12px] text-status-danger"
        >
            <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ pageError }}</span>
        </div>

        <!-- Changes Requested Banner -->
        <div
            v-if="localHandoff.status === 'changes_requested' && localHandoff.review_feedback"
            role="status"
            class="flex flex-col gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-4 py-3 text-[13px] text-primary sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Changes requested before provisioning</p>
                    <p class="text-[12px] text-secondary">{{ localHandoff.review_feedback }}</p>
                </div>
            </div>
            <Button
                v-if="props.can.resubmit"
                variant="secondary"
                size="sm"
                @click="resubmitDrawerOpen = true"
            >
                Update &amp; Resubmit
            </Button>
        </div>

        <!-- 2-Column Brief + Review Panel -->
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start">
            <div class="min-w-0 flex-1 space-y-4">
                <!-- Context Summary -->
                <section class="rounded-[8px] border border-default bg-surface p-4 space-y-3">
                    <h3 class="text-[13px] font-semibold text-primary">Commercial Context</h3>
                    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-[12px]">
                        <div>
                            <dt class="text-muted">Account</dt>
                            <dd class="font-medium text-primary">
                                <Link
                                    v-if="localHandoff.company"
                                    :href="`/app/crm/companies/${localHandoff.company.id}`"
                                    class="text-accent-primary hover:underline"
                                >
                                    {{ localHandoff.company.name }}
                                </Link>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Target Kickoff</dt>
                            <dd class="font-mono tabular-nums text-primary">
                                {{ formatShortDate(localHandoff.target_kickoff_date) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">Submitted</dt>
                            <dd class="font-mono tabular-nums text-primary">
                                {{ formatDateTime(localHandoff.submitted_at) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Scope Notes -->
                <section class="rounded-[8px] border border-default bg-surface p-4 space-y-2.5">
                    <h3 class="text-[13px] font-semibold text-primary">
                        Delivery Scope &amp; Technical Notes
                    </h3>
                    <div class="rounded-[6px] border border-default bg-subtle/30 p-3">
                        <RichJsonRenderer :document="localHandoff.scope_notes_json" />
                    </div>
                </section>

                <!-- Checklist -->
                <section class="rounded-[8px] border border-default bg-surface p-4 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-[13px] font-semibold text-primary">Handoff Checklist</h3>
                        <span class="font-mono text-[11px] tabular-nums text-muted">
                            {{ localHandoff.handoff_checklist.filter((i) => i.checked).length }} /
                            {{ localHandoff.handoff_checklist.length }}
                        </span>
                    </div>

                    <p
                        v-if="localHandoff.handoff_checklist.length === 0"
                        class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted"
                    >
                        No checklist items recorded on this handoff.
                    </p>

                    <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
                        <li
                            v-for="(item, idx) in localHandoff.handoff_checklist"
                            :key="`${item.label}-${idx}`"
                            class="flex items-center gap-2.5 px-3 py-2 text-[13px]"
                        >
                            <CheckSquare
                                v-if="item.checked"
                                class="h-4 w-4 shrink-0 text-status-success"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <Square
                                v-else
                                class="h-4 w-4 shrink-0 text-muted"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span :class="item.checked ? 'text-primary' : 'text-secondary'">
                                {{ item.label }}
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="w-full shrink-0 xl:w-[440px]">
                <HandoffReviewPanel
                    :handoff="localHandoff"
                    :templates="props.templates"
                    :assignable-pms="props.assignablePms"
                    :assignable-ams="props.assignableAms"
                    :can-approve="props.can.approve"
                    :can-request-changes="props.can.requestChanges"
                    :can-reject="props.can.reject"
                    :can-view-project="props.can.viewProject"
                    :show-full-detail-link="false"
                    @updated="handleUpdated"
                    @refresh-requested="refreshFromServer"
                />
            </div>
        </div>

        <!-- Resubmit Drawer -->
        <HandoffSubmitDrawer
            v-if="props.can.resubmit"
            :open="resubmitDrawerOpen"
            :deal="dealTarget"
            :existing-handoff="localHandoff"
            :assignable-ams="props.assignableAms"
            @update:open="resubmitDrawerOpen = $event"
            @submitted="handleUpdated"
        />
    </div>
</template>
