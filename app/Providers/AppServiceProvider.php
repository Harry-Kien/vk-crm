<?php

namespace App\Providers;

use App\Actions\Backup\GuardBackupEncryption;
use App\Actions\Backup\GuardOffServerBackupDestination;
use App\Actions\Backup\GuardRcloneDestinationReachable;
use App\Actions\Backup\PushBackupArchiveToRclone;
use App\Enums\Role;
use App\Http\Controllers\DocumentDownloadController;
use App\Listeners\RecordOutboundMail;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\NullScanner;
use App\Support\Files\VirusScanner;
use App\Support\Mail\OutboundLedgerMailManager;
use App\Support\Security\HttpsDefaults;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupWasSuccessful;

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

        /*
         * Mọi transport thư được bọc để một lần gửi HỎNG cũng để lại dòng `outbound_messages`
         * (SPEC §4.15). Lý do đầy đủ — vì sao phải bọc transport chứ không nghe thêm một sự
         * kiện, và vì sao bọc ở `createSymfonyTransport()` chứ không ở `Mail::extend()` — nằm ở
         * docblock của hai lớp trong `App\Support\Mail`.
         *
         * `extend()` chứ không `singleton()`, và đây là chỗ dễ sai nhất trong cả việc này:
         * `Illuminate\Mail\MailServiceProvider` là provider HOÃN và nó đăng ký `mail.manager`,
         * `mailer` lẫn `Markdown::class`. Một `singleton('mail.manager', …)` đặt ở đây sẽ bị
         * chính nó ghi đè vào lần đầu ai đó phân giải `mailer` hay `Markdown::class` — tức nhật
         * ký im lặng biến mất giữa chừng. Extender của container sống sót qua lần đăng ký lại ấy.
         *
         * Manager gốc bị bỏ đi chứ không bọc thêm một lớp, vì `MailManager` không mang trạng
         * thái nào lúc dựng ngoài chính `$app`, và không có nơi nào khác trong dự án thay nó.
         */
        $this->app->extend('mail.manager', fn ($manager, $app) => new OutboundLedgerMailManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * SPEC §10 mục 1 (kế hoạch M8 Task 1) — `SESSION_SECURE_COOKIE` để trống giải thành
         * `true` ở mọi môi trường trừ `local`/`testing` (thành ngữ chung, xem
         * `App\Support\Security\HttpsDefaults`). `config/session.php` VẪN đọc thẳng
         * `env('SESSION_SECURE_COOKIE')` (không đổi), nên giá trị đọc ra ở ĐÂY còn là giá trị THÔ
         * — ghi đè lại đúng khoá đó SAU KHI môi trường đã biết. `boot()` LÀ nơi AN TOÀN DUY NHẤT
         * để làm việc này: nó chạy sau `LoadConfiguration` (nên `app()->environment()` đã có câu
         * trả lời) và chạy TRƯỚC bất kỳ middleware nào — `Illuminate\Foundation\Http\Kernel`
         * chạy bootstrapper `BootProviders` (gọi `boot()` của mọi provider) làm bước CUỐI của
         * `bootstrap()`, trước khi router gửi request vào pipeline middleware, nên
         * `StartSession` không bao giờ đọc được giá trị thô còn sót lại. Đọc thêm lý do "vì sao
         * không đặt logic này thẳng trong `config/session.php`" ở docblock của `HttpsDefaults`.
         */
        config(['session.secure' => HttpsDefaults::boolFromRaw(config('session.secure'))]);

        /*
         * `URL::forceHttps()` không đặt trong middleware `EnforceHttps`: một job hàng đợi (thư có
         * link tải tệp ký sẵn, PDF xuất ra) không đi qua middleware nào, nhưng NÓ VẪN chạy qua
         * provider này — mọi tiến trình (web, `queue:work`, lệnh artisan) đều gọi `boot()`. Đặt ở
         * đây để link luôn là `https` bất kể ai sinh ra nó, không riêng request HTTP.
         */
        if (HttpsDefaults::boolFromRaw(config('vkcrm.security.force_https'))) {
            URL::forceHttps();
        }

        /*
         * Cánh cửa thư đi ra (SPEC §4.15, Phán quyết R1 của M6): nhật ký được ghi bởi một
         * listener nghe sự kiện thư của Laravel, không bởi từng nơi gửi thư — nên một thư mà
         * không ai nhớ là mình gửi vẫn để lại dấu vết.
         *
         * Đăng ký tường minh để chỗ móc vào framework nhìn thấy được bằng mắt. Laravel 13 CÓ tự
         * dò listener trong `app/Listeners`, nhưng chỉ nhặt những phương thức tên `handle*` hay
         * `__invoke` — `RecordOutboundMail` cố ý đặt tên khác, và docblock của nó ghi lại phép
         * đo vì sao (đăng ký cả hai đường thì mỗi thư sinh ra hai dòng nhật ký).
         */
        Event::subscribe(RecordOutboundMail::class);

        /*
         * Guard mã hoá sao lưu ở production (SPEC §10 mục 8, R3) chạy BÊN TRONG
         * `Spatie\Backup\Tasks\Backup\BackupJob::run()`, lúc manifest vừa dựng xong và zip chưa
         * được tạo — để lượt sao lưu bị từ chối đi đúng đường `BackupHasFailed` → thư báo lỗi,
         * và để không đường gọi nào (lệnh, `--config=`, gọi thẳng `BackupJob`) bỏ qua được. Lý
         * do đầy đủ ở docblock của `GuardBackupEncryption`. Listener đồng bộ: ngoại lệ phải nổi
         * lên trong chính `run()`.
         */
        Event::listen(BackupManifestWasCreated::class, [GuardBackupEncryption::class, 'handle']);

        /*
         * Cấu hình "đẩy Google Drive sẽ không bao giờ chạy" (M8a Task 2, vòng rà soát 1, fix I2 —
         * phần "consider" của brief) — CÙNG sự kiện với `GuardBackupEncryption` ngay trên, nhưng
         * KHÔNG NÉM LỖI (đọc docblock của `GuardRcloneDestinationReachable`): một `BackupHasFailed`
         * được phát thẳng, không chặn lượt sao lưu cục bộ đêm nay.
         */
        Event::listen(BackupManifestWasCreated::class, [GuardRcloneDestinationReachable::class, 'handle']);

        /*
         * Production không có bản sao NGOÀI máy chủ (không remote rclone, mọi đĩa đích là local) —
         * fix I4, lượt rà soát cuối M8a, SPEC §10 mục 8. Cùng sự kiện, cùng thành ngữ "báo mà không
         * chặn" với `GuardRcloneDestinationReachable` ngay trên; lý do ở docblock của
         * `GuardOffServerBackupDestination`.
         */
        Event::listen(BackupManifestWasCreated::class, [GuardOffServerBackupDestination::class, 'handle']);

        /*
         * Đẩy archive vừa sao lưu xong lên Google Drive bằng `rclone` (M8a Task 2, Ruling 1 của
         * brief). `BackupWasSuccessful` bắn NGAY SAU khi gói ghi xong archive vào một disk đích —
         * đăng ký tường minh, cùng thành ngữ với `GuardBackupEncryption` ngay trên. Lý do đầy đủ,
         * kể cả vì sao chỉ phản ứng với disk `local_backups`, ở docblock của
         * `PushBackupArchiveToRclone`.
         */
        Event::listen(BackupWasSuccessful::class, [PushBackupArchiveToRclone::class, 'handle']);

        /*
         * M7 Task 2: trang tự viết `App\Filament\Admin\Pages\BulkReassign` (bàn giao hàng loạt) —
         * admin hoặc trưởng phòng, cùng phán quyết controller ("luật sư dùng nút 'Bàn giao' từng
         * vụ như hiện nay"). `Gate::define()` thay vì thêm một quyền thứ 14 vào
         * `App\Enums\Permission` — enum đó ghim đúng "13 quyền ở SPEC §5", một danh sách đóng mà
         * SPEC liệt kê tường minh; ability này không nằm trong danh sách đó và không cần một cột
         * `permissions` mới cho một cổng chỉ mở MỘT trang. Từng vụ việc bên trong trang này vẫn tự
         * hỏi lại `MatterPolicy::manageTeam()` qua `ReassignMatter`/`ReassignMatters` — cổng này
         * chỉ quyết định ai MỞ ĐƯỢC trang, không quyết định vụ việc nào bàn giao được.
         */
        Gate::define('bulkReassign', fn (User $user): bool => $user->hasRole(Role::Admin->value) || $user->hasRole(Role::Manager->value));

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
            // M9 Task 2: bốn model tiền mới. Map NGHIÊM NGẶT — thiếu tên ở đây thì
            // `Audit::record(..., $contract)` hay `outbound_messages.related` trỏ tới một trong
            // bốn model này là một lỗi 500 (`ClassMorphViolationException`), không phải một dòng
            // âm thầm lưu tên lớp đầy đủ.
            'contract' => Contract::class,
            'instalment' => Instalment::class,
            'payment' => Payment::class,
            'contract_amendment' => ContractAmendment::class,
            // M9 Task 12: khung time_entries (SPEC §15, giai đoạn 2). Không Action/màn hình nào
            // ghi Audit trên model này ở M9, nhưng map NGHIÊM NGẶT đòi mọi model có tên ở đây
            // TRƯỚC KHI bất cứ đâu (kể cả một job tương lai) có thể trỏ `outbound_messages.related`
            // hay `Audit::record()` vào nó mà không vấp `ClassMorphViolationException`.
            'time_entry' => TimeEntry::class,
            // M10 Task 1: bản ghi tiếp nhận và bên đối lập của nó. Map NGHIÊM NGẶT — thiếu tên ở đây
            // thì `Audit::record(..., $intake)` (dòng `conflict_check_run` chủ thể là bản ghi tiếp
            // nhận, Task 2) và `outbound_messages.related` là một lỗi 500.
            'intake_request' => IntakeRequest::class,
            'intake_party' => IntakeParty::class,
            // M7 Task 8: chủ thể của `communication_logged` / `communication_log_deleted`. Cũng
            // có tên trong `ActivityOwningMatter::MATTER_OWNED`, để dòng nhật ký của một vụ
            // `restricted` không lọt ra trang Nhật ký hệ thống.
            'communication_log' => CommunicationLog::class,
        ]);

        // Giới hạn lượt tải tệp (route `documents.download`). Con số và toàn bộ lý lẽ — kể cả vì
        // sao KHÔNG dùng mã dùng một lần — nằm ở `DocumentDownloadController::DOWNLOADS_PER_MINUTE`;
        // ở đây chỉ có chỗ cắm vào framework. Khoá đếm cũng lấy từ controller để hai nơi không
        // định nghĩa "ai là người đang tải" theo hai cách khác nhau.
        RateLimiter::for('document-download', fn (Request $request) => Limit::perMinute(
            DocumentDownloadController::DOWNLOADS_PER_MINUTE,
        )->by(DocumentDownloadController::rateLimitKey($request)));

        // Giới hạn TẢI TỆP LÊN của endpoint `livewire.upload-file` (SPEC §10.3) KHÔNG còn đăng ký ở
        // đây: từ M8 Task 3 nó là middleware `App\Http\Middleware\ThrottleUploadedFiles` (cắm ở
        // `config/livewire.php`), vì một bộ đếm có tên của `ThrottleRequests` đếm REQUEST còn SPEC
        // đòi đếm TỆP — xem docblock `App\Support\UploadThrottle`.

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
