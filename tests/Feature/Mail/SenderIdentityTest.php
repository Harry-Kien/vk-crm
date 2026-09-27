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
