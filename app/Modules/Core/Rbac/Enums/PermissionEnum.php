<?php

namespace App\Modules\Core\Rbac\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PermissionEnum: string
{
    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';

    case ContactsView = 'contacts.view';
    case ContactsCreate = 'contacts.create';
    case ContactsUpdate = 'contacts.update';
    case ContactsDelete = 'contacts.delete';

    case DealsView = 'deals.view';
    case DealsCreate = 'deals.create';
    case DealsUpdate = 'deals.update';
    case DealsDelete = 'deals.delete';
    case DealsMoveStage = 'deals.move_stage';
    case DealsOverrideGates = 'deals.override_gates';

    case PipelinesView = 'pipelines.view';
    case PipelinesManage = 'pipelines.manage';

    case CustomFieldsView = 'custom_fields.view';
    case CustomFieldsManage = 'custom_fields.manage';

    case HandoffsView = 'handoffs.view';
    case HandoffsSubmit = 'handoffs.submit';
    case HandoffsReview = 'handoffs.review';
    case HandoffsApprove = 'handoffs.approve';
    case HandoffsRequestInfo = 'handoffs.request_info';

    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';
    case ProjectsManageTemplates = 'projects.manage_templates';

    case TasksView = 'tasks.view';
    case TasksCreate = 'tasks.create';
    case TasksUpdate = 'tasks.update';
    case TasksDelete = 'tasks.delete';
    case TasksComment = 'tasks.comment';

    case MailboxesViewShared = 'mailboxes.view_shared';
    case MailboxesReplyShared = 'mailboxes.reply_shared';
    case MailboxesAssignShared = 'mailboxes.assign_shared';
    case MailboxesManageAll = 'mailboxes.manage_all';
    case MailboxesConnectPersonal = 'mailboxes.connect_personal';

    case ChatView = 'chat.view';
    case ChatSend = 'chat.send';
    case ChatManageChannels = 'chat.manage_channels';
    case ChatAdmin = 'chat.admin';

    case AdminUsers = 'admin.users';
    case AdminSystemHealth = 'admin.system_health';
}
