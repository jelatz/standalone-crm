import { onMounted, onUnmounted, ref, toValue, watch, type MaybeRefOrGetter } from 'vue';
import {
    getEcho,
    type RealtimeEchoClient,
    type RealtimePresenceChannelLike,
    type RealtimePresenceMember,
} from '@/echo';

export const TASK_BOARD_CHANGED_EVENT =
    '.App\\Modules\\Projects\\Tasks\\Events\\TaskBoardChanged';

export interface TaskBoardChangedPayload {
    task_id?: string;
    project_id?: string;
    project_stage_id?: string | null;
    board_position?: number;
    version?: number;
    status?: string;
}

function normalizeMember(raw: unknown): RealtimePresenceMember | null {
    if (!raw || typeof raw !== 'object') {
        return null;
    }
    const candidate = raw as { id?: unknown; name?: unknown };
    if (candidate.id === undefined || candidate.id === null) {
        return null;
    }
    return {
        id: String(candidate.id),
        name: typeof candidate.name === 'string' ? candidate.name : undefined,
    };
}

export function useProjectBoardRealtime(options: {
    projectId: MaybeRefOrGetter<string>;
    enabled?: MaybeRefOrGetter<boolean>;
    getTaskVersion: (taskId: string) => number | undefined;
    isOwnMutation?: (taskId: string, version: number) => boolean;
    isBusy?: () => boolean;
    onReloadNeeded: () => void;
}) {
    const coViewers = ref<RealtimePresenceMember[]>([]);
    let activeEcho: RealtimeEchoClient | null = null;
    let activeChannelName: string | null = null;
    let activeChannel: RealtimePresenceChannelLike | null = null;
    let reloadTimer: ReturnType<typeof setTimeout> | null = null;
    let unmounted = false;

    function clearReloadTimer(): void {
        if (reloadTimer !== null) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }
    }

    function leaveChannel(): void {
        clearReloadTimer();
        if (activeChannel !== null) {
            activeChannel.stopListening(TASK_BOARD_CHANGED_EVENT, handleBoardChanged);
            activeChannel.stopListening('.TaskBoardChanged', handleBoardChanged);
            activeChannel = null;
        }
        if (activeEcho !== null && activeChannelName !== null) {
            activeEcho.leave(activeChannelName);
        }
        activeEcho = null;
        activeChannelName = null;
        coViewers.value = [];
    }

    function handleBoardChanged(rawPayload: unknown): void {
        if (unmounted || !rawPayload || typeof rawPayload !== 'object') {
            return;
        }

        const payload = rawPayload as TaskBoardChangedPayload;
        const currentProjectId = toValue(options.projectId);
        if (payload.project_id && payload.project_id !== currentProjectId) {
            return;
        }

        const taskId = typeof payload.task_id === 'string' ? payload.task_id : '';
        const incomingVersion = typeof payload.version === 'number' ? payload.version : 0;

        if (taskId && incomingVersion > 0) {
            if (options.isOwnMutation?.(taskId, incomingVersion)) {
                return;
            }
            const knownVersion = options.getTaskVersion(taskId);
            if (knownVersion !== undefined && incomingVersion <= knownVersion) {
                return;
            }
        }

        clearReloadTimer();
        reloadTimer = setTimeout(() => {
            reloadTimer = null;
            if (!unmounted && !options.isBusy?.()) {
                options.onReloadNeeded();
            }
        }, 250);
    }

    function joinChannel(): void {
        const isEnabled = options.enabled === undefined ? true : toValue(options.enabled);
        if (!isEnabled || unmounted) {
            leaveChannel();
            return;
        }

        const projectId = toValue(options.projectId);
        if (!projectId) {
            leaveChannel();
            return;
        }

        const channelName = `project-board.${projectId}`;
        const echo = getEcho();
        if (!echo || typeof echo.join !== 'function') {
            return;
        }

        if (activeEcho === echo && activeChannelName === channelName) {
            return;
        }

        leaveChannel();

        const channel = echo.join(channelName);
        activeEcho = echo;
        activeChannelName = channelName;
        activeChannel = channel;

        channel.here((members: unknown) => {
            if (!Array.isArray(members)) {
                coViewers.value = [];
                return;
            }
            const deduped = new Map<string, RealtimePresenceMember>();
            for (const raw of members) {
                const parsed = normalizeMember(raw);
                if (parsed) {
                    deduped.set(parsed.id, parsed);
                }
            }
            coViewers.value = Array.from(deduped.values());
        });

        channel.joining((rawMember: unknown) => {
            const member = normalizeMember(rawMember);
            if (!member) {
                return;
            }
            if (!coViewers.value.some((m) => m.id === member.id)) {
                coViewers.value = [...coViewers.value, member];
            }
        });

        channel.leaving((rawMember: unknown) => {
            const member = normalizeMember(rawMember);
            if (!member) {
                return;
            }
            coViewers.value = coViewers.value.filter((m) => m.id !== member.id);
        });

        channel.listen(TASK_BOARD_CHANGED_EVENT, handleBoardChanged);
        channel.listen('.TaskBoardChanged', handleBoardChanged);
    }

    onMounted(() => {
        joinChannel();
    });

    watch(
        () => [
            toValue(options.projectId),
            options.enabled === undefined ? true : toValue(options.enabled),
        ],
        () => {
            joinChannel();
        },
    );

    onUnmounted(() => {
        unmounted = true;
        leaveChannel();
    });

    return {
        coViewers,
        leaveChannel,
    };
}
