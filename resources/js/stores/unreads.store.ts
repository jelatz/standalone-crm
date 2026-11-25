import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export type UnreadChannel = 'chat' | 'sharedInbox' | 'handoffs' | 'notifications';

export const useUnreadsStore = defineStore('unreads', () => {
    const chat = ref<number>(0);
    const sharedInbox = ref<number>(0);
    const handoffs = ref<number>(0);
    const notifications = ref<number>(0);

    const totalUnread = computed(
        () => chat.value + sharedInbox.value + handoffs.value + notifications.value,
    );

    const channelRefs: Record<UnreadChannel, typeof chat> = {
        chat,
        sharedInbox,
        handoffs,
        notifications,
    };

    function clampCount(value: number): number {
        if (!Number.isFinite(value)) {
            return 0;
        }

        return Math.max(0, Math.floor(value));
    }

    function setCounts(counts: Partial<Record<UnreadChannel, number>>): void {
        (Object.keys(counts) as UnreadChannel[]).forEach((key) => {
            const nextValue = counts[key];

            if (typeof nextValue === 'number') {
                channelRefs[key].value = clampCount(nextValue);
            }
        });
    }

    function increment(channel: UnreadChannel, by = 1): void {
        channelRefs[channel].value = clampCount(channelRefs[channel].value + by);
    }

    function decrement(channel: UnreadChannel, by = 1): void {
        channelRefs[channel].value = clampCount(channelRefs[channel].value - by);
    }

    function resetChannel(channel: UnreadChannel): void {
        channelRefs[channel].value = 0;
    }

    function resetAll(): void {
        chat.value = 0;
        sharedInbox.value = 0;
        handoffs.value = 0;
        notifications.value = 0;
    }

    return {
        chat,
        sharedInbox,
        handoffs,
        notifications,
        totalUnread,
        setCounts,
        increment,
        decrement,
        resetChannel,
        resetAll,
    };
});
