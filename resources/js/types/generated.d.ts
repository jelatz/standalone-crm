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
namespace Pipelines {
namespace Enums {
export type PipelineStageTypeEnum = 'open' | 'won' | 'lost';
}
}
namespace Timeline {
namespace Enums {
export type ActivityTypeEnum = 'note_created' | 'deal_created' | 'stage_changed' | 'field_updated' | 'task_created' | 'task_completed' | 'email_received' | 'email_sent' | 'chat_thread_created' | 'handoff_submitted' | 'handoff_approved' | 'project_provisioned';
}
}
}
}
}
