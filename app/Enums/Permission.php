<?php

namespace App\Enums;

/**
 * Đúng 13 quyền ở SPEC §5. Tên quyền là nguồn sự thật, không sinh tự động từ resource.
 */
enum Permission: string
{
    case MatterViewAny = 'matter.viewAny';
    case MatterView = 'matter.view';
    case MatterCreate = 'matter.create';
    case MatterUpdate = 'matter.update';
    case MatterTransitionStage = 'matter.transitionStage';
    case StageLogPublish = 'stageLog.publish';
    case DocumentViewInternal = 'document.viewInternal';
    case DocumentPublish = 'document.publish';
    case ChecklistReview = 'checklist.review';
    case ClientManage = 'client.manage';
    case ClientUserManage = 'clientUser.manage';
    case SettingsManage = 'settings.manage';
    case AuditLogView = 'auditLog.view';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }
}
