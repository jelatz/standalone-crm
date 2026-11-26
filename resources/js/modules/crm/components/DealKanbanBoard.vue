<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertCircle, AlertTriangle, Calendar, RefreshCw } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import StageGateModal, { type StageGateSubmitPayload } from './StageGateModal.vue';
import { getEcho, type RealtimeChannelLike } from '@/echo';
import { useAuthStore } from '@/stores/auth.store';
import {
    crmApi,
    extractFirstFieldErrors,
    OptimisticLockConflictError,
    StageGateValidationError,
    ValidationRequestError,
} from '@/modules/crm/api/crmApi';
import {
    formatMoneyCents,
    formatShortDate,
    getInitials,
} from '@/modules/crm/utils/formatters';

const props = withDefaults(
    defineProps<{
        board: App.Modules.Crm.Deals.Data.DealKanbanBoardData;
        customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
        assignableUsers?: App.Modules.Core.Users.Data.UserSummaryData[];
        contacts?: Array<{
            id: string;
            full_name: string;
            primary_email: string | null;
            company_id: string | null;
        }>;
        canMoveDeals?: boolean;
        canOverrideGates?: boolean;
    }>(),
    {
        assignableUsers: () => [],
        contacts: () => [],
        canMoveDeals: true,
        canOverrideGates: false,
    },
);

const emit = defineEmits<{
    dealMoved: [deal: App.Modules.Crm.Deals.Data.DealDetailData];
}>();

const authStore = useAuthStore();

type ColumnData = App.Modules.Crm.Deals.Data.DealKanbanStageColumnData;
type DealCard = App.Modules.Crm.Deals.Data.DealCardData;

function cloneColumns(source: ColumnData[]): ColumnData[] {
    return source.map((col) => ({
        stage: { ...col.stage },
        deals_count: col.deals_count,
        total_amount_cents: col.total_amount_cents,
        deals: col.deals.map((deal) => ({
            ...deal,
            custom_fields: { ...(deal.custom_fields ?? {}) },
        })),
    }));
}

function recalculateColumnMetrics(columns: ColumnData[]): ColumnData[] {
    return columns.map((col) => ({
        ...col,
        deals_count: col.deals.length,
        total_amount_cents: col.deals.reduce((sum, item) => sum + (item.amount_cents ?? 0), 0),
    }));
}

const localColumns = ref<ColumnData[]>(cloneColumns(props.board.columns));

watch(
    () => props.board,
    (nextBoard) => {
        localColumns.value = cloneColumns(nextBoard.columns);
    },
    { deep: true },
);

const stages = computed(() => localColumns.value.map((col) => col.stage));
const movingDealId = ref<string | null>(null);
const draggedDealId = ref<string | null>(null);
const dragOverStageId = ref<string | null>(null);
const conflictMessage = ref<string>('');
const boardError = ref<string>('');
const refreshingBoard = ref<boolean>(false);

interface PendingGateMove {
    deal: DealCard;
    targetStage: App.Modules.Crm.Pipelines.Data.PipelineStageData;
    beforeDealId: string | null;
    afterDealId: string | null;
    missingCoreFields: string[];
    missingCustomFields: string[];
    errors: Record<string, string>;
    errorMessage: string;
}

const pendingGateMove = ref<PendingGateMove | null>(null);
const gateSubmitting = ref<boolean>(false);

const boardSummary = computed(() => {
    let totalDeals = 0;
    let totalCents = 0;
    let weightedCents = 0;
    let primaryCurrency = 'USD';

    for (const col of localColumns.value) {
        totalDeals += col.deals_count;
        totalCents += col.total_amount_cents;
        const probability = Number(col.stage.win_probability ?? 0) / 100;
        weightedCents += Math.round(col.total_amount_cents * probability);
        if (col.deals[0]?.currency) {
            primaryCurrency = col.deals[0].currency;
        }
    }

    return {
        totalDeals,
        totalCents,
        weightedCents,
        currency: primaryCurrency,
    };
});

function stageTypeVariant(stageType: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum): BadgeVariant {
    if (stageType === 'won') {
        return 'success';
    }
    if (stageType === 'lost') {
        return 'danger';
    }
    return 'neutral';
}

function columnWeightedCents(col: ColumnData): number {
    const probability = Number(col.stage.win_probability ?? 0) / 100;
    return Math.round(col.total_amount_cents * probability);
}

function findDealInBoard(dealId: string): DealCard | null {
    for (const col of localColumns.value) {
        const found = col.deals.find((d) => d.id === dealId);
        if (found) {
            return found;
        }
    }
    return null;
}

function applyDealMoveInColumns(
    columns: ColumnData[],
    dealId: string,
    targetStage: App.Modules.Crm.Pipelines.Data.PipelineStageData,
    updatedDeal?: App.Modules.Crm.Deals.Data.DealDetailData,
    insertIndex?: number,
): ColumnData[] {
    let movingCard: DealCard | null = null;
    const stripped = columns.map((col) => {
        const idx = col.deals.findIndex((item) => item.id === dealId);
        if (idx !== -1) {
            movingCard = { ...col.deals[idx] };
        }
        return {
            ...col,
            deals: col.deals.filter((item) => item.id !== dealId),
        };
    });

    if (!movingCard) {
        return columns;
    }

    const card: DealCard = movingCard;
    const nextCard: DealCard = updatedDeal
        ? {
              ...card,
              company_id: updatedDeal.company_id,
              company_name: updatedDeal.company_name,
              primary_contact_id: updatedDeal.primary_contact_id,
              primary_contact_name: updatedDeal.primary_contact_name,
              pipeline_id: updatedDeal.pipeline_id,
              stage_id: updatedDeal.stage_id,
              stage_name: updatedDeal.stage_name,
              stage_type: updatedDeal.stage_type,
              owner_id: updatedDeal.owner_id,
              owner_name: updatedDeal.owner_name,
              account_manager_id: updatedDeal.account_manager_id,
              account_manager_name: updatedDeal.account_manager_name,
              title: updatedDeal.title,
              amount_cents: updatedDeal.amount_cents,
              currency: updatedDeal.currency,
              expected_close_date: updatedDeal.expected_close_date,
              stage_entered_at: updatedDeal.stage_entered_at,
              won_at: updatedDeal.won_at,
              lost_at: updatedDeal.lost_at,
              board_position: updatedDeal.board_position,
              version: updatedDeal.version,
              is_rotting: false,
              custom_fields: { ...(updatedDeal.custom_fields ?? {}) },
              updated_at: updatedDeal.updated_at,
          }
        : {
              ...card,
              stage_id: targetStage.id,
              stage_name: targetStage.name,
              stage_type: targetStage.stage_type,
              is_rotting: false,
          };

    const placed = stripped.map((col) => {
        if (col.stage.id !== targetStage.id) {
            return col;
        }
        const nextDeals = [...col.deals];
        if (insertIndex !== undefined && insertIndex >= 0 && insertIndex <= nextDeals.length) {
            nextDeals.splice(insertIndex, 0, nextCard);
        } else {
            nextDeals.push(nextCard);
        }
        return {
            ...col,
            deals: nextDeals,
        };
    });

    return recalculateColumnMetrics(placed);
}

async function moveDeal(
    deal: DealCard,
    targetStageId: string,
    neighbors: { beforeDealId?: string | null; afterDealId?: string | null; insertIndex?: number } = {},
): Promise<void> {
    if (!props.canMoveDeals || movingDealId.value !== null) {
        return;
    }

    const targetStage = stages.value.find((s) => s.id === targetStageId);
    if (!targetStage) {
        return;
    }

    const beforeDealId =
        neighbors.beforeDealId && neighbors.beforeDealId !== deal.id ? neighbors.beforeDealId : null;
    const afterDealId =
        neighbors.afterDealId &&
        neighbors.afterDealId !== deal.id &&
        neighbors.afterDealId !== beforeDealId
            ? neighbors.afterDealId
            : null;

    if (deal.stage_id === targetStage.id && beforeDealId === null && afterDealId === null) {
        return;
    }

    conflictMessage.value = '';
    boardError.value = '';

    const snapshot = cloneColumns(localColumns.value);
    localColumns.value = applyDealMoveInColumns(
        localColumns.value,
        deal.id,
        targetStage,
        undefined,
        neighbors.insertIndex,
    );
    movingDealId.value = deal.id;

    try {
        const updated = await crmApi.moveDealStage(deal.id, {
            target_stage_id: targetStage.id,
            expected_version: deal.version,
            before_deal_id: beforeDealId,
            after_deal_id: afterDealId,
            core_field_updates: {},
            custom_field_updates: {},
            override_gates: false,
        });

        localColumns.value = applyDealMoveInColumns(
            localColumns.value,
            deal.id,
            targetStage,
            updated,
            neighbors.insertIndex,
        );
        emit('dealMoved', updated);
    } catch (error) {
        // Always rollback optimistic state on failure
        localColumns.value = snapshot;

        if (error instanceof OptimisticLockConflictError) {
            conflictMessage.value = error.message;
        } else if (error instanceof StageGateValidationError) {
            pendingGateMove.value = {
                deal,
                targetStage,
                beforeDealId,
                afterDealId,
                missingCoreFields: error.missingCoreFields,
                missingCustomFields: error.missingCustomFields,
                errors: extractFirstFieldErrors(error.errors),
                errorMessage: error.message,
            };
        } else if (error instanceof Error) {
            boardError.value = error.message;
        } else {
            boardError.value = 'Unable to move deal to the selected stage.';
        }
    } finally {
        movingDealId.value = null;
    }
}

async function handleGateSubmit(payload: StageGateSubmitPayload): Promise<void> {
    const pending = pendingGateMove.value;
    if (!pending || gateSubmitting.value) {
        return;
    }

    gateSubmitting.value = true;
    pending.errors = {};
    pending.errorMessage = '';

    try {
        const updated = await crmApi.moveDealStage(pending.deal.id, {
            target_stage_id: pending.targetStage.id,
            expected_version: pending.deal.version,
            before_deal_id: pending.beforeDealId,
            after_deal_id: pending.afterDealId,
            core_field_updates: payload.core_field_updates,
            custom_field_updates: payload.custom_field_updates,
            override_gates: payload.override_gates,
        });

        localColumns.value = applyDealMoveInColumns(
            localColumns.value,
            pending.deal.id,
            pending.targetStage,
            updated,
        );
        pendingGateMove.value = null;
        emit('dealMoved', updated);
    } catch (error) {
        if (error instanceof StageGateValidationError) {
            pending.missingCoreFields = error.missingCoreFields;
            pending.missingCustomFields = error.missingCustomFields;
            pending.errors = extractFirstFieldErrors(error.errors);
            pending.errorMessage = error.message;
        } else if (error instanceof ValidationRequestError) {
            pending.errors = extractFirstFieldErrors(error.errors);
            pending.errorMessage = error.message;
        } else if (error instanceof OptimisticLockConflictError) {
            pendingGateMove.value = null;
            conflictMessage.value = error.message;
        } else if (error instanceof Error) {
            pending.errorMessage = error.message;
        } else {
            pending.errorMessage = 'Failed to save required stage gate fields.';
        }
    } finally {
        gateSubmitting.value = false;
    }
}

function handleStageSelectChange(deal: DealCard, event: Event): void {
    const target = event.target as HTMLSelectElement;
    const nextStageId = target.value;
    if (nextStageId && nextStageId !== deal.stage_id) {
        void moveDeal(deal, nextStageId);
    }
}

function onDragStart(deal: DealCard, event: DragEvent): void {
    if (!props.canMoveDeals) {
        event.preventDefault();
        return;
    }
    draggedDealId.value = deal.id;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', deal.id);
    }
}

function onDragEnd(): void {
    draggedDealId.value = null;
    dragOverStageId.value = null;
}

function onColumnDragOver(stageId: string, event: DragEvent): void {
    if (!props.canMoveDeals) {
        return;
    }
    event.preventDefault();
    dragOverStageId.value = stageId;
}

function onColumnDrop(column: ColumnData, event: DragEvent): void {
    event.preventDefault();
    const dealId = draggedDealId.value || event.dataTransfer?.getData('text/plain') || '';
    draggedDealId.value = null;
    dragOverStageId.value = null;

    if (!dealId) {
        return;
    }

    const deal = findDealInBoard(dealId);
    if (!deal) {
        return;
    }

    const filteredDeals = column.deals.filter((d) => d.id !== deal.id);
    const lastDeal = filteredDeals[filteredDeals.length - 1] ?? null;
    void moveDeal(deal, column.stage.id, {
        beforeDealId: lastDeal?.id ?? null,
        afterDealId: null,
        insertIndex: filteredDeals.length,
    });
}

function onCardDrop(column: ColumnData, targetCard: DealCard, targetIndex: number, event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    const dealId = draggedDealId.value || event.dataTransfer?.getData('text/plain') || '';
    draggedDealId.value = null;
    dragOverStageId.value = null;

    if (!dealId || dealId === targetCard.id) {
        return;
    }

    const deal = findDealInBoard(dealId);
    if (!deal) {
        return;
    }

    const filteredDeals = column.deals.filter((d) => d.id !== deal.id);
    const adjustedIndex = filteredDeals.findIndex((d) => d.id === targetCard.id);
    const prevCard = adjustedIndex > 0 ? filteredDeals[adjustedIndex - 1] : null;

    void moveDeal(deal, column.stage.id, {
        beforeDealId: prevCard?.id ?? null,
        afterDealId: targetCard.id,
        insertIndex: adjustedIndex >= 0 ? adjustedIndex : targetIndex,
    });
}

function refreshBoardFromServer(): void {
    refreshingBoard.value = true;
    router.reload({
        only: ['board', 'tableDeals'],
        onFinish: () => {
            refreshingBoard.value = false;
            conflictMessage.value = '';
            boardError.value = '';
        },
    });
}

const BOARD_INVALIDATED_EVENT = '.App\\Modules\\Crm\\Deals\\Events\\SiloBoardInvalidated';
let reloadTimer: ReturnType<typeof setTimeout> | null = null;
let unmounted = false;
let subscribedChannel: RealtimeChannelLike | null = null;

function handleBoardInvalidated(payload: unknown): void {
    if (unmounted) {
        return;
    }

    if (payload && typeof payload === 'object') {
        const data = payload as { pipeline_id?: string };
        if (data.pipeline_id && data.pipeline_id !== props.board.pipeline.id) {
            return;
        }
    }

    if (reloadTimer !== null) {
        clearTimeout(reloadTimer);
    }

    reloadTimer = setTimeout(() => {
        if (!unmounted && movingDealId.value === null) {
            router.reload({ only: ['board', 'tableDeals'] });
        }
    }, 250);
}

onMounted(() => {
    const userId = authStore.user?.id;
    const echo = getEcho();
    if (echo && userId) {
        const channel = echo.private(`user.${userId}`);
        channel.listen(BOARD_INVALIDATED_EVENT, handleBoardInvalidated);
        subscribedChannel = channel;
    }
});

onUnmounted(() => {
    unmounted = true;
    if (subscribedChannel !== null) {
        subscribedChannel.stopListening(BOARD_INVALIDATED_EVENT, handleBoardInvalidated);
        subscribedChannel = null;
    }
    if (reloadTimer !== null) {
        clearTimeout(reloadTimer);
        reloadTimer = null;
    }
});
</script>

<template>
    <div data-testid="deal-kanban-board" class="space-y-3">
        <!-- Board Summary Strip -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 rounded-[8px] border border-default bg-surface px-3.5 py-2 text-[12px]"
        >
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-1.5">
                    <span class="text-secondary">Deals</span>
                    <span class="font-mono font-semibold tabular-nums text-primary">
                        {{ boardSummary.totalDeals }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-secondary">Pipeline Total</span>
                    <span class="font-mono font-semibold tabular-nums text-primary">
                        {{ formatMoneyCents(boardSummary.totalCents, boardSummary.currency) }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-secondary">Weighted</span>
                    <span class="font-mono font-semibold tabular-nums text-primary">
                        {{ formatMoneyCents(boardSummary.weightedCents, boardSummary.currency) }}
                    </span>
                </div>
            </div>

            <Button
                variant="ghost"
                size="sm"
                :loading="refreshingBoard"
                @click="refreshBoardFromServer"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Sync Board</span>
            </Button>
        </div>

        <!-- 409 Conflict Banner -->
        <div
            v-if="conflictMessage"
            role="alert"
            data-testid="kanban-conflict-banner"
            class="flex flex-col gap-2 rounded-[8px] border border-status-warning/40 bg-status-warning/10 px-3.5 py-2.5 text-[13px] text-primary sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-status-warning" :stroke-width="1.75" aria-hidden="true" />
                <div>
                    <p class="font-semibold">Concurrent update detected (409 Conflict)</p>
                    <p class="text-[12px] text-secondary">{{ conflictMessage }}</p>
                </div>
            </div>
            <Button
                variant="secondary"
                size="sm"
                :loading="refreshingBoard"
                data-testid="kanban-conflict-refresh"
                @click="refreshBoardFromServer"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Refresh board</span>
            </Button>
        </div>

        <!-- General Board Error Alert -->
        <div
            v-if="boardError"
            role="alert"
            data-testid="kanban-error-banner"
            class="flex items-center justify-between gap-2 rounded-[8px] border border-status-danger/35 bg-status-danger/10 px-3.5 py-2 text-[12px] text-status-danger"
        >
            <div class="flex items-center gap-2">
                <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ boardError }}</span>
            </div>
            <button
                type="button"
                class="text-[11px] font-medium underline cursor-pointer"
                @click="boardError = ''"
            >
                Dismiss
            </button>
        </div>

        <!-- Multi-Column Hairline Kanban Grid -->
        <div class="overflow-x-auto pb-2">
            <div class="flex min-w-max items-stretch divide-x divide-default rounded-[8px] border border-default bg-surface">
                <section
                    v-for="column in localColumns"
                    :key="column.stage.id"
                    :data-testid="`kanban-column-${column.stage.id}`"
                    :aria-label="`${column.stage.name} stage (${column.deals_count} deals)`"
                    :class="[
                        'flex w-[272px] shrink-0 flex-col transition-colors duration-150',
                        dragOverStageId === column.stage.id ? 'bg-accent-primary/5' : 'bg-surface',
                    ]"
                    @dragover="onColumnDragOver(column.stage.id, $event)"
                    @drop="onColumnDrop(column, $event)"
                >
                    <!-- Column Header -->
                    <header class="border-b border-default bg-subtle px-3 py-2.5 space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <h3 class="truncate text-[12px] font-semibold text-primary">
                                    {{ column.stage.name }}
                                </h3>
                                <Badge
                                    v-if="column.stage.stage_type !== 'open'"
                                    :variant="stageTypeVariant(column.stage.stage_type)"
                                >
                                    {{ column.stage.stage_type }}
                                </Badge>
                            </div>
                            <span
                                :data-testid="`kanban-column-count-${column.stage.id}`"
                                class="rounded-full border border-default bg-surface px-1.5 py-0.5 font-mono text-[11px] font-medium tabular-nums text-secondary"
                            >
                                {{ column.deals_count }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 font-mono text-[11px] tabular-nums">
                            <span class="font-medium text-primary">
                                {{ formatMoneyCents(column.total_amount_cents, boardSummary.currency) }}
                            </span>
                            <span class="text-muted" title="Probability-weighted total">
                                {{ column.stage.win_probability }}% · {{ formatMoneyCents(columnWeightedCents(column), boardSummary.currency) }}
                            </span>
                        </div>
                    </header>

                    <!-- Column Cards -->
                    <div class="flex-1 space-y-2 bg-canvas/50 p-2 min-h-[260px]">
                        <div
                            v-if="column.deals.length === 0"
                            class="flex h-24 items-center justify-center rounded-[6px] border border-dashed border-default px-3 text-center text-[11px] text-muted"
                        >
                            No deals in {{ column.stage.name }}
                        </div>

                        <article
                            v-for="(deal, dealIndex) in column.deals"
                            :key="deal.id"
                            :data-testid="`kanban-card-${deal.id}`"
                            :draggable="props.canMoveDeals"
                            :class="[
                                'rounded-[6px] border border-default bg-surface p-2.5 space-y-2 transition-colors duration-150',
                                movingDealId === deal.id ? 'opacity-60' : 'hover:border-secondary/45',
                                draggedDealId === deal.id ? 'opacity-40' : '',
                            ]"
                            @dragstart="onDragStart(deal, $event)"
                            @dragend="onDragEnd"
                            @dragover.prevent
                            @drop="onCardDrop(column, deal, dealIndex, $event)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1 space-y-0.5">
                                    <Link
                                        :href="`/app/crm/deals/${deal.id}`"
                                        class="block truncate text-[13px] font-semibold text-primary hover:text-accent-primary"
                                    >
                                        {{ deal.title }}
                                    </Link>
                                    <p class="truncate text-[11px] text-secondary">
                                        {{ deal.company_name ?? 'Restricted account' }}
                                    </p>
                                </div>
                                <Badge v-if="deal.is_rotting" variant="warning">
                                    Rotting
                                </Badge>
                            </div>

                            <div class="flex items-center justify-between gap-2 text-[12px]">
                                <span class="font-mono font-semibold tabular-nums text-primary">
                                    {{ formatMoneyCents(deal.amount_cents, deal.currency) }}
                                </span>
                                <span
                                    v-if="deal.expected_close_date"
                                    class="inline-flex items-center gap-1 font-mono text-[11px] tabular-nums text-muted"
                                >
                                    <Calendar class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                    <span>{{ formatShortDate(deal.expected_close_date) }}</span>
                                </span>
                            </div>

                            <div class="flex items-center justify-between gap-2 border-t border-default pt-2">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span
                                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-default bg-subtle font-mono text-[10px] font-semibold text-primary"
                                        aria-hidden="true"
                                    >
                                        {{ getInitials(deal.owner_name, 'OW') }}
                                    </span>
                                    <span class="truncate text-[11px] text-secondary">
                                        {{ deal.owner_name ?? 'Owner' }}
                                    </span>
                                </div>

                                <div v-if="props.canMoveDeals" class="shrink-0">
                                    <label :for="`stage-select-${deal.id}`" class="sr-only">
                                        Move stage for {{ deal.title }}
                                    </label>
                                    <select
                                        :id="`stage-select-${deal.id}`"
                                        :data-testid="`kanban-stage-select-${deal.id}`"
                                        :value="deal.stage_id"
                                        :disabled="movingDealId === deal.id"
                                        class="h-6 max-w-[116px] rounded-[4px] border border-default bg-subtle px-1.5 text-[11px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary disabled:opacity-50"
                                        @change="handleStageSelectChange(deal, $event)"
                                    >
                                        <option
                                            v-for="stageOption in stages"
                                            :key="stageOption.id"
                                            :value="stageOption.id"
                                        >
                                            {{ stageOption.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>

        <!-- Stage Gate Quick-Fill Modal -->
        <StageGateModal
            :open="pendingGateMove !== null"
            :deal-title="pendingGateMove?.deal.title ?? ''"
            :target-stage="pendingGateMove?.targetStage ?? null"
            :current-deal="
                pendingGateMove
                    ? {
                          amount_cents: pendingGateMove.deal.amount_cents,
                          currency: pendingGateMove.deal.currency,
                          expected_close_date: pendingGateMove.deal.expected_close_date,
                          primary_contact_id: pendingGateMove.deal.primary_contact_id,
                          lost_reason: null,
                          custom_fields: pendingGateMove.deal.custom_fields ?? {},
                      }
                    : null
            "
            :missing-core-fields="pendingGateMove?.missingCoreFields ?? []"
            :missing-custom-fields="pendingGateMove?.missingCustomFields ?? []"
            :custom-field-definitions="props.customFieldDefinitions"
            :assignable-users="props.assignableUsers"
            :contacts="props.contacts"
            :server-errors="pendingGateMove?.errors ?? {}"
            :error-message="pendingGateMove?.errorMessage ?? ''"
            :can-override-gates="props.canOverrideGates"
            :submitting="gateSubmitting"
            @update:open="!$event ? (pendingGateMove = null) : undefined"
            @submit="handleGateSubmit"
        />
    </div>
</template>
