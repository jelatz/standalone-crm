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
namespace Enums {
export type OidcProviderEnum = 'google' | 'microsoft';
}
}
namespace Rbac {
namespace Enums {
export type CollaboratorAccessLevel = 'viewer' | 'editor' | 'manager';
export type CollaboratorRoleLabel = 'account_manager' | 'project_manager' | 'sales_overlay' | 'support_lead' | 'it_lead' | 'observer';
export type PermissionEnum = 'companies.view' | 'companies.create' | 'companies.update' | 'companies.delete' | 'contacts.view' | 'contacts.create' | 'contacts.update' | 'contacts.delete' | 'deals.view' | 'deals.create' | 'deals.update' | 'deals.delete' | 'deals.move_stage' | 'deals.override_gates' | 'pipelines.view' | 'pipelines.manage' | 'custom_fields.view' | 'custom_fields.manage' | 'handoffs.view' | 'handoffs.submit' | 'handoffs.review' | 'handoffs.approve' | 'handoffs.request_info' | 'projects.view' | 'projects.create' | 'projects.update' | 'projects.delete' | 'projects.manage_templates' | 'tasks.view' | 'tasks.create' | 'tasks.update' | 'tasks.delete' | 'tasks.comment' | 'mailboxes.view_shared' | 'mailboxes.reply_shared' | 'mailboxes.assign_shared' | 'mailboxes.manage_all' | 'mailboxes.connect_personal' | 'chat.view' | 'chat.send' | 'chat.manage_channels' | 'chat.admin' | 'admin.users' | 'admin.system_health';
export type RoleEnum = 'admin' | 'director' | 'operations_manager' | 'sales' | 'account_manager' | 'project_manager' | 'support' | 'it';
}
}
namespace Users {
namespace Enums {
export type UserStatusEnum = 'active' | 'suspended' | 'offboarded';
}
}
}
}
}
