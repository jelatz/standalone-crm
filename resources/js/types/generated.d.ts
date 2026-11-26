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
namespace Enums {
export type CompanyLifecycleStageEnum = 'lead' | 'prospect' | 'active_client' | 'churned' | 'partner';
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
}
}
