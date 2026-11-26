<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, useId, watch, type Component } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Crosshair,
    Kanban,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    Search,
    SlidersHorizontal,
    Sun,
    UserCog,
    Users,
    X,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useRealtimeStore } from '@/stores/realtime.store';

interface CrmPageProps {
    auth?: {
        user?: App.Modules.Core.Auth.Data.AuthenticatedUserData | null;
    };
    moduleTitle?: string;
    [key: string]: unknown;
}

interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: Component;
    keywords: string;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

const page = usePage<CrmPageProps>();
const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();

const drawerId = `crm-nav-drawer-${useId()}`;

const canManageCrmSettings = computed(
    () =>
        authStore.hasPermission('pipelines.manage') ||
        authStore.hasPermission('custom_fields.manage'),
);

const visibleNavGroups = computed<NavGroup[]>(() => {
    const crmItems: NavItem[] = [
        {
            key: 'crm.overview',
            label: 'Overview',
            href: '/app',
            icon: LayoutDashboard,
            keywords: 'home overview dashboard forecast pipeline kpi',
        },
        ...(authStore.hasPermission('leads.view')
            ? [
                  {
                      key: 'crm.leads',
                      label: 'Leads',
                      href: '/app/crm/leads',
                      icon: Crosshair,
                      keywords: 'leads prospects qualification conversion inbound outbound',
                  },
              ]
            : []),
        ...(authStore.hasPermission('contacts.view')
            ? [
                  {
                      key: 'crm.contacts',
                      label: 'Contacts',
                      href: '/app/crm/contacts',
                      icon: Users,
                      keywords: 'people stakeholders directory contacts',
                  },
              ]
            : []),
        ...(authStore.hasPermission('companies.view')
            ? [
                  {
                      key: 'crm.companies',
                      label: 'Companies',
                      href: '/app/crm/companies',
                      icon: Building2,
                      keywords: 'accounts organizations clients companies',
                  },
              ]
            : []),
        ...(authStore.hasPermission('deals.view')
            ? [
                  {
                      key: 'crm.deals',
                      label: 'Deals',
                      href: '/app/crm/deals',
                      icon: Kanban,
                      keywords: 'pipeline opportunities kanban deals revenue',
                  },
              ]
            : []),
        ...(canManageCrmSettings.value
            ? [
                  {
                      key: 'crm.settings.pipelines',
                      label: 'CRM Settings',
                      href: '/app/crm/settings/pipelines',
                      icon: SlidersHorizontal,
                      keywords: 'pipelines stages gates custom fields settings crm',
                  },
              ]
            : []),
    ];

    const groups: NavGroup[] = [
        {
            label: 'CRM',
            items: crmItems,
        },
    ];

    if (authStore.hasRole('superadmin') && authStore.hasPermission('admin.users')) {
        groups.push({
            label: 'Admin',
            items: [
                {
                    key: 'admin.users',
                    label: 'Users',
                    href: '/app/admin/users',
                    icon: UserCog,
                    keywords: 'admin users accounts roles permissions superadmin',
                },
            ],
        });
    }

    return groups;
});

const allNavItems = computed(() => visibleNavGroups.value.flatMap((group) => group.items));

const isMobileNavOpen = ref(false);
const isCommandOpen = ref(false);
const commandQuery = ref('');
const isDark = ref(false);
const signingOut = ref(false);

watch(
    () => page.props?.auth?.user ?? null,
    (nextUser) => {
        authStore.setUser(nextUser);
        realtimeStore.connectForUser(nextUser?.id ?? null);
    },
    { immediate: true },
);

watch(
    () => page.url,
    () => {
        isMobileNavOpen.value = false;
    },
);

const currentPath = computed(() => (page.url ?? '/app').split('?')[0]);

function isNavItemActive(href: string): boolean {
    const path = currentPath.value;

    if (href === '/app') {
        return path === '/app' || path === '/';
    }

    return path === href || path.startsWith(`${href}/`);
}

const activePageTitle = computed(() => {
    const propTitle = page.props?.moduleTitle;

    if (typeof propTitle === 'string' && propTitle.trim() !== '') {
        return propTitle;
    }

    const activeItem = allNavItems.value.find((item) => isNavItemActive(item.href));
    return activeItem?.label ?? 'CRM Overview';
});

const roleLabel = computed(() => {
    const role = authStore.primaryRole;

    if (!role) {
        return 'Employee';
    }

    return role
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
});

const userInitials = computed(() => {
    const name = authStore.user?.name?.trim();

    if (!name) {
        return 'CR';
    }

    const parts = name.split(/\s+/);
    const first = parts[0]?.charAt(0) ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
    return (first + last).toUpperCase() || 'CR';
});

const connectionMeta = computed(() => {
    switch (realtimeStore.status) {
        case 'connected':
            return {
                dotClass: 'bg-status-success',
                label: 'Live',
            };
        case 'reconnecting':
            return {
                dotClass: 'bg-status-warning',
                label: 'Reconnecting',
            };
        default:
            return {
                dotClass: 'bg-status-danger',
                label: 'Offline',
            };
    }
});

const filteredCommandItems = computed(() => {
    const query = commandQuery.value.trim().toLowerCase();

    if (query === '') {
        return allNavItems.value;
    }

    return allNavItems.value.filter(
        (item) =>
            item.label.toLowerCase().includes(query) ||
            item.href.toLowerCase().includes(query) ||
            item.keywords.toLowerCase().includes(query),
    );
});

function applyThemeClass(darkMode: boolean): void {
    isDark.value = darkMode;

    if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle('dark', darkMode);
        document.documentElement.style.colorScheme = darkMode ? 'dark' : 'light';
    }
}

function toggleTheme(): void {
    const nextDark = !isDark.value;
    applyThemeClass(nextDark);

    try {
        localStorage.setItem('crm-theme', nextDark ? 'dark' : 'light');
    } catch {
        // Ignore storage errors in restricted browser contexts.
    }
}

function handleStorageSync(event: StorageEvent): void {
    if (event.key === 'crm-theme') {
        applyThemeClass(event.newValue === 'dark');
    }
}

function openCommandPalette(): void {
    isCommandOpen.value = true;
    void nextTick(() => {
        if (typeof document !== 'undefined') {
            document.getElementById('crm-command-search')?.focus();
        }
    });
}

function closeCommandPalette(): void {
    isCommandOpen.value = false;
    commandQuery.value = '';
}

function handleCommandOpenChange(nextOpen: boolean): void {
    if (!nextOpen) {
        closeCommandPalette();
        return;
    }

    openCommandPalette();
}

function navigateFromCommand(href: string): void {
    closeCommandPalette();
    router.visit(href);
}

function submitCommandFirstMatch(): void {
    const first = filteredCommandItems.value[0];
    if (first) {
        navigateFromCommand(first.href);
    }
}

function handleLogout(): void {
    if (signingOut.value) {
        return;
    }

    signingOut.value = true;
    router.post(
        '/logout',
        {},
        {
            onFinish: () => {
                signingOut.value = false;
            },
        },
    );
}

function handleGlobalKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();

        if (isCommandOpen.value) {
            closeCommandPalette();
        } else {
            openCommandPalette();
        }

        return;
    }

    if (event.key === 'Escape' && isMobileNavOpen.value) {
        isMobileNavOpen.value = false;
    }
}

onMounted(() => {
    if (typeof document !== 'undefined') {
        applyThemeClass(document.documentElement.classList.contains('dark'));
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('keydown', handleGlobalKeydown);
        window.addEventListener('storage', handleStorageSync);
    }
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', handleGlobalKeydown);
        window.removeEventListener('storage', handleStorageSync);
    }
});
</script>

<template>
    <div class="flex min-h-screen bg-canvas text-primary">
        <!-- Mobile backdrop -->
        <div
            v-if="isMobileNavOpen"
            class="fixed inset-0 z-40 bg-black/40 transition-opacity duration-150 ease-out lg:hidden"
            aria-hidden="true"
            @click="isMobileNavOpen = false"
        />

        <!-- Persistent 240px Desktop Rail / Mobile Slide-over Drawer -->
        <aside
            :id="drawerId"
            aria-label="CRM navigation"
            :class="[
                'flex-col border-r border-default bg-subtle',
                isMobileNavOpen
                    ? 'fixed inset-y-0 left-0 z-50 flex w-[260px] max-w-[85vw]'
                    : 'hidden lg:flex lg:w-[240px] lg:shrink-0',
            ]"
        >
            <!-- Rail Header -->
            <div class="flex h-11 shrink-0 items-center justify-between gap-2 border-b border-default px-3">
                <div class="flex min-w-0 items-center gap-2">
                    <span
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-[4px] border border-default bg-surface font-mono text-[11px] font-semibold text-primary"
                        aria-hidden="true"
                    >
                        C
                    </span>
                    <span class="truncate text-[13px] font-semibold tracking-[-0.01em] text-primary">
                        Standalone CRM
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="truncate text-[11px] font-medium text-secondary">
                        {{ roleLabel }}
                    </span>
                    <button
                        v-if="isMobileNavOpen"
                        type="button"
                        aria-label="Close navigation menu"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-[6px] text-secondary hover:bg-surface hover:text-primary lg:hidden cursor-pointer"
                        @click="isMobileNavOpen = false"
                    >
                        <X class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <!-- Cmd+K Search Trigger -->
            <div class="px-2.5 pt-2.5">
                <button
                    type="button"
                    data-testid="command-palette-trigger"
                    class="flex h-8 w-full items-center justify-between rounded-[6px] border border-default bg-surface px-2.5 text-[12px] text-muted transition-colors duration-150 ease-out hover:text-primary cursor-pointer"
                    @click="openCommandPalette"
                >
                    <span class="flex items-center gap-2 truncate">
                        <Search class="h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                        <span class="truncate">Search CRM</span>
                    </span>
                    <kbd
                        class="shrink-0 rounded border border-default bg-subtle px-1.5 py-0.5 font-mono text-[10px] font-medium tabular-nums text-secondary"
                    >
                        Cmd+K
                    </kbd>
                </button>
            </div>

            <!-- Grouped Navigation -->
            <nav aria-label="Primary sections" class="flex-1 overflow-y-auto px-2.5 py-3 space-y-4">
                <div v-for="group in visibleNavGroups" :key="group.label">
                    <p class="mb-1 px-2 text-[11px] font-medium uppercase tracking-wider text-secondary">
                        {{ group.label }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in group.items" :key="item.key">
                            <Link
                                :href="item.href"
                                :aria-current="isNavItemActive(item.href) ? 'page' : undefined"
                                :class="[
                                    'flex h-7 items-center gap-2 rounded-[6px] px-2 text-[13px] transition-colors duration-150 ease-out',
                                    isNavItemActive(item.href)
                                        ? 'border border-default bg-surface font-medium text-primary'
                                        : 'border border-transparent text-secondary hover:bg-surface/70 hover:text-primary',
                                ]"
                            >
                                <component
                                    :is="item.icon"
                                    :class="[
                                        'h-4 w-4 shrink-0',
                                        isNavItemActive(item.href) ? 'text-accent-primary' : 'text-secondary',
                                    ]"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Rail Footer -->
            <div class="shrink-0 border-t border-default p-2.5 space-y-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-default bg-surface font-mono text-[11px] font-semibold text-primary"
                        aria-hidden="true"
                    >
                        {{ userInitials }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[12px] font-medium leading-4 text-primary">
                            {{ authStore.user?.name ?? 'Local Operator' }}
                        </p>
                        <p class="truncate text-[11px] leading-4 text-secondary">
                            {{ authStore.user?.email ?? 'operator@standalone-crm.local' }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 pt-0.5">
                    <div
                        class="flex items-center gap-1.5 text-[11px] font-medium text-secondary"
                        data-testid="realtime-status-indicator"
                    >
                        <span
                            :class="['h-1.5 w-1.5 shrink-0 rounded-full', connectionMeta.dotClass]"
                            aria-hidden="true"
                        />
                        <span>{{ connectionMeta.label }}</span>
                    </div>

                    <Button
                        v-if="authStore.isAuthenticated"
                        variant="ghost"
                        size="sm"
                        :loading="signingOut"
                        aria-label="Sign out"
                        @click="handleLogout"
                    >
                        <LogOut class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>Sign out</span>
                    </Button>
                </div>
            </div>
        </aside>

        <!-- Main CRM Column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <!-- 44px Compact Top Bar -->
            <header
                class="flex h-11 shrink-0 items-center justify-between gap-3 border-b border-default bg-surface px-3 sm:px-4"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <button
                        type="button"
                        aria-label="Open navigation menu"
                        :aria-expanded="isMobileNavOpen ? 'true' : 'false'"
                        :aria-controls="drawerId"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary hover:bg-subtle hover:text-primary lg:hidden cursor-pointer"
                        @click="isMobileNavOpen = true"
                    >
                        <Menu class="h-4 w-4" :stroke-width="1.75" aria-hidden="true" />
                    </button>
                    <h1 class="truncate text-[15px] sm:text-[18px] font-semibold tracking-[-0.02em] text-primary">
                        {{ activePageTitle }}
                    </h1>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <div
                        class="flex items-center gap-1.5 rounded-full border border-default bg-subtle px-2 py-0.5 text-[11px] font-medium text-secondary lg:hidden"
                    >
                        <span
                            :class="['h-1.5 w-1.5 shrink-0 rounded-full', connectionMeta.dotClass]"
                            aria-hidden="true"
                        />
                        <span>{{ connectionMeta.label }}</span>
                    </div>

                    <button
                        type="button"
                        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        :aria-pressed="isDark ? 'true' : 'false'"
                        data-testid="theme-toggle"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary transition-colors duration-150 ease-out hover:bg-subtle hover:text-primary cursor-pointer"
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDark" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <Moon v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    </button>
                </div>
            </header>

            <!-- Swapped Inertia Page Slot -->
            <main class="min-w-0 flex-1 overflow-y-auto p-3 sm:p-5">
                <slot />
            </main>
        </div>

        <!-- Global Cmd+K / Ctrl+K Command Palette -->
        <Dialog
            :open="isCommandOpen"
            title="Command Palette"
            description="Jump directly to CRM sections or clear your filter to inspect available destinations."
            max-width-class="max-w-xl"
            @update:open="handleCommandOpenChange"
        >
            <div class="space-y-3" data-testid="command-palette-dialog">
                <form @submit.prevent="submitCommandFirstMatch">
                    <label for="crm-command-search" class="sr-only">
                        Search CRM modules
                    </label>
                    <Input
                        id="crm-command-search"
                        v-model="commandQuery"
                        placeholder="Jump to Overview, Leads, Contacts, Companies, Deals..."
                        autocomplete="off"
                        :spellcheck="false"
                    />
                </form>

                <div
                    v-if="filteredCommandItems.length === 0"
                    class="rounded-[6px] border border-default bg-subtle px-3 py-5 text-center space-y-2"
                >
                    <p class="text-[13px] font-medium text-primary">
                        No matching views for "{{ commandQuery }}"
                    </p>
                    <p class="text-[12px] text-secondary">
                        Try searching by section name such as Leads, Companies, Deals, or Settings.
                    </p>
                    <Button variant="secondary" size="sm" @click="commandQuery = ''">
                        Clear search
                    </Button>
                </div>

                <ul
                    v-else
                    role="listbox"
                    aria-label="CRM destinations"
                    class="divide-y divide-default rounded-[6px] border border-default bg-surface"
                >
                    <li v-for="item in filteredCommandItems" :key="item.key">
                        <button
                            type="button"
                            class="flex h-8 w-full items-center justify-between px-3 text-left text-[13px] text-primary transition-colors duration-150 hover:bg-subtle cursor-pointer"
                            @click="navigateFromCommand(item.href)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <component
                                    :is="item.icon"
                                    class="h-4 w-4 shrink-0 text-secondary"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <span class="truncate font-medium">{{ item.label }}</span>
                            </span>
                            <span class="font-mono text-[11px] text-muted">
                                {{ item.href }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </Dialog>
    </div>
</template>
