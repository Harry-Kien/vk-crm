<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\HandoverPackageStatus;
use App\Exceptions\HandoverPackageFailed;
use App\Jobs\GenerateHandoverPackage;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Support\Audit;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M7 Task 4 (R9) — ghi lại việc sinh gói THẤT BẠI HẲN: đặt `handover_status = failed` kèm một câu
 * tiếng Việt cho người vận hành, ghi nhật ký `handover_package_failed`, và báo luật sư phụ trách
 * qua thông báo trong hệ thống. Gọi từ {@see GenerateHandoverPackage} (lỗi có tên ở `handle()`,
 * lỗi lạ ở `failed()`).
 *
 * # Câu lưu vào `handover_error`
 *
 * Chỉ thông điệp của {@see HandoverPackageFailed} (đã là tiếng Việt, không lộ đường dẫn máy chủ);
 * mọi lỗi khác thành câu chung `handover.exceptions.unknown`. Thông điệp thô của exception lạ có
 * thể chứa đường dẫn, câu SQL hay tên tệp — nó đi vào log máy chủ (`Log::error`), không vào một
 * cột mà nhân sự đọc được.
 *
 * # Dấu của lần yêu cầu
 *
 * Chỉ ghi khi dòng lưu trữ vẫn ở `generating` VÀ `handover_requested_at` còn khớp `$requestedAt`:
 * một job cũ thất bại muộn không được đè lên trạng thái của lần yêu cầu mới hơn.
 *
 * # Thư mục tạm
 *
 * Khi một job hết `$timeout`, worker gọi `failed()` rồi tự giết tiến trình — `finally` của lần dựng
 * ({@see BuildHandoverPackage}) không bao giờ chạy, và zip dở (có thể vài trăm MB, trên đĩa có hạn
 * mức của shared hosting) sẽ nằm lại. Vì vậy action này xoá thư mục tạm của ĐÚNG lần yêu cầu đó
 * ({@see BuildHandoverPackage::workDirectory()}) — LUÔN, kể cả khi dấu yêu cầu không còn khớp
 * (thư mục vẫn là của job này, dù trạng thái thì không còn là của nó để ghi).
 *
 * # Người nhận
 *
 * Luật sư phụ trách và người đã bấm, qua {@see ResolveStaffRecipients} (M6.5 R3): người không còn
 * hoạt động hoặc không xem được vụ (kể cả vụ `restricted`) tự bị loại, và nếu không còn ai thì
 * chuỗi dự phòng chọn manager rồi admin. Thông báo trong hệ thống (kênh `database`) là một câu
 * INSERT, không phải thư — nên gửi thẳng ở đây, không cần xếp hàng.
 */
class RecordHandoverPackageFailure
{
    use ReadsWithoutPortalScope;

    public function __construct(private ResolveStaffRecipients $recipients) {}

    public function handle(int $matterId, int $requestedAt, Throwable $exception): void
    {
        $reason = $exception instanceof HandoverPackageFailed
            ? $exception->getMessage()
            : __('handover.exceptions.unknown');

        Log::error('handover_package.failed', [
            'matter_id' => $matterId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'previous' => $exception->getPrevious()?->getMessage(),
        ]);

        $this->discardWorkDirectory($matterId, $requestedAt);

        $matter = null;
        $requester = null;

        $recorded = DB::transaction(function () use ($matterId, $requestedAt, $reason, &$matter, &$requester): bool {
            // Khoá `matters` TRƯỚC, rồi `matter_archives`.
            $matter = $this->scopelessly(Matter::query())
                ->withTrashed()
                ->whereKey($matterId)
                ->lockForUpdate()
                ->first();

            $archive = $matter === null ? null : $this->scopelessly(MatterArchive::query())
                ->where('matter_id', $matter->getKey())
                ->lockForUpdate()
                ->first();

            if ($archive === null
                || $archive->handover_status !== HandoverPackageStatus::Generating
                || $archive->handover_requested_at?->getTimestamp() !== $requestedAt
            ) {
                return false;
            }

            $requester = $archive->handoverRequester;

            $archive->update([
                'handover_status' => HandoverPackageStatus::Failed,
                'handover_error' => mb_substr($reason, 0, 500),
            ]);

            Audit::record('handover_package_failed', $matter, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
            ], $requester);

            return true;
        });

        if (! $recorded || $matter === null) {
            return;
        }

        $preferred = [$matter->leadLawyer, $requester];

        foreach ($this->recipients->handle($matter, $preferred) as $user) {
            Notification::make()
                ->title(__('handover.notification.failed_title'))
                ->body(__('handover.notification.failed_body', ['code' => $matter->code, 'reason' => $reason]))
                ->danger()
                ->sendToDatabase($user);
        }
    }

    /**
     * Xoá thư mục tạm của lần yêu cầu này — xem docblock lớp, mục "Thư mục tạm". Lỗi xoá chỉ ghi
     * log: nó không được chặn việc ghi trạng thái lỗi và báo luật sư.
     */
    private function discardWorkDirectory(int $matterId, int $requestedAt): void
    {
        $directory = BuildHandoverPackage::workDirectory($matterId, $requestedAt);

        try {
            File::deleteDirectory($directory);
        } catch (Throwable $exception) {
            Log::warning('handover_package.work_directory_not_deleted', [
                'matter_id' => $matterId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
