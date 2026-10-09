<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\DocumentStoreStatus;
use App\Enums\OutboundStatus;
use App\Enums\Permission;
use App\Models\OutboundMessage;
use App\Models\SystemHealth;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * SPEC §7.1 mục 7 / §2 "Giám sát cron": dải đỏ khi lịch chạy tự động đã chết.
 *
 * ĐÍNH CHÍNH CÓ NGÀY cho SPEC §7.1 (2026-09-23), cùng lập luận đã ghi cho hàng ô số: §7.1 đánh
 * số widget này thứ bảy, nhưng nó không phải một widget trong danh sách — nó là một DẢI cảnh báo,
 * và chính SPEC gọi nó là "dải đỏ". Một cảnh báo rằng hệ thống nhắc việc đã chết mà nằm dưới đáy
 * trang thì vô dụng, vì thứ nó cảnh báo chính là thứ khiến người ta không cuộn xuống. Nên nó đứng
 * trên cùng, và chỉ hiện khi có chuyện — im lặng khi mọi thứ bình thường.
 *
 * BA TRẠNG THÁI, không phải hai. "Chưa bao giờ chạy" là trạng thái một lần triển khai mới thật sự
 * gặp: hệ thống vừa dựng xong, dòng cron chưa cắm, và một cách viết ngây thơ sẽ im lặng vì `null`
 * không lớn hơn ngưỡng nào cả. Đó là lúc cảnh báo cần thiết nhất.
 */
class SystemHealthWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.system-health';

    // Trên cả hàng ô số (-5), vì đây là cảnh báo chứ không phải thông tin.
    protected static ?int $sort = -6;

    protected int|string|array $columnSpan = 'full';

    /**
     * Chỉ nhân sự nội bộ. Không hỏi quyền cụ thể nào: đây là tình trạng vận hành của hệ thống,
     * không phải dữ liệu hồ sơ, và người cần thấy nó nhất có thể là người ít quyền nhất đang
     * ngồi ở văn phòng vào sáng thứ Hai.
     */
    public static function canView(): bool
    {
        return Auth::user() instanceof User;
    }

    public function getViewData(): array
    {
        $health = SystemHealth::query()->where('singleton', 1)->first();

        return [
            // Chưa có dòng nào cũng là "chưa bao giờ chạy": bảng rỗng và cột rỗng nói cùng một
            // chuyện với người đọc, nên màn hình phải nói cùng một câu.
            'neverRan' => $health === null || $health->scheduleHasNeverRun(),
            'stale' => $health !== null && $health->scheduleIsStale(),
            'lastRunAt' => $health?->last_schedule_run_at,
            'staleAfterMinutes' => SystemHealth::STALE_AFTER_MINUTES,
            'documentStore' => $this->documentStoreAlert($health),
            'backup' => $this->backupAlert($health),
        ];
    }

    /** Cửa sổ đếm thư báo lỗi sao lưu không gửi được ({@see self::backupAlert()}). */
    public const BACKUP_MAIL_FAILED_WINDOW_DAYS = 7;

    /**
     * Làn fc (kiểm tra nghiệp vụ 2026-10-09, mục B "cảnh báo sao lưu chỉ có một kênh") — dòng đỏ của
     * sao lưu, để cảnh báo không phụ thuộc thư có đi được hay không. Hai nguồn, mỗi nguồn một câu:
     *
     * - đã cấu hình Google Drive (`vkcrm.backup.rclone.remote`) mà bản gần nhất ĐÃ XÁC MINH trên đó
     *   (`system_health.last_offsite_backup_at`, ghi bởi `PushBackupArchiveToRclone`) cũ hơn
     *   {@see SystemHealth::offsiteBackupMaxAgeHours()} giờ — hay chưa từng có. Chưa cấu hình (máy dev)
     *   thì không nói gì: máy chủ thật thiếu đích ngoài đã có dòng ĐỎ của preflight và thư mỗi đêm;
     * - có thư báo lỗi sao lưu (`staff.backup_alert.*`) ở trạng thái `failed` trong
     *   {@see self::BACKUP_MAIL_FAILED_WINDOW_DAYS} ngày qua: một lỗi sao lưu mà thư báo về nó cũng hỏng.
     *
     * `null` khi không có gì để nói, và với người không có `settings.manage` — như dòng kho tài liệu:
     * việc sửa là của quản trị viên. Câu chữ không mang dữ liệu hồ sơ nào.
     *
     * @return array{offsite: ?string, mailFailed: int}|null
     */
    private function backupAlert(?SystemHealth $health): ?array
    {
        if (! Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value)) {
            return null;
        }

        $offsite = null;

        if (filled(config('vkcrm.backup.rclone.remote'))) {
            $health ??= new SystemHealth;

            if ($health->offsiteBackupIsStale()) {
                $offsite = $health->last_offsite_backup_at === null
                    ? __('ops_checks.widget.offsite_never')
                    : __('ops_checks.widget.offsite_stale', [
                        'hours' => SystemHealth::offsiteBackupMaxAgeHours(),
                        'at' => $health->last_offsite_backup_at->copy()->timezone(config('app.timezone'))->format('H:i d/m/Y'),
                    ]);
            }
        }

        $mailFailed = OutboundMessage::query()
            ->where('template', 'like', 'staff.backup_alert.%')
            ->where('status', OutboundStatus::Failed->value)
            ->where('created_at', '>=', now()->subDays(self::BACKUP_MAIL_FAILED_WINDOW_DAYS))
            ->count();

        if ($offsite === null && $mailFailed === 0) {
            return null;
        }

        return ['offsite' => $offsite, 'mailFailed' => $mailFailed];
    }

    /**
     * M14 Task 5 — dòng đỏ của kho tài liệu (kế hoạch R9, R13): khi lần kiểm sức khoẻ gần nhất
     * (`CheckDocumentStoreHealth`, mỗi giờ) để trạng thái KHÁC `ok`. `null` (không có dòng) khi kho ổn
     * hay chưa từng được kiểm (kho không dùng: mọi máy chủ trước M14), và với người không có
     * `settings.manage`: chi tiết có thể nêu email thành viên lạ trên Shared Drive, và việc sửa là
     * của quản trị viên. Dòng lịch chạy tự động ở trên vẫn cho mọi nhân sự, như cũ.
     *
     * @return array{status: string, detail: ?string, checkedAt: ?Carbon}|null
     */
    private function documentStoreAlert(?SystemHealth $health): ?array
    {
        $status = $health?->document_store_status;

        if ($status === null || $status === DocumentStoreStatus::Ok) {
            return null;
        }

        if (! Gate::forUser(Auth::user())->allows(Permission::SettingsManage->value)) {
            return null;
        }

        return [
            'status' => $status->label(),
            'detail' => $health->document_store_detail,
            'checkedAt' => $health->document_store_checked_at,
        ];
    }
}
