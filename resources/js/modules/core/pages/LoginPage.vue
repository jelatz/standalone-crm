<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { AlertCircle, ShieldCheck } from 'lucide-vue-next';

defineOptions({
    layout: () => null,
});

interface LoginPageProps {
    errors?: {
        sso?: string | string[];
    };
    [key: string]: unknown;
}

const props = withDefaults(
    defineProps<{
        providers?: {
            google?: boolean;
            microsoft?: boolean;
        };
    }>(),
    {
        providers: () => ({
            google: true,
            microsoft: true,
        }),
    },
);

const page = usePage<LoginPageProps>();
const redirectingProvider = ref<'google' | 'microsoft' | null>(null);

const googleEnabled = computed(() => props.providers?.google !== false);
const microsoftEnabled = computed(() => props.providers?.microsoft !== false);

const ssoError = computed<string | null>(() => {
    const raw = page.props?.errors?.sso;

    if (Array.isArray(raw)) {
        return raw[0] ?? null;
    }

    return typeof raw === 'string' && raw.trim() !== '' ? raw : null;
});

function handleProviderClick(provider: 'google' | 'microsoft', event: MouseEvent): void {
    if (redirectingProvider.value !== null) {
        event.preventDefault();
        return;
    }

    redirectingProvider.value = provider;
}
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-8 text-primary">
        <main class="w-full max-w-[400px] rounded-[8px] border border-default bg-surface p-6">
            <div class="flex items-center gap-2.5">
                <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] border border-default bg-subtle font-mono text-[12px] font-semibold text-primary"
                    aria-hidden="true"
                >
                    W
                </span>
                <div>
                    <h1 class="text-[18px] font-semibold leading-6 tracking-[-0.02em] text-primary">
                        Workspace Sign In
                    </h1>
                    <p class="text-[12px] leading-4 text-secondary">
                        Internal Enterprise Operations Platform
                    </p>
                </div>
            </div>

            <p class="mt-4 text-[13px] leading-5 text-secondary">
                Sign in with your corporate directory account to access CRM pipelines, project handoffs, internal chat, and shared mail triage.
            </p>

            <div
                v-if="ssoError"
                role="alert"
                data-testid="sso-error-banner"
                class="mt-4 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2.5 text-[13px] leading-5 text-status-danger"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ ssoError }}</span>
            </div>

            <div
                v-if="!googleEnabled && !microsoftEnabled"
                role="alert"
                class="mt-4 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2.5 text-[13px] leading-5 text-status-danger"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>No single sign-on providers are currently enabled. Contact IT Operations.</span>
            </div>

            <div class="mt-5 space-y-2.5">
                <a
                    v-if="googleEnabled"
                    href="/auth/google/redirect"
                    data-testid="sso-google-link"
                    :aria-disabled="redirectingProvider !== null ? 'true' : undefined"
                    class="flex h-9 w-full items-center justify-center gap-2.5 rounded-[6px] bg-accent-primary px-3 text-[13px] font-medium text-accent-contrast transition-colors duration-150 ease-out hover:bg-accent-hover"
                    @click="handleProviderClick('google', $event)"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path
                            fill="currentColor"
                            d="M21.8 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.5c-.2 1.2-1 2.3-2.1 3v2.5h3.4c2-1.8 3-4.5 3-7.3Z"
                        />
                        <path
                            fill="currentColor"
                            d="M12 22c2.7 0 5-.9 6.7-2.5l-3.4-2.5c-.9.6-2.1 1-3.3 1-2.6 0-4.8-1.7-5.6-4.1H2.9v2.6C4.6 19.8 8 22 12 22Z"
                        />
                        <path
                            fill="currentColor"
                            d="M6.4 13.9c-.2-.6-.3-1.2-.3-1.9s.1-1.3.3-1.9V7.5H2.9C2.3 8.9 2 10.4 2 12s.3 3.1.9 4.5l3.5-2.6Z"
                        />
                        <path
                            fill="currentColor"
                            d="M12 6c1.5 0 2.8.5 3.8 1.5l2.9-2.9C17 3 14.7 2 12 2 8 2 4.6 4.2 2.9 7.5l3.5 2.6C7.2 7.7 9.4 6 12 6Z"
                        />
                    </svg>
                    <span>
                        {{ redirectingProvider === 'google' ? 'Redirecting to Google Workspace...' : 'Sign in with Google Workspace' }}
                    </span>
                </a>

                <a
                    v-if="microsoftEnabled"
                    href="/auth/microsoft/redirect"
                    data-testid="sso-microsoft-link"
                    :aria-disabled="redirectingProvider !== null ? 'true' : undefined"
                    class="flex h-9 w-full items-center justify-center gap-2.5 rounded-[6px] border border-default bg-surface px-3 text-[13px] font-medium text-primary transition-colors duration-150 ease-out hover:bg-subtle"
                    @click="handleProviderClick('microsoft', $event)"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 21 21" aria-hidden="true">
                        <rect x="1" y="1" width="9" height="9" fill="currentColor" opacity="0.85" />
                        <rect x="11" y="1" width="9" height="9" fill="currentColor" opacity="0.65" />
                        <rect x="1" y="11" width="9" height="9" fill="currentColor" opacity="0.65" />
                        <rect x="11" y="11" width="9" height="9" fill="currentColor" opacity="0.85" />
                    </svg>
                    <span>
                        {{ redirectingProvider === 'microsoft' ? 'Redirecting to Microsoft 365...' : 'Sign in with Microsoft 365' }}
                    </span>
                </a>
            </div>

            <div class="mt-5 flex items-start gap-2 border-t border-default pt-4 text-[12px] leading-4 text-muted">
                <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <span>
                    Access requires an active employee record in the corporate directory. Contact IT Operations for role or tenant access issues.
                </span>
            </div>
        </main>
    </div>
</template>
