import { ref } from 'vue';
import { defineStore } from 'pinia';
import {
    getEcho,
    subscribeEchoConnectionState,
    type RealtimeConnectionState,
    type RealtimeEchoClient,
} from '@/echo';

export const useRealtimeStore = defineStore('realtime', () => {
    const status = ref<RealtimeConnectionState>('disconnected');
    const subscribedUserId = ref<string | null>(null);
    const lastConnectedAt = ref<string | null>(null);
    const lastError = ref<string | null>(null);

    let activeClient: RealtimeEchoClient | null = null;
    let unsubscribeConnection: (() => void) | null = null;

    function teardownSubscription(): void {
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

        const channel = nextClient.private(`user.${userId}`);

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
