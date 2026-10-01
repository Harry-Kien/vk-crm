<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Actions\Matter\CancelMatter;
use App\Actions\Notification\NotifyClientOfChecklistItemRejected;
use App\Enums\ChecklistItemStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\ChecklistItemRejected;
use App\Listeners\SendChecklistItemRejectedNotification;
use App\Mail\Client\DocumentRejected;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Đúng 20 ký tự tiếng Việt có dấu = 40 byte, ngưỡng tối thiểu SPEC §6.7. */
function rejectionReasonText(string $marker = ''): string
{
    return 'Ảnh chụp bị mờ, không đọc rõ số. Vui lòng chụp lại rõ nét hơn. '.$marker;
}

/** @return array{0: Matter, 1: User, 2: ClientUser, 3: MatterChecklistItem} */
function rejectableItemWithClientAccount(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)
        ->status(ChecklistItemStatus::PendingReview)
        ->create();

    return [$matter, $lawyer, $account, $item];
}

function rejectAsLawyer(MatterChecklistItem $item, User $lawyer, string $reason): MatterChecklistItem
{
    return app(ReviewChecklistItem::class)->handle(
        checklistItem: $item->fresh(),
        actor: $lawyer,
        decision: ChecklistItemStatus::Rejected,
        rejectionReason: $reason,
    );
}

it('emails the client when a lawyer rejects a submitted document', function () {
    Mail::fake();
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertSent(DocumentRejected::class, 1);
    Mail::assertSent(DocumentRejected::class, fn ($mail) => $mail->hasTo($account->email));
});

it('never tells a never-activated account (R12)', function () {
    Mail::fake();
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $account->update(['is_active' => false]);

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertNothingSent();
});

it('never tells an account whose client has been soft deleted', function () {
    Mail::fake();
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $account->client->delete();

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertNothingSent();
});

/**
 * Fix round 1 (finding Important 3, R6 ranh giới người nhận) — cùng lý lẽ
 * `DocumentPublishedNotificationTest`: một tài khoản đã kích hoạt của một khách hàng KHÁC không
 * bao giờ vào cùng lô người nhận.
 *
 * Mutation probe: xoá `->where('client_id', $clientId)` khỏi
 * `ResolveClientRecipients::eligibleQuery()` — test này ĐỎ.
 */
it('never tells an activated account belonging to a different client', function () {
    Mail::fake();
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $strangerClient = Client::factory()->create();
    $strangerAccount = ClientUser::factory()->activated()->create(['client_id' => $strangerClient->id, 'is_active' => true]);

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertSent(DocumentRejected::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(DocumentRejected::class, fn ($mail) => $mail->hasTo($strangerAccount->email));
});

/**
 * R6, ranh giới nội dung — cùng lối `DocumentPublishedNotificationTest`: một cột nội bộ của vụ
 * việc không bao giờ vào thư, soi ở cả ba nơi và bằng chuỗi đánh dấu, không bằng mắt.
 */
it('never carries the matters internal note into the client mailbox', function () {
    [$matter, , $account, $item] = rejectableItemWithClientAccount();
    $marker = 'DAU-HIEU-NOI-BO-'.uniqid();
    $matter->update(['description_internal' => $marker]);
    $item->update(['name' => 'Chung minh nhan dan', 'rejection_reason' => rejectionReasonText('MARKER-XYZ')]);

    $mail = new DocumentRejected($item->fresh(), $account);
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($subject)->not->toContain($marker)
        ->and($html)->not->toContain($marker)
        ->and($text)->not->toContain($marker)
        // Cặp dương: nội dung ĐÃ CÔNG BỐ (tên đầu mục + lý do) vẫn phải có mặt.
        ->and($html)->toContain('Chung minh nhan dan')
        ->and($html)->toContain('MARKER-XYZ')
        ->and($text)->toContain('Chung minh nhan dan')
        ->and($text)->toContain('MARKER-XYZ');
});

/**
 * Fix round 1 (finding Critical 1) — cùng lý lẽ `DocumentPublishedNotificationTest`, đi qua ĐÚNG
 * đường sản phẩm (`ReviewChecklistItem` thật).
 *
 * Mutation probe: xoá `->where('is_published_to_portal', true)` khỏi
 * `NotifyClientOfChecklistItemRejected::notifiableMatter()` (fix round 2 dời điều kiện từ `handle()`
 * về đó) — test này ĐỎ.
 */
it('sends nothing when rejecting an item on a matter with the portal switch off', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => false,
    ]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    expect($account->can('view', $matter->fresh()))->toBeFalse();

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertNothingSent();
});

/**
 * R6, ranh giới nội dung: tiêu đề KHÔNG bao giờ nêu tên đầu mục hay lý do từ chối.
 *
 * Mutation probe: nội suy `$this->checklistItem->name` vào `envelope()->subject` — test ĐỎ.
 */
it('never puts the item name or reason in the subject, only the matter code', function () {
    [$matter, , $account, $item] = rejectableItemWithClientAccount();
    $item->update(['name' => 'TEN-DAU-MUC-BI-MAT']);

    $subject = (new DocumentRejected($item->fresh(), $account))->envelope()->subject;

    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain('TEN-DAU-MUC-BI-MAT');
});

/** Cặp dương: tên đầu mục và lý do CÓ mặt trong thân thư. */
it('puts the item name and rejection reason in the body', function () {
    [, , $account, $item] = rejectableItemWithClientAccount();
    $item->update(['name' => 'Chung minh nhan dan', 'rejection_reason' => rejectionReasonText('MARKER-XYZ')]);

    $html = (new DocumentRejected($item->fresh(), $account))->render();

    expect($html)->toContain('Chung minh nhan dan')
        ->and($html)->toContain('MARKER-XYZ');
});

it('wires the ChecklistItemRejected event to the listener', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(ChecklistItemRejected::class, $listeners))->toBeTrue();
});

it('is a queued listener with a real retry budget', function () {
    $listener = app(SendChecklistItemRejectedNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

/**
 * Mutation probe: bỏ điều kiện `$fresh->status !== ChecklistItemStatus::Rejected` (tức bỏ qua
 * kiểm tra lại) khỏi `NotifyClientOfChecklistItemRejected::stillRejected()` — test ĐỎ.
 */
it('sends nothing when the client already resubmitted before the job ran', function () {
    Mail::fake();
    [, $lawyer, , $item] = rejectableItemWithClientAccount();
    $rejected = rejectAsLawyer($item, $lawyer, rejectionReasonText());

    // Khách nộp lại: trạng thái quay về pending_review giữa lúc sự kiện bắn và lúc job chạy.
    $rejected->update(['status' => ChecklistItemStatus::PendingReview]);

    Mail::fake();
    $sent = app(NotifyClientOfChecklistItemRejected::class)->handle($rejected->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Vụ việc bị HUỶ (xoá mềm) giữa lúc sự kiện bắn và lúc job chạy. Từ khi `notifiableMatter()` bỏ
 * `->open()` (rà soát cuối làn, I1), vế "chưa xoá mềm" chỉ còn do `SoftDeletingScope` mặc định canh.
 *
 * Mutation probe: thêm `->withTrashed()` vào `NotifyClientOfChecklistItemRejected::notifiableMatter()`
 * — test này ĐỎ (`handle()` trả 1 thay vì 0).
 */
it('sends nothing when the matter was cancelled before the job ran', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    [$matter, $lawyer, , $item] = rejectableItemWithClientAccount();
    $rejected = rejectAsLawyer($item, $lawyer, rejectionReasonText());

    app(CancelMatter::class)->handle($matter, $admin, 'Huỷ ngay trước khi job kịp chạy.');

    Mail::fake();
    $sent = app(NotifyClientOfChecklistItemRejected::class)->handle($rejected->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Rà soát cuối làn (I1): vụ ĐÃ ĐÓNG (`closed_at` khác null, KHÔNG xoá mềm) mà văn phòng còn để
 * trên cổng. Khách vẫn thấy vụ, vẫn nộp được (`DocumentPolicy::create` không hỏi `closed_at`), và
 * trang nộp đã hứa với họ một email nếu có gì chưa ổn (`portal_submit.done.body`) — nên thư từ chối
 * phải đi, cùng quyết định với `client.request_answered` và hai thư nội bộ của b0f98aa. Đường đi
 * qua màn hình được đo ở `SubmitDocumentTest` và `ChecklistRelationManagerTest`; ở đây đo đúng
 * câu hỏi của listener.
 *
 * Mutation probe: thêm lại `->open()` vào `NotifyClientOfChecklistItemRejected::notifiableMatter()`
 * — test này và test kế tiếp ĐỎ.
 */
it('emails the client when rejecting on a CLOSED matter the office left on the portal', function () {
    Mail::fake();
    [$matter, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $matter->update(['closed_at' => now()->subDay(), 'is_published_to_portal' => true]);

    expect($account->can('view', $matter->fresh()))->toBeTrue();

    rejectAsLawyer($item, $lawyer, rejectionReasonText());

    Mail::assertSent(DocumentRejected::class, 1);
    Mail::assertSent(DocumentRejected::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * Cùng quyết định, ở cửa sổ hàng đợi: vụ bị ĐÓNG giữa lúc sự kiện bắn và lúc job chạy. Sự kiện bị
 * `Event::fake()` chặn để listener không chạy lúc từ chối (nếu không, lần gọi tay dưới đây bị
 * `alreadyDelivered()` đếm là "đã gửi" vì một lý do KHÁC) — cùng khuôn
 * `NewClientDocumentNotificationTest`.
 */
it('still emails the client when the matter was closed between the event and the queued job', function () {
    Event::fake([ChecklistItemRejected::class]);
    [$matter, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $rejected = rejectAsLawyer($item, $lawyer, rejectionReasonText());
    $matter->update(['closed_at' => now()]);

    Mail::fake();
    $sent = app(NotifyClientOfChecklistItemRejected::class)->handle($rejected->fresh());

    expect($sent)->toBe(1);
    Mail::assertSent(DocumentRejected::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * Chống gửi trùng qua nhật ký thư — KHÔNG `Mail::fake()` (cùng lý do
 * `DocumentPublishedNotificationTest`).
 *
 * Mutation probe: bỏ khối `if ($this->alreadyDelivered(...))` — test ĐỎ (đếm ra 2).
 */
it('never sends the same rejection notification twice to the same recipient', function () {
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $rejected = rejectAsLawyer($item, $lawyer, rejectionReasonText());

    app(NotifyClientOfChecklistItemRejected::class)->handle($rejected->fresh());
    app(NotifyClientOfChecklistItemRejected::class)->handle($rejected->fresh());

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(1);
});

/**
 * "Hai lần từ chối khác nhau của CÙNG một đầu mục là hai thư" — ruling Task 3. Khách nộp lại
 * (status → pending_review), rồi bị từ chối LẦN NỮA (reviewed_at mới): đây phải là thư THỨ HAI,
 * không bị chặn bởi nhật ký của lần từ chối đầu.
 *
 * Mutation probe (paste vào báo cáo): bỏ `OutboundHeaders::LEDGER_TIER` khỏi
 * `DocumentRejected::additionalLedgerHeaders()` (tức khoá chống trùng chỉ còn related+recipient)
 * — test này ĐỎ (đếm ra 1 thay vì 2).
 */
it('sends a second, separate mail for a second rejection of the same item', function () {
    [, $lawyer, $account, $item] = rejectableItemWithClientAccount();
    $firstRejection = rejectAsLawyer($item, $lawyer, rejectionReasonText('lần 1'));

    app(NotifyClientOfChecklistItemRejected::class)->handle($firstRejection->fresh());

    // Khách nộp lại, rồi bị từ chối LẦN THỨ HAI, một ngày sau — `reviewed_at` (cột `timestamp`,
    // độ chính xác GIÂY) mới, status quay lại rejected. `travel()` thay vì tin đồng hồ thật chạy
    // (CLAUDE.md: "Không test nào được phụ thuộc vào giờ chạy thật"), và một ngày là khoảng cách
    // THẬT giữa hai lần nộp lại của khách, không phải một mẹo kỹ thuật để né trùng giây.
    $this->travel(1)->day();
    $firstRejection->fresh()->update(['status' => ChecklistItemStatus::PendingReview]);
    $secondRejection = rejectAsLawyer($firstRejection->fresh(), $lawyer, rejectionReasonText('lần 2'));

    app(NotifyClientOfChecklistItemRejected::class)->handle($secondRejection->fresh());

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('related_type', $secondRejection->getMorphClass())
        ->where('related_id', $secondRejection->getKey())
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(2);
});

it('tells the lead lawyer in-app when the rejection mail fails for good', function () {
    [, $lawyer, , $item] = rejectableItemWithClientAccount();
    $rejected = rejectAsLawyer($item, $lawyer, rejectionReasonText());

    app(SendChecklistItemRejectedNotification::class)->failed(new ChecklistItemRejected($rejected), new RuntimeException('SMTP'));

    $notice = $lawyer->fresh()->notifications()->latest()->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('matters.document_rejected_failed_notification.title'));
});
