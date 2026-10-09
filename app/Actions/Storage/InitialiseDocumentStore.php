<?php

namespace App\Actions\Storage;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Support\Audit;
use App\Support\Storage\GoogleDrive\DriveClient;
use App\Support\Storage\GoogleDrive\DriveDiagnosticClient;

/**
 * `vkcrm:storage:init` (kế hoạch M14, Task 5; Phụ lục A bước 11): tạo thư mục gốc của môi trường,
 * `vkcrm-<APP_ENV>`, ngay dưới gốc Shared Drive kho, và trả mã của nó để người cài đặt điền
 * `GOOGLE_DRIVE_ROOT_FOLDER_ID`.
 *
 * - Drive cho nhiều thư mục trùng tên. Nên trước khi tạo, Action đọc HẾT mọi trang con (chưa vào thùng
 *   rác) của gốc Shared Drive: đã có thư mục (đúng loại thư mục, không phải tệp) cùng tên thì trả danh
 *   sách mã và DỪNG, không tạo thêm. Đây là chỗ DUY NHẤT trong mã kho tìm theo tên, và chỉ để từ chối:
 *   mọi đường khác đi qua mã tệp (R4).
 * - Hỏi `drives.get` trước tiên: sai mã Shared Drive hay tài khoản dịch vụ không phải thành viên thì
 *   lỗi là {@see DocumentStorageMisconfigured} rõ ràng, không phải một danh sách rỗng.
 * - Audit `document_store_initialised` chỉ khi tạo, chỉ mang TÊN thư mục: mã Drive không vào nhật ký
 *   (R3). Mã được trả cho lệnh để in ra màn hình người vận hành — nơi duy nhất cần nó.
 * - Thiếu `GOOGLE_DRIVE_SHARED_DRIVE_ID` hay `GOOGLE_DRIVE_CREDENTIALS_PATH` → {@see DocumentStorageMisconfigured}
 *   trước mọi request.
 *
 * Lỗi Drive đi lên nguyên dạng ({@see DocumentStorageMisconfigured}, {@see DocumentStorageUnavailable}).
 */
final class InitialiseDocumentStore
{
    public const FOLDER_PREFIX = 'vkcrm-';

    /**
     * @return array{status: 'created'|'exists', name: string, folder_ids: list<string>}
     *
     * @throws DocumentStorageMisconfigured
     * @throws DocumentStorageUnavailable
     */
    public function handle(): array
    {
        $missing = self::missingConfiguration();

        if ($missing !== []) {
            throw DocumentStorageMisconfigured::notConfigured($missing);
        }

        $driveId = (string) config('vkcrm.storage.google_drive.shared_drive_id');
        $name = self::folderName();
        $client = DriveDiagnosticClient::make();

        $client->drive($driveId);

        $existing = $this->existingFolders($client, $driveId, $name);

        if ($existing !== []) {
            return ['status' => 'exists', 'name' => $name, 'folder_ids' => $existing];
        }

        $id = $client->folder($driveId, $name);

        Audit::record('document_store_initialised', null, ['folder_name' => $name]);

        return ['status' => 'created', 'name' => $name, 'folder_ids' => [$id]];
    }

    public static function folderName(): string
    {
        return self::FOLDER_PREFIX.trim((string) config('app.env'));
    }

    /** @return list<string> biến môi trường mà lệnh này cần mà còn trống (đọc qua `config()`) */
    public static function missingConfiguration(): array
    {
        return array_keys(array_filter([
            'GOOGLE_DRIVE_CREDENTIALS_PATH' => blank(config('vkcrm.storage.google_drive.credentials_path')),
            'GOOGLE_DRIVE_SHARED_DRIVE_ID' => blank(config('vkcrm.storage.google_drive.shared_drive_id')),
        ]));
    }

    /** @return list<string> mã các thư mục `$name` ngay dưới gốc Shared Drive, chưa vào thùng rác */
    private function existingFolders(DriveClient $client, string $driveId, string $name): array
    {
        $found = [];
        $pageToken = null;

        do {
            $page = $client->children($driveId, $pageToken);

            foreach ($page['files'] as $file) {
                if (($file['name'] ?? null) === $name && ($file['mimeType'] ?? null) === DriveClient::FOLDER_MIME) {
                    $found[] = (string) $file['id'];
                }
            }

            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null);

        return $found;
    }
}
