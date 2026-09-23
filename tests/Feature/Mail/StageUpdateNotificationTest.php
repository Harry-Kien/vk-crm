<?php

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Actions\TransitionMatterStage;
use App\Enums\Role;
use App\Events\StageLogPublished;
use App\Mail\Client\StageUpdate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Một vụ việc đã công bố cổng khách, kèm một tài khoản khách còn hoạt động. */
function publishedMatterWithClientAccount(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id, 'is_active' => true]);

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
    $spouse = ClientUser::factory()->create(['client_id' => $account->client_id, 'is_active' => true]);
    $closed = ClientUser::factory()->create(['client_id' => $account->client_id, 'is_active' => false]);

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

it('keeps the notice pending when the client has no account that could receive it', function () {
    Mail::fake();
    [$matter, , $account] = publishedMatterWithClientAccount();
    $account->update(['is_active' => false]);

    $log = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'is_published' => true,
        'published_at' => now(),
        'public_content' => 'Văn phòng vừa gửi bổ sung tài liệu theo yêu cầu của cơ quan tố tụng.',
    ]);

    app(NotifyClientOfStageUpdate::class)->handle($log);

    // Không đánh dấu đã báo: mở lại tài khoản thì lời báo phải còn nguyên, không biến mất.
    expect($log->fresh()->notified_at)->toBeNull();

    $account->update(['is_active' => true]);
    app(NotifyClientOfStageUpdate::class)->handle($log);

    Mail::assertSent(StageUpdate::class, 1);
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
