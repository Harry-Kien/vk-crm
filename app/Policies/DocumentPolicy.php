<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class DocumentPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, Document $document): bool
    {
        if ($user instanceof ClientUser) {
            return $this->visibleToPortal($user, $document) && $this->canSeeMatter($user, $document->matter);
        }

        if ($document->group->isInternal() && ! $user->can(Permission::DocumentViewInternal->value)) {
            return false;
        }

        return $this->canSeeMatter($user, $document->matter);
    }

    /** Tải tệp: khách phải được bật thêm client_can_download (SPEC §5). */
    public function download(User|ClientUser $user, Document $document): bool
    {
        if (! $this->view($user, $document)) {
            return false;
        }

        return $user instanceof ClientUser ? $document->client_can_download : true;
    }

    public function create(User|ClientUser $user): bool
    {
        return true; // khách nộp tài liệu vào danh mục, nhân sự tải lên; ràng buộc chi tiết ở M4
    }

    public function publish(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User
            && $user->can(Permission::DocumentPublish->value)
            && $this->canSeeMatter($user, $document->matter);
    }

    public function update(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $document->matter);
    }

    public function delete(User|ClientUser $user, Document $document): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $document->matter);
    }
}
