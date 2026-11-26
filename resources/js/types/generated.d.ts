declare namespace App {
namespace Modules {
namespace Core {
namespace AsyncJobs {
namespace Enums {
export type AsyncJobStatus = 'queued' | 'running' | 'completed' | 'failed';
}
}
namespace Attachments {
namespace Data {
export type AttachmentSummaryData = {
id: string,
original_filename: string,
mime_type: string,
size_bytes: number,
scan_status: App.Modules.Core.Attachments.Enums.AttachmentScanStatus,
uploaded_by_id: string,
uploaded_by_name: string | null,
created_at: string,
download_url: string,
};
export type PresignUploadRequestData = {
filename: string,
mime_type: string,
size_bytes: number,
sha256: string,
};
export type PresignUploadResponseData = {
attachment_id: string,
upload_url: string,
headers: Record<string, string>,
object_key: string,
expires_at: string,
};
}
namespace Enums {
export type AttachmentScanStatus = 'pending' | 'clean' | 'quarantined';
}
}
namespace Auth {
namespace Data {
export type AuthenticatedUserData = {
id: string,
name: string,
email: string,
employee_code: string,
job_title: string | null,
department_id: string,
roles: string[],
permissions: string[],
};
}
}
namespace Rbac {
namespace Data {
export type EntityCollaboratorData = {
id: string,
user_id: string,
user_name: string | null,
user_email: string | null,
access_level: App.Modules.Core.Rbac.Enums.CollaboratorAccessLevel,
role_label: App.Modules.Core.Rbac.Enums.CollaboratorRoleLabel,
granted_by_id: string,
granted_by_name: string | null,
created_at: string,
};
export type GrantCollaboratorData = {
user_id: string,
access_level: App.Modules.Core.Rbac.Enums.CollaboratorAccessLevel,
role_label: App.Modules.Core.Rbac.Enums.CollaboratorRoleLabel,
};
}
namespace Enums {
export type CollaboratorAccessLevel = 'viewer' | 'editor' | 'manager';
export type CollaboratorRoleLabel = 'account_manager' | 'project_manager' | 'sales_overlay' | 'support_lead' | 'it_lead' | 'observer';
export type PermissionEnum = 'companies.view' | 'companies.create' | 'companies.update' | 'companies.delete' | 'contacts.view' | 'contacts.create' | 'contacts.update' | 'contacts.delete' | 'deals.view' | 'deals.create' | 'deals.update' | 'deals.delete' | 'deals.move_stage' | 'deals.override_gates' | 'pipelines.view' | 'pipelines.manage' | 'custom_fields.view' | 'custom_fields.manage' | 'handoffs.view' | 'handoffs.submit' | 'handoffs.review' | 'handoffs.approve' | 'handoffs.request_info' | 'projects.view' | 'projects.create' | 'projects.update' | 'projects.delete' | 'projects.manage_templates' | 'tasks.view' | 'tasks.create' | 'tasks.update' | 'tasks.delete' | 'tasks.comment' | 'mailboxes.view_shared' | 'mailboxes.reply_shared' | 'mailboxes.assign_shared' | 'mailboxes.manage_all' | 'mailboxes.connect_personal' | 'chat.view' | 'chat.send' | 'chat.manage_channels' | 'chat.admin' | 'admin.users' | 'admin.system_health';
export type RoleEnum = 'superadmin' | 'admin' | 'director' | 'operations_manager' | 'sales' | 'account_manager' | 'project_manager' | 'support' | 'it';
}
}
namespace Users {
namespace Data {
export type UserSummaryData = {
id: string,
employee_code: string,
name: string,
email: string,
job_title: string | null,
department_id: string,
department_name: string,
status: string,
roles: string[],
created_at: string | null,
};
}
namespace Enums {
export type UserStatusEnum = 'active' | 'suspended' | 'offboarded';
}
}
}
namespace Crm {
namespace Companies {
namespace Data {
export type CompanyDetailData = {
id: string,
name: string,
domain: string | null,
lifecycle_stage: App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum,
industry: string | null,
employee_range: string | null,
annual_revenue_cents: number | null,
billing_address: Record<string, any>,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
custom_fields: Record<string, any>,
created_at: string,
updated_at: string,
};
export type CompanyFilterData = {
search: string | null,
lifecycle_stage: App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum | null,
owner_id: string | null,
custom_fields: Record<string, any> | null,
sort_by: string | null,
sort_dir: string | null,
page: number | null,
per_page: number | null,
};
export type CompanyListItemData = {
id: string,
name: string,
domain: string | null,
lifecycle_stage: App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum,
industry: string | null,
employee_range: string | null,
annual_revenue_cents: number | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
contacts_count: number,
deals_count: number,
custom_fields: Record<string, any>,
created_at: string,
updated_at: string,
};
export type UpsertCompanyData = {
name: undefined | string,
domain: undefined | string | null,
lifecycle_stage: App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum | undefined,
industry: undefined | string | null,
employee_range: undefined | string | null,
annual_revenue_cents: undefined | number | null,
billing_address: Record<string, any> | undefined | null,
account_manager_id: undefined | string | null,
custom_fields: Record<string, any> | undefined,
};
}
namespace Enums {
export type CompanyLifecycleStageEnum = 'lead' | 'prospect' | 'active_client' | 'churned' | 'partner';
}
}
namespace Contacts {
namespace Data {
export type ContactDetailData = {
id: string,
company_id: string | null,
company_name: string | null,
company_domain: string | null,
owner_id: string,
owner_name: string | null,
first_name: string,
last_name: string,
full_name: string,
primary_email: string | null,
secondary_emails: string[],
phones: any[],
job_title: string | null,
is_primary_contact: boolean,
custom_fields: Record<string, any>,
created_at: string,
updated_at: string,
has_masked_company: boolean,
};
export type ContactFilterData = {
search: string | null,
company_id: string | null,
owner_id: string | null,
custom_fields: Record<string, any> | null,
sort_by: string | null,
sort_dir: string | null,
page: number | null,
per_page: number | null,
};
export type ContactListItemData = {
id: string,
company_id: string | null,
company_name: string | null,
owner_id: string,
owner_name: string | null,
first_name: string,
last_name: string,
full_name: string,
primary_email: string | null,
secondary_emails: string[],
phones: any[],
job_title: string | null,
is_primary_contact: boolean,
custom_fields: Record<string, any>,
created_at: string,
updated_at: string,
};
export type UpsertContactData = {
company_id: undefined | string | null,
first_name: undefined | string,
last_name: undefined | string,
primary_email: undefined | string | null,
secondary_emails: string[] | undefined | null,
phones: any[] | undefined | null,
job_title: undefined | string | null,
is_primary_contact: undefined | boolean,
custom_fields: Record<string, any> | undefined,
};
}
}
namespace CustomFields {
namespace Data {
export type CustomFieldDefinitionData = {
id: string,
entity_type: App.Modules.Crm.CustomFields.Enums.CustomFieldEntityTypeEnum,
pipeline_id: string | null,
key: string,
label: string,
field_type: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum,
options: Record<string, any>[] | null,
validation_rules: Record<string, any>,
is_filterable: boolean,
is_active: boolean,
sort_order: number,
};
export type UpsertCustomFieldDefinitionData = {
entity_type: App.Modules.Crm.CustomFields.Enums.CustomFieldEntityTypeEnum | undefined,
pipeline_id: undefined | string | null,
key: undefined | string,
label: undefined | string,
field_type: App.Modules.Crm.CustomFields.Enums.CustomFieldTypeEnum | undefined,
options: (Record<string, any> | string)[] | undefined | null,
validation_rules: Record<string, any> | undefined,
is_filterable: undefined | boolean,
is_active: undefined | boolean,
sort_order: undefined | number,
};
}
namespace Enums {
export type CustomFieldEntityTypeEnum = 'company' | 'contact' | 'deal' | 'project';
export type CustomFieldTypeEnum = 'text' | 'textarea' | 'number' | 'currency' | 'boolean' | 'date' | 'select' | 'multi_select' | 'user_id' | 'url';
}
}
namespace Deals {
namespace Data {
export type CreateDealData = {
company_id: string,
title: string,
primary_contact_id: string | null,
pipeline_id: string | null,
stage_id: string | null,
account_manager_id: string | null,
amount_cents: number,
currency: string,
expected_close_date: string | null,
lost_reason: string | null,
custom_fields: Record<string, any>,
stakeholders: App.Modules.Crm.Deals.Data.DealStakeholderInputData[] | null,
};
export type DealCardData = {
id: string,
company_id: string | null,
company_name: string | null,
primary_contact_id: string | null,
primary_contact_name: string | null,
pipeline_id: string,
stage_id: string,
stage_name: string | null,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
title: string,
amount_cents: number,
currency: string,
expected_close_date: string | null,
stage_entered_at: string,
won_at: string | null,
lost_at: string | null,
board_position: number,
version: number,
is_rotting: boolean,
custom_fields: Record<string, any>,
updated_at: string,
};
export type DealDetailData = {
id: string,
company_id: string | null,
company_name: string | null,
company_domain: string | null,
primary_contact_id: string | null,
primary_contact_name: string | null,
primary_contact_email: string | null,
pipeline_id: string,
pipeline_name: string | null,
stage_id: string,
stage_name: string | null,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | null,
stage_win_probability: number | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
title: string,
amount_cents: number,
currency: string,
expected_close_date: string | null,
stage_entered_at: string,
won_at: string | null,
lost_at: string | null,
lost_reason: string | null,
board_position: number,
version: number,
custom_fields: Record<string, any>,
created_at: string,
updated_at: string,
has_masked_company: boolean,
has_masked_primary_contact: boolean,
};
export type DealFilterData = {
view: string | null,
pipeline_id: string | null,
stage_id: string | null,
owner_id: string | null,
company_id: string | null,
search: string | null,
custom_fields: Record<string, any> | null,
sort_by: string | null,
sort_dir: string | null,
page: number | null,
per_page: number | null,
};
export type DealKanbanBoardData = {
pipeline: App.Modules.Crm.Pipelines.Data.PipelineData,
columns: App.Modules.Crm.Deals.Data.DealKanbanStageColumnData[],
total_deals_count: number,
total_amount_cents: number,
};
export type DealKanbanStageColumnData = {
stage: App.Modules.Crm.Pipelines.Data.PipelineStageData,
deals_count: number,
total_amount_cents: number,
deals: App.Modules.Crm.Deals.Data.DealCardData[],
};
export type DealStakeholderData = {
contact_id: string,
full_name: string,
primary_email: string | null,
job_title: string | null,
stakeholder_role: App.Modules.Crm.Deals.Enums.DealStakeholderRoleEnum,
};
export type DealStakeholderInputData = {
contact_id: string,
stakeholder_role: App.Modules.Crm.Deals.Enums.DealStakeholderRoleEnum,
};
export type MoveDealStageData = {
target_stage_id: string,
expected_version: number,
before_deal_id: string | null,
after_deal_id: string | null,
core_field_updates: Record<string, any>,
custom_field_updates: Record<string, any>,
override_gates: boolean,
};
export type RelatedDealData = {
id: string,
company_id: string | null,
primary_contact_id: string | null,
pipeline_id: string,
pipeline_name: string | null,
stage_id: string,
stage_name: string | null,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
title: string,
amount_cents: number,
currency: string,
expected_close_date: string | null,
won_at: string | null,
lost_at: string | null,
updated_at: string,
};
export type SyncDealContactsData = {
contacts: App.Modules.Crm.Deals.Data.DealStakeholderInputData[],
};
export type UpdateDealData = {
expected_version: number,
company_id: undefined | string | null,
primary_contact_id: undefined | string | null,
stage_id: undefined | string,
account_manager_id: undefined | string | null,
title: undefined | string,
amount_cents: undefined | number,
currency: undefined | string,
expected_close_date: undefined | string | null,
lost_reason: undefined | string | null,
custom_fields: Record<string, any> | undefined,
override_gates: boolean,
};
}
namespace Enums {
export type DealStakeholderRoleEnum = 'decision_maker' | 'champion' | 'billing' | 'technical_buyer' | 'influencer';
}
}
namespace Notes {
namespace Data {
export type CreateNoteData = {
body_json: Record<string, any>,
targets: App.Modules.Crm.Notes.Data.NoteTargetData[],
is_pinned: boolean,
};
export type NoteData = {
id: string,
author_id: string,
author_name: string | null,
body_json: Record<string, any>,
body_text: string,
is_pinned: boolean,
created_at: string,
updated_at: string,
};
export type NoteTargetData = {
linkable_type: string,
linkable_id: string,
};
export type UpdateNoteData = {
body_json: Record<string, any>,
};
}
}
namespace Pipelines {
namespace Data {
export type PipelineData = {
id: string,
name: string,
slug: string,
department_id: string | null,
is_default: boolean,
is_active: boolean,
sort_order: number,
stages: App.Modules.Crm.Pipelines.Data.PipelineStageData[],
};
export type PipelineStageData = {
id: string,
pipeline_id: string,
name: string,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum,
win_probability: number,
rotting_days: number | null,
required_core_fields: string[],
required_custom_field_ids: string[],
sort_order: number,
};
export type UpsertPipelineData = {
name: undefined | string,
slug: undefined | string | null,
department_id: undefined | string | null,
is_default: undefined | boolean,
is_active: undefined | boolean,
sort_order: undefined | number,
};
export type UpsertPipelineStageData = {
name: undefined | string,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | undefined,
win_probability: undefined | number,
rotting_days: undefined | number | null,
required_core_fields: string[] | undefined,
required_custom_field_ids: string[] | undefined,
sort_order: undefined | number,
};
}
namespace Enums {
export type PipelineStageTypeEnum = 'open' | 'won' | 'lost';
}
}
namespace Timeline {
namespace Data {
export type ActivityData = {
id: string,
actor_id: string | null,
actor_name: string | null,
activity_type: App.Modules.Crm.Timeline.Enums.ActivityTypeEnum,
source_type: string,
source_id: string,
visibility_mode: string,
summary: string,
metadata: Record<string, any>,
occurred_at: string,
note: App.Modules.Crm.Notes.Data.NoteData | null,
};
export type TimelinePageData = {
items: App.Modules.Crm.Timeline.Data.ActivityData[],
next_cursor: string | null,
has_more: boolean,
};
}
namespace Enums {
export type ActivityTypeEnum = 'note_created' | 'deal_created' | 'stage_changed' | 'field_updated' | 'task_created' | 'task_completed' | 'email_received' | 'email_sent' | 'chat_thread_created' | 'handoff_submitted' | 'handoff_approved' | 'project_provisioned';
}
}
}
namespace Projects {
namespace Handoffs {
namespace Data {
export type ApproveHandoffData = {
project_template_id: string,
assigned_pm_id: string,
assigned_am_id: undefined | string | null,
project_name: undefined | string | null,
target_kickoff_date: undefined | string | null,
review_feedback: undefined | string | null,
};
export type DealHandoffDetailData = {
id: string,
deal_id: string,
company_id: string,
deal: App.Modules.Projects.Handoffs.Data.HandoffDealSummaryData | null,
company: App.Modules.Projects.Handoffs.Data.HandoffCompanySummaryData | null,
status: App.Modules.Projects.Handoffs.Enums.DealHandoffStatusEnum,
scope_notes_json: Record<string, any>,
handoff_checklist: {
label: string,
checked: boolean,
}[],
target_kickoff_date: string | null,
review_feedback: string | null,
submitted_by: App.Modules.Core.Users.Data.UserSummaryData | null,
reviewed_by: App.Modules.Core.Users.Data.UserSummaryData | null,
assigned_pm: App.Modules.Core.Users.Data.UserSummaryData | null,
assigned_am: App.Modules.Core.Users.Data.UserSummaryData | null,
template: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData | null,
provisioned_project: App.Modules.Projects.Handoffs.Data.HandoffProjectSummaryData | null,
submitted_at: string,
reviewed_at: string | null,
provisioned_at: string | null,
created_at: string,
updated_at: string,
};
export type DealHandoffSummaryData = {
id: string,
deal_id: string,
company_id: string,
deal_title: string | null,
deal_amount_cents: number | null,
deal_currency: string | null,
company_name: string | null,
status: App.Modules.Projects.Handoffs.Enums.DealHandoffStatusEnum,
target_kickoff_date: string | null,
review_feedback: string | null,
submitted_by: App.Modules.Core.Users.Data.UserSummaryData | null,
reviewed_by: App.Modules.Core.Users.Data.UserSummaryData | null,
assigned_pm: App.Modules.Core.Users.Data.UserSummaryData | null,
assigned_am: App.Modules.Core.Users.Data.UserSummaryData | null,
template: App.Modules.Projects.Templates.Data.ProjectTemplateSummaryData | null,
provisioned_project: App.Modules.Projects.Handoffs.Data.HandoffProjectSummaryData | null,
submitted_at: string,
reviewed_at: string | null,
provisioned_at: string | null,
};
export type HandoffCompanySummaryData = {
id: string,
name: string,
domain: string | null,
lifecycle_stage: App.Modules.Crm.Companies.Enums.CompanyLifecycleStageEnum,
};
export type HandoffDealSummaryData = {
id: string,
title: string,
amount_cents: number,
currency: string,
stage_id: string,
stage_name: string | null,
stage_type: App.Modules.Crm.Pipelines.Enums.PipelineStageTypeEnum | null,
};
export type HandoffProjectSummaryData = {
id: string,
code: string,
name: string,
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
};
export type ReviewHandoffData = {
review_feedback: string,
};
export type SubmitHandoffData = {
scope_notes_json: Record<string, any>,
handoff_checklist: {
label: string,
checked: boolean,
}[],
target_kickoff_date: string | null,
assigned_am_id: string | null,
};
}
namespace Enums {
export type DealHandoffStatusEnum = 'pending_review' | 'changes_requested' | 'approved' | 'provisioned' | 'rejected';
}
}
namespace Projects {
namespace Data {
export type CreateProjectData = {
name: string,
company_id: string | null,
primary_deal_id: string | null,
owner_id: string | null,
account_manager_id: string | null,
department_id: string | null,
description_json: Record<string, any> | null,
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
start_date: string | null,
target_end_date: string | null,
custom_fields: Record<string, any>,
stages: App.Modules.Projects.Projects.Data.ProjectStageInputData[] | null,
};
export type ManageProjectStagesData = {
stages: App.Modules.Projects.Projects.Data.ProjectStageInputData[],
};
export type ProjectDetailData = {
id: string,
code: string,
company_id: string | null,
company_name: string | null,
company_domain: string | null,
primary_deal_id: string | null,
primary_deal_title: string | null,
deal_handoff_id: string | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
department_id: string,
department_name: string | null,
name: string,
description_json: Record<string, any> | null,
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
start_date: string | null,
target_end_date: string | null,
completed_at: string | null,
custom_fields: Record<string, any>,
has_masked_company: boolean,
has_masked_deal: boolean,
created_at: string,
updated_at: string,
};
export type ProjectFilterData = {
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum | null,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum | null,
owner_id: string | null,
company_id: string | null,
search: string | null,
sort_by: string | null,
sort_dir: string | null,
page: number | null,
per_page: number | null,
};
export type ProjectStageData = {
id: string,
project_id: string,
name: string,
category: App.Modules.Projects.Projects.Enums.ProjectStageCategoryEnum,
sort_order: number,
wip_limit: number | null,
tasks_count: number,
};
export type ProjectStageInputData = {
name: string,
category: App.Modules.Projects.Projects.Enums.ProjectStageCategoryEnum,
id: string | null,
wip_limit: number | null,
sort_order: number | null,
};
export type ProjectSummaryData = {
id: string,
code: string,
company_id: string | null,
company_name: string | null,
primary_deal_id: string | null,
primary_deal_title: string | null,
deal_handoff_id: string | null,
owner_id: string,
owner_name: string | null,
account_manager_id: string | null,
account_manager_name: string | null,
department_id: string,
department_name: string | null,
name: string,
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum,
start_date: string | null,
target_end_date: string | null,
completed_at: string | null,
tasks_count: number,
completed_tasks_count: number,
has_masked_company: boolean,
has_masked_deal: boolean,
created_at: string,
updated_at: string,
};
export type ProjectWorkspaceData = {
project: App.Modules.Projects.Projects.Data.ProjectDetailData,
stages: App.Modules.Projects.Projects.Data.ProjectStageData[],
columns: App.Modules.Projects.Tasks.Data.TaskStageColumnData[],
tasks: App.Modules.Projects.Tasks.Data.TaskData[],
collaborators: App.Modules.Core.Rbac.Data.EntityCollaboratorData[],
attachments: App.Modules.Core.Attachments.Data.AttachmentSummaryData[],
customFieldDefinitions: App.Modules.Crm.CustomFields.Data.CustomFieldDefinitionData[],
timeline: App.Modules.Crm.Timeline.Data.TimelinePageData,
assignableUsers: App.Modules.Core.Users.Data.UserSummaryData[],
collaboratorAccessLevels: string[],
collaboratorRoleLabels: string[],
statuses: string[],
healthOptions: string[],
stageCategories: string[],
taskPriorities: string[],
taskStatuses: string[],
can: {
update: boolean,
delete: boolean,
manageStages: boolean,
manageCollaborators: boolean,
createTask: boolean,
},
};
export type UpdateProjectData = {
name: undefined | string,
company_id: undefined | string | null,
primary_deal_id: undefined | string | null,
owner_id: undefined | string,
account_manager_id: undefined | string | null,
department_id: undefined | string,
description_json: Record<string, any> | undefined | null,
status: App.Modules.Projects.Projects.Enums.ProjectStatusEnum | undefined,
health: App.Modules.Projects.Projects.Enums.ProjectHealthEnum | undefined,
start_date: undefined | string | null,
target_end_date: undefined | string | null,
custom_fields: Record<string, any> | undefined,
};
}
namespace Enums {
export type ProjectHealthEnum = 'on_track' | 'at_risk' | 'off_track';
export type ProjectStageCategoryEnum = 'backlog' | 'todo' | 'in_progress' | 'review' | 'done' | 'cancelled';
export type ProjectStatusEnum = 'planning' | 'active' | 'on_hold' | 'completed' | 'cancelled';
}
}
namespace Tasks {
namespace Data {
export type CreateTaskData = {
title: string,
project_id: string | null,
project_stage_id: string | null,
parent_task_id: string | null,
linkable_type: string | null,
linkable_id: string | null,
assignee_id: string | null,
description_json: Record<string, any> | null,
priority: App.Modules.Projects.Tasks.Enums.TaskPriorityEnum,
status: App.Modules.Projects.Tasks.Enums.TaskStatusEnum | null,
due_at: string | null,
estimated_minutes: number | null,
};
export type MoveTaskData = {
target_stage_id: string,
expected_version: number,
before_task_id: string | null,
after_task_id: string | null,
};
export type MyWorkGroupData = {
key: string,
label: string,
tasks_count: number,
tasks: App.Modules.Projects.Tasks.Data.TaskData[],
};
export type TaskData = {
id: string,
project_id: string | null,
project_code: string | null,
project_name: string | null,
project_stage_id: string | null,
project_stage_name: string | null,
project_stage_category: App.Modules.Projects.Projects.Enums.ProjectStageCategoryEnum | null,
parent_task_id: string | null,
linkable_type: string | null,
linkable_id: string | null,
creator_id: string,
creator_name: string | null,
assignee_id: string | null,
assignee_name: string | null,
title: string,
description_json: Record<string, any> | null,
priority: App.Modules.Projects.Tasks.Enums.TaskPriorityEnum,
status: App.Modules.Projects.Tasks.Enums.TaskStatusEnum,
board_position: number,
due_at: string | null,
completed_at: string | null,
estimated_minutes: number | null,
version: number,
subtasks_count: number,
watchers_count: number,
watcher_ids: string[],
is_watching: boolean,
has_masked_linkable: boolean,
created_at: string,
updated_at: string,
};
export type TaskStageColumnData = {
stage: App.Modules.Projects.Projects.Data.ProjectStageData,
tasks_count: number,
tasks: App.Modules.Projects.Tasks.Data.TaskData[],
};
export type ToggleTaskWatcherData = {
user_id: string | null,
watching: boolean | null,
};
export type UpdateTaskData = {
expected_version: number,
title: undefined | string,
description_json: Record<string, any> | undefined | null,
assignee_id: undefined | string | null,
priority: App.Modules.Projects.Tasks.Enums.TaskPriorityEnum | undefined,
status: App.Modules.Projects.Tasks.Enums.TaskStatusEnum | undefined,
due_at: undefined | string | null,
estimated_minutes: undefined | number | null,
};
}
namespace Enums {
export type TaskPriorityEnum = 'low' | 'medium' | 'high' | 'urgent';
export type TaskStatusEnum = 'todo' | 'in_progress' | 'blocked' | 'review' | 'done' | 'cancelled';
}
}
namespace Templates {
namespace Data {
export type ProjectTemplateSummaryData = {
id: string,
name: string,
department_id: string | null,
department_name: string | null,
description: string | null,
is_active: boolean,
default_stages: Record<string, any>[],
tasks_count: number,
created_at: string,
updated_at: string,
};
}
namespace Enums {
export type TemplateAssigneeRoleHintEnum = 'pm' | 'am' | 'support' | 'it';
}
}
}
}
}
