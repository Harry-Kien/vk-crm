<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\DocumentDownload;
use App\Models\User;

/** Nhật ký tải tài liệu: chỉ nhân sự có quyền tra cứu nhật ký mới xem được (SPEC §4.12). */
class DocumentDownloadPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::AuditLogView->value);
    }

    public function view(User|ClientUser $user, DocumentDownload $download): bool
    {
        return $this->viewAny($user);
    }
}
