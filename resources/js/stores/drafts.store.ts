import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export type DraftKind = 'email' | 'chat';

export interface WorkspaceDraft {
    id: string;
    kind: DraftKind;
    title: string;
    targetLabel: string;
    bodyText: string;
    updatedAt: string;
}

export interface UpsertDraftInput {
    id?: string;
    kind: DraftKind;
    title: string;
    targetLabel: string;
    bodyText: string;
}

let draftSequence = 0;

function nextDraftId(kind: DraftKind): string {
    draftSequence += 1;
    return `${kind}-draft-${Date.now()}-${draftSequence}`;
}

export const useDraftsStore = defineStore('drafts', () => {
    const drafts = ref<WorkspaceDraft[]>([]);
    const isTrayOpen = ref<boolean>(false);
    const activeDraftId = ref<string | null>(null);

    const activeDraft = computed<WorkspaceDraft | null>(
        () => drafts.value.find((draft) => draft.id === activeDraftId.value) ?? drafts.value[0] ?? null,
    );

    const draftCount = computed(() => drafts.value.length);

    function upsertDraft(input: UpsertDraftInput): WorkspaceDraft {
        const now = new Date().toISOString();
        const existingIndex = input.id
            ? drafts.value.findIndex((draft) => draft.id === input.id)
            : -1;

        if (existingIndex !== -1) {
            const updated: WorkspaceDraft = {
                ...drafts.value[existingIndex],
                kind: input.kind,
                title: input.title,
                targetLabel: input.targetLabel,
                bodyText: input.bodyText,
                updatedAt: now,
            };

            drafts.value.splice(existingIndex, 1, updated);
            activeDraftId.value = updated.id;

            return updated;
        }

        const created: WorkspaceDraft = {
            id: input.id ?? nextDraftId(input.kind),
            kind: input.kind,
            title: input.title,
            targetLabel: input.targetLabel,
            bodyText: input.bodyText,
            updatedAt: now,
        };

        drafts.value.unshift(created);
        activeDraftId.value = created.id;

        return created;
    }

    function openDraft(input: UpsertDraftInput): WorkspaceDraft {
        const draft = upsertDraft(input);
        isTrayOpen.value = true;
        return draft;
    }

    function selectDraft(id: string): void {
        if (drafts.value.some((draft) => draft.id === id)) {
            activeDraftId.value = id;
            isTrayOpen.value = true;
        }
    }

    function removeDraft(id: string): void {
        const index = drafts.value.findIndex((draft) => draft.id === id);

        if (index === -1) {
            return;
        }

        drafts.value.splice(index, 1);

        if (activeDraftId.value === id) {
            activeDraftId.value = drafts.value[0]?.id ?? null;
        }

        if (drafts.value.length === 0) {
            isTrayOpen.value = false;
        }
    }

    function toggleTray(force?: boolean): void {
        isTrayOpen.value = force !== undefined ? force : !isTrayOpen.value;
    }

    return {
        drafts,
        isTrayOpen,
        activeDraftId,
        activeDraft,
        draftCount,
        upsertDraft,
        openDraft,
        selectDraft,
        removeDraft,
        toggleTray,
    };
});
