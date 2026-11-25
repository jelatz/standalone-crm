<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch, type Component } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    FileText,
    FolderKanban,
    Inbox,
    Kanban,
    LayoutDashboard,
    LogOut,
    Mail,
    Menu,
    MessageSquare,
    Moon,
    Plus,
    Search,
    Sun,
    Trash2,
    UserCog,
    Users,
    X,
} from 'lucide-vue-next';
import Badge, { type BadgeVariant } from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import Input from '@/components/ui/Input.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useDraftsStore, type DraftKind } from '@/stores/drafts.store';
import { useRealtimeStore } from '@/stores/realtime.store';
import { useUnreadsStore, type UnreadChannel } from '@/stores/unreads.store';

interface WorkspacePageProps {
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
    badgeChannel?: UnreadChannel;
    badgeVariant?: BadgeVariant;
    keywords: string;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

const navGroups: NavGroup[] = [
    {
        label: 'Core',
        items: [
            {
                key: 'app.home',
                label: 'Overview',
                href: '/app',
                icon: LayoutDashboard,
                keywords: 'home dashboard overview operational kpi',
            },
        ],
    },
    {
        label: 'CRM',
        items: [
            {
                key: 'crm.companies',
                label: 'Companies',
                href: '/app/crm/companies',
                icon: Building2,
                keywords: 'accounts organizations clients companies',
            },
            {
                key: 'crm.contacts',
                label: 'Contacts',
                href: '/app/crm/contacts',
                icon: Users,
                keywords: 'people stakeholders directory contacts',
            },
            {
                key: 'crm.deals',
                label: 'Deals',
                href: '/app/crm/deals',
                icon: Kanban,
                keywords: 'pipeline opportunities kanban deals revenue',
            },
        ],
    },
    {
        label: 'Delivery',
        items: [
            {
                key: 'projects.handoffs',
                label: 'Handoff Intake',
                href: '/app/projects/handoffs',
                icon: ClipboardCheck,
                badgeChannel: 'handoffs',
                badgeVariant: 'warning',
                keywords: 'handoffs intake approval queue review',
            },
            {
                key: 'projects.index',
                label: 'Projects',
                href: '/app/projects',
                icon: FolderKanban,
                keywords: 'projects tasks delivery boards milestones',
            },
        ],
    },
    {
        label: 'Messaging',
        items: [
            {
                key: 'chat.index',
                label: 'Chat',
                href: '/app/chat',
                icon: MessageSquare,
                badgeChannel: 'chat',
                badgeVariant: 'cobalt',
                keywords: 'chat channels dm messages internal',
            },
            {
                key: 'email.index',
                label: 'Shared Inbox',
                href: '/app/email',
                icon: Inbox,
                badgeChannel: 'sharedInbox',
                badgeVariant: 'cobalt',
                keywords: 'email mail inbox triage shared',
            },
        ],
    },
];

const page = usePage<WorkspacePageProps>();
const authStore = useAuthStore();
const realtimeStore = useRealtimeStore();
const unreadsStore = useUnreadsStore();
const draftsStore = useDraftsStore();

const visibleNavGroups = computed<NavGroup[]>(() => {
    if (authStore.hasRole('superadmin') && authStore.hasPermission('admin.users')) {
        return [
            ...navGroups,
            {
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
            },
        ];
    }

    return navGroups;
});

const allNavItems = computed(() => visibleNavGroups.value.flatMap((group) => group.items));

const isMobileNavOpen = ref(false);
const isCommandOpen = ref(false);
const commandQuery = ref('');
const isCommandSearching = ref(false);
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

    if (href === '/app/projects') {
        return (
            path === '/app/projects' ||
            (path.startsWith('/app/projects/') && !path.startsWith('/app/projects/handoffs'))
        );
    }

    return path === href || path.startsWith(`${href}/`);
}

const activePageTitle = computed(() => {
    const propTitle = page.props?.moduleTitle;

    if (typeof propTitle === 'string' && propTitle.trim() !== '') {
        return propTitle;
    }

    const activeItem = allNavItems.value.find((item) => isNavItemActive(item.href));
    return activeItem?.label ?? 'Operational Overview';
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
                badgeVariant: 'success' as BadgeVariant,
            };
        case 'reconnecting':
            return {
                dotClass: 'bg-status-warning',
                label: 'Reconnecting',
                badgeVariant: 'warning' as BadgeVariant,
            };
        default:
            return {
                dotClass: 'bg-status-danger',
                label: 'Offline',
                badgeVariant: 'danger' as BadgeVariant,
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

function getUnreadCount(channel?: UnreadChannel): number {
    if (!channel) {
        return 0;
    }

    return unreadsStore[channel];
}

function toggleTheme(): void {
    const nextDark = !isDark.value;
    isDark.value = nextDark;

    if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle('dark', nextDark);
    }

    try {
        localStorage.setItem('workspace-theme', nextDark ? 'dark' : 'light');
    } catch {
        // Ignore storage errors in restricted browser contexts.
    }
}

function openCommandPalette(): void {
    isCommandOpen.value = true;
}

function closeCommandPalette(): void {
    isCommandOpen.value = false;
    commandQuery.value = '';
    isCommandSearching.value = false;
}

function handleCommandOpenChange(nextOpen: boolean): void {
    if (!nextOpen) {
        closeCommandPalette();
        return;
    }

    isCommandOpen.value = true;
}

function navigateFromCommand(href: string): void {
    closeCommandPalette();
    router.visit(href);
}

function createDraft(kind: DraftKind): void {
    draftsStore.openDraft({
        kind,
        title: kind === 'email' ? 'New email draft' : 'New chat note',
        targetLabel: kind === 'email' ? 'ops@company.internal' : '#delivery-triage',
        bodyText: '',
    });
}

function updateActiveDraftField(
    field: 'title' | 'targetLabel' | 'bodyText',
    value: string,
): void {
    const current = draftsStore.activeDraft;

    if (!current) {
        return;
    }

    draftsStore.upsertDraft({
        id: current.id,
        kind: current.kind,
        title: field === 'title' ? value : current.title,
        targetLabel: field === 'targetLabel' ? value : current.targetLabel,
        bodyText: field === 'bodyText' ? value : current.bodyText,
    });
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

function handleTrayKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && draftsStore.isTrayOpen) {
        draftsStore.toggleTray(false);
    }
}

watch(commandQuery, (nextQuery) => {
    if (nextQuery.trim() === '') {
        isCommandSearching.value = false;
    }
});

onMounted(() => {
    if (typeof document !== 'undefined') {
        isDark.value = document.documentElement.classList.contains('dark');
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('keydown', handleGlobalKeydown);
    }
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', handleGlobalKeydown);
    }
});
</script>

<template>
    <div class="flex min-h-screen bg-canvas text-primary">
        <!-- Mobile backdrop -->
        <div
            v-if="isMobileNavOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="isMobileNavOpen = false"
        />

        <!-- Persistent 240px Desktop Rail / Mobile Slide-over Drawer -->
        <aside
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
                        class="inline-flex h-6 w-6 items-center justify-center rounded-[6px] text-secondary hover:bg-surface hover:text-primary lg:hidden"
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
                                    'flex h-7 items-center justify-between gap-2 rounded-[6px] px-2 text-[13px] transition-colors duration-150 ease-out',
                                    isNavItemActive(item.href)
                                        ? 'border border-default bg-surface font-medium text-primary'
                                        : 'border border-transparent text-secondary hover:bg-surface/70 hover:text-primary',
                                ]"
                            >
                                <span class="flex min-w-0 items-center gap-2">
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
                                </span>
                                <Badge
                                    v-if="item.badgeChannel && getUnreadCount(item.badgeChannel) > 0"
                                    :variant="item.badgeVariant ?? 'neutral'"
                                    :data-testid="`unread-badge-${item.badgeChannel}`"
                                >
                                    {{ getUnreadCount(item.badgeChannel) }}
                                </Badge>
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
                            {{ authStore.user?.email ?? 'local@standalone-crm.local' }}
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
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary hover:bg-subtle hover:text-primary lg:hidden"
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
                        data-testid="theme-toggle"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] border border-default bg-surface text-secondary transition-colors duration-150 ease-out hover:bg-subtle hover:text-primary cursor-pointer"
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDark" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <Moon v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    </button>

                    <Button
                        variant="secondary"
                        size="sm"
                        data-testid="topbar-new-draft"
                        @click="createDraft('email')"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <span>New Draft</span>
                    </Button>
                </div>
            </header>

            <!-- Swapped Inertia Page Slot -->
            <main class="min-w-0 flex-1 overflow-y-auto p-3 sm:p-5 pb-20">
                <slot />
            </main>
        </div>

        <!-- Global Cmd+K / Ctrl+K Command Palette -->
        <Dialog
            :open="isCommandOpen"
            title="Command Palette"
            description="Jump directly to CRM modules or clear filter to inspect available destinations."
            max-width-class="max-w-xl"
            @update:open="handleCommandOpenChange"
        >
            <div class="space-y-3" data-testid="command-palette-dialog">
                <div>
                    <label for="workspace-command-search" class="sr-only">
                        Search CRM views and records
                    </label>
                    <Input
                        id="workspace-command-search"
                        v-model="commandQuery"
                        placeholder="Filter modules (Companies, Handoffs, Chat, Inbox)..."
                        autocomplete="off"
                    />
                </div>

                <div
                    v-if="isCommandSearching"
                    class="rounded-[6px] border border-default bg-subtle px-3 py-4 text-[12px] text-secondary"
                >
                    Searching indexed CRM views...
                </div>

                <div
                    v-else-if="filteredCommandItems.length === 0"
                    class="rounded-[6px] border border-default bg-subtle px-3 py-5 text-center space-y-2"
                >
                    <p class="text-[13px] font-medium text-primary">
                        No matching views for "{{ commandQuery }}"
                    </p>
                    <p class="text-[12px] text-secondary">
                        Full-text record indexing across CRM, Projects, and Mail activates in Phase 5.
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

        <!-- Bottom-Right Docked Persistent Drafts Tray -->
        <section
            aria-label="Persistent drafts tray"
            data-testid="persistent-drafts-tray"
            class="fixed bottom-3 right-3 z-30 w-[calc(100vw-1.5rem)] max-w-[380px] rounded-[8px] border border-default bg-surface shadow-lg"
            @keydown="handleTrayKeydown"
        >
            <div class="flex h-9 items-center justify-between gap-2 px-3">
                <button
                    type="button"
                    :aria-expanded="draftsStore.isTrayOpen ? 'true' : 'false'"
                    aria-label="Toggle drafts tray"
                    data-testid="drafts-tray-toggle"
                    class="flex min-w-0 flex-1 items-center gap-2 text-left cursor-pointer"
                    @click="draftsStore.toggleTray()"
                >
                    <FileText class="h-3.5 w-3.5 shrink-0 text-secondary" :stroke-width="1.75" aria-hidden="true" />
                    <span class="truncate text-[12px] font-semibold text-primary">
                        Drafts
                    </span>
                    <Badge variant="neutral" data-testid="drafts-tray-count">
                        {{ draftsStore.draftCount }}
                    </Badge>
                </button>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        aria-label="Start email draft"
                        class="inline-flex h-6 items-center gap-1 rounded-[4px] px-1.5 text-[11px] font-medium text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                        @click="createDraft('email')"
                    >
                        <Mail class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                        <span>Email</span>
                    </button>
                    <button
                        type="button"
                        aria-label="Start chat draft"
                        class="inline-flex h-6 items-center gap-1 rounded-[4px] px-1.5 text-[11px] font-medium text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                        @click="createDraft('chat')"
                    >
                        <MessageSquare class="h-3 w-3" :stroke-width="1.75" aria-hidden="true" />
                        <span>Chat</span>
                    </button>
                    <button
                        type="button"
                        :aria-label="draftsStore.isTrayOpen ? 'Collapse drafts tray' : 'Expand drafts tray'"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-[4px] text-secondary hover:bg-subtle hover:text-primary cursor-pointer"
                        @click="draftsStore.toggleTray()"
                    >
                        <ChevronDown v-if="draftsStore.isTrayOpen" class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                        <ChevronUp v-else class="h-3.5 w-3.5" :stroke-width="1.75" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <div
                v-if="draftsStore.isTrayOpen"
                class="border-t border-default p-3 space-y-2.5"
            >
                <div
                    v-if="!draftsStore.activeDraft"
                    class="rounded-[6px] border border-default bg-subtle p-3 text-center space-y-2"
                >
                    <p class="text-[12px] text-secondary">
                        No open drafts. Drafts started here remain mounted while navigating across CRM, Projects, Chat, and Inbox.
                    </p>
                    <div class="flex items-center justify-center gap-2">
                        <Button variant="secondary" size="sm" @click="createDraft('email')">
                            New Email Draft
                        </Button>
                        <Button variant="secondary" size="sm" @click="createDraft('chat')">
                            New Chat Note
                        </Button>
                    </div>
                </div>

                <template v-else>
                    <div
                        v-if="draftsStore.drafts.length > 1"
                        class="flex items-center gap-1 overflow-x-auto pb-0.5"
                    >
                        <button
                            v-for="draft in draftsStore.drafts"
                            :key="draft.id"
                            type="button"
                            :class="[
                                'inline-flex max-w-[140px] shrink-0 items-center gap-1 rounded-[4px] border px-2 py-0.5 text-[11px] font-medium truncate cursor-pointer',
                                draft.id === draftsStore.activeDraft.id
                                    ? 'border-accent-primary/40 bg-accent-primary/10 text-accent-primary'
                                    : 'border-default bg-subtle text-secondary hover:text-primary',
                            ]"
                            @click="draftsStore.selectDraft(draft.id)"
                        >
                            <span class="truncate">{{ draft.title || 'Untitled' }}</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-2">
                        <div>
                            <label for="docked-draft-title" class="sr-only">Draft title</label>
                            <Input
                                id="docked-draft-title"
                                :model-value="draftsStore.activeDraft.title"
                                placeholder="Subject or note title"
                                @update:model-value="updateActiveDraftField('title', $event)"
                            />
                        </div>

                        <div>
                            <label for="docked-draft-target" class="sr-only">Recipient or channel</label>
                            <Input
                                id="docked-draft-target"
                                :model-value="draftsStore.activeDraft.targetLabel"
                                placeholder="Recipient email or #channel"
                                @update:model-value="updateActiveDraftField('targetLabel', $event)"
                            />
                        </div>

                        <div>
                            <label for="docked-draft-body" class="sr-only">Draft body</label>
                            <textarea
                                id="docked-draft-body"
                                rows="4"
                                :value="draftsStore.activeDraft.bodyText"
                                placeholder="Write message or handoff note..."
                                class="w-full resize-none rounded-[6px] border border-default bg-surface p-2 text-[13px] text-primary placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-accent-primary"
                                @input="
                                    updateActiveDraftField(
                                        'bodyText',
                                        ($event.target as HTMLTextAreaElement).value,
                                    )
                                "
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-0.5">
                        <div class="flex items-center gap-1.5">
                            <Badge variant="neutral">
                                {{ draftsStore.activeDraft.kind.toUpperCase() }}
                            </Badge>
                            <span class="text-[11px] text-muted">
                                Held across module navigation
                            </span>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Discard draft"
                            @click="draftsStore.removeDraft(draftsStore.activeDraft.id)"
                        >
                            <Trash2 class="h-3.5 w-3.5 text-status-danger" :stroke-width="1.75" aria-hidden="true" />
                            <span>Discard</span>
                        </Button>
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
