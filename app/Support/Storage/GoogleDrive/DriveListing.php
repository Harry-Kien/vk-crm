<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;

/**
 * Danh sách THẬT của kho trên Google Drive, đọc bằng `files.list` (kế hoạch M14, R4, R11): thư mục
 * gốc của môi trường, các thư mục tháng `<YYYY-MM>` ngay dưới nó, và mọi tệp (chưa vào thùng rác) ở
 * gốc và trong các thư mục tháng. Dùng cho `vkcrm:storage:reindex` (dựng lại chỉ mục từ tên) và
 * `vkcrm:storage:orphans` (so Drive với chỉ mục) — hai lệnh chỉ ĐỌC Drive.
 *
 * - Chỉ đi một tầng dưới thư mục tháng: adapter chỉ ghi tệp vào thư mục tháng (R4). Thư mục tên khác
 *   khuôn tháng ở gốc, và thư mục con trong một thư mục tháng, không được duyệt: chúng được trả về ở
 *   `other_folders` (đường dẫn tên, không mã) để lệnh báo.
 * - Tệp ở ngay thư mục gốc vẫn được trả về (thư mục cha là gốc): không đường mã nào ghi ở đó, nhưng
 *   một tệp do người đặt tay vào cũng phải hiện trong báo cáo.
 * - Không bao giờ tìm theo tên (Drive cho trùng tên): mọi tệp mang mã của nó, và hai tệp cùng tên là
 *   hai phần tử.
 * - Client là client CHẨN ĐOÁN ({@see DriveDiagnosticClient}): ngắt mạch riêng, nên một lượt liệt kê
 *   cả kho lúc Google chập chờn không mở ngắt mạch của web hay job.
 *
 * Toàn bộ danh sách nằm trong bộ nhớ (vài trăm byte mỗi tệp: 400.000 mục ≈ 100–150 MB): đủ cho một
 * Shared Drive, vì lệnh chạy ngoài request web. Lỗi của Drive đi thẳng ra
 * ({@see DocumentStorageUnavailable}, {@see DocumentStorageMisconfigured}) cho lệnh báo.
 */
final class DriveListing
{
    public const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/D';

    public function __construct(
        private readonly DriveClient $client,
        private readonly string $rootFolderId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(DriveDiagnosticClient::make(), (string) config('vkcrm.storage.google_drive.root_folder_id'));
    }

    /**
     * @return array{
     *     folders: list<array{id: string, name: string}>,
     *     other_folders: list<string>,
     *     files: list<array{id: string, name: string, size: int, md5: ?string, mime: ?string, parent: string}>
     * }
     */
    public function scan(): array
    {
        $folders = [];
        $otherFolders = [];
        $files = [];

        foreach ($this->children($this->rootFolderId) as $item) {
            if (($item['mimeType'] ?? null) === DriveClient::FOLDER_MIME) {
                if (preg_match(self::MONTH_PATTERN, (string) $item['name']) === 1) {
                    $folders[] = ['id' => (string) $item['id'], 'name' => (string) $item['name']];
                } else {
                    $otherFolders[] = (string) $item['name'];
                }

                continue;
            }

            $files[] = $this->file($item, $this->rootFolderId);
        }

        foreach ($folders as $folder) {
            foreach ($this->children($folder['id']) as $item) {
                if (($item['mimeType'] ?? null) === DriveClient::FOLDER_MIME) {
                    $otherFolders[] = $folder['name'].'/'.$item['name'];

                    continue;
                }

                $files[] = $this->file($item, $folder['id']);
            }
        }

        return ['folders' => $folders, 'other_folders' => $otherFolders, 'files' => $files];
    }

    /** @return iterable<array<string, mixed>> mọi trang của `files.list` cho một thư mục */
    private function children(string $folderId): iterable
    {
        $token = null;

        do {
            $page = $this->client->children($folderId, $token);

            yield from $page['files'];

            $token = $page['nextPageToken'];
        } while ($token !== null);
    }

    /** @return array{id: string, name: string, size: int, md5: ?string, mime: ?string, parent: string} */
    private function file(array $item, string $parent): array
    {
        $md5 = $item['md5Checksum'] ?? null;

        return [
            'id' => (string) $item['id'],
            'name' => (string) $item['name'],
            'size' => (int) ($item['size'] ?? 0),
            'md5' => is_string($md5) && $md5 !== '' ? $md5 : null,
            'mime' => isset($item['mimeType']) ? (string) $item['mimeType'] : null,
            'parent' => $parent,
        ];
    }
}
