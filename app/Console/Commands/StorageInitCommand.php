<?php

namespace App\Console\Commands;

use App\Actions\Storage\InitialiseDocumentStore;
use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:init` (kế hoạch M14, Task 5; Phụ lục A bước 11) — tạo thư mục gốc `vkcrm-<APP_ENV>`
 * trong Shared Drive kho và in mã để điền `GOOGLE_DRIVE_ROOT_FOLDER_ID`. Nghiệp vụ ở
 * {@see InitialiseDocumentStore}; lớp này chỉ in và chọn mã thoát.
 *
 * Mã thoát: 0 đã tạo; 1 đã có thư mục cùng tên (liệt kê, không tạo thêm) hoặc Drive báo lỗi; 2 thiếu
 * cấu hình (không request nào được gửi).
 *
 * Chạy MỘT lần cho mỗi môi trường, với `DOCUMENT_STORAGE` vẫn là `local`. Lần chạy thật trên Shared
 * Drive của văn phòng là việc của người cài đặt (PENDING OWNER).
 */
class StorageInitCommand extends Command
{
    protected $signature = 'vkcrm:storage:init';

    protected $description = 'Tạo thư mục gốc vkcrm-<APP_ENV> trong Shared Drive kho và in mã của nó (M14)';

    public function handle(InitialiseDocumentStore $action): int
    {
        $missing = InitialiseDocumentStore::missingConfiguration();

        if ($missing !== []) {
            $this->error(__('document_store.init.not_configured', ['missing' => implode(', ', $missing)]));

            return self::INVALID;
        }

        try {
            $result = $action->handle();
        } catch (DocumentStorageMisconfigured|DocumentStorageUnavailable $e) {
            $this->error(__('document_store.init.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        if ($result['status'] === 'exists') {
            $this->warn(__('document_store.init.exists', ['name' => $result['name'], 'count' => count($result['folder_ids'])]));

            foreach ($result['folder_ids'] as $id) {
                $this->line(__('document_store.init.exists_item', ['id' => $id]));
            }

            $this->line(__('document_store.init.exists_hint'));

            return self::FAILURE;
        }

        $this->info(__('document_store.init.created', ['name' => $result['name']]));
        $this->line(__('document_store.init.env_line'));
        $this->line('GOOGLE_DRIVE_ROOT_FOLDER_ID='.$result['folder_ids'][0]);

        return self::SUCCESS;
    }
}
