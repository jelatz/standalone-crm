<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, Eye, EyeOff, Plus, Users } from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { useAccessibleFormValidation } from '@/utils/formAccessibility';

interface DepartmentOption {
    id: string;
    name: string;
}

interface RoleOption {
    value: string;
    label: string;
}

interface UsersPageProps {
    flash?: {
        success?: string | null;
    };
    [key: string]: unknown;
}

const props = withDefaults(
    defineProps<{
        users?: App.Modules.Core.Users.Data.UserSummaryData[];
        departments?: DepartmentOption[];
        roles?: RoleOption[];
        initialState?: 'ready' | 'loading' | 'error';
        initialError?: string;
    }>(),
    {
        users: () => [],
        departments: () => [],
        roles: () => [],
        initialState: 'ready',
        initialError: '',
    },
);

const page = usePage<UsersPageProps>();
const isCreateDialogOpen = ref(false);
const showCreatePassword = ref(false);
const createFormRef = ref<HTMLFormElement | null>(null);

useAccessibleFormValidation(createFormRef);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: props.roles.find((r) => r.value === 'sales')?.value ?? props.roles[0]?.value ?? 'sales',
    department_id: props.departments[0]?.id ?? '',
    job_title: '',
    employee_code: '',
});

const flashSuccess = computed<string | null>(() => {
    const value = page.props?.flash?.success;
    return typeof value === 'string' && value.trim() !== '' ? value : null;
});

function statusVariant(status: string): BadgeVariant {
    if (status === 'active') {
        return 'success';
    }
    if (status === 'suspended') {
        return 'warning';
    }
    return 'danger';
}

function roleLabel(roleValue: string): string {
    return roleValue
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function openCreateDialog(): void {
    createForm.clearErrors();
    if (!createForm.department_id && props.departments[0]?.id) {
        createForm.department_id = props.departments[0].id;
    }
    isCreateDialogOpen.value = true;
}

function closeCreateDialog(): void {
    isCreateDialogOpen.value = false;
    showCreatePassword.value = false;
    createForm.reset('name', 'email', 'password', 'job_title', 'employee_code');
    createForm.clearErrors();
}

function submitCreateUser(): void {
    createForm.post('/app/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateDialog();
        },
    });
}
</script>

<template>
    <Head title="User Management" />

    <div class="space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex items-center gap-2">
                <p class="text-[13px] text-secondary truncate">
                    Directory of provisioned accounts, roles, and reporting departments.
                </p>
                <Badge variant="neutral" class="tabular-nums">
                    {{ props.users.length }} users
                </Badge>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button
                    variant="primary"
                    size="sm"
                    data-testid="open-create-user-dialog"
                    @click="openCreateDialog"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    <span>Create User</span>
                </Button>
            </div>
        </div>

        <div
            v-if="flashSuccess"
            role="status"
            data-testid="user-created-banner"
            class="flex items-center gap-2 rounded-[6px] border border-status-success/30 bg-status-success/10 px-3 py-2 text-[13px] text-status-success"
        >
            <CheckCircle2 class="h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ flashSuccess }}</span>
        </div>

        <section
            aria-label="Provisioned users"
            class="overflow-hidden rounded-[8px] border border-default bg-surface"
        >
            <div
                v-if="props.initialState === 'loading'"
                data-testid="users-loading-state"
                class="divide-y divide-default"
            >
                <div
                    v-for="row in 4"
                    :key="row"
                    class="flex h-8 items-center justify-between gap-4 px-4"
                >
                    <div class="h-3 w-24 rounded bg-subtle" />
                    <div class="h-3 flex-1 rounded bg-subtle" />
                    <div class="h-3 w-20 rounded bg-subtle" />
                </div>
            </div>

            <div
                v-else-if="props.initialState === 'error'"
                role="alert"
                data-testid="users-error-state"
                class="m-4 flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 p-3 text-[13px] text-status-danger"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ props.initialError || 'Unable to load directory users.' }}</span>
            </div>

            <div
                v-else-if="props.users.length === 0"
                data-testid="users-empty-state"
                class="px-4 py-8 text-center space-y-2"
            >
                <Users class="mx-auto h-5 w-5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <p class="text-[13px] font-medium text-primary">
                    No users provisioned yet
                </p>
                <p class="text-[12px] text-secondary">
                    Create an employee account to grant access to CRM pipelines and handoffs.
                </p>
                <Button variant="primary" size="sm" @click="openCreateDialog">
                    Create User
                </Button>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="h-8 border-b border-default bg-subtle text-[11px] font-medium text-secondary">
                            <th class="px-4 py-1.5 font-medium whitespace-nowrap">Code</th>
                            <th class="px-3 py-1.5 font-medium">Name &amp; Email</th>
                            <th class="px-3 py-1.5 font-medium whitespace-nowrap">Department</th>
                            <th class="px-3 py-1.5 font-medium whitespace-nowrap">Role</th>
                            <th class="px-4 py-1.5 text-right font-medium whitespace-nowrap">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-default text-[13px]">
                        <tr
                            v-for="user in props.users"
                            :key="user.id"
                            class="h-8 transition-colors duration-150 hover:bg-subtle/60"
                        >
                            <td class="px-4 py-1.5 font-mono text-[12px] tabular-nums text-secondary whitespace-nowrap">
                                {{ user.employee_code }}
                            </td>
                            <td class="px-3 py-1.5 min-w-0 max-w-[300px]">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-medium text-primary truncate">
                                        {{ user.name }}
                                    </span>
                                    <span class="text-[12px] text-secondary truncate">
                                        {{ user.email }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-1.5 text-[12px] text-secondary whitespace-nowrap">
                                {{ user.department_name }}
                            </td>
                            <td class="px-3 py-1.5 whitespace-nowrap">
                                <Badge variant="cobalt">
                                    {{ roleLabel(user.roles[0] ?? 'employee') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-1.5 text-right whitespace-nowrap">
                                <Badge :variant="statusVariant(user.status)">
                                    {{ user.status }}
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog
            :open="isCreateDialogOpen"
            title="Create User"
            description="Provision a new employee account with initial role and credentials."
            max-width-class="max-w-md"
            @update:open="(open) => (open ? (isCreateDialogOpen = true) : closeCreateDialog())"
        >
            <form
                ref="createFormRef"
                data-testid="create-user-form"
                class="space-y-3"
                @submit.prevent="submitCreateUser"
            >
                <div class="space-y-1">
                    <label for="create-user-name" class="block text-[12px] font-medium text-primary">
                        Full name
                    </label>
                    <Input
                        id="create-user-name"
                        v-model="createForm.name"
                        name="name"
                        type="text"
                        autocomplete="name"
                        enterkeyhint="next"
                        placeholder="Jordan Mercer"
                        data-error="create-user-name-error"
                        :error-message="createForm.errors.name"
                        required
                    />
                    <p
                        id="create-user-name-error"
                        class="hidden text-[12px] text-status-danger [:has(#create-user-name:user-invalid)_&]:block [:has(#create-user-name.user-invalid-fallback)_&]:block"
                    >
                        Enter the employee's full name.
                    </p>
                </div>

                <div class="space-y-1">
                    <label for="create-user-email" class="block text-[12px] font-medium text-primary">
                        Email address
                    </label>
                    <Input
                        id="create-user-email"
                        v-model="createForm.email"
                        name="email"
                        type="email"
                        autocomplete="off"
                        :spellcheck="false"
                        enterkeyhint="next"
                        placeholder="jordan.mercer@company.internal"
                        data-error="create-user-email-error"
                        :error-message="createForm.errors.email"
                        required
                    />
                    <p
                        id="create-user-email-error"
                        class="hidden text-[12px] text-status-danger [:has(#create-user-email:user-invalid)_&]:block [:has(#create-user-email.user-invalid-fallback)_&]:block"
                    >
                        Enter an email address in the format name@example.com.
                    </p>
                </div>

                <div class="space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <label for="create-user-password" class="block text-[12px] font-medium text-primary">
                            Initial password
                        </label>
                        <button
                            type="button"
                            data-testid="toggle-create-password-visibility"
                            :aria-pressed="showCreatePassword ? 'true' : 'false'"
                            class="inline-flex items-center gap-1 text-[11px] font-medium text-secondary hover:text-primary cursor-pointer"
                            @click="showCreatePassword = !showCreatePassword"
                        >
                            <EyeOff v-if="showCreatePassword" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <Eye v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                            <span>{{ showCreatePassword ? 'Hide' : 'Show' }}</span>
                        </button>
                    </div>
                    <p id="create-user-password-rules" class="text-[11px] leading-4 text-secondary">
                        At least 8 characters including uppercase, lowercase, number, and symbol.
                    </p>
                    <Input
                        id="create-user-password"
                        v-model="createForm.password"
                        name="password"
                        :type="showCreatePassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        enterkeyhint="next"
                        :minlength="8"
                        pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}"
                        described-by="create-user-password-rules"
                        data-error="create-user-password-error"
                        placeholder="Set initial password"
                        :error-message="createForm.errors.password"
                        required
                    />
                    <p
                        id="create-user-password-error"
                        class="hidden text-[12px] text-status-danger [:has(#create-user-password:user-invalid)_&]:block [:has(#create-user-password.user-invalid-fallback)_&]:block"
                    >
                        Password must meet all requirements listed above.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="create-user-role" class="block text-[12px] font-medium text-primary">
                            Role
                        </label>
                        <select
                            id="create-user-role"
                            v-model="createForm.role"
                            name="role"
                            required
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary"
                        >
                            <option v-for="role in props.roles" :key="role.value" :value="role.value">
                                {{ role.label }}
                            </option>
                        </select>
                        <p v-if="createForm.errors.role" role="alert" class="text-[12px] text-status-danger">
                            {{ createForm.errors.role }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <label for="create-user-department" class="block text-[12px] font-medium text-primary">
                            Department
                        </label>
                        <select
                            id="create-user-department"
                            v-model="createForm.department_id"
                            name="department_id"
                            class="h-8 w-full rounded-[6px] border border-default bg-surface px-2.5 text-[13px] text-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary"
                        >
                            <option v-for="dept in props.departments" :key="dept.id" :value="dept.id">
                                {{ dept.name }}
                            </option>
                        </select>
                        <p v-if="createForm.errors.department_id" role="alert" class="text-[12px] text-status-danger">
                            {{ createForm.errors.department_id }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="create-user-job-title" class="block text-[12px] font-medium text-primary">
                            Job title <span class="text-muted">(optional)</span>
                        </label>
                        <Input
                            id="create-user-job-title"
                            v-model="createForm.job_title"
                            name="job_title"
                            type="text"
                            autocomplete="organization-title"
                            placeholder="Account Executive"
                            :error-message="createForm.errors.job_title"
                        />
                    </div>

                    <div class="space-y-1">
                        <label for="create-user-employee-code" class="block text-[12px] font-medium text-primary">
                            Employee code <span class="text-muted">(optional)</span>
                        </label>
                        <Input
                            id="create-user-employee-code"
                            v-model="createForm.employee_code"
                            name="employee_code"
                            type="text"
                            placeholder="Auto-generated"
                            :error-message="createForm.errors.employee_code"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-default pt-3">
                    <Button variant="secondary" size="md" type="button" @click="closeCreateDialog">
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="md"
                        type="submit"
                        data-testid="submit-create-user"
                        :loading="createForm.processing"
                    >
                        Create User
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
