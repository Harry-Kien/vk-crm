<?php

namespace App\Providers;

use App\Http\Controllers\DocumentDownloadController;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\NullScanner;
use App\Support\Files\VirusScanner;
use App\Support\UploadThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SPEC §6.6 bước 5: implementation chọn theo `CLAMAV_ENABLED`, không sửa Action nào để
        // đổi. Đây là MỘT nơi duy nhất quyết định — `UploadStaffDocument`/`SubmitClientDocument`
        // (Task 3/4) chỉ khai báo phụ thuộc vào interface `VirusScanner`.
        $this->app->bind(VirusScanner::class, fn () => config('vkcrm.clamav.enabled')
            ? new ClamAvScanner(config('vkcrm.clamav.socket'))
            : new NullScanner);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'client_user' => ClientUser::class,
            'stage_log' => StageLog::class,
            'document' => Document::class,
            'matter' => Matter::class,
            'deadline' => Deadline::class,
            'client_request' => ClientRequest::class,
            // Chủ thể của hai dòng nhật ký M5 Task 6 (`client_request_replied_by_client` và
            // `client_request_answered_by_staff`, xem `ReplyToClientRequest`). Cùng lý do với
            // `matter_checklist_item` bên dưới: thiếu tên ở đây thì `Audit::record()` với chủ
            // thể là một dòng trả lời là một lỗi 500, chứ không phải một cột lưu tên lớp.
            'client_request_reply' => ClientRequestReply::class,
            'client' => Client::class,
            'matter_party' => MatterParty::class,
            // Chủ thể của dòng nhật ký `checklist_item_reviewed` (SPEC §6.7). `enforceMorphMap()`
            // là bản NGHIÊM NGẶT: một model không có tên ở đây thì `getMorphClass()` ném
            // `ClassMorphViolationException` chứ không lặng lẽ lưu tên lớp đầy đủ — nên thiếu
            // dòng này, `Audit::record()` với chủ thể là một đầu mục danh mục là một lỗi 500.
            'matter_checklist_item' => MatterChecklistItem::class,
        ]);

        // Giới hạn lượt tải tệp (route `documents.download`). Con số và toàn bộ lý lẽ — kể cả vì
        // sao KHÔNG dùng mã dùng một lần — nằm ở `DocumentDownloadController::DOWNLOADS_PER_MINUTE`;
        // ở đây chỉ có chỗ cắm vào framework. Khoá đếm cũng lấy từ controller để hai nơi không
        // định nghĩa "ai là người đang tải" theo hai cách khác nhau.
        RateLimiter::for('document-download', fn (Request $request) => Limit::perMinute(
            DocumentDownloadController::DOWNLOADS_PER_MINUTE,
        )->by(DocumentDownloadController::rateLimitKey($request)));

        /*
         * Giới hạn lượt TẢI TỆP LÊN của endpoint `livewire.upload-file` (SPEC §10.3). Cùng thành
         * ngữ với bộ đếm ngay trên, và cố ý cùng thành ngữ: con số, cách khoá và toàn bộ lý lẽ
         * nằm ở `App\Support\UploadThrottle`; ở đây chỉ có chỗ cắm vào framework. Chỗ cắm phía
         * route nằm ở `config/livewire.php` (`throttle:livewire-upload`).
         *
         * Một bộ đếm CÓ TÊN chứ không phải `throttle:20,60` trần, vì chỉ bộ đếm có tên mới tự
         * quyết định được khoá: `ThrottleRequests` mặc định hỏi guard MẶC ĐỊNH, thứ luôn rỗng
         * trên cổng khách hàng, nên bản trần khoá cả hai vợ chồng vào một rổ theo địa chỉ.
         */
        RateLimiter::for(UploadThrottle::NAME, fn (Request $request) => Limit::perMinutes(
            UploadThrottle::WINDOW_MINUTES,
            UploadThrottle::FILES_PER_HOUR,
        )->by(UploadThrottle::keyFor($request)));

        // Câu trả lời cho "virus scanning có thật sự bật không" phải lấy được từ chính hệ thống,
        // không phải từ việc đọc `.env` hay mã nguồn — `php artisan about` là chỗ một người vận
        // hành đã quen tra cứu tình trạng cấu hình của ứng dụng.
        //
        // Ba trạng thái, không phải hai: `isActive()` của `ClamAvScanner` nay hỏi thật daemon
        // (PING/PONG), nên nó phân biệt được "đã bật và daemon đang trả lời" với "đã bật nhưng
        // daemon câm". Gộp trạng thái thứ ba vào ô "TẮT" sẽ đọc thành "không cấu hình quét virus"
        // trong khi sự thật là quét virus ĐANG BẬT và mọi tệp sắp bị từ chối — hai việc phải xử
        // lý hoàn toàn khác nhau.
        AboutCommand::add('VK-CRM', fn () => [
            'Quét virus khi nộp tệp (VirusScanner)' => match (true) {
                ! (bool) config('vkcrm.clamav.enabled') => 'TẮT — NullScanner (không quét gì cả)',
                $this->app->make(VirusScanner::class)->isActive() => 'BẬT — ClamAvScanner, daemon trả lời PING',
                default => 'BẬT nhưng daemon KHÔNG trả lời — mọi tệp tải lên sẽ bị từ chối',
            },
        ]);
    }
}
