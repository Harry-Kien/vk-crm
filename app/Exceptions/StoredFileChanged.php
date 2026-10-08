<?php

namespace App\Exceptions;

use App\Actions\Matter\BuildHandoverPackage;
use App\Actions\Storage\MaterialiseStoredFile;
use App\Actions\Storage\PullDocumentsToLocal;

/**
 * Bản tải về từ kho ĐỦ byte (hay thừa byte) mà lệch md5 hay cỡ của dòng `media` (M14, rà soát cuối vòng
 * sửa 1, I7): bản trên kho đã bị đổi ngoài CRM, không phải mạng chập chờn — tải lại lần nữa vẫn lệch.
 * {@see MaterialiseStoredFile} ném lỗi này; bản tải về THIẾU byte vẫn là {@see DocumentStorageUnavailable}
 * thường (một luồng đứng vì hết `read_timeout` cũng cho ra như vậy).
 *
 * Kế thừa {@see DocumentStorageUnavailable} để mọi nơi bắt cũ giữ nguyên hành vi (không đổi đĩa, không
 * dùng bản tải về). Nơi cần nói thật với người dùng bắt nó TRƯỚC: {@see BuildHandoverPackage} (câu "báo
 * quản trị, kiểm bằng vkcrm:storage:verify" thay vì "bấm sinh lại sau ít phút") và
 * {@see PullDocumentsToLocal} (lý do `changed`: chạy lại quay lui không giúp).
 *
 * Thông điệp mang khoá mờ, chỉ cho log (như {@see StoredFileMissing}); không mã tệp Drive.
 */
class StoredFileChanged extends DocumentStorageUnavailable
{
    public static function forKey(string $key): self
    {
        return new self(__('storage.read.checksum_mismatch', ['key' => $key]));
    }
}
