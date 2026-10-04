<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DocumentGroup;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Listeners\SendStageUpdateNotification;
use App\Mail\Client\DocumentPublished as DocumentPublishedMail;
use App\Mail\Client\DocumentRejected;
use App\Mail\Client\RequestAnswered;
use App\Mail\Client\StageUpdate;
use App\Mail\OutboundHeaders;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\PushAlert;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 8 — thông báo đẩy cho bốn sự kiện của khách (R10, R11; Review Focus 2, 3)
|--------------------------------------------------------------------------
|
| Bốn đường sản phẩm, đi qua ĐÚNG màn hình của văn phòng (Livewire), không gọi thẳng Action:
|  - luật sư chuyển giai đoạn có công bố (`client.stage_update`);
|  - văn phòng công bố tài liệu (`client.document_published`);
|  - văn phòng từ chối giấy tờ khách nộp (`client.document_rejected`);
|  - văn phòng trả lời yêu cầu của khách (`client.request_answered`).
|
| R10: push không có luật người nhận của riêng nó. Mỗi Action thư gọi `SendPushAlert` với ĐÚNG những
| tài khoản mà CHÍNH lượt đó vừa gửi thư thành công (phán quyết (d) của controller) — nên tập người
| nhận push đúng bằng tập người nhận thư, và chống trùng là sổ thư sẵn có (`alreadyDelivered()`,
| `stage_logs.notified_at`), không trí nhớ mới. Test so id tài khoản giữa `Mail::fake()` và
| `Notification::fake()`; vài test đi đường thật (máy chủ push giả ở tầng HTTP, hàng đợi `database`
| với worker thật) để đo phần mà hai bản giả không thấy.
|
| Hàng đợi `sync` của `phpunit.xml`: listener thư (`ShouldQueue`) chạy ngay sau commit của request
| Livewire, và `PushAlert` cũng vậy khi `Notification` không bị giả.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    config(WebPushTestKeys::config());
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
});

/** @return array<string, string> chỗ nhạy cảm => chuỗi đánh dấu (Review Focus 2) */
function clientPushMarkers(): array
{
    return [
        'tiêu đề vụ' => 'DAUVET8-TIEUDE-VU',
        'mã hồ sơ' => 'DAUVET8-MAHS',
        'tên khách' => 'DAUVET8-TEN-KHACH',
        'tên các bên' => 'DAUVET8-BEN-KIA',
        'tên toà' => 'DAUVET8-TOA',
        'số thụ lý' => 'DAUVET8-THULY',
        'internal_note' => 'DAUVET8-GHICHU-NOIBO',
        'nội dung công bố' => 'DAUVET8-CONGBO',
        'tiêu đề tài liệu' => 'DAUVET8-TIEUDE-TAILIEU',
        'tên đầu mục giấy tờ' => 'DAUVET8-DAUMUC',
        'lý do từ chối' => 'DAUVET8-LYDO-TUCHOI',
        'tiêu đề yêu cầu' => 'DAUVET8-TIEUDE-YEUCAU',
        'nội dung yêu cầu' => 'DAUVET8-NOIDUNG-YEUCAU',
        'nội dung trả lời' => 'DAUVET8-TRALOI',
    ];
}

/** @return array<string, array{0: string}> bốn đường sản phẩm của bảng R10 phía khách */
function clientPushPaths(): array
{
    return [
        'chuyển giai đoạn có công bố' => ['stage'],
        'công bố tài liệu' => ['document_published'],
        'từ chối giấy tờ' => ['document_rejected'],
        'trả lời yêu cầu' => ['request_answered'],
    ];
}

/** Mailable của đường `$path` — thư mà push đi cùng. */
function clientPushMailable(string $path): string
{
    return match ($path) {
        'stage' => StageUpdate::class,
        'document_published' => DocumentPublishedMail::class,
        'document_rejected' => DocumentRejected::class,
        'request_answered' => RequestAnswered::class,
    };
}

function clientPushTopic(string $path): PushTopic
{
    return match ($path) {
        'stage' => PushTopic::ClientStageUpdate,
        'document_published' => PushTopic::ClientDocumentPublished,
        'document_rejected' => PushTopic::ClientDocumentRejected,
        'request_answered' => PushTopic::ClientRequestAnswered,
    };
}

/**
 * Một vụ việc đang mở, đã công bố cổng, mang chuỗi đánh dấu ở mọi chỗ nhạy cảm; luật sư phụ trách
 * trong đội; và tài khoản "vợ" — R12 (còn hoạt động, đã kích hoạt) của đúng khách hàng sở hữu vụ.
 *
 * @return array{0: Matter, 1: User, 2: ClientUser}
 */
function clientPushScenario(): array
{
    $m = clientPushMarkers();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['name' => $m['tên khách']]);
    $wife = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);

    $matter = Matter::factory()->atStage('intake')->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
        'code' => $m['mã hồ sơ'],
        'title' => $m['tiêu đề vụ'],
        'court_name' => $m['tên toà'],
        'case_number' => $m['số thụ lý'],
    ]);
    $matter->team()->syncWithoutDetaching([$lawyer->id]);
    MatterParty::factory()->for($matter)->create(['name' => $m['tên các bên']]);

    return [$matter, $lawyer, $wife];
}

/** Một tài khoản R12 khác của CÙNG khách hàng ("chồng" — SPEC §4.3). */
function clientPushSpouse(ClientUser $wife, array $attributes = []): ClientUser
{
    return ClientUser::factory()->activated()->create(['client_id' => $wife->client_id, 'is_active' => true, ...$attributes]);
}

/** Form "Chuyển giai đoạn" của tab Tiến độ, như luật sư bấm. Trả dòng tiến độ vừa tạo. */
function clientPushTransition(Matter $matter, User $lawyer, bool $publish, string $toStage = 'collecting_documents'): StageLog
{
    $m = clientPushMarkers();
    test()->actingAs($lawyer, 'web');

    test()->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('transitionStage', data: [
            'to_stage' => $toStage,
            'occurred_at' => today()->toDateString(),
            'internal_note' => $m['internal_note'],
            'public_content' => $publish ? 'Văn phòng đã nộp hồ sơ ('.$m['nội dung công bố'].') và đang chờ cơ quan có thẩm quyền xem xét.' : null,
            'next_step' => null,
            'client_action' => null,
            'expected_next_update_at' => null,
            'publish' => $publish,
        ])
        ->assertHasNoTableActionErrors();

    return StageLog::query()->withoutGlobalScope(ClientPortalScope::class)->where('matter_id', $matter->id)->latest('id')->firstOrFail();
}

/**
 * Văn phòng làm đúng việc của đường `$path` qua màn hình của nó. Trả bản ghi mà THƯ đi cùng ghi vào
 * nhật ký (`relatedRecord()` của mailable) — push phải mang đúng bản ghi đó.
 */
function clientPushAct(string $path, Matter $matter, User $lawyer, ClientUser $author): Model
{
    $m = clientPushMarkers();

    if ($path === 'stage') {
        return clientPushTransition($matter, $lawyer, publish: true);
    }

    test()->actingAs($lawyer, 'web');
    $manager = fn (string $class) => test()->livewire($class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);

    return match ($path) {
        'document_published' => (function () use ($matter, $m, $manager): Document {
            $document = Document::factory()->for($matter)->group(DocumentGroup::Authority)->create(['title' => $m['tiêu đề tài liệu']]);
            $document->addMedia(UploadedFile::fake()->createWithContent('quyet-dinh.pdf', '%PDF-1.4 noi dung'))
                ->usingFileName('01k5g7q8wz0000000000000000.pdf')
                ->toMediaCollection('file');

            $manager(DocumentsRelationManager::class)
                ->callAction(TestAction::make('publish')->table($document->refresh()), data: [
                    'client_can_view' => true,
                    'client_can_download' => true,
                ])
                ->assertHasNoActionErrors();

            return $document->refresh();
        })(),
        'document_rejected' => (function () use ($matter, $m, $manager): MatterChecklistItem {
            $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)
                ->create(['name' => $m['tên đầu mục giấy tờ']]);

            $manager(ChecklistRelationManager::class)
                ->callAction(TestAction::make('reject')->table($item), data: [
                    'rejection_reason' => 'Ảnh chụp bị mờ, nhờ anh/chị chụp lại ('.$m['lý do từ chối'].').',
                ])
                ->assertHasNoActionErrors();

            return $item->refresh();
        })(),
        'request_answered' => (function () use ($matter, $m, $author, $manager): ClientRequestReply {
            $thread = ClientRequest::factory()->for($matter)->create([
                'client_user_id' => $author->id,
                'subject' => $m['tiêu đề yêu cầu'],
                'content' => $m['nội dung yêu cầu'],
                'status' => ClientRequestStatus::New,
            ]);

            $manager(ClientRequestsRelationManager::class)
                ->callTableAction('reply', $thread, ['content' => 'Văn phòng trả lời: '.$m['nội dung trả lời'].'.'])
                ->assertHasNoTableActionErrors();

            return ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class)
                ->where('request_id', $thread->id)->latest('id')->firstOrFail();
        })(),
    };
}

/**
 * Ai nhận thư (theo `Mail::fake()`) và ai nhận push (theo `Notification::fake()`), id tăng dần.
 *
 * @param  list<ClientUser>  $accounts
 * @return array{mailed: list<int>, pushed: list<int>}
 */
function clientPushDelivered(string $path, array $accounts): array
{
    $mailable = clientPushMailable($path);
    $mailed = [];
    $pushed = [];

    foreach ($accounts as $account) {
        if (Mail::sent($mailable, fn ($mail): bool => $mail->hasTo($account->email))->isNotEmpty()) {
            $mailed[] = $account->id;
        }

        if (Notification::sent($account, PushAlert::class)->isNotEmpty()) {
            $pushed[] = $account->id;
        }
    }

    sort($mailed);
    sort($pushed);

    return ['mailed' => $mailed, 'pushed' => $pushed];
}

/** Số `PushAlert` đã xếp cho từng tài khoản (theo `Notification::fake()`), theo thứ tự đưa vào. */
function clientPushCounts(ClientUser ...$accounts): array
{
    return array_map(fn (ClientUser $account): int => Notification::sent($account, PushAlert::class)->count(), $accounts);
}

/**
 * Rút hàng đợi `database` bằng worker THẬT, mỗi lần MỘT job (`--once`, tiền lệ
 * `openMatterDrainDatabaseQueue()` của `OpenMatterTest`): một job vừa ném lỗi được thả lại với id
 * mới, và vòng lặp trong một lần `queue:work` không chắc xét tới nó. Dừng khi không còn job nào tới
 * hạn — job đang chờ backoff ở lại cho lượt sau.
 */
function clientPushDrainQueue(): void
{
    for ($guard = 0; $guard < 20; $guard++) {
        if (! DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->exists()) {
            return;
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }
}

/**
 * Transport CHỌN LỌC: hỏng cho đúng một địa chỉ, gửi được cho mọi địa chỉ khác — đi đường cấu hình
 * thật của Laravel, nên `OutboundLedgerTransport` ghi sổ thư như ở máy chủ thật (cùng lối
 * `StageUpdateSelectiveFailTransport`).
 */
class ClientPushSelectiveFailTransport implements TransportInterface
{
    public function __construct(private readonly string $failingAddress) {}

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        if ($message instanceof Email) {
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $this->failingAddress) {
                    throw new TransportException('SMTP từ chối '.$this->failingAddress);
                }
            }
        }

        return new SymfonySentMessage($message, new SymfonyEnvelope(new Address('gui@vidu.test'), [new Address('nhan@vidu.test')]));
    }

    public function __toString(): string
    {
        return 'client-push-selective-fail://';
    }
}

function clientPushSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.client_push_selective_fail', ['transport' => 'client_push_selective_fail']);
    Mail::extend('client_push_selective_fail', fn (): TransportInterface => new ClientPushSelectiveFailTransport($failingAddress));

    return 'client_push_selective_fail';
}

/** Dòng nhật ký `sent`/`failed` của kênh email cho một địa chỉ. */
function clientPushMailRows(string $email, OutboundStatus $status): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('channel', OutboundChannel::Email)
        ->where('recipient', $email)
        ->where('status', $status)
        ->count();
}

/**
 * Plan Task 8: "Mỗi đường, khẳng định tập người nhận push đúng bằng tập người nhận thư (so sánh id
 * với `Mail::fake()` và `Notification::fake()`)." Cùng một cảnh cho cả bốn đường: vợ và chồng (R12,
 * mỗi người một máy), một tài khoản chưa kích hoạt, một tài khoản bị khoá, một tài khoản của khách
 * hàng KHÁC — tất cả đều đã bật máy, nên chỉ luật người nhận của THƯ quyết định ai nhận push.
 *
 * Cùng test: push mang ĐÚNG chủ đề và bản ghi mà thư ghi vào nhật ký (header `X-VKCRM-Template` /
 * `X-VKCRM-Related` của mailable), và payload không lộ chuỗi đánh dấu nào (Review Focus 2) — kể cả
 * lý do từ chối, tiêu đề tài liệu, câu trả lời mà chính lần bấm này vừa gõ.
 *
 * Mutation probe (báo cáo Task 8): bỏ lời gọi `SendPushAlert` ở Action của một đường → đường đó ĐỎ.
 */
it('pushes exactly the accounts it mails, about the same record, with nothing of the case on the lock screen', function (string $path) {
    Mail::fake();
    Notification::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();
    $husband = clientPushSpouse($wife);
    $neverActivated = ClientUser::factory()->create(['client_id' => $wife->client_id, 'is_active' => true]);
    $locked = clientPushSpouse($wife, ['is_active' => false]);
    $stranger = ClientUser::factory()->activated()->create(['is_active' => true]);
    $accounts = [$wife, $husband, $neverActivated, $locked, $stranger];

    foreach ($accounts as $i => $account) {
        FakePushServer::device($account, $path.'-'.$i);
    }

    $related = clientPushAct($path, $matter, $lawyer, $wife);

    $delivered = clientPushDelivered($path, $accounts);

    expect($delivered['pushed'])->toBe($delivered['mailed'])
        ->and($delivered['mailed'])->toBe([$wife->id, $husband->id]);

    foreach ([$wife, $husband] as $account) {
        $mail = Mail::sent(clientPushMailable($path), fn ($mail): bool => $mail->hasTo($account->email))->sole();
        $alert = Notification::sent($account, PushAlert::class)->sole();
        $headers = $mail->headers()->text;

        expect($alert->message->topic)->toBe(clientPushTopic($path)->value)
            ->and($alert->message->topic)->toBe($headers[OutboundHeaders::TEMPLATE])
            ->and($alert->message->relatedType.':'.$alert->message->relatedId)->toBe($headers[OutboundHeaders::RELATED])
            ->and($alert->message->relatedId)->toBe($related->getKey());

        $payload = json_encode($alert->toWebPush($account, $alert)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (clientPushMarkers() as $where => $marker) {
            expect(str_contains($payload, $marker))->toBeFalse("Payload của {$path} lộ {$where}");
        }
    }
})->with(clientPushPaths());

/**
 * Plan Task 8: "Tài khoản chưa kích hoạt, tài khoản bị vô hiệu, khách hàng xoá mềm: không thư, không
 * push. Mỗi điều kiện một mutation probe ở luật người nhận chung." Luật đó nằm MỘT chỗ —
 * `ResolveClientRecipients::eligibleQuery()` (`is_active`, `whereNotNull('activated_at')`,
 * `whereHas('client')`). Push được khẳng định TRƯỚC thư: bỏ một điều kiện ở đó thì dòng "không push"
 * đỏ trước tiên — bằng chứng push đi theo đúng luật đó, không có bản sao nào của riêng nó.
 *
 * Vế dương trong cùng test (trừ khi chính khách hàng đã xoá): tài khoản "vợ" của cùng khách vẫn nhận
 * đúng một thư và một push — lượt gửi đã chạy thật, không phải xanh vì không ai được xét.
 */
it('neither mails nor pushes an account the shared client rule leaves out', function (string $path, string $condition) {
    Mail::fake();
    Notification::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();

    $excluded = match ($condition) {
        'never activated' => ClientUser::factory()->create(['client_id' => $wife->client_id, 'is_active' => true]),
        'locked' => clientPushSpouse($wife, ['is_active' => false]),
        'client soft-deleted' => $wife,
    };

    FakePushServer::device($wife, 'vo');

    if ($excluded->isNot($wife)) {
        FakePushServer::device($excluded, 'loai');
    }

    if ($condition === 'client soft-deleted') {
        $wife->client->delete();
    }

    clientPushAct($path, $matter, $lawyer, $wife);

    expect(clientPushCounts($excluded))->toBe([0], "{$condition}: có push")
        ->and(Mail::sent(clientPushMailable($path), fn ($mail): bool => $mail->hasTo($excluded->email)))->toBeEmpty();

    if ($condition !== 'client soft-deleted') {
        expect(clientPushCounts($wife))->toBe([1])
            ->and(Mail::sent(clientPushMailable($path), fn ($mail): bool => $mail->hasTo($wife->email)))->toHaveCount(1);
    }
})->with(clientPushPaths())->with([
    'never activated',
    'locked',
    'client soft-deleted',
]);

/**
 * Plan Task 8: "Chuyển giai đoạn không công bố: không push. Chạy lại listener: không push thứ hai
 * (`notified_at`)." `Mail::fake()` không ghi sổ thư, nên ở lượt chạy lại `alreadyDelivered()` không
 * chặn ai — cái chặn DUY NHẤT còn lại là `stage_logs.notified_at` (`eligibleRecipients()` trả rỗng),
 * đúng trí nhớ mà kế hoạch chỉ định.
 */
it('pushes nothing for an unpublished stage change, and never twice when the listener runs again', function () {
    Mail::fake();
    Notification::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();
    $husband = clientPushSpouse($wife);
    FakePushServer::device($wife, 'vo');
    FakePushServer::device($husband, 'chong');

    $unpublished = clientPushTransition($matter, $lawyer, publish: false);

    expect($unpublished->is_published)->toBeFalse()
        ->and(clientPushCounts($wife, $husband))->toBe([0, 0]);
    Mail::assertNotSent(StageUpdate::class);

    $published = clientPushTransition($matter->refresh(), $lawyer, publish: true, toStage: 'drafting');

    expect($published->is_published)->toBeTrue()
        ->and($published->notified_at)->not->toBeNull()
        ->and(clientPushCounts($wife, $husband))->toBe([1, 1]);

    // Hàng đợi giao lại cùng sự kiện (worker chết sau khi gửi, `--max-time`): listener chạy lần hai.
    app(SendStageUpdateNotification::class)->handle(new StageLogPublished($published->fresh()));

    expect(clientPushCounts($wife, $husband))->toBe([1, 1]);
    Mail::assertSent(StageUpdate::class, 2);
});

/**
 * Plan Task 8: "Khách hàng có hai tài khoản (vợ và chồng), mỗi người một máy: cả hai nhận; tài khoản
 * của khách hàng khác không nhận." Đi đường THẬT tới máy chủ push (bản giả ở tầng HTTP — kênh của
 * gói mã hoá, ký VAPID và gửi): đúng hai request, tới máy của vợ và máy của chồng; máy của người lạ
 * không nhận gì; mỗi máy một dòng nhật ký `push` mang chủ đề và bản ghi của thư.
 */
it('reaches the phones of both spouses and of nobody else, through the real push channel', function (string $path) {
    $server = FakePushServer::start();
    Mail::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();
    $husband = clientPushSpouse($wife);
    $stranger = ClientUser::factory()->activated()->create(['is_active' => true]);
    $wifePhone = FakePushServer::device($wife, 'vo');
    $husbandPhone = FakePushServer::device($husband, 'chong');
    $strangerPhone = FakePushServer::device($stranger, 'nguoi-la');

    $related = clientPushAct($path, $matter, $lawyer, $wife);

    expect($server->endpoints())->toEqualCanonicalizing([$wifePhone, $husbandPhone])
        ->and($server->endpoints())->not->toContain($strangerPhone);

    $rows = OutboundMessage::query()->withoutGlobalScopes()->where('channel', OutboundChannel::Push)->orderBy('id')->get();

    expect($rows->pluck('recipient')->sort()->values()->all())
        ->toBe(collect(['client_user:'.$wife->id, 'client_user:'.$husband->id])->sort()->values()->all())
        ->and($rows->pluck('status')->unique()->all())->toBe([OutboundStatus::Sent])
        ->and($rows->pluck('template')->unique()->all())->toBe([clientPushTopic($path)->value])
        ->and($rows->pluck('related_type')->unique()->all())->toBe([$related->getMorphClass()])
        ->and($rows->pluck('related_id')->unique()->all())->toBe([$related->getKey()]);
})->with(clientPushPaths());

/**
 * Phán quyết (d) của controller: push đi theo TỪNG người, ngay sau khi thư của CHÍNH người đó đi được;
 * chống trùng là sổ thư. Đường thật: hàng đợi `database`, worker thật, transport hỏng đúng địa chỉ
 * của chồng ở lượt đầu.
 *  - Lượt 1: thư của vợ đi → vợ nhận push; thư của chồng hỏng → chồng KHÔNG nhận push (chưa có thư
 *    thì chưa có push), listener ném lại để hàng đợi thử lại.
 *  - Lượt 2 (sau backoff, SMTP đã sống): vợ đã có dòng `sent` → không thư thứ hai, KHÔNG push thứ
 *    hai; thư của chồng đi → chồng nhận push.
 *
 * Mutation probe (báo cáo Task 8): đưa nguyên `$recipients` vào `SendPushAlert` thay vì những người
 * vừa nhận thư → vợ nhận push lần hai ở lượt 2, ĐỎ; xếp push cho người nhận TRƯỚC khi thư của họ đi
 * (hay cả khi thư hỏng) → chồng nhận push ở lượt 1, ĐỎ; đặt lời gọi push SAU `throw` → vợ không nhận
 * push nào (lượt 1 ném trước khi đẩy, lượt 2 vợ đã có thư), ĐỎ.
 */
it('pushes each account once, right after its own mail went through, across a queue retry', function (string $path) {
    config(['queue.default' => 'database']);
    config(['mail.default' => clientPushSelectiveFailMailer('chong-push-hong@vidu.test')]);
    Notification::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();
    $husband = clientPushSpouse($wife, ['email' => 'chong-push-hong@vidu.test']);
    FakePushServer::device($wife, 'vo');
    FakePushServer::device($husband, 'chong');

    clientPushAct($path, $matter, $lawyer, $wife);

    // Request của luật sư chỉ xếp listener; chưa thư, chưa push.
    expect(clientPushCounts($wife, $husband))->toBe([0, 0]);

    clientPushDrainQueue();

    expect(clientPushCounts($wife, $husband))->toBe([1, 0])
        ->and(clientPushMailRows($wife->email, OutboundStatus::Sent))->toBe(1)
        ->and(clientPushMailRows($husband->email, OutboundStatus::Failed))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);

    config(['mail.default' => 'array']);
    $this->travel(61)->seconds();
    clientPushDrainQueue();

    expect(clientPushCounts($wife, $husband))->toBe([1, 1])
        ->and(clientPushMailRows($wife->email, OutboundStatus::Sent))->toBe(1)
        ->and(clientPushMailRows($husband->email, OutboundStatus::Sent))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with(clientPushPaths());

/**
 * Nút "Gửi lại" của nhật ký thư (M6 Task 10) gọi lại ĐÚNG `handle()` của Action thư
 * (`ResendTargets`), nên push đi theo cùng luật: người vừa nhận thư nhờ lần gửi lại nhận push; người
 * đã có thư từ trước (dòng `sent`) không nhận thư thứ hai, không nhận push thứ hai.
 */
it('pushes only the account that the office resend actually mails', function () {
    Mail::fake();
    Notification::fake();
    [$matter, , $wife] = clientPushScenario();
    $husband = clientPushSpouse($wife);
    FakePushServer::device($wife, 'vo');
    FakePushServer::device($husband, 'chong');

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng đã nộp hồ sơ tới toà án và đang chờ thụ lý vụ việc.',
    ]);
    $ledger = fn (string $email, OutboundStatus $status): OutboundMessage => OutboundMessage::factory()->create([
        'template' => 'client.stage_update',
        'related_type' => $log->getMorphClass(),
        'related_id' => $log->getKey(),
        'recipient' => $email,
        'status' => $status,
        'error' => $status === OutboundStatus::Failed ? 'TransportException: SMTP chết lúc đó' : null,
        'payload' => ['subject' => 'Tiêu đề cũ'],
    ]);
    $ledger($wife->email, OutboundStatus::Sent);
    $failed = $ledger($husband->email, OutboundStatus::Failed);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');
    $this->livewire(ListOutboundMessages::class)->callAction(TestAction::make('resend')->table($failed));

    Mail::assertSent(StageUpdate::class, 1);
    Mail::assertSent(StageUpdate::class, fn (StageUpdate $mail): bool => $mail->hasTo($husband->email));
    expect(clientPushCounts($wife, $husband))->toBe([0, 1]);
});

/**
 * Chống trùng của `client.document_rejected` theo LẦN từ chối (khoá `reviewed_at` của sổ thư, ruling
 * M6 Task 3): khách nộp lại rồi bị từ chối lần nữa là thư thứ hai — và push thứ hai, vì push đi theo
 * đúng thư. Mailer `array` THẬT (không `Mail::fake()`), để sổ thư được ghi và `alreadyDelivered()` có
 * gì để đọc. Bước "khách nộp lại" đặt thẳng trạng thái chờ duyệt (cùng lối
 * `DocumentRejectedNotificationTest`): đường nộp của cổng không phải thứ đang đo ở đây.
 */
it('pushes again on a second rejection of the same item, as it mails again', function () {
    Notification::fake();
    [$matter, $lawyer, $wife] = clientPushScenario();
    FakePushServer::device($wife, 'vo');

    $item = clientPushAct('document_rejected', $matter, $lawyer, $wife);

    expect(clientPushCounts($wife))->toBe([1])
        ->and(clientPushMailRows($wife->email, OutboundStatus::Sent))->toBe(1);

    $this->travel(1)->day();
    $item->update(['status' => ChecklistItemStatus::PendingReview]);

    test()->livewire(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->callAction(TestAction::make('reject')->table($item->refresh()), data: [
            'rejection_reason' => 'Bản nộp lại vẫn thiếu mặt sau, nhờ anh/chị chụp thêm.',
        ])
        ->assertHasNoActionErrors();

    $alerts = Notification::sent($wife, PushAlert::class);

    expect($alerts)->toHaveCount(2)
        ->and($alerts->map(fn (PushAlert $alert): string => $alert->message->tag)->unique()->all())->toBe(['client.document_rejected:'.$item->id])
        ->and(clientPushMailRows($wife->email, OutboundStatus::Sent))->toBe(2);
});
