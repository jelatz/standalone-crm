import { ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import {
    getEcho,
    subscribeEchoConnectionState,
    type RealtimeConnectionState,
    type RealtimeEchoClient,
} from '@/echo';
import { useUnreadsStore } from './unreads.store';

export const HANDOFF_STATUS_CHANGED_EVENT =
    '.App\\Modules\\Projects\\Handoffs\\Events\\HandoffStatusChanged';

interface UnreadPayload {
    chat?: number;
    sharedInbox?: number;
    handoffs?: number;
    notifications?: number;
}

interface HandoffStatusChangedPayload {
    handoff_id?: string;
    status?: string;
    deal_id?: string;
}

function isUnreadPayload(payload: unknown): payload is UnreadPayload {
    return typeof payload === 'object' && payload !== null;
}

function isHandoffStatusPayload(payload: unknown): payload is HandoffStatusChangedPayload {
    return typeof payload === 'object' && payload !== null;
}

export const useRealtimeStore = defineStore('realtime', () => {
    const status = ref<RealtimeConnectionState>('disconnected');
    const subscribedUserId = ref<string | null>(null);
    const lastConnectedAt = ref<string | null>(null);
    const lastError = ref<string | null>(null);

    let activeClient: RealtimeEchoClient | null = null;
    let unsubscribeConnection: (() => void) | null = null;
    let handoffReloadTimer: ReturnType<typeof setTimeout> | null = null;

    function clearHandoffReloadTimer(): void {
        if (handoffReloadTimer !== null) {
            clearTimeout(handoffReloadTimer);
            handoffReloadTimer = null;
        }
    }

    function scheduleUnreadCountsReload(): void {
        if (typeof router?.reload !== 'function') {
            return;
        }

        clearHandoffReloadTimer();
        handoffReloadTimer = setTimeout(() => {
            handoffReloadTimer = null;
            if (typeof router?.reload === 'function') {
                router.reload({ only: ['unreadCounts'] });
            }
        }, 250);
    }

    function teardownSubscription(): void {
        clearHandoffReloadTimer();

        if (unsubscribeConnection !== null) {
            unsubscribeConnection();
            unsubscribeConnection = null;
        }

        if (activeClient !== null && subscribedUserId.value !== null) {
            activeClient.leave(`user.${subscribedUserId.value}`);
        }

        activeClient = null;
        subscribedUserId.value = null;
    }

    function connectForUser(userId: string | null, overrideClient?: RealtimeEchoClient | null): void {
        if (!userId) {
            disconnect();
            return;
        }

        const nextClient = overrideClient !== undefined ? overrideClient : getEcho();

        if (
            subscribedUserId.value === userId &&
            activeClient !== null &&
            (overrideClient === undefined || activeClient === nextClient)
        ) {
            return;
        }

        if (subscribedUserId.value !== null && subscribedUserId.value !== userId) {
            teardownSubscription();
        } else if (overrideClient !== undefined && activeClient !== null && activeClient !== nextClient) {
            teardownSubscription();
        }

        subscribedUserId.value = userId;

        if (nextClient === null) {
            status.value = 'disconnected';
            return;
        }

        activeClient = nextClient;
        lastError.value = null;

        unsubscribeConnection = subscribeEchoConnectionState(nextClient, (nextStatus) => {
            status.value = nextStatus;

            if (nextStatus === 'connected') {
                lastConnectedAt.value = new Date().toISOString();
                lastError.value = null;
            }
        });

        const unreadsStore = useUnreadsStore();
        const channel = nextClient.private(`user.${userId}`);

        channel.notification(() => {
            unreadsStore.increment('notifications', 1);
        });

        channel.listen('.unreads.updated', (payload: unknown) => {
            if (isUnreadPayload(payload)) {
                unreadsStore.setCounts(payload);
            }
        });

        const handleHandoffStatusChanged = (payload: unknown): void => {
            if (isHandoffStatusPayload(payload) && payload.status === 'pending_review') {
                unreadsStore.increment('handoffs', 1);
            }
            scheduleUnreadCountsReload();
        };

        channel.listen(HANDOFF_STATUS_CHANGED_EVENT, handleHandoffStatusChanged);
        channel.listen('.HandoffStatusChanged', handleHandoffStatusChanged);

        channel.error((error: unknown) => {
            lastError.value =
                error instanceof Error ? error.message : 'Realtime subscription error.';
        });
    }

    function disconnect(): void {
        teardownSubscription();
        status.value = 'disconnected';
    }

    return {
        status,
        subscribedUserId,
        lastConnectedAt,
        lastError,
        connectForUser,
        disconnect,
    };
});
