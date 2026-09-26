<?php

namespace App\Jobs;

use App\Actions\SyncClientPartyIdentities;
use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * M6.5 Task 8, fix round 3, N1 (Important) — "thời điểm thứ ba" (R13e) giờ chạy như một JOB hàng
 * đợi, không còn đồng bộ ngay trong request lưu hồ sơ khách hàng.
 *
 * **Vì sao round 2 chưa đủ.** `SyncClientPartyIdentities::recheckAffectedOpenMattersSafely()`
 * (round 2) chạy lần rà NGAY trong request, dưới khoá `conflict-check`, nhưng CHỜ khoá đó tối đa
 * 10 giây rồi NUỐT mọi lỗi (kể cả `LockTimeoutException`) bằng `report()`. Hai vấn đề thật:
 * (1) một trợ lý sửa CCCD phải CHỜ tới 10 giây nếu đúng lúc đó `OpenMatter`/`AddMatterParty` khác
 * đang giữ khoá — trải nghiệm tệ cho một thao tác lẽ ra tức thời; (2) NGHIÊM TRỌNG HƠN — nếu khoá
 * không lấy được (hay bất kỳ lỗi nào khác), lần rà đó MẤT VĨNH VIỄN, ÂM THẦM: không có gì re-trigger
 * nó, không có dòng audit nào ghi lại việc bị lỡ, `report()` chỉ ghi vào `laravel.log` mà trên
 * shared hosting (SPEC §2) không ai đọc. Một vụ việc M2 (văn phòng đang kiện chính khách hàng của
 * mình) có thể không bao giờ được gắn cờ, và không ai biết để mà tự tay chạy lại.
 *
 * **Bây giờ.** `SyncClientPartyIdentities::handle()` chỉ còn đồng bộ ảnh chụp định danh (đồng bộ,
 * nhanh, không đụng khoá `conflict-check`), rồi dispatch job NÀY `afterCommit()` — CHỈ mang
 * `clientId` (SPEC §10.5: không mang định danh thô nào vào payload hàng đợi; job tự đọc lại mọi
 * thứ từ CSDL lúc nó THẬT SỰ chạy, xem `handle()`). Job này mới là nơi khoá `conflict-check` và
 * chạy `RunConflictCheck` cho các vụ việc bị ảnh hưởng — `LockTimeoutException` giờ được để LỌT RA
 * (không bắt), để hàng đợi tự thử lại theo `$tries`/`backoff()`. Chỉ khi CẢ `$tries` lần đều thất
 * bại, `failed()` mới chạy — VÀ đó là lúc BẮT BUỘC phải hiện ra: một dòng audit
 * (`client_identity_recheck_failed`) và một thông báo trong ứng dụng cho MỌI admin đang hoạt động,
 * để một lần lỡ hẳn không còn ÂM THẦM.
 *
 * **Fix round 4 (NB-1) — ai xếp job này.** `SyncClientPartyIdentities::handle()` xếp nó sau MỌI lần
 * sửa định danh, kể cả khi chưa có dòng `matter_parties` nào để đồng bộ; `OpenMatter` (bước 5) và
 * `AddMatterParty` (bước 4) xếp nó khi lần làm mới dưới khoá thấy định danh của khách hàng đã đổi
 * so với ảnh chụp mà lần kiểm tra vừa dùng. Hai lời dispatch sau nằm BÊN TRONG khoá
 * `conflict-check` — đúng với hàng đợi `database`, vì job chỉ lấy được khoá sau khi Action nhả nó
 * (xem docblock `OpenMatter::refreshOwnClientIdentitiesUnderLock()`).
 *
 * **`$lockWaitSeconds` (khác `10` cứng của round 2) — vì sao là một property, không phải hằng số.**
 * Test không nên chờ 10 giây thật cho một khẳng định "khoá bận thì ném LockTimeoutException" — bộ
 * test đã có tiền lệ chờ thật ~10-17s ở `OpenMatterTest`/`AddMatterPartyTest` cho một khẳng định
 * KHÁC (khoá phải hoạt động đúng ở tầng ứng dụng thật), nhưng lặp lại điều đó cho MỖI test của job
 * này sẽ làm chậm cả bộ test một cách không cần thiết — hành vi "chờ rồi ném" đã được đo đủ ở hai
 * tệp kia. Test của job này chỉ cần đo "có ném đúng loại lỗi hay không", nên đặt
 * `$lockWaitSeconds = 1` trước khi gọi `handle()` trực tiếp là đủ.
 */
class RecheckClientIdentityConflicts implements ShouldQueue
{
    use Queueable;

    /**
     * 5 lần thử, backoff tăng dần — một lần thất bại thoáng qua (khoá bận) không cần báo động ngay.
     * Thời gian tệ nhất tới lúc `failed()` báo admin: xem `backoff()`.
     */
    public int $tries = 5;

    /** Không hằng số (xem docblock lớp): test đặt ngắn lại trước khi gọi handle() trực tiếp. */
    public int $lockWaitSeconds = 10;

    public function __construct(public readonly int $clientId) {}

    /**
     * Đúng `$tries - 1` = 4 độ trễ (fix round 4, minor): worker thả job lại hàng đợi sau lần thử
     * 1..4 với `backoff()[attempts - 1]`; lần thử thứ 5 thất bại thì gọi `failed()`, không thả lại.
     * Bản trước có phần tử thứ năm (300 giây) không bao giờ được dùng tới.
     *
     * **Thời gian tệ nhất tới lúc admin được báo: khoảng 7–8 phút.** Mỗi lần thử chờ khoá tối đa
     * `$lockWaitSeconds` (10 giây), nên cộng thuần là 5 × 10 + (10 + 30 + 60 + 120) = 270 giây.
     * Nhưng hàng đợi được rút bằng cron mỗi phút với `--stop-when-empty` (`queue.drain`): khi job
     * duy nhất còn lại đang chờ backoff, lần rút đó dừng ngay, và lần thử kế chỉ chạy ở lần cron
     * ĐẦU TIÊN sau khi độ trễ hết. Tính từ lần thử đầu (phút 0): thử lại ở phút 1, 2, 4 và 7; lần
     * thứ năm hỏng vào khoảng 7 phút 10 giây. Cộng tối đa một phút từ lúc dispatch tới lần rút đầu
     * tiên: khoảng 8 phút sau lần sửa hồ sơ khách hàng.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Khoá `conflict-check` — CÙNG khoá `OpenMatter`/`AddMatterParty` dùng (R13g) — rồi rà lại.
     * `LockTimeoutException` KHÔNG bắt ở đây: để nó lọt ra tới worker, đúng cơ chế `$tries`/
     * `backoff()` của hàng đợi xử lý, thay vì một `try`/`catch` tự chế bên trong job.
     */
    public function handle(): void
    {
        Cache::store('database')->lock('conflict-check', 30)->block(
            $this->lockWaitSeconds,
            fn () => app(SyncClientPartyIdentities::class)->recheckForQueuedClient($this->clientId),
        );
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lần đều thất bại (kể cả vì `LockTimeoutException` lặp
     * lại `$tries` lần, nghĩa là khoá bận liên tục nhiều phút — một dấu hiệu bất thường thật sự
     * đáng báo, không phải một lần bận thoáng qua). Bắt buộc phải HIỆN RA, không chỉ nằm trong
     * `laravel.log` — SPEC §2 (shared hosting, không ai theo dõi log) là chính lý do N1 tồn tại.
     *
     * `error_class`, KHÔNG phải `$exception->getMessage()` (SPEC §10.5): thông điệp lỗi là văn bản
     * tự do, không có gì đảm bảo một exception tương lai không vô tình nội suy dữ liệu nhạy cảm vào
     * đó — tên lớp exception đủ để đội kỹ thuật tra `laravel.log` thật, không cần lặp lại nội dung
     * ở đây.
     */
    public function failed(?Throwable $exception): void
    {
        // Bỏ ClientPortalScope (xem chú thích cùng nội dung ở SyncClientPartyIdentities::
        // recheckForQueuedClient()) — `failed()` chỉ dùng $client để gắn subject của dòng audit,
        // nhưng vẫn phải đọc được nó bất kể ngữ cảnh nào worker đang chạy.
        $client = Client::withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($this->clientId);

        Audit::record('client_identity_recheck_failed', $client, [
            'client_id' => $this->clientId,
            'error_class' => $exception === null ? null : $exception::class,
        ]);

        $admins = User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($admins as $admin) {
            Notification::make()
                ->title(__('conflicts.recheck_failed_notification.title'))
                ->body(__('conflicts.recheck_failed_notification.body', ['client_id' => $this->clientId]))
                ->color('danger')
                ->sendToDatabase($admin);
        }
    }
}
