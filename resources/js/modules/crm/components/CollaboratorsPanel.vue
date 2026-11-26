<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { AlertCircle, Plus, Trash2, Users } from 'lucide-vue-next';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import { crmApi } from '@/modules/crm/api/crmApi';
import { formatEnumLabel } from '@/modules/crm/utils/formatters';

const props = defineProps<{
    entityType: 'company' | 'contact' | 'deal';
    entityId: string;
    collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[];
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    accessLevels: string[];
    roleLabels: string[];
    canManage: boolean;
}>();

const instanceId = useId();
const userSelectId = `collab-user-${instanceId}`;
const accessSelectId = `collab-access-${instanceId}`;
const roleSelectId = `collab-role-${instanceId}`;

const items = ref<App.Modules.Core.Rbac.Data.EntityCollaboratorData[]>([...props.collaborators]);

watch(
    () => props.collaborators,
    (next) => {
        items.value = [...next];
    },
);

const selectedUserId = ref<string>('');
const selectedAccessLevel = ref<string>(props.accessLevels[0] ?? 'viewer');
const selectedRoleLabel = ref<string>(props.roleLabels[0] ?? 'observer');
const submitting = ref<boolean>(false);
const revokingUserId = ref<string | null>(null);
const errorMessage = ref<string>('');

const availableUsers = computed(() => {
    const existingIds = new Set(items.value.map((c) => c.user_id));
    return props.assignableUsers.filter((user) => !existingIds.has(user.id));
});

async function handleGrant(): Promise<void> {
    if (!props.canManage || submitting.value || !selectedUserId.value) {
        return;
    }

    submitting.value = true;
    errorMessage.value = '';

    try {
        const granted = await crmApi.grantCollaborator(props.entityType, props.entityId, {
            user_id: selectedUserId.value,
            access_level: selectedAccessLevel.value as App.Modules.Core.Rbac.Enums.CollaboratorAccessLevel,
            role_label: selectedRoleLabel.value as App.Modules.Core.Rbac.Enums.CollaboratorRoleLabel,
        });

        const existingIndex = items.value.findIndex((c) => c.user_id === granted.user_id);
        if (existingIndex >= 0) {
            const next = [...items.value];
            next[existingIndex] = granted;
            items.value = next;
        } else {
            items.value = [...items.value, granted];
        }

        selectedUserId.value = '';
    } catch (error) {
        errorMessage.value =
            error instanceof Error ? error.message : 'Unable to grant collaborator access.';
    } finally {
        submitting.value = false;
    }
}

async function handleRevoke(userId: string): Promise<void> {
    if (!props.canManage || revokingUserId.value) {
        return;
    }

    revokingUserId.value = userId;
    errorMessage.value = '';

    try {
        await crmApi.revokeCollaborator(props.entityType, props.entityId, userId);
        items.value = items.value.filter((c) => c.user_id !== userId);
    } catch (error) {
        errorMessage.value =
            error instanceof Error ? error.message : 'Unable to revoke collaborator access.';
    } finally {
        revokingUserId.value = null;
    }
}
</script>

<template>
    <section
        data-testid="collaborators-panel"
        aria-label="Collaborators"
        class="rounded-[8px] border border-default bg-surface p-3.5 space-y-3"
    >
        <div class="flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-primary">
                <Users class="h-3.5 w-3.5 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                <span>Collaborators</span>
            </h3>
            <Badge variant="neutral">{{ items.length }}</Badge>
        </div>

        <div
            v-if="errorMessage"
            role="alert"
            class="flex items-start gap-2 rounded-[6px] border border-status-danger/30 bg-status-danger/10 px-2.5 py-2 text-[12px] text-status-danger"
        >
            <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" :stroke-width="1.75" aria-hidden="true" />
            <span>{{ errorMessage }}</span>
        </div>

        <!-- Existing Collaborators List -->
        <div v-if="items.length === 0" class="rounded-[6px] border border-dashed border-default px-3 py-3 text-[12px] text-muted">
            No cross-silo collaborators granted.
        </div>

        <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
            <li
                v-for="collab in items"
                :key="collab.id"
                class="flex items-center justify-between gap-2 px-2.5 py-2 text-[12px]"
            >
                <div class="min-w-0 space-y-0.5">
                    <p class="truncate font-medium text-primary">
                        {{ collab.user_name ?? 'Workspace Member' }}
                    </p>
                    <div class="flex flex-wrap items-center gap-1">
                        <Badge variant="cobalt">{{ collab.access_level }}</Badge>
                        <span class="text-[11px] text-secondary">
                            {{ formatEnumLabel(collab.role_label) }}
                        </span>
                    </div>
                </div>

                <button
                    v-if="props.canManage"
                    type="button"
                    :aria-label="`Revoke access for ${collab.user_name ?? 'collaborator'}`"
                    :disabled="revokingUserId === collab.user_id"
                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] text-secondary hover:bg-status-danger/10 hover:text-status-danger cursor-pointer disabled:opacity-40"
                    @click="handleRevoke(collab.user_id)"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                </button>
            </li>
        </ul>

        <!-- Grant Collaborator Form (strictly permission-gated) -->
        <form
            v-if="props.canManage"
            class="space-y-2 border-t border-default pt-2.5"
            @submit.prevent="handleGrant"
        >
            <div>
                <label :for="userSelectId" class="block text-[11px] font-medium text-secondary mb-1">
                    Grant cross-silo access
                </label>
                <select
                    :id="userSelectId"
                    v-model="selectedUserId"
                    :disabled="submitting"
                    class="h-8 w-full rounded-[6px] border border-default bg-surface px-2 text-[12px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                >
                    <option value="">Select employee...</option>
                    <option
                        v-for="user in availableUsers"
                        :key="user.id"
                        :value="user.id"
                    >
                        {{ user.name }} ({{ user.employee_code }})
                    </option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label :for="accessSelectId" class="sr-only">Access level</label>
                    <select
                        :id="accessSelectId"
                        v-model="selectedAccessLevel"
                        :disabled="submitting"
                        class="h-7 w-full rounded-[6px] border border-default bg-surface px-2 text-[11px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <option
                            v-for="level in props.accessLevels"
                            :key="level"
                            :value="level"
                        >
                            {{ formatEnumLabel(level) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label :for="roleSelectId" class="sr-only">Role label</label>
                    <select
                        :id="roleSelectId"
                        v-model="selectedRoleLabel"
                        :disabled="submitting"
                        class="h-7 w-full rounded-[6px] border border-default bg-surface px-2 text-[11px] text-primary focus-visible:outline-2 focus-visible:outline-accent-primary"
                    >
                        <option
                            v-for="role in props.roleLabels"
                            :key="role"
                            :value="role"
                        >
                            {{ formatEnumLabel(role) }}
                        </option>
                    </select>
                </div>
            </div>

            <Button
                type="submit"
                variant="secondary"
                size="sm"
                class="w-full"
                :loading="submitting"
                :disabled="!selectedUserId"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                <span>Grant Collaborator</span>
            </Button>
        </form>
        <p v-else class="text-[11px] text-muted">
            Only record owners and managers can modify collaborators.
        </p>
    </section>
</template>
