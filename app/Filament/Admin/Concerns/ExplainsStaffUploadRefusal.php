<?php

namespace App\Filament\Admin\Concerns;

use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Support\UploadThrottle;
use Illuminate\Validation\ValidationException;

/**
 * Cửa RA của endpoint tải lên cho các màn hình NHÂN SỰ có ô chọn tệp (hôm nay: tab "Tài liệu" của
 * hồ sơ, {@see DocumentsRelationManager}).
 * Cắm vào Livewire component nào chứa một `FileUpload` của panel /admin.
 *
 * # Vấn đề (M8 Task 3, fix round 1, F2)
 *
 * Endpoint `livewire.upload-file` có trần riêng cho nhân sự ({@see UploadThrottle::STAFF_FILES_PER_HOUR},
 * 200 tệp/giờ) và trả 429 khi chạm. JS của Livewire gọi `_uploadErrored` với `errors = null` cho
 * MỌI mã khác 422 (đã đọc trong `vendor/livewire/livewire/dist/livewire.esm.js`), nên bản gốc của
 * Livewire ném "Tải lên … không thành công" — không lý do, không thời gian chờ, và tên thuộc tính
 * kỹ thuật (`mountedActions.0.data.file`) ngay trong câu. Luật sư tải một bộ hồ sơ toà lớn chạm trần
 * rồi thử lại mãi. Cổng khách đã có câu riêng ({@see SubmitDocument::_uploadErrored()});
 * đây là bản của nhân sự, cố ý KHÔNG nhắc số điện thoại văn phòng (nhân sự không gọi văn phòng để
 * được tải tiếp — họ chờ hoặc nhờ quản trị hệ thống).
 *
 * # Đúng MỘT lý do được nhận ra
 *
 * Chỉ khi `$errorsInJson === null` (không phải 422) VÀ bộ đếm của chính người này báo trần giờ
 * ({@see UploadThrottle::refusalWaitMinutes()}) thì mới dùng câu này. Mọi trường hợp còn lại đi
 * nguyên xi qua `parent::_uploadErrored()` — kể cả một lỗi xác thực 422 (thân JSON còn nguyên, vẫn
 * hiện câu của luật `max`/`mimetypes` bằng tiếng Việt) xảy ra ngay sau một lần chạm trần.
 * `dispatch('upload:errored')` giữ nguyên của bản gốc: FilePond nghe nó để gỡ vòng quay tải lên.
 *
 * Trait phải đứng trong lớp CON của lớp đang dùng `WithFileUploads` (mọi component Filament có
 * schema đều dùng nó qua lớp cha) — `parent::` ở đây trỏ về bản của Livewire.
 */
trait ExplainsStaffUploadRefusal
{
    public function _uploadErrored($name, $errorsInJson, $isMultiple) // @phpstan-ignore-line — chữ ký của Livewire
    {
        $minutes = $errorsInJson === null ? UploadThrottle::refusalWaitMinutes(request()) : null;

        if ($minutes === null) {
            return parent::_uploadErrored($name, $errorsInJson, $isMultiple);
        }

        $this->dispatch('upload:errored', name: $name)->self();

        throw ValidationException::withMessages([
            $name => __('documents.errors.staff_upload_rate_limited', [
                'limit' => UploadThrottle::limitFor(request()),
                'minutes' => $minutes,
            ]),
        ]);
    }
}
