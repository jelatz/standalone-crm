<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useDraftsStore } from '@/stores/drafts.store';
import { useRealtimeStore } from '@/stores/realtime.store';

const props = defineProps<{
    moduleKey: string;
    moduleTitle: string;
    moduleDescription: string;
    phaseLabel: string;
}>();

const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();
const draftsStore = useDraftsStore();

function openModuleDraft(): void {
    const isChat = props.moduleKey.startsWith('chat');

    draftsStore.openDraft({
        kind: isChat ? 'chat' : 'email',
        title: `${props.moduleTitle} draft`,
        targetLabel: isChat ? '#general-ops' : 'ops@company.internal',
        bodyText: '',
    });
}
</script>

<template>
    <Head :title="props.moduleTitle" />

    <div class="rounded-[8px] border border-default bg-surface p-5 space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                        {{ props.moduleTitle }}
                    </h2>
                    <Badge variant="cobalt">{{ props.phaseLabel }}</Badge>
                </div>
                <p class="text-[13px] leading-5 text-secondary">
                    {{ props.moduleDescription }}
                </p>
                <p class="font-mono text-[11px] text-muted">
                    Route key: {{ props.moduleKey }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button variant="primary" size="md" @click="openModuleDraft">
                    <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Open Linked Draft</span>
                </Button>
                <Link
                    href="/app"
                    class="inline-flex h-8 items-center justify-center rounded-[6px] border border-default bg-surface px-3 text-[13px] font-medium text-primary transition-colors duration-150 hover:bg-subtle"
                >
                    Overview
                </Link>
            </div>
        </div>

        <dl class="grid grid-cols-1 divide-y divide-default rounded-[6px] border border-default bg-subtle text-[12px] sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <div class="px-3.5 py-2.5 space-y-0.5">
                <dt class="text-secondary">Realtime Socket</dt>
                <dd class="font-mono font-medium text-primary">
                    {{ realtimeStore.status }}
                </dd>
            </div>
            <div class="px-3.5 py-2.5 space-y-0.5">
                <dt class="text-secondary">Active Role</dt>
                <dd class="font-mono font-medium text-primary">
                    {{ authStore.primaryRole ?? 'employee' }}
                </dd>
            </div>
            <div class="px-3.5 py-2.5 space-y-0.5">
                <dt class="text-secondary">Session Drafts Held</dt>
                <dd class="font-mono font-medium tabular-nums text-primary">
                    {{ draftsStore.draftCount }}
                </dd>
            </div>
        </dl>
    </div>
</template>
