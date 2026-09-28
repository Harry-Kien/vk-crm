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
