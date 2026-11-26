<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';

type ProjectRow = App.Modules.Projects.Projects.Data.ProjectSummaryData;

const props = defineProps<{
    projects: {
        data: ProjectRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: App.Modules.Projects.Projects.Data.ProjectFilterData;
    assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[];
    companies: Array<{ id: string; name: string; domain: string | null }>;
    customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[];
    statuses: string[];
    healthOptions: string[];
    can: {
        create: boolean;
    };
}>();
</script>

<template>
    <Head title="Projects" />

    <div class="space-y-4 rounded-[8px] border border-default bg-surface p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-[16px] font-semibold tracking-[-0.01em] text-primary">
                    Projects
                </h1>
                <p class="text-[13px] text-secondary">
                    Silo-scoped delivery projects, boards, and milestone tracking.
                </p>
            </div>
            <Badge variant="cobalt">{{ props.projects.total }} total</Badge>
        </div>

        <div v-if="props.projects.data.length === 0" class="rounded-[6px] border border-default bg-subtle p-4 text-[13px] text-secondary">
            No projects match the current filter.
        </div>

        <ul v-else class="divide-y divide-default rounded-[6px] border border-default">
            <li
                v-for="project in props.projects.data"
                :key="project.id"
                class="flex items-center justify-between gap-3 px-4 py-3 text-[13px]"
            >
                <div class="min-w-0">
                    <Link
                        :href="`/app/projects/${project.id}`"
                        class="font-medium text-primary hover:underline"
                    >
                        <span class="font-mono text-[12px] text-secondary">{{ project.code }}</span>
                        — {{ project.name }}
                    </Link>
                    <p class="text-[12px] text-secondary">
                        {{ project.company_name ?? 'Internal Delivery' }}
                    </p>
                </div>
                <Badge variant="neutral">{{ project.status }}</Badge>
            </li>
        </ul>
    </div>
</template>
