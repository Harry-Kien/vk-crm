<?php

namespace App\Exceptions;

use App\Actions\User\CreateAdminFromConsole;
use DomainException;

/**
 * `vkcrm:create-admin` từ chối tạo tài khoản ({@see CreateAdminFromConsole}).
 * Thông điệp là câu tiếng Việt in thẳng cho người vận hành — không phải một lỗi hệ thống.
 */
class AdminCreationRefused extends DomainException
{
    public static function adminsExist(int $count): self
    {
        return new self(__('users.create_admin.admins_exist', ['count' => $count]));
    }
}
