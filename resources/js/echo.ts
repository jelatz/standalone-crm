import Echo, { type ConnectionStatus } from 'laravel-echo';
import Pusher from 'pusher-js';

export type RealtimeConnectionState = 'connected' | 'reconnecting' | 'disconnected';
export type ReverbEchoInstance = Echo<'reverb'>;

export interface RealtimePresenceMember {
    id: string;
    name?: string;
    [key: string]: unknown;
}

export interface RealtimeChannelLike {
    listen(event: string, callback: CallableFunction): this;
    stopListening(event: string, callback?: CallableFunction): this;
    notification(callback: CallableFunction): this;
    error(callback: CallableFunction): this;
}

export interface RealtimePresenceChannelLike extends RealtimeChannelLike {
    here(callback: CallableFunction): this;
    joining(callback: CallableFunction): this;
    leaving(callback: CallableFunction): this;
}

export interface RealtimeEchoClient {
    private(channel: string): RealtimeChannelLike;
    join?(channel: string): RealtimePresenceChannelLike;
    leave(channel: string): void;
    connectionStatus(): ConnectionStatus;
    disconnect(): void;
    connector: {
        onConnectionChange(callback: (status: ConnectionStatus) => void): () => void;
    };
}

let echoSingleton: RealtimeEchoClient | null = null;

export function mapEchoConnectionStatus(status: ConnectionStatus): RealtimeConnectionState {
    if (status === 'connected') {
        return 'connected';
    }

    if (status === 'connecting' || status === 'reconnecting') {
        return 'reconnecting';
    }

    return 'disconnected';
}

export function subscribeEchoConnectionState(
    client: RealtimeEchoClient,
    callback: (state: RealtimeConnectionState) => void,
): () => void {
    callback(mapEchoConnectionStatus(client.connectionStatus()));

    return client.connector.onConnectionChange((status) => {
        callback(mapEchoConnectionStatus(status));
    });
}

export function getEcho(): RealtimeEchoClient | null {
    if (echoSingleton !== null) {
        return echoSingleton;
    }

    if (typeof window === 'undefined' || typeof WebSocket === 'undefined') {
        return null;
    }

    const appKey = String(import.meta.env.VITE_REVERB_APP_KEY ?? '').trim();

    if (appKey === '') {
        return null;
    }

    const scheme = String(import.meta.env.VITE_REVERB_SCHEME ?? 'http').toLowerCase();
    const forceTLS = scheme === 'https';
    const host = String(import.meta.env.VITE_REVERB_HOST ?? window.location.hostname);
    const rawPort = Number(import.meta.env.VITE_REVERB_PORT ?? (forceTLS ? 443 : 8080));
    const port = Number.isFinite(rawPort) && rawPort > 0 ? rawPort : forceTLS ? 443 : 8080;

    echoSingleton = new Echo<'reverb'>({
        broadcaster: 'reverb',
        key: appKey,
        Pusher,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS,
        enabledTransports: ['ws', 'wss'],
    });

    return echoSingleton;
}

export function setEchoInstanceForTesting(client: RealtimeEchoClient | null): void {
    echoSingleton = client;
}

export function resetEcho(): void {
    if (echoSingleton !== null) {
        echoSingleton.disconnect();
        echoSingleton = null;
    }
}
