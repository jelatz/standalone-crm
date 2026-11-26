import type { BadgeVariant } from '@/components/ui/Badge.vue';

export function handoffStatusVariant(
    status: App.Modules.Projects.Handoffs.Enums.DealHandoffStatusEnum,
): BadgeVariant {
    switch (status) {
        case 'pending_review':
            return 'warning';
        case 'changes_requested':
            return 'danger';
        case 'approved':
            return 'cobalt';
        case 'provisioned':
            return 'success';
        case 'rejected':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function handoffStatusLabel(
    status: App.Modules.Projects.Handoffs.Enums.DealHandoffStatusEnum,
): string {
    switch (status) {
        case 'pending_review':
            return 'Pending Review';
        case 'changes_requested':
            return 'Changes Requested';
        case 'approved':
            return 'Approved';
        case 'provisioned':
            return 'Provisioned';
        case 'rejected':
            return 'Rejected';
        default:
            return status;
    }
}

export function projectStatusVariant(
    status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
): BadgeVariant {
    switch (status) {
        case 'active':
            return 'cobalt';
        case 'on_hold':
            return 'warning';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function projectHealthVariant(
    health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
): BadgeVariant {
    switch (health) {
        case 'on_track':
            return 'success';
        case 'at_risk':
            return 'warning';
        case 'off_track':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function taskPriorityVariant(
    priority: App.Modules.Projects.Tasks.Enums.TaskPriorityEnum,
): BadgeVariant {
    switch (priority) {
        case 'urgent':
            return 'danger';
        case 'high':
            return 'warning';
        case 'medium':
            return 'cobalt';
        default:
            return 'neutral';
    }
}

export function taskStatusVariant(
    status: App.Modules.Projects.Tasks.Enums.TaskStatusEnum,
): BadgeVariant {
    switch (status) {
        case 'in_progress':
            return 'cobalt';
        case 'blocked':
            return 'danger';
        case 'review':
            return 'warning';
        case 'done':
            return 'success';
        default:
            return 'neutral';
    }
}

export function linkableHref(linkableType: string | null, linkableId: string | null): string | null {
    if (!linkableType || !linkableId) {
        return null;
    }

    switch (linkableType) {
        case 'deal':
            return `/app/crm/deals/${linkableId}`;
        case 'company':
            return `/app/crm/companies/${linkableId}`;
        case 'contact':
            return `/app/crm/contacts/${linkableId}`;
        default:
            return null;
    }
}

export function hasTipTapContent(doc: Record<string, any> | null | undefined): boolean {
    if (!doc || typeof doc !== 'object') {
        return false;
    }

    const walk = (node: unknown): boolean => {
        if (!node || typeof node !== 'object') {
            return false;
        }
        const candidate = node as { type?: string; text?: string; content?: unknown[] };
        if (candidate.type === 'text' && typeof candidate.text === 'string' && candidate.text.trim() !== '') {
            return true;
        }
        if (Array.isArray(candidate.content)) {
            return candidate.content.some(walk);
        }
        return false;
    };

    return walk(doc);
}

export function toHandoffSummaryFromDetail(
    detail: App.Modules.Projects.Handoffs.Data.DealHandoffDetailData,
): App.Modules.Projects.Handoffs.Data.DealHandoffSummaryData {
    return {
        id: detail.id,
        deal_id: detail.deal_id,
        company_id: detail.company_id,
        deal_title: detail.deal?.title ?? null,
        deal_amount_cents: detail.deal?.amount_cents ?? null,
        deal_currency: detail.deal?.currency ?? null,
        company_name: detail.company?.name ?? null,
        status: detail.status,
        target_kickoff_date: detail.target_kickoff_date,
        review_feedback: detail.review_feedback,
        submitted_by: detail.submitted_by,
        reviewed_by: detail.reviewed_by,
        assigned_pm: detail.assigned_pm,
        assigned_am: detail.assigned_am,
        template: detail.template,
        provisioned_project: detail.provisioned_project,
        submitted_at: detail.submitted_at,
        reviewed_at: detail.reviewed_at,
        provisioned_at: detail.provisioned_at,
    };
}
