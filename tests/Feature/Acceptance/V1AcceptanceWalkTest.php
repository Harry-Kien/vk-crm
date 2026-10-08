<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Filament\Admin\Resources\Matters\Actions\TransitionStageAction;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Portal\Pages\Auth\Login;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Notifications\Client\SendLoginCode;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
 * Nghiệm thu bản 1.0 (v1 Task 2, M8 Task 8) — SPEC §14 mục 3 và mục 4, ĐI TRÊN DỮ LIỆU MẪU
 * (`DatabaseSeeder`, đúng tài khoản demo của `docs/CAI-DAT.md`), qua màn hình thật (Livewire/HTTP),
 * với đường gửi THẬT: thư đi qua transport `array` của `phpunit.xml` (sổ thư `outbound_messages` ghi
 * như ở máy chủ thật — không `Mail::fake()`), thông báo đẩy đi qua kênh Web Push thật tới máy chủ push
 * giả ở tầng HTTP (`FakePushServer`, M12), hàng đợi `sync`.
 *
 *  - §14.3: luật sư `luatsu1@` chuyển giai đoạn MỘT lần trên tab Tiến độ (có công bố) → khách
 *    `khach1@` nhận đúng một thư, điện thoại của khách nhận đúng một thông báo đẩy, và trang hồ sơ trên
 *    cổng hiện cập nhật — không ai bấm thêm gì. Ghi chú nội bộ không đi tới đâu cả.
 *  - §14.4: khách đăng nhập (mật khẩu + mã một lần trong thư), xem tiến độ, thấy giấy tờ còn thiếu,
 *    nộp một ẢNH CHỤP (JPEG) cho đúng mục đó; luật sư từ chối kèm lý do → khách nhận thư
 *    `client.document_rejected` + thông báo đẩy, và trang hồ sơ hiện nguyên văn lý do cùng mục cần
 *    nộp lại.
 *
 * Hàm toàn cục mang tiền tố `v1a…`.
 */
beforeEach(function () {
    // FakePushServer::start() phải đứng TRƯỚC lần đầu kênh Web Push được phân giải (docblock của nó).
    config(WebPushTestKeys::config());
    $this->pushServer = FakePushServer::start();
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Ho_Chi_Minh'));
    $this->seed(DatabaseSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Hồ sơ ĐẦU TIÊN của khách demo `khach1@` — hồ sơ mà `MatterSeeder` cho đủ bốn trạng thái giấy tờ. */
function v1aMatter(): Matter
{
    return Matter::query()
        ->whereHas('client', fn ($query) => $query->where('email', 'khach1@example.com'))
        ->where('is_published_to_portal', true)
        ->orderBy('id')
        ->firstOrFail();
}

function v1aClient(): ClientUser
{
    return ClientUser::query()->where('email', 'khach1@example.com')->sole();
}

/** @return list<Email> mọi thư transport `array` đã nhận tới giờ, theo thứ tự gửi. */
function v1aSentMail(): array
{
    return collect(Mail::mailer()->getSymfonyTransport()->innerTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->values()
        ->all();
}

/** @return list<string> địa chỉ nhận của từng thư trong `$mails`. */
function v1aRecipients(array $mails): array
{
    return collect($mails)
        ->flatMap(fn (Email $mail) => collect($mail->getTo())->map->getAddress())
        ->values()
        ->all();
}

function v1aProgressUrl(Matter $matter): string
{
    return MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal');
}

/** Dòng `sent` của sổ thư (kênh email) cho `khach1@` theo mẫu thư — dữ liệu mẫu đã có sẵn vài dòng. */
function v1aMailRows(string $template): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('channel', OutboundChannel::Email)
        ->where('recipient', 'khach1@example.com')
        ->where('template', $template)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

function v1aPushRows(ClientUser $account, PushTopic $topic): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('channel', OutboundChannel::Push)
        ->where('recipient', 'client_user:'.$account->id)
        ->where('template', $topic->value)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

it('§14.3 — one stage change by the lawyer mails the client, reaches the phone, and shows on the portal, with no extra step', function () {
    $matter = v1aMatter();
    $lawyer = $matter->leadLawyer;
    $client = v1aClient();
    $phone = FakePushServer::device($client, 'khach1-dien-thoai');

    expect($lawyer->email)->toBe('luatsu1@luatvukhang.com');

    $mailBefore = count(v1aSentMail());
    $ledgerBefore = v1aMailRows('client.stage_update');
    $toStage = array_key_first(TransitionStageAction::stageOptions($matter, $lawyer));
    $publicContent = 'Văn phòng đã nộp đơn và hồ sơ kèm theo tới cơ quan có thẩm quyền, chờ thụ lý (V1A-CONGBO).';

    // Luật sư bấm "Chuyển giai đoạn" MỘT lần, có công bố.
    $this->actingAs($lawyer, 'web');
    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('transitionStage', data: [
            'to_stage' => $toStage,
            'occurred_at' => today()->toDateString(),
            'internal_note' => 'V1A-GHICHU-NOIBO không bao giờ tới tay khách',
            'public_content' => $publicContent,
            'next_step' => null,
            'client_action' => null,
            'expected_next_update_at' => null,
            'publish' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($matter->refresh()->stage)->toBe($toStage);

    // Thư: đúng một thư mới, tới đúng khách, tiêu đề mang mã hồ sơ, không mang ghi chú nội bộ.
    $newMail = array_slice(v1aSentMail(), $mailBefore);

    expect(v1aRecipients($newMail))->toBe(['khach1@example.com'])
        ->and($newMail[0]->getSubject())->toContain($matter->code)
        ->and($newMail[0]->toString())->not->toContain('V1A-GHICHU-NOIBO');

    expect(v1aMailRows('client.stage_update'))->toBe($ledgerBefore + 1);

    // Điện thoại: đúng một thông báo đẩy, tới đúng máy của khách, ghi sổ là đã gửi.
    expect($this->pushServer->endpoints())->toBe([$phone])
        ->and(v1aPushRows($client, PushTopic::ClientStageUpdate))->toBe(1);

    // Cổng: khách mở hồ sơ và thấy cập nhật, giai đoạn mới theo nhãn của khách, không thấy ghi chú nội bộ.
    Filament::setCurrentPanel('portal');
    $stageLabel = $matter->matterType->stages->firstWhere('key', $toStage)->client_label;

    $this->actingAs($client, 'client')
        ->get(v1aProgressUrl($matter))
        ->assertOk()
        ->assertSee($publicContent)
        ->assertSee($stageLabel)
        ->assertDontSee('V1A-GHICHU-NOIBO');
});

it('§14.4 — the client signs in, sees what is missing, sends a photo, and hears back by mail and on the phone when it is rejected', function () {
    $matter = v1aMatter();
    $lawyer = $matter->leadLawyer;
    $client = v1aClient();
    $phone = FakePushServer::device($client, 'khach1-dien-thoai');

    // Một mục bắt buộc còn thiếu (MatterSeeder đặt sẵn trên hồ sơ đầu của khach1).
    $item = MatterChecklistItem::query()
        ->where('matter_id', $matter->id)
        ->where('status', ChecklistItemStatus::Missing)
        ->where('is_required', true)
        ->orderBy('id')
        ->firstOrFail();

    // 1. Đăng nhập: mật khẩu, rồi mã sáu số trong thư (đường thật của SendLoginCode).
    $codes = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$codes): void {
        if ($event->notification instanceof SendLoginCode) {
            $codes[] = $event->notification->code();
        }
    });

    Filament::setCurrentPanel('portal');
    $login = $this->livewire(Login::class)
        ->set('data.email', 'khach1@example.com')
        ->set('data.password', 'password')
        ->call('authenticate');

    expect($codes)->toHaveCount(1);

    $login->set('data.multiFactor.email_code.code', $codes[0])->call('authenticate')->assertHasNoErrors();

    expect(auth('client')->id())->toBe($client->id);

    // 2. Xem tiến độ và thấy còn thiếu giấy tờ gì.
    $this->actingAs($client->fresh(), 'client')
        ->get(v1aProgressUrl($matter))
        ->assertOk()
        ->assertSee($item->name);

    // 3. Nộp một ảnh chụp cho đúng mục đó.
    $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB";

    $this->actingAs($client->fresh(), 'client')
        ->livewire(SubmitDocument::class, ['record' => $matter->getKey()])
        ->call('chooseItem', $item->getKey())
        ->set('data.file', UploadedFile::fake()->createWithContent('anh-chup-giay-to.jpg', $jpeg))
        ->call('submit')
        ->assertHasNoErrors();

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and(Document::query()->withoutGlobalScopes()->where('matter_checklist_item_id', $item->id)->count())->toBe(1);

    // 4. Luật sư từ chối kèm lý do, trên tab Hồ sơ giấy tờ.
    $reason = 'Ảnh chụp bị mờ ở góc dưới nên không đọc được số giấy tờ. Nhờ anh chụp lại dưới ánh sáng tự nhiên.';
    $mailBefore = count(v1aSentMail());
    $ledgerBefore = v1aMailRows('client.document_rejected');

    Filament::setCurrentPanel('admin');
    $this->actingAs($lawyer, 'web');
    $this->livewire(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('reject')->table($item->refresh()), data: ['rejection_reason' => $reason])
        ->assertHasNoActionErrors();

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::Rejected);

    // 5. Khách nhận thư `client.document_rejected` và thông báo đẩy trên điện thoại.
    $newMail = array_slice(v1aSentMail(), $mailBefore);

    expect(v1aRecipients($newMail))->toBe(['khach1@example.com'])
        ->and($newMail[0]->toString())->toContain($matter->code)
        ->and(v1aMailRows('client.document_rejected'))->toBe($ledgerBefore + 1);

    expect($this->pushServer->endpoints())->toBe([$phone])
        ->and(v1aPushRows($client, PushTopic::ClientDocumentRejected))->toBe(1);

    // 6. Trên cổng: nguyên văn lý do, và mục đó lại nằm trong việc khách cần làm.
    Filament::setCurrentPanel('portal');
    $this->actingAs($client->fresh(), 'client')
        ->get(v1aProgressUrl($matter))
        ->assertOk()
        ->assertSee($reason)
        ->assertSee($item->name);
});
