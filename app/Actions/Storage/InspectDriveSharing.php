<?php

namespace App\Actions\Storage;

use App\Enums\PreflightLevel;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Storage\CredentialFileInspector;
use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\GoogleDrive\DriveClient;

/**
 * Kiểm chia sẻ của Shared Drive kho và thư mục gốc của môi trường (kế hoạch M14, R5). Dùng chung cho
 * kiểm tra sẵn sàng (`StorageReadiness`, dòng `drive_sharing` và `drive_root_folder`: lúc triển khai, lúc
 * chạy lệnh) và kiểm tra sức khoẻ mỗi giờ (`CheckDocumentStoreHealth`, để bắt lệch). Chỉ ĐỌC:
 * `drives.get`, `permissions.list` (mọi trang), `files.get` của thư mục gốc. Không lệnh nào tạo, sửa
 * hay xoá quyền (R5; test cấu trúc của Task 2 giữ điều đó cho cả mã).
 *
 * # Chia sẻ
 *
 * | Mức | Điều kiện |
 * |---|---|
 * | ĐỎ | `restrictions.driveMembersOnly` khác `true`; có quyền kiểu `anyone` hay `domain`; có thành viên (người hay nhóm) ngoài tài khoản dịch vụ và danh sách được phép; một thành viên trong danh sách mang vai khác vai đã khai; tài khoản dịch vụ mang vai khác `fileOrganizer`, hay không có trong danh sách thành viên; `GOOGLE_DRIVE_ALLOWED_MEMBERS` có mục sai dạng |
 * | VÀNG | `sharingFoldersRequiresOrganizerPermission` khác `true`; `domainUsersOnly` khác `true` (tài khoản dịch vụ ở `gserviceaccount.com`, tức người ngoài tổ chức: hàng rào thật là "chỉ thành viên" cộng danh sách thành viên kiểm mỗi giờ) |
 *
 * - Danh sách được phép = tài khoản dịch vụ (`client_email` của tệp khoá) + `GOOGLE_DRIVE_ALLOWED_MEMBERS`
 *   (`email:vai`, phân tách dấu phẩy; vai là một trong {@see self::ROLES}). So email không phân biệt hoa
 *   thường; so vai đúng từng chữ (vai của Drive là `fileOrganizer`, không phải `fileorganizer`).
 * - Thành viên được phép mà CHƯA có mặt trên Drive không phải lỗi (máy văn phòng chưa có).
 * - Vai `fileOrganizer` (Người quản lý nội dung) và chỉ vai đó cho tài khoản dịch vụ: `organizer` thừa
 *   quyền (xoá vĩnh viễn, quản lý thành viên); `writer` không cho vào thùng rác được, nên mỗi lần xoá
 *   media để lại một tệp mồ côi lặng lẽ; `reader`/`commenter` không ghi được.
 *
 * # Thư mục gốc
 *
 * ĐỎ khi chưa cấu hình mã (`GOOGLE_DRIVE_ROOT_FOLDER_ID`, in bởi `vkcrm:storage:init`), khi mã không có
 * trên Drive (404), khi nó không phải thư mục, thuộc Shared Drive khác, hay đang ở thùng rác.
 *
 * # Lỗi
 *
 * Lỗi gọi Drive (trừ 404 của thư mục gốc) đi lên nguyên dạng cho nơi gọi phân loại:
 * {@see DocumentStorageMisconfigured} (khoá sai, Shared Drive không có, mất quyền) hay
 * {@see DocumentStorageUnavailable} (mạng, 429, 5xx).
 *
 * Câu trả về có thể nêu email của một thành viên lạ (người vận hành cần biết ai để gỡ): chúng đi vào
 * đầu ra lệnh, dòng sức khoẻ trên trang admin — không vào thư cảnh báo (thư chỉ có số đếm).
 */
final class InspectDriveSharing
{
    public const SERVICE_ACCOUNT_ROLE = 'fileOrganizer';

    /** Vai của một thành viên Shared Drive (Drive API v3, `permissions.role`). */
    public const ROLES = ['organizer', 'fileOrganizer', 'writer', 'commenter', 'reader'];

    public function __construct(private readonly CredentialFileInspector $files) {}

    /**
     * @return array{
     *     sharing: array{level: PreflightLevel, problems: list<string>, warnings: list<string>},
     *     root: array{level: PreflightLevel, problems: list<string>},
     * }
     */
    public function handle(DriveClient $client): array
    {
        return [
            'sharing' => $this->sharing($client),
            'root' => $this->rootFolder($client),
        ];
    }

    /** @return array{level: PreflightLevel, problems: list<string>, warnings: list<string>} */
    public function sharing(DriveClient $client): array
    {
        $driveId = (string) config('vkcrm.storage.google_drive.shared_drive_id');
        $restrictions = (array) ($client->drive($driveId)['restrictions'] ?? []);

        $problems = [];
        $warnings = [];

        if (($restrictions['driveMembersOnly'] ?? null) !== true) {
            $problems[] = __('document_store.sharing.members_only_off');
        }

        [$allowed, $invalid] = $this->allowedMembers();

        foreach ($invalid as $entry) {
            $problems[] = __('document_store.sharing.allowed_member_invalid', ['entry' => $entry]);
        }

        $serviceAccount = $this->serviceAccountEmail();

        if ($serviceAccount === null) {
            $problems[] = __('document_store.sharing.service_account_unknown');
        }

        $serviceAccountSeen = false;

        foreach ($client->drivePermissions($driveId) as $permission) {
            $type = (string) ($permission['type'] ?? '');
            $role = (string) ($permission['role'] ?? '');

            if ($type === 'anyone') {
                $problems[] = __('document_store.sharing.anyone', ['role' => $role]);

                continue;
            }

            if ($type === 'domain') {
                $problems[] = __('document_store.sharing.domain', ['domain' => (string) ($permission['domain'] ?? ''), 'role' => $role]);

                continue;
            }

            $email = strtolower(trim((string) ($permission['emailAddress'] ?? '')));

            if ($serviceAccount !== null && $email === $serviceAccount) {
                $serviceAccountSeen = true;

                if ($role !== self::SERVICE_ACCOUNT_ROLE) {
                    $problems[] = __('document_store.sharing.service_account_role', ['role' => $role]);
                }

                continue;
            }

            if (! array_key_exists($email, $allowed)) {
                $problems[] = __('document_store.sharing.stranger', [
                    'email' => $email !== '' ? $email : __('document_store.sharing.no_email'),
                    'role' => $role,
                ]);

                continue;
            }

            if ($allowed[$email] !== $role) {
                $problems[] = __('document_store.sharing.wrong_role', ['email' => $email, 'role' => $role, 'expected' => $allowed[$email]]);
            }
        }

        if ($serviceAccount !== null && ! $serviceAccountSeen) {
            $problems[] = __('document_store.sharing.service_account_missing', ['email' => $serviceAccount]);
        }

        if (($restrictions['sharingFoldersRequiresOrganizerPermission'] ?? null) !== true) {
            $warnings[] = __('document_store.sharing.folders_organizer_off');
        }

        if (($restrictions['domainUsersOnly'] ?? null) !== true) {
            $warnings[] = __('document_store.sharing.domain_users_off');
        }

        return [
            'level' => $problems !== [] ? PreflightLevel::Red : ($warnings !== [] ? PreflightLevel::Yellow : PreflightLevel::Green),
            'problems' => $problems,
            'warnings' => $warnings,
        ];
    }

    /** @return array{level: PreflightLevel, problems: list<string>} */
    public function rootFolder(DriveClient $client): array
    {
        $rootId = (string) config('vkcrm.storage.google_drive.root_folder_id');

        if ($rootId === '') {
            return ['level' => PreflightLevel::Red, 'problems' => [__('document_store.root.missing')]];
        }

        try {
            $folder = $client->metadata($rootId);
        } catch (DriveApiError $e) {
            if ($e->isNotFound()) {
                return ['level' => PreflightLevel::Red, 'problems' => [__('document_store.root.not_found')]];
            }

            throw $e;
        }

        $problems = [];

        if (($folder['mimeType'] ?? null) !== DriveClient::FOLDER_MIME) {
            $problems[] = __('document_store.root.not_folder');
        }

        if (($folder['driveId'] ?? null) !== (string) config('vkcrm.storage.google_drive.shared_drive_id')) {
            $problems[] = __('document_store.root.other_drive');
        }

        if (($folder['trashed'] ?? false) === true) {
            $problems[] = __('document_store.root.trashed');
        }

        return ['level' => $problems === [] ? PreflightLevel::Green : PreflightLevel::Red, 'problems' => $problems];
    }

    /**
     * `GOOGLE_DRIVE_ALLOWED_MEMBERS` → [email thường => vai], và các mục sai dạng (giữ nguyên chữ).
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function allowedMembers(): array
    {
        $allowed = [];
        $invalid = [];

        foreach (explode(',', (string) config('vkcrm.storage.google_drive.allowed_members')) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $separator = strrpos($entry, ':');
            $email = $separator === false ? '' : strtolower(trim(substr($entry, 0, $separator)));
            $role = $separator === false ? '' : trim(substr($entry, $separator + 1));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! in_array($role, self::ROLES, true)) {
                $invalid[] = $entry;

                continue;
            }

            $allowed[$email] = $role;
        }

        return [$allowed, $invalid];
    }

    /** `client_email` (chữ thường) của tệp khoá đang cấu hình, `null` khi không đọc được. */
    private function serviceAccountEmail(): ?string
    {
        $path = (string) config('vkcrm.storage.google_drive.credentials_path');
        $json = $path === '' ? null : json_decode((string) $this->files->contents($path), true);
        $email = is_array($json) ? ($json['client_email'] ?? null) : null;

        return is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;
    }
}
