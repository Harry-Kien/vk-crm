<?php

namespace App\Jobs;

use App\Actions\Client\IssuePortalAccess;
use App\Enums\Role;
use App\Mail\Client\Activation;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sinh mật khẩu tạm, ghi hash, đặt `must_change_password = true`, và gửi mẫu `client.activation` —
 * dispatch bởi `App\Actions\Client\IssuePortalAccess` (SPEC §9, Task 3).
 *
 * **Vì sao SINH MẬT KHẨU ở ĐÂY, không ở `IssuePortalAccess` (đề xuất của setup agent, giữ nguyên
 * ở task này).** Một Job `ShouldQueue` được Laravel serialize TOÀN BỘ thuộc tính công khai/promoted
 * của nó vào cột `payload` của bảng `jobs` (và `failed_jobs` nếu hỏng hẳn) — văn bản THUẦN, không
 * mã hoá. Nếu `IssuePortalAccess` sinh mật khẩu rồi truyền nó vào CONSTRUCTOR của job này, chuỗi
 * mật khẩu rõ nằm trong `jobs.payload` suốt thời gian job còn chờ tới lượt (và mãi mãi trong
 * `failed_jobs` nếu job hỏng hẳn) — bất kỳ ai đọc được bảng đó (một quản trị viên CSDL, một bản
 * backup rò rỉ) đọc được mật khẩu của MỌI tài khoản khách đang chờ kích hoạt.
 *
 * Job này vì vậy chỉ nhận `$clientUserId`/`$actorId` (hai số nguyên, vô hại khi lộ) — mật khẩu
 * được sinh MỚI, ngay bên trong `handle()`, mỗi lần job CHẠY (không phải mỗi lần job được TẠO),
 * sống trong một biến cục bộ, dùng để (1) ghi hash và (2) dựng `Activation` mailable rồi gửi
 * NGAY — và biến đó biến mất khi `handle()` trả về. Không gì serialize nó.
 *
 * **"Thử lại thì sinh mật khẩu mới" (đề xuất của setup agent).** Nếu `Mail::to()->send()` ném lỗi
 * (transport chết), job này thất bại và hàng đợi thử lại theo `$tries`/`backoff()` — lần thử SAU
 * gọi lại `handle()` TỪ ĐẦU, sinh một mật khẩu KHÁC, ghi đè hash CŨ (chưa từng tới tay ai — lần
 * gửi trước đã hỏng). Không có gì mất: tài khoản chỉ dùng được với mật khẩu của lần gửi THÀNH
 * CÔNG cuối cùng, và `must_change_password` vẫn `true` cho tới khi khách tự đổi.
 *
 * **Ghi hash TRƯỚC khi gửi, dưới khoá dòng, trong một transaction NGẮN — rồi gửi thư NGOÀI
 * transaction đó** (R2, M6.5: "mọi thư đi qua hàng đợi, sau khi commit, không bao giờ nằm trong
 * transaction"; ở đây job NÀY đã tách khỏi transaction của Action gọi nó, nên transaction bên
 * dưới chỉ còn bọc đúng CÂU GHI, không bọc cú gọi mạng).
 */
class SendPortalActivationMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  int|null  $actorId  Người vừa bấm "Cấp quyền truy cập" — chỉ dùng để BÁO nếu job
     *                             này hỏng hẳn (xem {@see self::failed()}); `null` khi không xác
     *                             định được người thực hiện (không nên xảy ra qua UI thật).
     */
    public function __construct(
        public readonly int $clientUserId,
        public readonly ?int $actorId = null,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $issued = DB::transaction(function (): ?array {
            /** @var ClientUser|null $account */
            $account = ClientUser::query()->lockForUpdate()->find($this->clientUserId);

            if ($account === null || ! $this->stillEligibleForActivation($account)) {
                return null;
            }

            // `Str::password()` mặc định trộn chữ hoa/thường, số, ký hiệu — thừa sức thoả
            // `PasswordRule::default()` (tối thiểu 8 ký tự, xem `ClientUserForm` cũ và
            // `ChangePassword`), không cần một vòng lặp kiểm tra lại.
            $temporaryPassword = Str::password(12);

            $account->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ])->save();

            return [$account, $temporaryPassword];
        });

        if ($issued === null) {
            return;
        }

        [$account, $temporaryPassword] = $issued;

        Mail::to($account->email)->send(new Activation($account, $temporaryPassword));
    }

    /**
     * `is_active` VÀ khách chưa xoá mềm — NGOẠI LỆ có chủ ý của R12 (không đòi `activated_at`,
     * vì đây CHÍNH LÀ thư chứng minh hộp thư): xem docblock `App\Actions\Notification\
     * ResolveClientRecipients` và `App\Mail\Client\Activation`.
     *
     * Fix round 1 (finding Important 1): gọi lại {@see IssuePortalAccess::isEligible()} thay vì
     * giữ một bản điều kiện riêng — `IssuePortalAccess::handle()` giờ hỏi ĐÚNG câu này TRƯỚC khi
     * ghi audit/dispatch, nên job (đọc lại lúc THẬT SỰ chạy) và Action (đọc lúc CẤP quyền) phải
     * dùng chung một định nghĩa, không phải hai bản có thể lệch nhau sau này. `$account` ở đây đã
     * được đọc dưới khoá (`lockForUpdate()` ở `handle()`), nên đây chỉ là một câu hỏi thêm trên
     * đúng bản ghi đó, không phải một truy vấn danh sách.
     */
    private function stillEligibleForActivation(ClientUser $account): bool
    {
        return IssuePortalAccess::isEligible($account);
    }

    /**
     * Hỏng HẲN sau khi hết `$tries`: không ai có mật khẩu để đăng nhập, và không ai biết. Không có
     * `Matter` nào để hỏi `ResolveStaffRecipients` — tài khoản cổng không thuộc về một vụ việc cụ
     * thể (khác `client.stage_update`/`client.document_published`) — nên chuỗi báo ở đây là CỦA
     * RIÊNG mẫu này: người vừa bấm "Cấp quyền truy cập" nếu còn `is_active`, rơi xuống MỌI admin
     * đang hoạt động khi không (đã rời văn phòng giữa lúc job chờ tới lượt, hoặc job không mang
     * `$actorId`).
     */
    public function failed(?Throwable $exception): void
    {
        $account = ClientUser::query()->withTrashed()->find($this->clientUserId);

        if ($account === null) {
            return;
        }

        foreach ($this->staffToNotify() as $recipient) {
            Notification::make()
                ->title(__('client_users.activation_failed_notification.title'))
                ->body(__('client_users.activation_failed_notification.body', ['email' => $account->email]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }

    /** @return Collection<int, User> */
    private function staffToNotify(): Collection
    {
        $actor = $this->actorId !== null
            ? User::query()->where('is_active', true)->find($this->actorId)
            : null;

        if ($actor !== null) {
            return collect([$actor]);
        }

        return User::query()->where('is_active', true)->role(Role::Admin->value)->get();
    }
}
