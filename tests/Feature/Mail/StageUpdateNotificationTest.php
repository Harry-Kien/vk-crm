<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Actions\TransitionMatterStage;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Listeners\SendStageUpdateNotification;
use App\Mail\Client\StageUpdate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Transport giả LUÔN hỏng, đi đúng đường cấu hình thật của Laravel (không `Mail::fake()` — xem
 * docblock `tests/Feature/Mail/OutboundLedgerTest.php` cho lý do: `MailFake` thay cả trình gửi
 * thư, không phát sự kiện, vô hiệu hoá đúng cơ chế Task 11 cần đo).
 */
class StageUpdateFailingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP giả lập chết hẳn (Task 11)');
    }

    public function __toString(): string
    {
        return 'stage-update-failing://';
    }
}

function stageUpdateFailingMailer(): string
{
    config()->set('mail.mailers.stage_update_failing', ['transport' => 'stage_update_failing']);
    Mail::extend('stage_update_failing', fn (): TransportInterface => new StageUpdateFailingTransport);

    return 'stage_update_failing';
}

/**
 * Một vụ việc đã công bố cổng khách, kèm một tài khoản khách còn hoạt động VÀ đã kích hoạt
 * (`->activated()`: must_change_password=false, activated_at=now()) — Task 7 (R12) thêm điều
 * kiện `activated_at không null` vào recipientsFor(), nên từ đây một tài khoản "đủ điều kiện
 * nhận thư" trong bộ test này phải đúng hình dạng đó, không còn chỉ `is_active`.
 */
function publishedMatterWithClientAccount(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);

    $type = MatterType::factory()->withStages()->create();
    $open = $type->stages->reject(fn ($s) => $s->is_terminal);

    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $open->first()->key,
        'is_published_to_portal' => true,
    ]);

    $matter->team()->syncWithoutDetaching([$lawyer->id]);

    return [$matter, $lawyer, $account, $open];
}

/**
 * **Test đi qua ĐÚNG đường sản phẩm.**
 *
 * Nó gọi `TransitionMatterStage` thật chứ không gọi thẳng Action gửi thư, vì điều đang được
 * khẳng định là tiêu chí nghiệm thu SPEC §14 mục 3: luật sư chuyển giai đoạn MỘT LẦN thì khách
 * nhận được thư, không cần thao tác nào thêm. Gọi thẳng Action sẽ chứng minh Action chạy được,
 * chứ không chứng minh dây nối có thật.
 */
it('emails the client when a lawyer publishes a progress update, with no extra step', function () {
    Mail::fake();
    [$matter, $lawyer, $account, $open] = publishedMatterWithClientAccount();

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: $open->skip(1)->first()->key,
        occurredAt: today(),
        internalNote: null,
        publicContent: 'Toà án đã thụ lý vụ việc và sẽ tiến hành các bước tiếp theo trong thời gian tới.',
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    Mail::assertSent(StageUpdate::class, 1);
    Mail::assertSent(StageUpdate::class, fn ($mail) => $mail->hasTo($account->email));
});

it('says nothing to the client when the update is not published', function () {
    Mail::fake();
    [$matter, $lawyer, , $open] = publishedMatterWithClientAccount();

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: $open->skip(1)->first()->key,
        occurredAt: today(),
        internalNote: 'Ghi chú nội bộ, chưa báo khách.',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );

    Mail::assertNothingSent();
});

it('tells every active account of the client, because a client may have two', function () {
    Mail::fake();
    [$matter, , $account] = publishedMatterWithClientAccount();

    // SPEC §4.3 nêu đúng trường hợp này: vợ và chồng, hai tài khoản, một khách hàng.
    $spouse = ClientUser::factory()->activated()->create(['client_id' => $account->client_id, 'is_active' => true]);
    $closed = ClientUser::factory()->activated()->create(['client_id' => $account->client_id, 'is_active' => false]);

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng đã nộp hồ sơ tới cơ quan có thẩm quyền và đang chờ kết quả.',
    ]);

    $sent = app(NotifyClientOfStageUpdate::class)->handle($log);

    expect($sent)->toBe(2);
    Mail::assertSent(StageUpdate::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertSent(StageUpdate::class, fn ($mail) => $mail->hasTo($spouse->email));
    // Tài khoản văn phòng đã chủ động khoá thì không nhận: gửi vào đó mâu thuẫn với chính
    // quyết định khoá.
    Mail::assertNotSent(StageUpdate::class, fn ($mail) => $mail->hasTo($closed->email));
});

/**
 * R12 (`intake/intake-04`, `intake/intake-05`): activated_at chỉ được hệ thống ghi khi khách TỰ
 * TAY đổi mật khẩu lần đầu — bằng chứng duy nhất người này làm chủ hộp thư đã gõ. Một tài khoản
 * nhân sự vừa gõ tay lúc nghe điện thoại, chưa từng đăng nhập, không được coi là "người của vụ
 * việc" để nhận thư client.stage_update cho tới lúc đó — gõ nhầm một ký tự trùng vào một hộp thư
 * có thật thì tiến độ vụ án bị gửi cho người ngoài ở MỌI lần cập nhật sau đó.
 *
 * Twin dương/âm trong CÙNG một test: hai tài khoản của cùng một khách, chỉ khác activated_at.
 *
 * Mutation probe: bỏ `->whereNotNull('activated_at')` khỏi
 * NotifyClientOfStageUpdate::eligibleRecipientsQuery() thì test này đỏ (xem báo cáo).
 */
it('tells only the activated account when the client has two, one activated and one never signed in', function () {
    Mail::fake();
    [$matter, , $account] = publishedMatterWithClientAccount();

    // Factory mặc định (không ->activated()): must_change_password=true, activated_at=null —
    // đúng hình dạng một tài khoản nhân sự vừa tạo, khách chưa từng đăng nhập lần nào.
    $neverActivated = ClientUser::factory()->create(['client_id' => $account->client_id, 'is_active' => true]);

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng vừa nộp hồ sơ khởi kiện tới toà án có thẩm quyền.',
    ]);

    $sent = app(NotifyClientOfStageUpdate::class)->handle($log);

    expect($sent)->toBe(1);
    Mail::assertSent(StageUpdate::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(StageUpdate::class, fn ($mail) => $mail->hasTo($neverActivated->email));
});

/**
 * Rà soát Task 2 (carried forward): `Client::delete()` (EditClient → DeleteAction) không tự tắt
 * các `client_users` của khách đó — is_active MỘT MÌNH không đủ để loại một tài khoản của khách
 * hàng văn phòng đã xoá mềm. Gửi thư về một vụ việc của một khách đã bị chính văn phòng xoá là
 * mâu thuẫn với quyết định xoá đó.
 *
 * Twin dương: test đầu tiên của tệp này ("emails the client when a lawyer publishes…"), cùng
 * helper, khách CHƯA xoá — thư vẫn đi.
 *
 * Mutation probe: bỏ `->whereHas('client')` khỏi
 * NotifyClientOfStageUpdate::eligibleRecipientsQuery() thì test này đỏ (xem báo cáo).
 */
it('never tells an account whose client has been soft deleted, even while the account itself is still active', function () {
    Mail::fake();
    [$matter, , $account] = publishedMatterWithClientAccount();

    $account->client->delete();

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng vừa nộp hồ sơ khởi kiện tới toà án có thẩm quyền.',
    ]);

    $sent = app(NotifyClientOfStageUpdate::class)->handle($log);

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

it('never tells the client twice about the same update', function () {
    Mail::fake();
    [$matter] = publishedMatterWithClientAccount();

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Hồ sơ đã chuyển sang giai đoạn tiếp theo, văn phòng sẽ báo lại khi có kết quả.',
    ]);

    app(NotifyClientOfStageUpdate::class)->handle($log);
    app(NotifyClientOfStageUpdate::class)->handle($log);
    app(NotifyClientOfStageUpdate::class)->handle($log);

    Mail::assertSent(StageUpdate::class, 1);
    expect($log->fresh()->notified_at)->not->toBeNull();
});

/**
 * `notify/notify-13` (test_gap): bản trước gọi `handle()` MỘT LẦN NỮA sau khi kích hoạt lại tài
 * khoản, để chứng minh "lời báo còn nguyên" — nhưng không đường sản phẩm nào từng gọi lại
 * `NotifyClientOfStageUpdate` như vậy: `StageLogPublished` chỉ bắn ĐÚNG MỘT LẦN, lúc tạo dòng.
 * Test đó xanh vì tự dựng một đường không tồn tại ngoài đời, không phải vì hệ thống thật sự gửi
 * lại. Bỏ vế đó; giữ lại đúng phần có thật: không có ai nhận thì không đánh dấu đã báo (không
 * đánh dấu SAI một lần gửi không hề xảy ra), và KHÔNG có gì tự gửi lại sau đó nữa — xem docblock
 * lớp `NotifyClientOfStageUpdate` (mục "Lời hứa đã BỎ") và cảnh báo thay thế của Task 7
 * (`BuildsStageUpdateSchema::noActivatedAccountWarning()`, `tests/Feature/Filament/
 * TransitionStageActionTest.php`).
 */
it('keeps the notice pending, without marking it sent, when the client has no account that could receive it', function () {
    Mail::fake();
    [$matter, , $account] = publishedMatterWithClientAccount();
    $account->update(['is_active' => false]);

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng vừa gửi bổ sung tài liệu theo yêu cầu của cơ quan tố tụng.',
    ]);

    $sent = app(NotifyClientOfStageUpdate::class)->handle($log);

    expect($sent)->toBe(0)
        ->and($log->fresh()->notified_at)->toBeNull();
    Mail::assertNothingSent();
});

/**
 * Ranh giới tuyệt đối của SPEC §9: "Email gửi cho khách chỉ chứa nội dung đã công bố, tuyệt đối
 * không nhúng `internal_note`." Đo bằng một chuỗi đánh dấu, không đọc bằng mắt.
 */
it('never carries the internal note into the client mailbox', function () {
    [$matter, , $account] = publishedMatterWithClientAccount();
    $marker = 'DAU-HIEU-NOI-BO-'.uniqid();

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng đã hoàn tất bước chuẩn bị hồ sơ và sẽ nộp trong tuần này.',
        'internal_note' => $marker,
    ]);

    $mail = new StageUpdate($log, $account);
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($html)->not->toContain($marker)
        ->and($text)->not->toContain($marker)
        // Cặp dương: nội dung ĐÃ CÔNG BỐ thì phải có mặt, nếu không test trên xanh vì thư rỗng.
        ->and($html)->toContain('hoàn tất bước chuẩn bị hồ sơ');
});

it('puts the matter code in the subject and nothing about the case itself', function () {
    [$matter, , $account] = publishedMatterWithClientAccount();

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Nội dung đã công bố cho khách hàng đọc trên cổng thông tin.',
    ]);

    $subject = (new StageUpdate($log, $account))->envelope()->subject;

    // Người ngoài liếc qua hộp thư không đọc được gì về vụ việc; khách thì nhận ra mã của mình.
    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain($matter->title);
});

it('wires the published event to the listener, not just to a class that exists', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(StageLogPublished::class, $listeners))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 11 (`stage/stage-01`, `notify/notify-1`, `spec-gap/spec-gap-02`, `e2e/F2`) — hàng đợi
// ---------------------------------------------------------------------------------------------

it('is a queued listener with a real retry budget, not a plain synchronous one', function () {
    $listener = app(SendStageUpdateNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        // Đúng $tries - 1 độ trễ: worker thả lại hàng đợi sau lần thử 1..4, lần thứ 5 hỏng thì
        // dừng hẳn (cùng lý lẽ đã ghim ở `RecheckClientIdentityConflictsTest`).
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

/**
 * Đúng ba khẳng định của brief Task 11: bấm "Thêm cập nhật" qua Livewire với transport LUÔN hỏng
 * KHÔNG lỗi 500, dòng tiến độ chỉ lưu một lần, và có một job nằm trong hàng đợi (chưa chạy).
 *
 * `queue.default = database` là ĐIỀU KIỆN của test này: dưới hàng đợi `sync` (mặc định bộ test),
 * `push()` chạy job NGAY, đồng bộ — đúng cái mà Task 11 xoá bỏ (xem docblock
 * `SendStageUpdateNotification`). Chuyển sang `database` để phép đo phản ánh đúng production, nơi
 * `queue:work` là một tiến trình cron RIÊNG, tách khỏi request Livewire của luật sư.
 */
it('never 500s the lawyer on a broken transport: it queues the send instead of running it inline', function () {
    config(['queue.default' => 'database']);
    config(['mail.default' => stageUpdateFailingMailer()]);
    Filament::setCurrentPanel('admin');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->atStage('intake')->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'is_published_to_portal' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    // Không bọc trong expect(fn () => ...)->not->toThrow(): nếu listener vẫn chạy đồng bộ và ném
    // ra, PestPHP tự báo LỖI cho chính dòng gọi này — đó CHÍNH LÀ phép đo "không lỗi 500".
    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('addUpdate', data: [
        'occurred_at' => today()->toDateString(),
        'internal_note' => null,
        'public_content' => 'Toà án đã thụ lý và đang xem xét hồ sơ khởi kiện của khách hàng.',
        'next_step' => null,
        'client_action' => null,
        'expected_next_update_at' => null,
        'publish' => true,
    ]);

    expect(StageLog::query()->where('matter_id', $matter->id)->count())->toBe(1)
        ->and(StageLog::query()->where('matter_id', $matter->id)->sole()->notified_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
});

/**
 * Chạy worker THẬT (`queue:work`, từng lần một — cùng cách rút hàng đợi mà
 * `tests/Feature/Actions/OpenMatterTest.php` đã dùng) với transport hỏng: một dòng
 * `outbound_messages` `failed` xuất hiện, mang lý do, và nó CÒN LẠI sau khi job đã hết `$tries`
 * lần thử — đúng phán quyết R2 ("một thư gửi hỏng để lại dòng outbound_messages với
 * status = failed, và dòng đó phải còn lại").
 */
it('leaves a failed outbound row behind that survives even after the job exhausts every retry', function () {
    config(['queue.default' => 'database']);
    config(['mail.default' => stageUpdateFailingMailer()]);

    [$matter, $lawyer, $account, $open] = publishedMatterWithClientAccount();

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: $open->skip(1)->first()->key,
        occurredAt: today(),
        internalNote: null,
        publicContent: 'Văn phòng vừa nộp đơn khởi kiện tới toà án có thẩm quyền xét xử.',
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    // $tries = 5: 4 lần thả lại (backoff 60/300/900/3600s), lần thứ 5 hỏng thì dừng hẳn.
    $delays = [0, 61, 301, 901, 3601];

    foreach ($delays as $delay) {
        if ($delay > 0) {
            $this->travel($delay)->seconds();
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $log = StageLog::query()->where('matter_id', $matter->id)->sole();

    expect($log->notified_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $failedRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Failed)
        ->get();

    expect($failedRows)->not->toBeEmpty();
    expect($failedRows->first()->error)->toContain('TransportException');
});

/**
 * Đối xứng của test trên: transport phục hồi TRƯỚC khi job hết lượt thử — worker gửi được, đúng
 * một lần, và `notified_at` được ghi.
 */
it('delivers on a later retry once the transport recovers, and marks notified_at exactly once', function () {
    config(['queue.default' => 'database']);
    config(['mail.default' => stageUpdateFailingMailer()]);

    [$matter, $lawyer, $account, $open] = publishedMatterWithClientAccount();

    app(TransitionMatterStage::class)->handle(
        matter: $matter,
        actor: $lawyer,
        toStage: $open->skip(1)->first()->key,
        occurredAt: today(),
        internalNote: null,
        publicContent: 'Văn phòng vừa nộp đơn khởi kiện tới toà án có thẩm quyền xét xử.',
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: true,
    );

    // Lượt 1: transport còn hỏng.
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    $log = StageLog::query()->where('matter_id', $matter->id)->sole();
    expect($log->notified_at)->toBeNull();

    // Transport phục hồi; hàng đợi mặc định của bộ test (`array`) không bao giờ ném.
    config(['mail.default' => 'array']);
    $this->travel(61)->seconds();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect($log->fresh()->notified_at)->not->toBeNull();

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    // Đúng một lần: lượt 1 hỏng không để lại một bản "đã gửi" nào cho người này.
    expect($sentRows)->toBe(1);
});

/**
 * Transport CHỌN LỌC: hỏng cho đúng một địa chỉ, thành công cho những địa chỉ khác — dựng lại
 * chính kịch bản của `notify/notify-1`: "khách có hai tài khoản, SMTP chết giữa chừng, người đầu
 * đã nhận thư".
 */
class StageUpdateSelectiveFailTransport implements TransportInterface
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

        return new SymfonySentMessage($message, new SymfonyEnvelope(
            new Address('gui@vidu.test'),
            [new Address('nhan@vidu.test')],
        ));
    }

    public function __toString(): string
    {
        return 'stage-update-selective-fail://';
    }
}

function stageUpdateSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.stage_update_selective_fail', ['transport' => 'stage_update_selective_fail']);
    Mail::extend('stage_update_selective_fail', fn (): TransportInterface => new StageUpdateSelectiveFailTransport($failingAddress));

    return 'stage_update_selective_fail';
}

/**
 * `notify/notify-1`, point (b) + (c) réunis: một người nhận hỏng KHÔNG chặn người còn lại trong
 * CÙNG lượt gọi, và một lượt thử lại (job hàng đợi retry) KHÔNG gửi thêm một bản cho người đã
 * nhận thành công ở lượt trước.
 *
 * Mutation probe (paste vào báo cáo): bỏ khối `if ($this->alreadyDelivered(...)) { continue; }`
 * khỏi `NotifyClientOfStageUpdate::handle()` — test này ĐỎ vì $secondAttemptSentToWife trở thành
 * 2, không phải 1.
 */
it('does not double-mail a recipient who already received the update once another recipient keeps failing on retry', function () {
    config(['mail.default' => stageUpdateSelectiveFailMailer('chong-fail@vidu.test')]);

    [$matter, , $wife] = publishedMatterWithClientAccount();
    $husband = ClientUser::factory()->activated()->create([
        'client_id' => $wife->client_id,
        'is_active' => true,
        'email' => 'chong-fail@vidu.test',
    ]);
    // Tạo SAU chồng: đứng SAU trong danh sách recipientsFor() (khoá tăng dần). Nếu handle() vẫn
    // dừng hẳn ở người hỏng đầu tiên (bug notify-1, điểm b) thì người này sẽ KHÔNG nhận được gì
    // ở lượt 1 — điều test này cũng phải bắt được, không chỉ chuyện gửi trùng ở lượt 2.
    $sibling = ClientUser::factory()->activated()->create([
        'client_id' => $wife->client_id,
        'is_active' => true,
    ]);

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng đã nộp hồ sơ và đang chờ toà án thụ lý vụ việc.',
    ]);

    // Lượt 1: vợ và người thứ ba nhận được, chồng (đứng GIỮA) hỏng — handle() phải ném lại,
    // NHƯNG cả hai người kia đã được gửi trong CÙNG lượt gọi này.
    try {
        app(NotifyClientOfStageUpdate::class)->handle($log);
    } catch (TransportException) {
        // Kỳ vọng: lượt gọi này thất bại vì địa chỉ của chồng — job hàng đợi thật sẽ thử lại.
    }

    expect($log->fresh()->notified_at)->toBeNull();

    // Lượt 2 (mô phỏng job hàng đợi thử lại): chồng vẫn hỏng.
    try {
        app(NotifyClientOfStageUpdate::class)->handle($log);
    } catch (TransportException) {
        //
    }

    $sentToWife = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $wife->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    $sentToSibling = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $sibling->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    $attemptsForHusband = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $husband->email)
        ->where('status', OutboundStatus::Failed)
        ->count();

    expect($sentToWife)->toBe(1)
        ->and($sentToSibling)->toBe(1)
        ->and($attemptsForHusband)->toBe(2);
});

/**
 * Carry-forward binding của Task 11: "queued client mail jobs re-check before sending" — vụ việc
 * bị huỷ (`CancelMatter`, Task 5, xoá mềm) GIỮA lúc listener được xếp hàng và lúc nó thật sự chạy
 * không được gửi thư, và không được ném lỗi. `recipientsFor()` đọc `$stageLog->matter?->client_id`
 * TƯƠI ngay lúc `handle()` chạy — quan hệ `BelongsTo` mang theo `SoftDeletingScope` của `Matter`
 * nên một vụ đã huỷ tự trả `null`, không cần thêm điều kiện nào khác.
 */
it('skips silently, without error, when the matter is cancelled between queuing and running the job', function () {
    Mail::fake();

    $admin = User::factory()->withRole(Role::Admin)->create();
    [$matter, , $account] = publishedMatterWithClientAccount();

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng đã nộp hồ sơ và đang theo dõi tiến độ xử lý tại toà án.',
    ]);

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm hồ sơ, huỷ ngay trước khi job kịp chạy.');

    $sent = app(NotifyClientOfStageUpdate::class)->handle($log);

    expect($sent)->toBe(0)
        ->and($log->fresh()->notified_at)->toBeNull();
    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 12 (`notify/notify-10`, `spec-gap/spec-gap-09`) — liên kết cổng khách trong thư
// KHÔNG được lấy theo host của request hiện tại (thư này được dựng SAU một request Livewire ở
// /admin, dù giờ đã qua job hàng đợi kể từ Task 11 — xem docblock StageUpdate/BrandedMailable).
// ---------------------------------------------------------------------------------------------

/**
 * Probe E của kiểm toán tái hiện lại: đặt ADMIN_DOMAIN/PORTAL_DOMAIN khác nhau, và GIẢ LẬP thư
 * được dựng ngay sau một request tới host quản trị (đúng đường thật, dù nay thư nằm trong job).
 * Liên kết trong thư phải trỏ PORTAL_DOMAIN, không phải host của request đó.
 *
 * Mutation probe: xem báo cáo — đổi `PortalUrl::base()` về lại `url('/portal')` cũ làm chính test
 * này đỏ (liên kết quay về host quản trị).
 */
it('builds the portal link from PORTAL_DOMAIN, not the host of the current (admin) request', function () {
    config([
        'vkcrm.admin_domain' => 'quantri.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);

    app()->instance('request', Request::create('https://quantri.luatvukhang.test/admin/matters/1'));

    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    $html = (new StageUpdate($stageLog, $recipient))->render();

    // Chỉ đối chiếu LIÊN KẾT CỔNG, không đối chiếu toàn bộ HTML: logo thư (`asset()` ở
    // layout.blade.php) cũng đọc theo request hiện tại — một vấn đề cosmetic KHÁC, nằm ngoài
    // phạm vi Task 12 (brief chỉ nêu `StageUpdate.php` cho liên kết cổng, không nêu asset()).
    // Scheme lấy theo APP_URL của môi trường test (không hardcode https): xem PortalUrl::scheme().
    $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';

    expect($html)->toContain('href="'.$scheme.'://khachhang.luatvukhang.test/portal"')
        ->and($html)->not->toContain('href="'.$scheme.'://quantri.luatvukhang.test/portal"');
});

/** Cặp dương: một tên miền (không tách ADMIN_DOMAIN/PORTAL_DOMAIN) thì liên kết vẫn dựng đúng. */
it('still builds a working portal link when a single domain serves both panels', function () {
    config(['vkcrm.admin_domain' => null, 'vkcrm.portal_domain' => null]);

    $stageLog = StageLog::factory()->create();
    $recipient = ClientUser::factory()->create();

    $html = (new StageUpdate($stageLog, $recipient))->render();

    expect($html)->toContain(rtrim(config('app.url'), '/').'/portal');
});
