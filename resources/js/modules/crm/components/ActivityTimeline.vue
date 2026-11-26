<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    AlertCircle,
    ArrowRightLeft,
    CheckCircle2,
    FileEdit,
    Mail,
    MessageSquare,
    Pin,
    PinOff,
    PlusCircle,
    Trash2,
} from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import RichNoteEditor, { type ProseMirrorDoc } from '@/components/editor/RichNoteEditor.vue';
import RichJsonRenderer from './RichJsonRenderer.vue';
import { crmApi } from '@/modules/crm/api/crmApi';
import { formatDateTime, formatEnumLabel } from '@/modules/crm/utils/formatters';

type TimelineFilter = 'all' | 'notes' | 'stages' | 'fields' | 'tasks' | 'messaging';

const props = withDefaults(
    defineProps<{
        linkableType: 'company' | 'contact' | 'deal';
        linkableId: string;
        initialPage: App.Modules.Crm.Timeline.Data.TimelinePageData;
        canCreateNote?: boolean;
    }>(),
    {
        canCreateNote: true,
    },
);

const items = ref<App.Modules.Crm.Timeline.Data.ActivityData[]>([...props.initialPage.items]);
const nextCursor = ref<string | null>(props.initialPage.next_cursor);
const hasMore = ref<boolean>(props.initialPage.has_more);

watch(
    () => props.initialPage,
    (nextPage) => {
        items.value = [...nextPage.items];
        nextCursor.value = nextPage.next_cursor;
        hasMore.value = nextPage.has_more;
    },
);

const activeFilter = ref<TimelineFilter>('all');
const creatingNote = ref<boolean>(false);
const noteError = ref<string>('');
const noteResetKey = ref<number>(0);

const loadingMore = ref<boolean>(false);
const loadMoreError = ref<string>('');
const actionError = ref<string>('');
const busyNoteId = ref<string | null>(null);

const filterTabs: Array<{ key: TimelineFilter; label: string }> = [
    { key: 'all', label: 'All' },
    { key: 'notes', label: 'Notes' },
    { key: 'stages', label: 'Stage Changes' },
    { key: 'fields', label: 'Field Updates' },
    { key: 'tasks', label: 'Tasks & Handoffs' },
    { key: 'messaging', label: 'Messaging' },
];

const pinnedEntries = computed(() =>
    items.value.filter((entry) => entry.note && entry.note.is_pinned),
);

function matchesFilter(
    activityType: App.Modules.Crm.Timeline.Enums.ActivityTypeEnum,
    filter: TimelineFilter,
): boolean {
    if (filter === 'all') {
        return true;
    }
    if (filter === 'notes') {
        return activityType === 'note_created';
    }
    if (filter === 'stages') {
        return activityType === 'stage_changed' || activityType === 'deal_created';
    }
    if (filter === 'fields') {
        return activityType === 'field_updated';
    }
    if (filter === 'tasks') {
        return (
            activityType === 'task_created' ||
            activityType === 'task_completed' ||
            activityType === 'handoff_submitted' ||
            activityType === 'handoff_approved' ||
            activityType === 'project_provisioned'
        );
    }
    if (filter === 'messaging') {
        return (
            activityType === 'email_received' ||
            activityType === 'email_sent' ||
            activityType === 'chat_thread_created'
        );
    }
    return true;
}

const filteredItems = computed(() =>
    items.value.filter((entry) => matchesFilter(entry.activity_type, activeFilter.value)),
);

function iconForActivity(activityType: App.Modules.Crm.Timeline.Enums.ActivityTypeEnum) {
    switch (activityType) {
        case 'note_created':
            return FileEdit;
        case 'stage_changed':
            return ArrowRightLeft;
        case 'deal_created':
            return PlusCircle;
        case 'task_completed':
        case 'handoff_approved':
            return CheckCircle2;
        case 'email_received':
        case 'email_sent':
            return Mail;
        case 'chat_thread_created':
            return MessageSquare;
        default:
            return FileEdit;
    }
}

async function handleCreateNote(payload: { body_json: ProseMirrorDoc; is_pinned: boolean }): Promise<void> {
    if (creatingNote.value) {
        return;
    }

    creatingNote.value = true;
    noteError.value = '';
    actionError.value = '';

    try {
        const created = await crmApi.createNote({
            body_json: payload.body_json,
            is_pinned: payload.is_pinned,
            targets: [
                {
                    linkable_type: props.linkableType,
                    linkable_id: props.linkableId,
                },
            ],
        });

        const syntheticActivity: App.Modules.Crm.Timeline.Data.ActivityData = {
            id: `note-${created.id}`,
            actor_id: created.author_id,
            actor_name: created.author_name,
            activity_type: 'note_created',
            source_type: 'note',
            source_id: created.id,
            visibility_mode: 'shared',
            summary: created.body_text.slice(0, 255),
            metadata: { is_pinned: created.is_pinned },
            occurred_at: created.created_at,
            note: created,
        };

        items.value = [syntheticActivity, ...items.value];
        noteResetKey.value += 1;
    } catch (error) {
        noteError.value = error instanceof Error ? error.message : 'Unable to save note.';
    } finally {
        creatingNote.value = false;
    }
}

async function handleTogglePin(note: App.Modules.Crm.Notes.Data.NoteData): Promise<void> {
    if (busyNoteId.value) {
        return;
    }

    busyNoteId.value = note.id;
    actionError.value = '';

    try {
        const updated = await crmApi.togglePinNote(note.id, !note.is_pinned);
        items.value = items.value.map((entry) =>
            entry.note?.id === updated.id ? { ...entry, note: updated } : entry,
        );
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to update note pin.';
    } finally {
        busyNoteId.value = null;
    }
}

async function handleDeleteNote(noteId: string): Promise<void> {
    if (busyNoteId.value) {
        return;
    }

    busyNoteId.value = noteId;
    actionError.value = '';

    try {
        await crmApi.deleteNote(noteId);
        items.value = items.value.filter((entry) => entry.note?.id !== noteId);
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to delete note.';
    } finally {
        busyNoteId.value = null;
    }
}

async function loadMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value || !nextCursor.value) {
        return;
    }

    loadingMore.value = true;
    loadMoreError.value = '';

    try {
        const page = await crmApi.fetchTimeline({
            linkable_type: props.linkableType,
            linkable_id: props.linkableId,
            cursor: nextCursor.value,
            limit: 25,
        });

        const existingIds = new Set(items.value.map((item) => item.id));
        const incoming = page.items.filter((item) => !existingIds.has(item.id));
        items.value = [...items.value, ...incoming];
        nextCursor.value = page.next_cursor;
        hasMore.value = page.has_more;
    } catch (error) {
        loadMoreError.value =
            error instanceof Error ? error.message : 'Unable to load earlier timeline entries.';
    } finally {
        loadingMore.value = false;
    }
}
</script>

<template>
    <div data-testid="activity-timeline" class="space-y-4">
        <!-- Embedded TipTap Rich Note Composer -->
        <RichNoteEditor
            v-if="props.canCreateNote"
            :submitting="creatingNote"
            :error-message="noteError"
            :reset-key="noteResetKey"
            @submit="handleCreateNote"
        />

        <!-- Action Error Banner -->
        <div
            v-if="actionError"
            role="alert"
            class="flex items-center gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-[12px] text-status-danger"
        >
            <AlertCircle class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ actionError }}</span>
        </div>

        <!-- Pinned Notes Banner -->
        <section
            v-if="pinnedEntries.length > 0"
            data-testid="pinned-notes-section"
            aria-label="Pinned notes"
            class="rounded-[8px] border border-accent-primary/30 bg-accent-primary/5 p-3 space-y-2.5"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-accent-primary">
                    <Pin class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                    <span>Pinned Notes</span>
                </span>
                <Badge variant="cobalt">{{ pinnedEntries.length }}</Badge>
            </div>

            <div class="space-y-2">
                <article
                    v-for="entry in pinnedEntries"
                    :key="`pinned-${entry.id}`"
                    class="rounded-[6px] border border-default bg-surface p-3 space-y-2"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2 text-[12px]">
                            <span class="truncate font-medium text-primary">
                                {{ entry.note?.author_name ?? entry.actor_name ?? 'Team Member' }}
                            </span>
                            <span class="font-mono text-[11px] tabular-nums text-muted">
                                {{ formatDateTime(entry.note?.created_at ?? entry.occurred_at) }}
                            </span>
                        </div>
                        <div v-if="entry.note" class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                aria-label="Unpin note"
                                :disabled="busyNoteId === entry.note.id"
                                class="inline-flex h-6 items-center gap-1 rounded-[4px] px-1.5 text-[11px] font-medium text-secondary hover:bg-subtle hover:text-primary cursor-pointer disabled:opacity-50"
                                @click="handleTogglePin(entry.note)"
                            >
                                <PinOff class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                                <span>Unpin</span>
                            </button>
                            <button
                                type="button"
                                aria-label="Delete pinned note"
                                :disabled="busyNoteId === entry.note.id"
                                class="inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer disabled:opacity-50"
                                @click="handleDeleteNote(entry.note.id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                    <RichJsonRenderer
                        v-if="entry.note"
                        :document="entry.note.body_json"
                        :fallback-text="entry.note.body_text"
                    />
                </article>
            </div>
        </section>

        <!-- Timeline Feed Card -->
        <div class="overflow-hidden rounded-[8px] border border-default bg-surface">
            <!-- Filter Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default bg-subtle px-3.5 py-2">
                <h3 class="text-[13px] font-semibold text-primary">Activity Timeline</h3>
                <div class="flex flex-wrap items-center gap-1" role="tablist" aria-label="Filter timeline activity">
                    <button
                        v-for="tab in filterTabs"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeFilter === tab.key ? 'true' : 'false'"
                        :class="[
                            'h-6 rounded-[4px] px-2 text-[11px] font-medium transition-colors duration-150 cursor-pointer',
                            activeFilter === tab.key
                                ? 'bg-accent-primary text-accent-contrast'
                                : 'bg-surface text-secondary border border-default hover:text-primary',
                        ]"
                        @click="activeFilter = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="filteredItems.length === 0"
                data-testid="timeline-empty-state"
                class="px-4 py-8 text-center space-y-2"
            >
                <p class="text-[13px] font-medium text-primary">
                    No activity recorded for this filter
                </p>
                <p class="text-[12px] text-secondary">
                    Notes, stage transitions, and field edits appear here in reverse chronological order.
                </p>
                <Button
                    v-if="activeFilter !== 'all'"
                    variant="secondary"
                    size="sm"
                    @click="activeFilter = 'all'"
                >
                    Show all activity
                </Button>
            </div>

            <!-- Feed Items -->
            <ul v-else class="divide-y divide-default">
                <li
                    v-for="entry in filteredItems"
                    :key="entry.id"
                    :data-testid="`timeline-entry-${entry.id}`"
                    class="px-4 py-3 space-y-2"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span
                                class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-default bg-subtle text-secondary"
                                aria-hidden="true"
                            >
                                <component :is="iconForActivity(entry.activity_type)" class="h-3.5 w-3.5" :stroke-width="1.75" />
                            </span>
                            <div class="min-w-0 space-y-0.5">
                                <div class="flex flex-wrap items-center gap-1.5 text-[12px]">
                                    <span class="font-semibold text-primary">
                                        {{ entry.actor_name ?? 'System' }}
                                    </span>
                                    <Badge variant="neutral">{{ formatEnumLabel(entry.activity_type) }}</Badge>
                                    <Badge v-if="entry.metadata?.gate_overridden" variant="warning">
                                        Gate Override
                                    </Badge>
                                </div>
                                <p v-if="!entry.note" class="text-[13px] text-primary break-words">
                                    {{ entry.summary }}
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span class="font-mono text-[11px] tabular-nums text-muted whitespace-nowrap">
                                {{ formatDateTime(entry.occurred_at) }}
                            </span>

                            <template v-if="entry.note">
                                <button
                                    type="button"
                                    :aria-label="entry.note.is_pinned ? 'Unpin note' : 'Pin note'"
                                    :disabled="busyNoteId === entry.note.id"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer disabled:opacity-50"
                                    @click="handleTogglePin(entry.note)"
                                >
                                    <PinOff v-if="entry.note.is_pinned" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                    <Pin v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Delete note"
                                    :disabled="busyNoteId === entry.note.id"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer disabled:opacity-50"
                                    @click="handleDeleteNote(entry.note.id)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Safe Recursive ProseMirror Note Content (ZERO v-html) -->
                    <div
                        v-if="entry.note"
                        class="ml-8 rounded-[6px] border border-default bg-subtle/40 px-3 py-2"
                    >
                        <RichJsonRenderer
                            :document="entry.note.body_json"
                            :fallback-text="entry.note.body_text"
                        />
                    </div>

                    <!-- Structured Field Diffs -->
                    <div
                        v-else-if="Array.isArray(entry.metadata?.changed_fields) && entry.metadata.changed_fields.length > 0"
                        class="ml-8 flex flex-wrap items-center gap-1"
                    >
                        <span
                            v-for="fieldName in entry.metadata.changed_fields"
                            :key="String(fieldName)"
                            class="rounded border border-default bg-subtle px-1.5 py-0.5 font-mono text-[10px] text-secondary"
                        >
                            {{ fieldName }}
                        </span>
                    </div>
                </li>
            </ul>

            <!-- Pagination Error + Cursor Load More -->
            <div
                v-if="loadMoreError || hasMore"
                class="border-t border-default bg-subtle/50 px-4 py-2.5 space-y-2"
            >
                <p
                    v-if="loadMoreError"
                    role="alert"
                    class="text-[12px] text-status-danger"
                >
                    {{ loadMoreError }}
                </p>
                <div v-if="hasMore" class="flex justify-center">
                    <Button
                        variant="secondary"
                        size="sm"
                        :loading="loadingMore"
                        data-testid="timeline-load-more"
                        @click="loadMore"
                    >
                        Load earlier activity
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
