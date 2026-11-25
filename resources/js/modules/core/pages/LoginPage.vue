<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertCircle, Eye, EyeOff, LockKeyhole } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useAccessibleFormValidation } from '@/utils/formAccessibility';

defineOptions({
    layout: () => null,
});

const formRef = ref<HTMLFormElement | null>(null);
const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

useAccessibleFormValidation(formRef);

const topLevelError = computed<string | null>(() => {
    const emailErr = form.errors.email;
    const passwordErr = form.errors.password;

    if (typeof emailErr === 'string' && emailErr.trim() !== '') {
        return emailErr;
    }

    if (typeof passwordErr === 'string' && passwordErr.trim() !== '') {
        return passwordErr;
    }

    return null;
});

function submit(): void {
    form.post('/login', {
        onFinish: () => {
            form.reset('password');
        },
    });
}
</script>

<template>
    <Head title="Sign In" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-8 text-primary">
        <main class="w-full max-w-[400px] rounded-[8px] border border-default bg-surface p-6">
            <div class="flex items-center gap-2.5">
                <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] border border-default bg-subtle font-mono text-[12px] font-semibold text-primary"
                    aria-hidden="true"
                >
                    C
                </span>
                <div>
                    <h1 class="text-[18px] font-semibold leading-6 tracking-[-0.02em] text-primary">
                        Standalone CRM
                    </h1>
                    <p class="text-[12px] leading-4 text-secondary">
                        Sign in to your operational account
                    </p>
                </div>
            </div>

            <div
                v-if="topLevelError"
                role="alert"
                data-testid="login-error-banner"
                class="mt-4 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-3 py-2.5 text-[13px] leading-5 text-status-danger"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ topLevelError }}</span>
            </div>

            <form
                ref="formRef"
                class="mt-5 space-y-4"
                @submit.prevent="submit"
            >
                <div class="space-y-1">
                    <label for="email" class="block text-[12px] font-medium text-primary">
                        Email address
                    </label>
                    <Input
                        id="email"
                        v-model="form.email"
                        name="email"
                        type="email"
                        autocomplete="username"
                        :spellcheck="false"
                        enterkeyhint="next"
                        placeholder="superadmin@example.com"
                        data-error="email-client-error"
                        :error-message="form.errors.email"
                        required
                    />
                    <p
                        id="email-client-error"
                        class="hidden text-[12px] text-status-danger [:has(#email:user-invalid)_&]:block [:has(#email.user-invalid-fallback)_&]:block"
                    >
                        Enter a valid email address in the format name@example.com.
                    </p>
                </div>

                <div class="space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <label for="password" class="block text-[12px] font-medium text-primary">
                            Password
                        </label>
                        <button
                            type="button"
                            data-testid="toggle-password-visibility"
                            :aria-pressed="showPassword ? 'true' : 'false'"
                            class="inline-flex items-center gap-1 text-[11px] font-medium text-secondary hover:text-primary cursor-pointer"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <Eye v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>{{ showPassword ? 'Hide password' : 'Show password' }}</span>
                        </button>
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        name="password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        enterkeyhint="done"
                        placeholder="Enter your password"
                        data-error="password-client-error"
                        :error-message="form.errors.password"
                        required
                    />
                    <p
                        id="password-client-error"
                        class="hidden text-[12px] text-status-danger [:has(#password:user-invalid)_&]:block [:has(#password.user-invalid-fallback)_&]:block"
                    >
                        Enter your password to sign in.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        id="remember"
                        v-model="form.remember"
                        name="remember"
                        type="checkbox"
                        class="h-3.5 w-3.5 rounded-[4px] border border-default accent-accent-primary cursor-pointer"
                    />
                    <label for="remember" class="text-[12px] text-secondary select-none cursor-pointer">
                        Keep me signed in on this device
                    </label>
                </div>

                <Button
                    type="submit"
                    variant="primary"
                    size="md"
                    data-testid="login-submit"
                    :loading="form.processing"
                    class="w-full"
                >
                    Sign In
                </Button>
            </form>

            <div class="mt-5 flex items-start gap-2 border-t border-default pt-4 text-[12px] leading-4 text-muted">
                <LockKeyhole class="mt-0.5 h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <span>
                    Accounts are provisioned by your Superadmin. Contact system administration if you need access.
                </span>
            </div>
        </main>
    </div>
</template>
