<?php

use App\Actions\Schedule\RemindMissingDocuments;
use App\Enums\ChecklistItemStatus;
use App\Enums\Confidentiality;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendMissingDocumentsMail;
use App\Mail\Client\MissingDocuments;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\MissingDocumentsStuckAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * SPEC §6.9 — nhắc khách nộp giấy tờ còn thiếu. Thứ Hai/Tư/Sáu 08:00: mỗi hồ sơ đang mở, đã công
 * bố portal, còn đầu mục BẮT BUỘC `missing`/`rejected` được một thư `client.missing_documents` gửi
 * các tài khoản khách đủ điều kiện (R12), không quá một thư mỗi 3 ngày (R3 của kế hoạch, tra
 * `outbound_messages`); thiếu quá 14 ngày thì luật sư phụ trách được báo trong hệ thống, một lần
 * mỗi đợt thiếu.
 *
 * Định nghĩa "còn thiếu" và đồng hồ "thiếu từ" KHÔNG được đo lại ở đây — chúng là của
 * `ChecklistProgress` (`ChecklistProgressTest`) và widget (`MattersMissingDocumentsWidgetTest`);
 * các test "đã đóng"/"chưa công bố"/"pending_review" bên dưới ghim rằng Action thật sự đi qua chúng.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Hồ sơ thiếu giấy tờ, kèm khách có MỘT tài khoản đã kích hoạt.
 *
 * @return array{0: Matter, 1: ClientUser, 2: User, 3: MatterChecklistItem}
 */
function awaitingMatter(int $missingDaysAgo = 5, array $matterOverrides = []): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(array_merge([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
    ], $matterOverrides));

    return [$matter, $account, $lawyer, missingDocItem($matter, $missingDaysAgo)];
}

function missingDocItem(Matter $matter, int $daysAgo, ChecklistItemStatus $status = ChecklistItemStatus::Missing, bool $required = true): MatterChecklistItem
{
    $item = MatterChecklistItem::factory()->for($matter)->status($status)->create([
        'is_required' => $required,
        'rejection_reason' => $status === ChecklistItemStatus::Rejected ? 'Ảnh chụp bị mờ, vui lòng chụp lại rõ nét hơn.' : null,
        'reviewed_at' => $status === ChecklistItemStatus::Rejected ? now()->subDays($daysAgo) : null,
    ]);
    $item->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

    return $item->refresh();
}

function sentMissingDocsRow(Matter $matter, string $recipient, Carbon $sentAt, OutboundStatus $status = OutboundStatus::Sent, string $template = 'client.missing_documents'): OutboundMessage
{
    return OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $recipient,
        'template' => $template,
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => $status,
        'sent_at' => $sentAt,
    ]);
}

// ---------------------------------------------------------------------------------------------
// Tập hồ sơ (§6.9) và người nhận (R12, R6)
// ---------------------------------------------------------------------------------------------

it('mails the client account of an open, published matter that still lacks a required item', function () {
    Mail::fake();
    [$matter, $account, , $item] = awaitingMatter();

    $result = (new RemindMissingDocuments)->handle();

    Mail::assertSent(MissingDocuments::class, 1);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email)
        && $mail->matter->is($matter)
        && $mail->items->pluck('id')->all() === [$item->id]);
    expect($result['mailed'])->toBe(1);
});

/**
 * Sáu hình dạng "không thuộc tập §6.9": vụ đã đóng, chưa công bố portal, xoá mềm, không còn đầu
 * mục bắt buộc nào thiếu (đã duyệt / đang chờ duyệt / chỉ còn đầu mục không bắt buộc).
 *
 * Mutation probe: xoá `->open()` / `->where('matters.is_published_to_portal', true)` khỏi
 * `ChecklistProgress::mattersAwaitingClient()` — hàng tương ứng ĐỎ (thư vẫn đi).
 */
it('mails nothing for a matter outside the section 6.9 set', function (Closure $arrange) {
    Mail::fake();
    [$matter] = awaitingMatter();
    $arrange($matter);

    $result = (new RemindMissingDocuments)->handle();

    Mail::assertNothingSent();
    expect($result)->toBe(['notified' => 0, 'mailed' => 0]);
})->with([
    'closed' => [fn (Matter $m) => $m->update(['closed_at' => now()->subDay()])],
    'unpublished' => [fn (Matter $m) => $m->update(['is_published_to_portal' => false])],
    'soft-deleted' => [fn (Matter $m) => $m->delete()],
    'everything accepted' => [fn (Matter $m) => $m->checklistItems()->update(['status' => ChecklistItemStatus::Accepted])],
    'only waiting for the office to review' => [fn (Matter $m) => $m->checklistItems()->update(['status' => ChecklistItemStatus::PendingReview])],
    'only optional items missing' => [fn (Matter $m) => $m->checklistItems()->update(['is_required' => false])],
]);

/** R12 — ba điều kiện của tài khoản nhận, mỗi hàng gỡ MỘT điều kiện. */
it('never mails an account that R12 excludes', function (Closure $arrange) {
    Mail::fake();
    [, $account] = awaitingMatter();
    $arrange($account);

    $result = (new RemindMissingDocuments)->handle();

    Mail::assertNothingSent();
    expect($result['mailed'])->toBe(0);
})->with([
    'never activated' => [fn (ClientUser $a) => $a->update(['activated_at' => null])],
    'deactivated' => [fn (ClientUser $a) => $a->update(['is_active' => false])],
    'client soft-deleted' => [fn (ClientUser $a) => $a->client->delete()],
]);

/**
 * R6, ranh giới người nhận: thư về hồ sơ X chỉ tới tài khoản của khách hàng SỞ HỮU X.
 *
 * Mutation probe: xoá `->where('client_id', $clientId)` khỏi `ResolveClientRecipients::eligibleQuery()`
 * — test này ĐỎ (tài khoản người lạ nhận thư).
 */
it('never mails an activated account that belongs to a different client', function () {
    Mail::fake();
    [, $account] = awaitingMatter();
    $stranger = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    (new RemindMissingDocuments)->handle();

    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($stranger->email));
});

it('sends one mail to each eligible account of the client, each naming the same matter', function () {
    Mail::fake();
    [$matter, $first] = awaitingMatter();
    $second = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    $result = (new RemindMissingDocuments)->handle();

    Mail::assertSent(MissingDocuments::class, 2);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($first->email));
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($second->email));
    expect($result['mailed'])->toBe(1);
});

it('does not queue a mail when the client has no account that can receive one', function () {
    Queue::fake([SendMissingDocumentsMail::class]);
    [, $account] = awaitingMatter();
    $account->update(['activated_at' => null]);

    $result = (new RemindMissingDocuments)->handle();

    Queue::assertNothingPushed();
    expect($result['mailed'])->toBe(0);
});

/**
 * Việc sau gộp M7 (làn fu2): Action hỏi người nhận đúng như job (`ResolveClientRecipients::
 * onPortal()` sau R12), nên không xếp một job mà job sẽ bỏ, và `mailed` không đếm một thư không đi.
 * Trạng thái: vụ đang mở mà dòng lưu trữ còn `client_access_until` đã qua (lần mở lại chưa dọn),
 * khách còn một vụ khác trên cổng. Vế dương: ngày tra cứu cuối thì vẫn xếp.
 *
 * Mutation probe: bỏ `onPortal()` khỏi `RemindMissingDocuments::processOne()` — hàng "đã hết hạn" ĐỎ.
 */
it('queues no mail for a matter the portal no longer shows the client, and still queues on its last day', function (string $accessUntil, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
    Queue::fake([SendMissingDocumentsMail::class]);
    [$matter, $account] = awaitingMatter();
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $accessUntil]);
    Matter::factory()->create(['client_id' => $account->client_id, 'is_published_to_portal' => true]);

    $result = (new RemindMissingDocuments)->handle();

    Queue::assertPushed(SendMissingDocumentsMail::class, $expected);
    expect($result['mailed'])->toBe($expected);
})->with([
    'hạn tra cứu đã qua từ hôm qua' => ['2026-10-20', 0],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', 1],
]);

// ---------------------------------------------------------------------------------------------
// R3 của kế hoạch — không quá một thư mỗi 3 ngày cho cùng một hồ sơ (tra outbound_messages)
// ---------------------------------------------------------------------------------------------

/**
 * Mutation probe: xoá kiểm tra `alreadyDelivered()` khỏi `RemindMissingDocuments::processOne()` —
 * test này ĐỎ (thư gửi lại dù mới 1 ngày trước).
 */
it('does not mail again within 3 days of the last successful send', function () {
    Mail::fake();
    [$matter, $account] = awaitingMatter();
    sentMissingDocsRow($matter, $account->email, now()->subDay());

    $result = (new RemindMissingDocuments)->handle();

    Mail::assertNothingSent();
    expect($result['mailed'])->toBe(0);
});

/**
 * Cửa sổ theo NGÀY LỊCH (cùng bài học R5 của Task 7: `sent_at` được đóng dấu khi worker gửi, muộn
 * hơn lượt 08:00 đã xếp job vài chục giây). Thư ngày D chặn D..D+2 và KHÔNG chặn D+3.
 *
 * Mutation probe: đổi `RemindMissingDocuments::mailWindowStart()` về `now()->subDays(3)` — hàng
 * "3 ngày trước, đóng dấu sau lượt 08:00" ĐỎ; thu còn `today()->subDays(1)` — hàng "2 ngày
 * trước" ĐỎ (gửi lại sớm một ngày).
 */
it('holds back for calendar days D to D+2 and mails again on D+3', function (string $now, string $lastSentAt, bool $mails) {
    Mail::fake();
    $this->travelTo(Carbon::parse($now));
    [$matter, $account] = awaitingMatter();
    sentMissingDocsRow($matter, $account->email, Carbon::parse($lastSentAt));

    $result = (new RemindMissingDocuments)->handle();

    expect($result['mailed'])->toBe($mails ? 1 : 0);
})->with([
    '3 days ago, stamped seconds after that morning run' => ['2026-10-14 08:00:02', '2026-10-11 08:00:40', true],
    '3 days ago plus one minute' => ['2026-10-14 09:00:00', '2026-10-11 09:01:00', true],
    '2 days ago, early morning of that calendar day' => ['2026-10-14 08:00:02', '2026-10-12 00:00:05', false],
    '1 day ago' => ['2026-10-14 08:00:02', '2026-10-13 08:00:40', false],
]);

/**
 * R3 của kế hoạch: một thư THẤT BẠI vẫn là một dòng nhưng không phải "đã nhắc". `sent_at` gán
 * tường minh để phép thử đo ĐÚNG điều kiện `status = sent` (xem cùng test ở CheckStaleMattersTest).
 *
 * Mutation probe: xoá `->where('status', OutboundStatus::Sent)` khỏi `alreadyDelivered()` — ĐỎ.
 */
it('does not let a failed send count as already reminded', function () {
    Mail::fake();
    [$matter, $account] = awaitingMatter();
    sentMissingDocsRow($matter, $account->email, now()->subHours(2), OutboundStatus::Failed);

    $result = (new RemindMissingDocuments)->handle();

    expect($result['mailed'])->toBe(1);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * Mutation probe: xoá `->where('template', 'client.missing_documents')` khỏi `alreadyDelivered()` —
 * ĐỎ (một thư báo tiến độ hôm qua nuốt mất lời nhắc thiếu giấy tờ).
 */
it('is not silenced by a sent mail of another template about the same matter', function () {
    Mail::fake();
    [$matter, $account] = awaitingMatter();
    sentMissingDocsRow($matter, $account->email, now()->subHour(), OutboundStatus::Sent, 'client.stage_update');

    $result = (new RemindMissingDocuments)->handle();

    expect($result['mailed'])->toBe(1);
});

/** Ranh giới `related`: thư nhắc hồ sơ KHÁC cho cùng người không chặn hồ sơ này. */
it('is not silenced by a reminder about a different matter to the same account', function () {
    Mail::fake();
    [$matter, $account] = awaitingMatter();
    [$other] = awaitingMatter();
    sentMissingDocsRow($other, $account->email, now()->subHour());

    (new RemindMissingDocuments)->handle();

    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email) && $mail->matter->is($matter));
});

/**
 * Thư đã gửi cho người thứ nhất, người thứ hai thì hỏng: lượt sau vẫn phải xếp job, và job chỉ gửi
 * cho người thứ hai — chống trùng theo TỪNG người nhận, không theo hồ sơ.
 *
 * Mutation probe: đổi `->contains(fn => ! alreadyDelivered)` thành `->every(...)` (chặn theo hồ
 * sơ thay vì theo từng người) — ĐỎ.
 */
it('still queues the job when only some of the accounts already got this cycle mail', function () {
    Mail::fake();
    [$matter, $first] = awaitingMatter();
    $second = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);
    sentMissingDocsRow($matter, $first->email, now()->subHour());

    $result = (new RemindMissingDocuments)->handle();

    expect($result['mailed'])->toBe(1);
    Mail::assertSent(MissingDocuments::class, 1);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($second->email));
});

// ---------------------------------------------------------------------------------------------
// R4 — chạy Action hai lần liên tiếp không sinh thêm thư/thông báo (sổ thư ghi THẬT, không fake)
// ---------------------------------------------------------------------------------------------

it('running the action twice in a row sends exactly one mail and one notice, not two', function () {
    [$matter, $account, $lawyer] = awaitingMatter(missingDaysAgo: 20);

    $first = (new RemindMissingDocuments)->handle();
    $second = (new RemindMissingDocuments)->handle();

    expect($first)->toBe(['notified' => 1, 'mailed' => 1])
        ->and($second)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);

    $sent = OutboundMessage::query()->withoutGlobalScopes()
        ->where('related_type', $matter->getMorphClass())
        ->where('related_id', $matter->getKey())
        ->where('template', 'client.missing_documents')
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sent)->toBe(1);
});

/**
 * Hai lần chạy TRƯỚC khi ai rút hàng đợi: sổ thư còn trống nên lần hai vẫn xếp một job nữa. Lớp
 * chống trùng thật là job — nó tra `outbound_messages` ngay trước MỖI thư.
 *
 * Mutation probe: xoá `if (RemindMissingDocuments::alreadyDelivered(...)) { continue; }` khỏi
 * `SendMissingDocumentsMail::handle()` — ĐỎ (2 thư mỗi người).
 */
it('sends each account one mail even when the action ran twice before the queue was drained', function () {
    Queue::fake([SendMissingDocumentsMail::class]);
    [$matter, $first] = awaitingMatter();
    $second = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    (new RemindMissingDocuments)->handle();
    (new RemindMissingDocuments)->handle();

    $jobs = Queue::pushed(SendMissingDocumentsMail::class);
    expect($jobs)->toHaveCount(2);

    foreach ($jobs as $job) {
        $job->handle();
    }

    foreach ([$first, $second] as $account) {
        $count = OutboundMessage::query()->withoutGlobalScopes()
            ->where('related_type', $matter->getMorphClass())
            ->where('related_id', $matter->getKey())
            ->where('template', 'client.missing_documents')
            ->where('recipient', $account->email)
            ->where('status', OutboundStatus::Sent)
            ->count();

        expect($count)->toBe(1);
    }
});

// ---------------------------------------------------------------------------------------------
// Danh sách ứng viên dựng TRƯỚC vòng lặp; điều kiện phải được đọc lại sau khi khoá dòng
// ---------------------------------------------------------------------------------------------

/** Chạy `$change` ĐÚNG MỘT lần, ngay sau truy vấn dựng danh sách ứng viên — xem CheckStaleMattersTest. */
function afterMissingDocsCandidateListIsBuilt(Closure $change): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $change) {
        if (! $fired && preg_match('/^select [`"]matters[`"]\.[`"]id[`"] from [`"]matters[`"]/i', $query->sql) === 1) {
            $fired = true;
            $change();
        }
    });
}

/**
 * Mutation probe: xoá khối `if (! ChecklistProgress::mattersAwaitingClient(...)->exists()) { return; }`
 * khỏi `RemindMissingDocuments::processOne()` — cả ba test này ĐỎ.
 */
it('skips a matter that was closed after the candidate list was built', function () {
    Mail::fake();
    [$matter, , $lawyer] = awaitingMatter(missingDaysAgo: 20);

    afterMissingDocsCandidateListIsBuilt(fn () => DB::table('matters')->where('id', $matter->id)->update(['closed_at' => now()]));

    $result = (new RemindMissingDocuments)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Mail::assertNothingSent();
});

it('skips a matter whose last missing item was submitted after the candidate list was built', function () {
    Mail::fake();
    [, , $lawyer, $item] = awaitingMatter(missingDaysAgo: 20);

    afterMissingDocsCandidateListIsBuilt(fn () => DB::table('matter_checklist_items')->where('id', $item->id)->update(['status' => 'pending_review']));

    $result = (new RemindMissingDocuments)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
    Mail::assertNothingSent();
});

it('skips a matter that was unpublished from the portal after the candidate list was built', function () {
    Mail::fake();
    [$matter] = awaitingMatter();

    afterMissingDocsCandidateListIsBuilt(fn () => DB::table('matters')->where('id', $matter->id)->update(['is_published_to_portal' => false]));

    expect((new RemindMissingDocuments)->handle()['mailed'])->toBe(0);
    Mail::assertNothingSent();
});

/** Cặp dương của ba test trên: cùng cơ chế nghe truy vấn, nhưng không đổi gì — hồ sơ vẫn được nhắc. */
it('still handles the matter when nothing changes after the candidate list was built', function () {
    Mail::fake();
    awaitingMatter();

    afterMissingDocsCandidateListIsBuilt(fn () => null);

    expect((new RemindMissingDocuments)->handle()['mailed'])->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// 14 ngày — thông báo trong hệ thống cho luật sư phụ trách (một lần mỗi đợt thiếu)
// ---------------------------------------------------------------------------------------------

it('notifies the lead lawyer in-app when a required item has been missing for more than 14 days', function () {
    Mail::fake();
    [$matter, , $lawyer] = awaitingMatter(missingDaysAgo: 15);

    $result = (new RemindMissingDocuments)->handle();

    $lawyer->refresh();
    expect($lawyer->notifications)->toHaveCount(1)
        ->and($lawyer->notifications->first()->type)->toBe(MissingDocumentsStuckAlert::class)
        ->and($lawyer->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id)
        ->and($result['notified'])->toBe(1);
});

/**
 * Mutation probe: đổi ngưỡng `ChecklistProgress::STUCK_AFTER_DAYS` (14) trong điều kiện thông báo
 * thành 0 — test này ĐỎ (lawyer nhận thông báo ở ngày 13).
 */
it('does not notify the lead lawyer before the item has been missing for 14 days', function () {
    Mail::fake();
    [, , $lawyer] = awaitingMatter(missingDaysAgo: 13);

    $result = (new RemindMissingDocuments)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($result['notified'])->toBe(0);
});

/** Đồng hồ là của ĐẦU MỤC bị từ chối (lúc từ chối), không phải lúc tạo — cùng widget. */
it('clocks a rejected item from its rejection when deciding the 14-day notice', function () {
    Mail::fake();
    [, , $lawyer, $item] = awaitingMatter(missingDaysAgo: 60);
    $item->update(['status' => ChecklistItemStatus::Rejected, 'reviewed_at' => now()->subDay(), 'rejection_reason' => 'Ảnh chụp bị mờ, vui lòng chụp lại.']);

    (new RemindMissingDocuments)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0);
});

/** Một đầu mục thiếu quá 14 ngày là đủ, dù các đầu mục khác của hồ sơ mới thiếu hôm qua. */
it('notifies once any required item of the matter is past 14 days, even if the others are fresh', function () {
    Mail::fake();
    [$matter, , $lawyer] = awaitingMatter(missingDaysAgo: 30);
    missingDocItem($matter, 1);

    (new RemindMissingDocuments)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(1);
});

/**
 * Mutation probe: xoá điều kiện `created_at >= episodeStart` khỏi `alreadyNotified()` — bước
 * "đợt mới" ĐỎ; xoá cả `alreadyNotified()` — bước "cùng đợt" ĐỎ.
 */
it('notifies once per episode: not again while it lasts, again once a new rejection opens a new one', function () {
    Mail::fake();
    $this->travelTo(now()->startOfDay());
    [$matter, , $lawyer, $item] = awaitingMatter(missingDaysAgo: 15);

    (new RemindMissingDocuments)->handle();
    $this->travelTo(now()->addDays(2));
    (new RemindMissingDocuments)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(1);

    // Khách nộp, văn phòng duyệt xong: hồ sơ không còn thiếu gì — đợt cũ kết thúc.
    $item->update(['status' => ChecklistItemStatus::Accepted]);
    (new RemindMissingDocuments)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(1);

    // Một đầu mục mới bị từ chối: đợt MỚI, đồng hồ chạy từ lúc từ chối — chưa đủ 14 ngày.
    missingDocItem($matter, 0, ChecklistItemStatus::Rejected);
    (new RemindMissingDocuments)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(1);

    // 15 ngày sau lần từ chối đó, đợt mới quá hạn — thông báo phải được gửi lại.
    $this->travelTo(now()->addDays(15));
    (new RemindMissingDocuments)->handle();
    expect($lawyer->fresh()->notifications)->toHaveCount(2);
});

/** Thông báo 14 ngày độc lập với thư khách: khách chưa kích hoạt tài khoản thì luật sư VẪN được báo. */
it('notifies the lead lawyer even when no client account can be mailed', function () {
    Mail::fake();
    [, $account, $lawyer] = awaitingMatter(missingDaysAgo: 20);
    $account->update(['activated_at' => null]);

    $result = (new RemindMissingDocuments)->handle();

    expect($result)->toBe(['notified' => 1, 'mailed' => 0])
        ->and($lawyer->fresh()->notifications)->toHaveCount(1);
    Mail::assertNothingSent();
});

/** Ngược lại: mới thiếu 5 ngày thì khách được nhắc nhưng luật sư chưa bị báo. */
it('mails the client at 5 days but does not yet bother the lead lawyer', function () {
    Mail::fake();
    [, , $lawyer] = awaitingMatter(missingDaysAgo: 5);

    $result = (new RemindMissingDocuments)->handle();

    expect($result)->toBe(['notified' => 0, 'mailed' => 1])
        ->and($lawyer->fresh()->notifications)->toHaveCount(0);
});

/** R3 (M6.5): "không bao giờ im lặng" — luật sư phụ trách bị vô hiệu hoá thì chuỗi dự phòng thế chỗ. */
it('falls back down the recipient chain when the lead lawyer is deactivated', function () {
    Mail::fake();
    [$matter, , $lawyer] = awaitingMatter(missingDaysAgo: 20);
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    (new RemindMissingDocuments)->handle();

    expect($lawyer->fresh()->notifications)->toHaveCount(0)
        ->and($manager->fresh()->notifications)->toHaveCount(1)
        ->and($manager->fresh()->notifications->first()->data['viewData']['matter_id'])->toBe($matter->id);
});

/** Review Focus 1: hồ sơ `restricted` — manager không xem được nên không nhận, admin thế chỗ. */
it('sends the 14-day notice of a restricted matter to the admin, never to a manager who cannot view it', function () {
    Mail::fake();
    [$matter, , $lawyer] = awaitingMatter(missingDaysAgo: 20, matterOverrides: ['confidentiality' => Confidentiality::Restricted]);
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    (new RemindMissingDocuments)->handle();

    expect($manager->fresh()->notifications)->toHaveCount(0)
        ->and($admin->fresh()->notifications)->toHaveCount(1)
        ->and($admin->fresh()->notifications->first()->data['body'])->toContain($matter->code);
});

/** Thân thông báo vẽ THÔ trong chuông (sanitizeHtml giữ `<a href>`): tiêu đề do nhân sự gõ phải được thoát. */
it('escapes a staff-typed matter title in the 14-day notice body', function () {
    Mail::fake();
    [, , $lawyer] = awaitingMatter(missingDaysAgo: 20, matterOverrides: ['title' => 'Tranh chấp <a href="https://x.test">bấm vào</a>']);

    (new RemindMissingDocuments)->handle();

    $body = $lawyer->fresh()->notifications->first()->data['body'];
    expect($body)->not->toContain('<a href')
        ->and($body)->toContain('&lt;a href');
});
