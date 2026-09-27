<?php

use App\Enums\Role;
use App\Mail\Client\StageUpdate;
use App\Mail\Staff\DeadlineReminder;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * M6.5 Task 12 (`notify/notify-14`) — người gửi và Reply-To của mọi thư văn phòng.
 *
 * Trước bản sửa này: `MAIL_FROM_NAME="${APP_NAME}"` (`.env.example`), nên hộp thư của khách hiện
 * người gửi 'VK-CRM' — tên KỸ THUẬT của dự án, không phải tên văn phòng khách nhận ra. Không mẫu
 * thư nào đặt Reply-To, nên khách bấm "Trả lời" rơi vào `no-reply@` — một hộp không ai đọc, chỉ
 * dùng cho SPF/DKIM (`MAIL_FROM_ADDRESS`).
 *
 * Không dùng `Mail::fake()` (cùng lý do `OutboundLedgerTest`/`EmailLayoutTest`: `MailFake` thay
 * cả trình gửi thư, không đi qua transport thật, và From/Reply-To được `Illuminate\Mail\Mailer`
 * gắn vào đúng lúc gửi thật). `MAIL_MAILER=array` (`phpunit.xml`) nên thư "gửi" xong rồi nằm lại
 * trong bộ nhớ, đọc được qua `ArrayTransport::messages()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Đọc thư THẬT cuối cùng đã "gửi" qua transport `array` mặc định — cùng kỹ thuật EmailLayoutTest. */
function lastSentSymfonyEmail(): Email
{
    return Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();
}

it('shows the office name as the sender, not the technical app name', function () {
    $clientUser = ClientUser::factory()->create();

    $clientUser->notify(new SendLoginCode('123456', 5));

    $from = lastSentSymfonyEmail()->getFrom()[0];

    expect($from->getName())->toBe((string) config('vkcrm.brand.short_name'))
        ->and($from->getName())->not->toBe((string) config('app.name'));
});

it('sets a reply-to address on client mail, so replying does not fall into no-reply', function () {
    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));

    $replyTo = lastSentSymfonyEmail()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe((string) config('vkcrm.brand.reply_to'))
        ->and($replyTo[0]->getAddress())->not->toBe((string) config('mail.from.address'));
});

it('sets a reply-to address on staff mail too', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today()->addDays(7),
    ]);

    Mail::to($lawyer->email)->send(new DeadlineReminder($deadline, $lawyer, 'd7'));

    $replyTo = lastSentSymfonyEmail()->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe((string) config('vkcrm.brand.reply_to'));
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1 (minor) — Reply-To phải "blank-safe" (BRAND_REPLY_TO_ADDRESS= rỗng nghĩa là "chưa
// cấu hình", không phải Address('')), và phải áp dụng MẶC ĐỊNH từ chính BrandedMailable, không
// cần mỗi mẫu con tự gọi.
// ---------------------------------------------------------------------------------------------

/**
 * Mutation probe: xem báo cáo — bỏ điều kiện `filled($address)` trong
 * `BrandedMailable::replyToAddress()` (trả thẳng `config(...)` dù rỗng) làm test này đỏ:
 * `Address('')` khiến `getReplyTo()` không còn rỗng (Symfony vẫn dựng một Address, dù chuỗi rỗng).
 */
it('does not set a reply-to header when the configured address is blank', function () {
    config(['vkcrm.brand.reply_to' => '']);

    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));

    expect(lastSentSymfonyEmail()->getReplyTo())->toBeEmpty();
});

/** Cặp dương: một địa chỉ có giá trị thì vẫn có Reply-To như thường — ghim rằng test trên đỏ đúng vì rỗng, không vì lý do khác. */
it('still sets a reply-to header when the configured address is not blank', function () {
    config(['vkcrm.brand.reply_to' => 'lienhe@luatvukhang.com']);

    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));

    expect(lastSentSymfonyEmail()->getReplyTo())->toHaveCount(1);
});

/**
 * Biến thể của "blank-safe": một giá trị CHỈ CÓ KHOẢNG TRẮNG (`' '`) — PHP coi `empty(' ')` là
 * `false` (chỉ chuỗi RỖNG mới `empty()`), nên đây là ca DUY NHẤT phân biệt được điều kiện
 * `filled()` của `replyToAddress()` với việc dựa vào `Illuminate\Mail\Mailable::setAddress()` tự
 * lọc (hàm đó dùng `empty()`, không lọc được khoảng trắng). Không có điều kiện `filled()` riêng,
 * một cấu hình gõ nhầm khoảng trắng sẽ cố dựng một địa chỉ Reply-To không hợp lệ.
 *
 * Mutation probe: xem báo cáo — bỏ `filled()`, trả thẳng `config(...)`, làm test này đỏ (Reply-To
 * không còn rỗng, mang một địa chỉ chỉ có khoảng trắng).
 */
it('treats a whitespace-only reply-to address as blank too, not just an empty string', function () {
    config(['vkcrm.brand.reply_to' => ' ']);

    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    Mail::to($recipient->email)->send(new StageUpdate($stageLog, $recipient));

    expect(lastSentSymfonyEmail()->getReplyTo())->toBeEmpty();
});
