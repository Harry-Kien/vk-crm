<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Settings\SendTestMailToSelf;
use App\Mail\Staff\TestMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Gửi thư thử của nút "Gửi thư thử" (làn st) — xếp bởi {@see SendTestMailToSelf} SAU commit. Người
 * nhận tính lại LÚC GỬI qua {@see ResolveStaffRecipients::forTestMail()} (R3): người bấm đã bị hạ quyền
 * hay vô hiệu hoá giữa chừng thì không thư nào đi.
 *
 * Đúng MỘT lần thử (`$tries = 1`): thư thử dùng để thấy máy chủ thư có hỏng không — thử lại âm thầm
 * năm lần sẽ che chính điều quản trị viên đang muốn thấy. Lần hỏng nằm ở sổ thư (dòng `failed`, có
 * lý do) như mọi thư khác; không chuông, không thư báo lỗi.
 */
class SendTestMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        foreach (app(ResolveStaffRecipients::class)->forTestMail($this->userId) as $recipient) {
            Mail::to($recipient->email)->send(new TestMail($recipient));
        }
    }
}
