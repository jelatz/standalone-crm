import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export type AuthenticatedUser = App.Modules.Core.Auth.Data.AuthenticatedUserData;
export type WorkspaceRole = App.Modules.Core.Rbac.Enums.RoleEnum;
export type WorkspacePermission = App.Modules.Core.Rbac.Enums.PermissionEnum;

export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthenticatedUser | null>(null);

    const isAuthenticated = computed(() => user.value !== null);
    const primaryRole = computed<string | null>(() => user.value?.roles[0] ?? null);

    function setUser(nextUser: AuthenticatedUser | null): void {
        user.value = nextUser;
    }

    function syncFromPageProps(authProps?: { user?: AuthenticatedUser | null } | null): void {
        user.value = authProps?.user ?? null;
    }

    function hasRole(role: WorkspaceRole | string): boolean {
        return user.value?.roles.includes(role) ?? false;
    }

    function hasPermission(permission: WorkspacePermission | string): boolean {
        return user.value?.permissions.includes(permission) ?? false;
    }

    function clear(): void {
        user.value = null;
    }

    return {
        user,
        isAuthenticated,
        primaryRole,
        setUser,
        syncFromPageProps,
        hasRole,
        hasPermission,
        clear,
    };
});
