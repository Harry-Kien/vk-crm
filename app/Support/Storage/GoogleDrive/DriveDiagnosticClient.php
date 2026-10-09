<?php

namespace App\Support\Storage\GoogleDrive;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * {@see DriveClient} cho các lệnh CHẨN ĐOÁN của kho (kế hoạch M14, Task 5): kiểm tra sẵn sàng
 * (`StorageReadiness`), kiểm tra sức khoẻ mỗi giờ (`CheckDocumentStoreHealth`), `vkcrm:storage:init`.
 *
 * Cùng cấu hình với client của đĩa `documents_remote` (`DriveClient::fromConfig()`: Shared Drive, thời
 * gian chờ, thử lại theo phạm vi web/job, cùng nhà cung cấp token của container), chỉ khác MỘT điều:
 * ngắt mạch riêng, trong bộ nhớ, mới cho mỗi lần dựng.
 *
 * - Lần chẩn đoán không bị chặn bởi ngắt mạch đang mở của web hay job: nó phải tự hỏi Google, đó là
 *   lý do nó tồn tại.
 * - Lỗi của nó không mở ngắt mạch dùng chung (store `file`): một lượt `vkcrm:storage:check` lúc Google
 *   chập chờn không được biến mọi lượt tải trên web thành 503 trong 60 giây.
 */
final class DriveDiagnosticClient
{
    public static function make(): DriveClient
    {
        return DriveClient::fromConfig(
            app(DriveTokenProvider::class),
            new DriveCircuitBreaker(new Repository(new ArrayStore)),
        );
    }
}
