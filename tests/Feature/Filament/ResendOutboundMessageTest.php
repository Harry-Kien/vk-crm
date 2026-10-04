<?php

use App\Actions\Notification\ResendOutboundMessage;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ViewOutboundMessage;
use App\Jobs\ResendOutboundMessageJob;
use App\Mail\Client\DocumentRejected;
use App\Mail\Staff\NewClientDocument as NewClientDocumentMail;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\Staff\OutboundResendFailedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * M6 Task 10 — nút "Gửi lại" trên một dòng nhật ký thư `failed` (sửa đổi Task 21 của kế hoạch M6;
 * M6.5 Task 13 để lại). Mọi hành vi MÀN HÌNH đi qua Livewire (bảng `ListOutboundMessages` và trang
 * `ViewOutboundMessage`), không gọi thẳng Action — lời gọi thẳng (ép) nằm ở
 * `tests/Feature/Actions/Notification/ResendOutboundMessageTest.php`.
 *
 * Thư gửi qua mailer `array` THẬT (không `Mail::fake()`) ở các test cần đọc nhật ký: `MailFake` thay
 * cả trình gửi thư nên `OutboundLedgerTransport` không bao giờ ghi dòng mới — đúng thứ các test này
 * cần đo ("thư gửi lại là một dòng MỚI, dòng cũ không đổi").
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Transport LUÔN hỏng, đi đúng đường cấu hình thật (cùng lối `StageUpdateNotificationTest`). */
class ResendFailingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP giả lập vẫn chết (Task 10)');
    }

    public function __toString(): string
    {
        return 'resend-failing://';
    }
}

function resendFailingMailer(): string
{
    config()->set('mail.mailers.resend_failing', ['transport' => 'resend_failing']);
    Mail::extend('resend_failing', fn (): TransportInterface => new ResendFailingTransport);

    return 'resend_failing';
}

/**
 * Một vụ việc đã công bố cổng, luật sư phụ trách còn làm việc, khách có MỘT tài khoản R12 (còn
 * hoạt động, đã kích hoạt).
 *
 * @return array{0: Matter, 1: User, 2: ClientUser}
 */
function resendMatter(array $matterState = []): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        ...$matterState,
    ]);
    $matter->team()->syncWithoutDetaching([$lawyer->id]);

    return [$matter, $lawyer, $account];
}

/** Một dòng nhật ký `failed` cho `$related`, đúng hình dạng `RecordOutboundMessage` ghi. */
function failedRowFor(string $template, Model $related, string $recipient, array $payload = []): OutboundMessage
{
    return OutboundMessage::factory()->create([
        'template' => $template,
        'related_type' => $related->getMorphClass(),
        'related_id' => $related->getKey(),
        'recipient' => $recipient,
        'status' => OutboundStatus::Failed,
        'error' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: SMTP chết lúc đó',
        'payload' => ['subject' => 'Tiêu đề cũ', ...$payload],
    ]);
}

/**
 * Mỗi mẫu gửi lại được, một bản ghi liên quan ĐỦ mọi cổng lúc-gửi của Action/Job gốc, và dòng
 * `failed` về nó. Trả `[dòng hỏng, địa chỉ phải nhận lại, vụ việc]`.
 *
 * @return array{0: OutboundMessage, 1: string, 2: Matter}
 */
function resendFixture(string $template): array
{
    $stale = $template === 'staff.stale_matter' ? ['last_client_update_at' => now()->subDays(22)] : [];
    [$matter, $lawyer, $account] = resendMatter($stale);

    [$related, $to, $payload] = match ($template) {
        'client.stage_update' => [
            StageLog::factory()->create(['matter_id' => $matter->id]),
            $account->email,
            [],
        ],
        'client.document_published' => [
            Document::factory()->create([
                'matter_id' => $matter->id,
                'group' => DocumentGroup::Issued,
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => true,
                'published_at' => now(),
            ]),
            $account->email,
            [],
        ],
        'client.document_rejected' => (function () use ($matter, $lawyer, $account): array {
            $item = MatterChecklistItem::factory()->for($matter)->create([
                'status' => ChecklistItemStatus::Rejected,
                'rejection_reason' => 'Ảnh bị mờ, nhờ anh/chị chụp lại.',
                'reviewed_by' => $lawyer->id,
                'reviewed_at' => now()->subHour(),
            ]);

            return [$item, $account->email, ['tier' => DocumentRejected::ledgerKeyFor($item)]];
        })(),
        'client.request_answered' => (function () use ($matter, $lawyer, $account): array {
            $thread = ClientRequest::factory()->create([
                'matter_id' => $matter->id,
                'client_user_id' => $account->id,
                'status' => ClientRequestStatus::Answered,
            ]);

            return [
                ClientRequestReply::factory()->create([
                    'request_id' => $thread->id,
                    'author_type' => $lawyer->getMorphClass(),
                    'author_id' => $lawyer->id,
                ]),
                $account->email,
                [],
            ];
        })(),
        'client.missing_documents' => (function () use ($matter, $account): array {
            MatterChecklistItem::factory()->for($matter)->create([
                'name' => 'Chứng minh nhân dân',
                'is_required' => true,
                'status' => ChecklistItemStatus::Missing,
            ]);

            return [$matter, $account->email, []];
        })(),
        'staff.new_client_request' => [
            ClientRequest::factory()->create(['matter_id' => $matter->id, 'client_user_id' => $account->id]),
            $lawyer->email,
            [],
        ],
        'staff.new_client_document' => [
            Document::factory()->pendingReview()->uploadedBy($account)->create(['matter_id' => $matter->id]),
            $lawyer->email,
            [],
        ],
        'staff.stale_matter' => [$matter, $lawyer->email, []],
    };

    return [failedRowFor($template, $related, $to, $payload), $to, $matter];
}

/**
 * Ngữ cảnh của một lời gọi Livewire GIẢ MẠO vào nút của một hàng bảng — đúng thứ trình duyệt gửi
 * lên (`mountAction('resend', [], {table, recordKey})`), không qua `callAction()` của bộ test (bản
 * đó tự khẳng định nút đang hiện). Livewire cho gọi tên action bất kỳ, nên nút bị ẩn vẫn phải
 * không làm gì khi bị gọi thẳng.
 *
 * @return array{table: bool, recordKey: string}
 */
function forcedTableContext(OutboundMessage $record): array
{
    return ['table' => true, 'recordKey' => (string) $record->getKey()];
}

function resendAdmin(): User
{
    return User::factory()->withRole(Role::Admin)->create();
}

/** Mọi câu thông báo (toast) của request vừa rồi — thân câu, cả hai khoá phiên của Filament. */
function resendToastBodies(): array
{
    return collect([
        ...session()->get('filament.notifications', []),
        ...session()->get('filament.claimed_notifications', []),
    ])->pluck('body')->all();
}

function resendRowsAfter(OutboundMessage $failed)
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('id', '>', $failed->getKey())
        ->where('template', $failed->template)
        ->where('related_type', $failed->related_type)
        ->where('related_id', $failed->related_id)
        ->get();
}

function resendAudits()
{
    return Activity::query()->where('event', 'outbound_message_resent')->get();
}

/** @return array<int, string> */
function resendableTemplates(): array
{
    return [
        'client.stage_update',
        'client.document_published',
        'client.document_rejected',
        'client.request_answered',
        'client.missing_documents',
        'staff.new_client_request',
        'staff.new_client_document',
        'staff.stale_matter',
    ];
}

// ---------------------------------------------------------------------------------------------
// Đường chính: admin bấm "Gửi lại" trên từng mẫu gửi lại được.
// ---------------------------------------------------------------------------------------------

/**
 * Với MỖI mẫu gửi lại được: nút hiện cho admin, bấm thì thư đi lại qua ĐÚNG đường gửi của mẫu —
 * một dòng `sent` MỚI tới người nhận hiện hợp lệ; dòng hỏng giữ nguyên từng cột (bằng chứng); một
 * dòng audit ghi ai bấm, dòng nào, mẫu nào, số người nhận — không địa chỉ, không nội dung.
 */
it('lets an admin resend a failed row: a new sent row, the failed row untouched, one audit row', function (string $template) {
    [$failed, $to] = resendFixture($template);
    $before = $failed->fresh()->getAttributes();
    $admin = resendAdmin();

    $this->actingAs($admin, 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertActionVisible(TestAction::make('resend')->table($failed))
        ->callAction(TestAction::make('resend')->table($failed));

    Notification::assertNotified(__('outbound.resend.success_title'));

    $new = resendRowsAfter($failed);

    expect($new->where('recipient', $to)->where('status', OutboundStatus::Sent))->toHaveCount(1)
        ->and($new->where('status', OutboundStatus::Failed))->toBeEmpty()
        ->and($failed->fresh()->getAttributes())->toEqual($before);

    $audit = resendAudits()->sole();

    expect($audit->causer_id)->toBe($admin->id)
        ->and($audit->properties['outbound_message_id'])->toBe($failed->id)
        ->and($audit->properties['template'])->toBe($template)
        ->and($audit->properties['recipients'])->toBeGreaterThanOrEqual(1)
        ->and(json_encode($audit->properties->all()))->not->toContain($to)
        ->and(json_encode($audit->properties->all()))->not->toContain('Tiêu đề cũ');
})->with(resendableTemplates());

/** Cùng nút ở trang xem của dòng (header action) — hai nơi dùng chung một nút, không lệch nhau. */
it('offers the same resend button on the view page, and it resends', function () {
    [$failed, $to] = resendFixture('client.stage_update');

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ViewOutboundMessage::class, ['record' => $failed->getKey()])
        ->assertActionVisible('resend')
        ->callAction('resend');

    expect(resendRowsAfter($failed)->where('recipient', $to)->where('status', OutboundStatus::Sent))->toHaveCount(1);
});

// ---------------------------------------------------------------------------------------------
// Ai thấy nút, dòng nào có nút.
// ---------------------------------------------------------------------------------------------

/**
 * Chỉ admin: manager và luật sư phụ trách XEM được dòng này (M6.5 Task 13 mở `viewAny` cho
 * `matter.view`) nhưng không có nút; ép gọi nút cũng không gửi gì, không ghi audit.
 *
 * Mutation probe: bỏ `hasRole(Role::Admin)` khỏi `OutboundMessagePolicy::resend()` → ĐỎ.
 */
it('hides the resend button from a manager and from the lead lawyer who can both see the row', function (string $who) {
    Queue::fake();
    [$failed, , $matter] = resendFixture('client.stage_update');
    $user = $who === 'manager'
        ? User::factory()->withRole(Role::Manager)->create()
        : User::find($matter->lead_lawyer_id);

    $this->actingAs($user, 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertCanSeeTableRecords([$failed])
        ->assertActionHidden(TestAction::make('resend')->table($failed))
        ->call('mountAction', 'resend', [], forcedTableContext($failed))
        ->call('callMountedAction');

    $this->livewire(ViewOutboundMessage::class, ['record' => $failed->getKey()])
        ->assertActionHidden('resend')
        ->call('mountAction', 'resend')
        ->call('callMountedAction');

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty()
        ->and(resendRowsAfter($failed))->toBeEmpty();
})->with(['manager', 'lead lawyer']);

/**
 * Dòng không phải `failed` không có nút: `queued` (chưa ra khỏi máy) và `sent` (đã tới nơi) không
 * phải một lần gửi hỏng. Cặp dương là test đường chính ở trên (cùng mẫu, `failed` → có nút).
 *
 * Mutation probe: bỏ điều kiện `status !== Failed` khỏi `ResendOutboundMessage::canResend()` → ĐỎ.
 */
it('shows no resend button on a queued or a sent row', function (OutboundStatus $status) {
    Queue::fake();
    [$failed] = resendFixture('client.stage_update');
    $failed->forceFill(['status' => $status, 'error' => null])->save();

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertActionHidden(TestAction::make('resend')->table($failed))
        ->call('mountAction', 'resend', [], forcedTableContext($failed))
        ->call('callMountedAction');

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
})->with([
    'queued' => [OutboundStatus::Queued],
    'sent' => [OutboundStatus::Sent],
]);

/**
 * Các mẫu KHÔNG gửi lại được — mỗi mẫu một lý do (`ResendTargets::NOT_RESENDABLE`): OTP (mã
 * chết), nhắc mốc hạn (lượt kiểm tra hạn đầu tiên của ngày hôm sau tự nhắc lại, chuông đã báo),
 * kích hoạt (cấp mật khẩu tạm mới — nút riêng ở màn hình tài khoản cổng), nhắc đợt quá hạn (lượt
 * 08:00 kế tiếp tự gửi lại), báo lỗi sao lưu (nói về một lượt sao lưu đã qua), `undeclared`
 * (không dựng lại được). Hai họ thư của main — `staff.instalment_overdue` (M9) và
 * `staff.backup_alert.*` (M8a) — thêm ở việc sau gộp M6 (làn fu, mục 2).
 *
 * Mutation probe: bỏ nhánh `default => null` (hoặc thêm một mẫu vào `ResendTargets::for()`) → ĐỎ.
 */
it('shows no resend button on a failed row of a template that must not be resent', function (string $template, string $relatedType) {
    Queue::fake();
    [$matter, $lawyer, $account] = resendMatter();
    $related = match ($relatedType) {
        'client_user' => $account,
        'deadline' => Deadline::factory()->create(['matter_id' => $matter->id]),
        'instalment' => Instalment::factory()->for(Contract::factory()->for($matter))->create(),
        'intake_request' => IntakeRequest::factory()->create(),
        'none' => null,
    };
    $failed = OutboundMessage::factory()->create([
        'template' => $template,
        'related_type' => $related?->getMorphClass(),
        'related_id' => $related?->getKey(),
        'recipient' => $account->email,
        'status' => OutboundStatus::Failed,
        'error' => 'TransportException',
    ]);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertActionHidden(TestAction::make('resend')->table($failed))
        ->call('mountAction', 'resend', [], forcedTableContext($failed))
        ->call('callMountedAction');

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
})->with([
    'client.otp' => ['client.otp', 'client_user'],
    'staff.deadline_reminder' => ['staff.deadline_reminder', 'deadline'],
    'client.activation' => ['client.activation', 'client_user'],
    'staff.instalment_overdue' => ['staff.instalment_overdue', 'instalment'],
    'staff.backup_alert.backup_failed' => ['staff.backup_alert.backup_failed', 'none'],
    'staff.backup_alert.cleanup_failed' => ['staff.backup_alert.cleanup_failed', 'none'],
    'staff.backup_alert.unhealthy' => ['staff.backup_alert.unhealthy', 'none'],
    // Gộp `main` vào làn M10 (rà soát cuối, vòng sửa 1): nhắc liên hệ chưa ai gọi lại — lượt nhắc kế tiếp
    // tự gửi lại khi bản ghi còn "Mới".
    'staff.intake_unanswered' => ['staff.intake_unanswered', 'intake_request'],
    'undeclared' => ['undeclared', 'none'],
]);

/**
 * Mẫu gửi lại được nhưng `related_type` không khớp mẫu (dữ liệu hỏng): không dựng lại được, không
 * có nút. Mutation probe: bỏ vế `related_type === $target->relatedType` khỏi `canResend()` → ĐỎ.
 */
it('shows no resend button when the row points at a record of the wrong kind for its template', function () {
    Queue::fake();
    [$matter, , $account] = resendMatter();
    $failed = failedRowFor('client.stage_update', Document::factory()->create(['matter_id' => $matter->id]), $account->email);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)
        ->assertActionHidden(TestAction::make('resend')->table($failed))
        ->call('mountAction', 'resend', [], forcedTableContext($failed))
        ->call('callMountedAction');

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------
// Người nhận suy lại lúc gửi lại, cổng lúc-gửi của mẫu gốc.
// ---------------------------------------------------------------------------------------------

/**
 * R12: tài khoản cổng cũ (người nhận của dòng hỏng) đã bị KHOÁ; khách có một tài khoản MỚI đã
 * kích hoạt. Gửi lại đi tới tài khoản mới, không tới địa chỉ cũ — cột `recipient` cũ không bao giờ
 * được dùng lại.
 */
it('re-derives client recipients at resend time: not the locked old account, but the new activated one', function () {
    [$failed, $oldEmail, $matter] = resendFixture('client.stage_update');
    ClientUser::query()->where('email', $oldEmail)->update(['is_active' => false]);
    $new = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id, 'is_active' => true]);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    $rows = resendRowsAfter($failed);

    expect($rows->pluck('recipient')->all())->toBe([$new->email]);
});

/**
 * R3: luật sư phụ trách (người nhận cũ) đã NGHỈ VIỆC. Gửi lại không tới họ; chuỗi dự phòng của
 * `ResolveStaffRecipients` chọn một người còn làm việc và xem được vụ.
 */
it('re-derives staff recipients at resend time: never the deactivated lead, someone who can view the matter instead', function () {
    [$failed, $oldEmail, $matter] = resendFixture('staff.new_client_request');
    User::query()->where('email', $oldEmail)->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = resendAdmin();

    $this->actingAs($admin, 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    $recipients = resendRowsAfter($failed)->pluck('recipient');

    expect($recipients)->not->toBeEmpty()
        ->and($recipients)->not->toContain($oldEmail)
        ->and($recipients->every(fn (string $email): bool => in_array($email, [$manager->email, $admin->email], true)))->toBeTrue();
});

/**
 * Cổng lúc-gửi của CHÍNH mẫu gốc: vụ việc đã tắt công bố cổng sau lần gửi hỏng → không ai đủ
 * điều kiện → câu từ chối nói rõ, không gửi, không audit, không xếp hàng.
 *
 * Mutation probe: bỏ nhánh `$eligible->isEmpty()` khỏi `ResendOutboundMessage::handle()` → ĐỎ
 * (thông báo thành công "0 người nhận" + một dòng audit).
 */
it('refuses with a clear message when nobody is eligible any more, sending nothing', function () {
    Queue::fake();
    [$failed, , $matter] = resendFixture('client.stage_update');
    $matter->forceFill(['is_published_to_portal' => false])->save();

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    // Đọc thân câu TRƯỚC `assertNotified()` — hàm đó tiêu thụ các thông báo trong phiên.
    expect(resendToastBodies())->toContain(__('outbound.resend.refused.no_eligible_recipient'));
    Notification::assertNotified(__('actions.failed_title'));

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
});

/**
 * Người nhận của dòng hỏng đã nhận được đúng thư này ở một lần gửi SAU (thử lại của hàng đợi): ai
 * đủ điều kiện cũng đã nhận → từ chối "đã nhận", không gửi thêm bản thứ hai.
 *
 * Mutation probe: bỏ nhánh `$pending->isEmpty()` khỏi `handle()` → ĐỎ.
 */
it('refuses when every eligible recipient already received this very mail on a later attempt', function () {
    Queue::fake();
    [$failed, $to] = resendFixture('client.document_published');
    OutboundMessage::factory()->sent()->create([
        'template' => $failed->template,
        'related_type' => $failed->related_type,
        'related_id' => $failed->related_id,
        'recipient' => $to,
    ]);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    expect(resendToastBodies())->toContain(__('outbound.resend.refused.already_delivered'));

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
});

/**
 * `stage_logs.notified_at` được ghi khi MỌI người nhận đã nhận (một lượt thử lại sau đó của hàng
 * đợi thành công). Cổng lúc-gửi của mẫu gốc trả "không ai", nhưng sự thật là "đã nhận" — câu từ
 * chối phải nói đúng sự thật đó, không đổ cho vụ việc/tài khoản.
 *
 * Mutation probe: bỏ nhánh `deliveredLater()` khỏi `handle()` → ĐỎ (câu "không còn ai đủ điều kiện").
 */
it('says already delivered, not nobody eligible, when the stage update was delivered on a later retry', function () {
    Queue::fake();
    [$failed, $to] = resendFixture('client.stage_update');
    OutboundMessage::factory()->sent()->create([
        'template' => $failed->template,
        'related_type' => $failed->related_type,
        'related_id' => $failed->related_id,
        'recipient' => $to,
    ]);
    StageLog::query()->whereKey($failed->related_id)->update(['notified_at' => now()]);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    expect(resendToastBodies())->toContain(__('outbound.resend.refused.already_delivered'))
        ->and(resendToastBodies())->not->toContain(__('outbound.resend.refused.no_eligible_recipient'));

    Queue::assertNothingPushed();
});

/**
 * `client.document_rejected`: dòng hỏng nói về lần từ chối CŨ (khoá `rejected@<reviewed_at>` ở
 * `payload.tier`); từ đó khách nộp lại và bị từ chối LẦN MỚI. Gửi lại dòng cũ không được gửi
 * thư của lần mới dưới danh nghĩa lần cũ — cùng cổng "đúng lần từ chối mà sự kiện bắn ra" của
 * `NotifyClientOfChecklistItemRejected::stillRejected()`.
 *
 * Mutation probe: bỏ nhánh `isSameInstance()` khỏi `ResendOutboundMessage::handle()` → ĐỎ.
 */
it('refuses to resend a rejection mail whose rejection has since been replaced by a newer one', function () {
    Queue::fake();
    [$failed] = resendFixture('client.document_rejected');
    MatterChecklistItem::query()->whereKey($failed->related_id)->update([
        'reviewed_at' => now()->addMinute(),
        'rejection_reason' => 'Lần từ chối mới: thiếu trang 2.',
    ]);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    expect(resendToastBodies())->toContain(__('outbound.resend.refused.superseded'));

    Queue::assertNothingPushed();
    expect(resendAudits())->toBeEmpty();
});

/**
 * `staff.new_client_document`: nhật ký chỉ giữ tệp ĐẠI DIỆN của lô. Gửi lại dựng lại lô của CÙNG
 * lần nộp (hai mặt CCCD = 2 tệp) — không đếm tệp của một lần nộp khác (khách khác của cùng vụ nộp
 * cùng đầu mục/version trong cùng phút), để số tệp trong thư nội bộ đúng.
 *
 * Mutation probe: bỏ điều kiện `uploader_id` khỏi `ResendTargets::batchOf()` → ĐỎ (đếm 3).
 */
it('rebuilds the staff new-document mail from the same submission batch only', function () {
    Mail::fake();
    [$matter, $lawyer, $account] = resendMatter();
    $other = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id, 'is_active' => true]);
    $front = Document::factory()->pendingReview()->uploadedBy($account)->create(['matter_id' => $matter->id]);
    Document::factory()->pendingReview()->uploadedBy($account)->create([
        'matter_id' => $matter->id,
        'matter_checklist_item_id' => $front->matter_checklist_item_id,
        'version' => $front->version,
    ]);
    Document::factory()->pendingReview()->uploadedBy($other)->create([
        'matter_id' => $matter->id,
        'matter_checklist_item_id' => $front->matter_checklist_item_id,
        'version' => $front->version,
    ]);
    $failed = failedRowFor('staff.new_client_document', $front, $lawyer->email);

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    Mail::assertSent(NewClientDocumentMail::class, fn (NewClientDocumentMail $mail): bool => $mail->hasTo($lawyer->email)
        && $mail->firstDocument->is($front)
        && $mail->count === 2);
});

// ---------------------------------------------------------------------------------------------
// Bấm hai lần, lượt gửi lại hỏng.
// ---------------------------------------------------------------------------------------------

/**
 * Bấm hai lần liền (job chưa kịp chạy — hàng đợi `database` thật): lần hai bị từ chối "đã yêu cầu
 * gửi lại", chỉ MỘT job trong hàng đợi, MỘT dòng audit.
 *
 * Mutation probe: bỏ nhánh `$previous !== null` khỏi `handle()` → ĐỎ (hai job, hai audit).
 */
it('queues exactly one resend when the button is clicked twice before the queue runs', function () {
    config(['queue.default' => 'database']);
    [$failed] = resendFixture('client.stage_update');

    $this->actingAs(resendAdmin(), 'web');

    $page = $this->livewire(ListOutboundMessages::class);
    $page->callAction(TestAction::make('resend')->table($failed));
    $page->callAction(TestAction::make('resend')->table($failed));

    expect(resendToastBodies())->toContain(__('outbound.resend.refused.already_requested', [
        'time' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i'),
    ]));

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(resendAudits())->toHaveCount(1);
});

/**
 * Chạy job gửi lại qua ĐỦ ngân sách thử lại của nó trên hàng đợi `database` thật — cùng lịch
 * `StageUpdateNotificationTest` (5 lượt, backoff 60/300/900/3600 giây).
 */
function drainResendAttempts(): void
{
    foreach ([0, 61, 301, 901, 3601] as $delay) {
        if ($delay > 0) {
            test()->travel($delay)->seconds();
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }
}

/**
 * Máy chủ thư vẫn chết lúc gửi lại: mỗi lượt thử của job gửi lại để lại một dòng `failed` MỚI (dòng
 * hỏng mới nhất có nút riêng), dòng cũ không đổi; chỉ SAU lượt cuối, NGƯỜI BẤM được báo trong hệ
 * thống — đúng một lần, câu báo không nêu mã/tiêu đề vụ việc (vụ `restricted`), không ai khác nhận.
 *
 * Mutation probe: bỏ lời gọi `->notify(...)` trong `ResendOutboundMessageJob::failed()` → ĐỎ.
 */
it('leaves new failed rows and tells only the admin who clicked, without naming the matter, when every resend attempt fails', function () {
    config(['queue.default' => 'database']);
    [$failed, $to, $matter] = resendFixture('client.stage_update');
    $matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    $before = $failed->fresh()->getAttributes();
    $admin = resendAdmin();
    $otherAdmin = resendAdmin();

    $this->actingAs($admin, 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    $bodies = implode(' ', array_filter(resendToastBodies()));
    expect($bodies)->not->toContain($matter->code)
        ->and($bodies)->not->toContain($matter->title);

    config(['mail.default' => resendFailingMailer()]);

    // Lượt 1 hỏng: chưa ai được báo — một lần hỏng thoáng qua không phải là "hỏng hẳn".
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    expect(DB::table('notifications')->where('type', OutboundResendFailedAlert::class)->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    foreach ([61, 301, 901, 3601] as $delay) {
        $this->travel($delay)->seconds();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $new = resendRowsAfter($failed);

    expect($new)->toHaveCount(5)
        ->and($new->every(fn (OutboundMessage $row): bool => $row->status === OutboundStatus::Failed && $row->recipient === $to))->toBeTrue()
        ->and($failed->fresh()->getAttributes())->toEqual($before)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $alerts = DB::table('notifications')->where('type', OutboundResendFailedAlert::class)->get();

    expect($alerts)->toHaveCount(1)
        ->and((int) $alerts->first()->notifiable_id)->toBe($admin->id)
        ->and($alerts->first()->data)->not->toContain($matter->code)
        ->and($alerts->first()->data)->not->toContain(json_encode($matter->title))
        ->and($otherAdmin->notifications()->count())->toBe(0);

    // Dòng hỏng MỚI NHẤT có nút gửi lại của riêng nó.
    $this->livewire(ListOutboundMessages::class)
        ->assertActionVisible(TestAction::make('resend')->table($new->sortByDesc('id')->first()));
});

/**
 * Người bấm đã NGHỈ VIỆC trước khi job hỏng hẳn: câu báo không tới một tài khoản đã khoá, mà đi theo
 * chuỗi dự phòng R3 của `ResolveStaffRecipients` (luật sư phụ trách còn làm việc và xem được vụ) —
 * "không bao giờ im lặng", và không có luật người nhận thứ hai.
 *
 * Mutation probe: thay `ResolveStaffRecipients::handle($matter, [$actor])` bằng `collect([$actor])`
 * trong `ResendOutboundMessageJob::failed()` → ĐỎ.
 */
it('tells the lead lawyer instead when the admin who clicked has been deactivated by the time the resend fails for good', function () {
    config(['queue.default' => 'database']);
    [$failed, , $matter] = resendFixture('client.stage_update');
    $admin = resendAdmin();

    $this->actingAs($admin, 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    $admin->forceFill(['is_active' => false])->save();
    config(['mail.default' => resendFailingMailer()]);

    drainResendAttempts();

    $alerts = DB::table('notifications')->where('type', OutboundResendFailedAlert::class)->get();

    expect($alerts->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all())->toBe([$matter->lead_lawyer_id]);
});

/**
 * Cửa sổ hàng đợi: admin bấm (job xếp hàng), rồi TRƯỚC khi job chạy đầu mục bị từ chối LẦN MỚI.
 * Job hỏi lại cùng cổng "đúng lần từ chối" với Action — không gửi thư của lần mới dưới danh nghĩa
 * dòng cũ (thư lần mới là việc của listener gốc của lần đó).
 *
 * Mutation probe: bỏ `! ResendTargets::isSameInstance(...)` khỏi `ResendOutboundMessageJob::handle()`
 * → ĐỎ.
 */
it('sends nothing when the rejection is replaced between the click and the queued resend running', function () {
    config(['queue.default' => 'database']);
    Mail::fake();
    [$failed] = resendFixture('client.document_rejected');

    $this->actingAs(resendAdmin(), 'web');

    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    expect(DB::table('jobs')->count())->toBe(1);

    MatterChecklistItem::query()->whereKey($failed->related_id)->update(['reviewed_at' => now()->addMinute()]);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    Mail::assertNothingSent();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

/**
 * R2 "Job có `tries` và `backoff`": cùng ngân sách thử lại của các listener/job gốc (5 lượt,
 * 60/300/900/3600 giây). An toàn vì đường gửi thật của mẫu tự bỏ qua người đã có dòng `sent`.
 */
it('retries the resend job with the same budget as the original mail listeners', function () {
    $job = new ResendOutboundMessageJob(1, 1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);
});

/** Hằng số audit và chuỗi literal (để `ActivityLogEventTranslationsTest` quét được) không lệch nhau. */
it('keeps the audit event constant equal to the literal the action records', function () {
    expect(ResendOutboundMessage::AUDIT_EVENT)->toBe('outbound_message_resent')
        ->and(__('activity.events.outbound_message_resent'))->not->toBe('activity.events.outbound_message_resent');
});
