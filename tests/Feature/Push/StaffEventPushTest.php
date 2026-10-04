<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Actions\Schedule\CheckStaleMatters;
use App\Actions\Schedule\RemindMissingDocuments;
use App\Actions\Schedule\RemindOverdueInstalments;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Portal\Pages\MyRequests;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Mail\Client\MissingDocuments;
use App\Mail\OutboundHeaders;
use App\Mail\Staff\DeadlineReminder;
use App\Mail\Staff\InstalmentOverdue;
use App\Mail\Staff\NewClientDocument as NewClientDocumentMail;
use App\Mail\Staff\NewClientRequest as NewClientRequestMail;
use App\Mail\Staff\StaleMatterReminder;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\PushAlert;
use App\Notifications\Staff\ClientRequestFollowUpAlert;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
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
| M12 Task 9 — thông báo đẩy cho các sự kiện của nhân sự (R10, R11; Review Focus 2, 3)
|--------------------------------------------------------------------------
|
| Năm đường sản phẩm, mỗi đường đi đúng lối mà nó chạy ở văn phòng — không gọi thẳng Action thư:
|  - mốc thời hạn (`staff.deadline_reminder`): tác vụ `CheckDeadlines` THẬT, job thư chạy ngay sau
|    commit (hàng đợi `sync` của `phpunit.xml`);
|  - khách gửi yêu cầu (`staff.new_client_request`): trang "Yêu cầu" của cổng khách (Livewire);
|  - khách hỏi tiếp vào luồng cũ (`REQ-2`, cùng chủ đề): cùng trang — KHÔNG có thư, push đi cùng
|    thông báo trong hệ thống `ClientRequestFollowUpAlert`;
|  - khách nộp giấy tờ (`staff.new_client_document`): trang "Nộp giấy tờ" của cổng (Livewire);
|  - đợt thanh toán quá hạn (`staff.instalment_overdue`, M9 — phán quyết (e)): tác vụ
|    `RemindOverdueInstalments` THẬT.
| "Gói bàn giao đã sinh" (M7 Task 4, `staff.handover_ready`) chưa có trên nhánh này: mang sang lúc
| gộp M7 (Ghi chú M12, Task 9).
|
| R10: push không có luật người nhận của riêng nó. Nơi gửi thư gọi `SendPushAlert` với ĐÚNG những
| người mà CHÍNH lượt đó vừa gửi thư thành công (phán quyết (d)), nên tập người nhận push đúng bằng
| tập người nhận thư (`ResolveStaffRecipients`, R3 — gồm luật vụ `restricted`), và chống trùng là
| trí nhớ sẵn có của thư (`reminders_sent`, sổ thư).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(WebPushTestKeys::config());
    Storage::fake('private');
    config(['media-library.prefix' => 'test-'.Str::random(16)]);
    // Giờ cố định trong ngày: các bậc mốc hạn và cửa sổ 7 ngày của nhắc đợt thu tính theo ngày lịch.
    $this->travelTo(today()->setTime(8, 0));
});

/** @return array<string, string> chỗ nhạy cảm => chuỗi đánh dấu (Review Focus 2) */
function staffPushMarkers(): array
{
    return [
        'tiêu đề vụ' => 'DAUVET9-TIEUDE-VU',
        'mã hồ sơ' => 'DAUVET9-MAHS',
        'tên khách' => 'DAUVET9-TEN-KHACH',
        'tên các bên' => 'DAUVET9-BEN-KIA',
        'tên toà' => 'DAUVET9-TOA',
        'số thụ lý' => 'DAUVET9-THULY',
        'tên mốc hạn' => 'DAUVET9-MOC-HAN',
        'tiêu đề yêu cầu' => 'DAUVET9-TIEUDE-YEUCAU',
        'nội dung yêu cầu' => 'DAUVET9-NOIDUNG-YEUCAU',
        'câu hỏi tiếp' => 'DAUVET9-HOI-TIEP',
        'tên đầu mục giấy tờ' => 'DAUVET9-DAUMUC',
        'tên tệp' => 'DAUVET9-TENTEP',
        'tên đợt thu' => 'DAUVET9-DOT-THU',
        'ghi chú đợt thu' => 'DAUVET9-GHICHU-DOT',
        'mã hợp đồng' => 'DAUVET9-MAHD',
        'số tiền' => '987654321',
    ];
}

/** @return array<string, array{0: string}> năm đường của bảng R10 phía nhân sự đã có trên nhánh này */
function staffPushPaths(): array
{
    return [
        'mốc thời hạn' => ['deadline'],
        'khách gửi yêu cầu' => ['new_request'],
        'khách hỏi tiếp vào luồng cũ' => ['follow_up'],
        'khách nộp giấy tờ' => ['new_document'],
        'đợt thanh toán quá hạn' => ['instalment'],
    ];
}

/** Bốn đường CÓ thư (REQ-2 không có thư nào để thử lại). */
function staffPushMailPaths(): array
{
    return array_diff_key(staffPushPaths(), ['khách hỏi tiếp vào luồng cũ' => true]);
}

function staffPushTopic(string $path): PushTopic
{
    return match ($path) {
        'deadline' => PushTopic::StaffDeadlineReminder,
        'new_request', 'follow_up' => PushTopic::StaffNewClientRequest,
        'new_document' => PushTopic::StaffNewClientDocument,
        'instalment' => PushTopic::StaffInstalmentOverdue,
    };
}

/** Mailable của đường `$path` — thư mà push đi cùng (`null`: REQ-2, không có thư). */
function staffPushMailable(string $path): ?string
{
    return match ($path) {
        'deadline' => DeadlineReminder::class,
        'new_request' => NewClientRequestMail::class,
        'follow_up' => null,
        'new_document' => NewClientDocumentMail::class,
        'instalment' => InstalmentOverdue::class,
    };
}

/**
 * Một vụ việc đang mở, đã công bố cổng, mang chuỗi đánh dấu ở mọi chỗ nhạy cảm, cùng MỌI vai trò
 * nhân sự — mỗi người MỘT MÁY đã bật, nên chỉ luật người nhận của THƯ quyết định ai nhận push:
 * luật sư phụ trách, trợ lý trong đội, luật sư phối hợp (trong đội, không phải trợ lý), quản lý,
 * admin, kế toán, và bốn tài khoản đã vô hiệu (một trợ lý trong đội, một quản lý, một kế toán, một
 * admin). Cộng một tài khoản cổng R12 của khách hàng sở hữu vụ (người gửi yêu cầu, nộp giấy tờ).
 *
 * `$suffix` cho mã hồ sơ khi một test dựng hai vụ (mã hồ sơ là duy nhất).
 *
 * @return array{matter: Matter, lead: User, assistant: User, associate: User, manager: User, admin: User, accountant: User, inactive: list<User>, account: ClientUser}
 */
function staffPushScenario(bool $restricted = false, string $suffix = ''): array
{
    $m = staffPushMarkers();
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $inactive = [
        User::factory()->withRole(Role::Assistant)->create(['is_active' => false]),
        User::factory()->withRole(Role::Manager)->create(['is_active' => false]),
        User::factory()->withRole(Role::Accountant)->create(['is_active' => false]),
        User::factory()->withRole(Role::Admin)->create(['is_active' => false]),
    ];
    $client = Client::factory()->create(['name' => $m['tên khách']]);
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);

    $factory = Matter::factory()->atStage('intake');
    $matter = ($restricted ? $factory->restricted() : $factory)->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lead->id,
        'is_published_to_portal' => true,
        'code' => $m['mã hồ sơ'].$suffix,
        'title' => $m['tiêu đề vụ'],
        'court_name' => $m['tên toà'],
        'case_number' => $m['số thụ lý'],
    ]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    $matter->addTeamMember($associate, MatterRole::Associate);
    $matter->addTeamMember($inactive[0], MatterRole::Assistant);
    MatterParty::factory()->for($matter)->create(['name' => $m['tên các bên']]);

    foreach ([$lead, $assistant, $associate, $manager, $admin, $accountant, ...$inactive] as $i => $user) {
        FakePushServer::device($user, 'nhan-su-'.$suffix.'-'.$i);
    }

    FakePushServer::device($account, 'khach-'.$suffix);

    return compact('matter', 'lead', 'assistant', 'associate', 'manager', 'admin', 'accountant', 'inactive', 'account');
}

/** @return list<User> mọi nhân sự của cảnh, kể cả tài khoản đã vô hiệu */
function staffPushEveryone(array $s): array
{
    return [$s['lead'], $s['assistant'], $s['associate'], $s['manager'], $s['admin'], $s['accountant'], ...$s['inactive']];
}

/**
 * Người nhận THƯ mà luật R3 cho ra ở mỗi đường (id tăng dần) — viết tay, để test đồng nhất còn đo
 * được nếu cả thư lẫn push cùng lệch.
 *
 * @return list<int>
 */
function staffPushExpected(string $path, array $s, bool $restricted): array
{
    $ids = array_map(fn (User $user): int => $user->id, match ($path) {
        // Bậc 1 ngày: người phụ trách mốc + giám sát (vụ thường: quản lý; vụ hạn chế: admin thay quản lý).
        'deadline' => [$s['lead'], $restricted ? $s['admin'] : $s['manager']],
        // Luật sư phụ trách + trợ lý trong đội; vụ hạn chế: trợ lý không xem được vụ.
        'new_request', 'new_document' => $restricted ? [$s['lead']] : [$s['lead'], $s['assistant']],
        // REQ-2: người đang giữ luồng, chưa ai giữ thì luật sư phụ trách.
        'follow_up' => [$s['lead']],
        // Thư tiền: luật sư phụ trách + kế toán; vụ hạn chế: admin thay kế toán.
        'instalment' => [$s['lead'], $restricted ? $s['admin'] : $s['accountant']],
    });
    sort($ids);

    return $ids;
}

/** Byte thật của một PDF tối thiểu — `FileGuard` đọc MIME bằng `finfo` trên nội dung tệp. */
function staffPushPdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

/** Mốc thời hạn của vụ, người phụ trách là luật sư phụ trách vụ (trừ khi nói khác). */
function staffPushDeadline(array $s, int $daysLeft, ?User $responsible = null, DeadlineSeverity $severity = DeadlineSeverity::Normal): Deadline
{
    return Deadline::factory()->create([
        'matter_id' => $s['matter']->id,
        'responsible_user_id' => ($responsible ?? $s['lead'])->id,
        'name' => staffPushMarkers()['tên mốc hạn'],
        'due_date' => today()->addDays($daysLeft),
        'severity' => $severity,
        'is_completed' => false,
        'reminders_sent' => [],
    ]);
}

/**
 * Làm đúng việc của đường `$path` qua lối thật của nó. Trả bản ghi mà THƯ đi cùng ghi vào nhật ký
 * (`relatedRecord()` của mailable; REQ-2: luồng yêu cầu) — push phải mang đúng bản ghi đó.
 */
function staffPushAct(string $path, array $s): Model
{
    $m = staffPushMarkers();

    if (in_array($path, ['new_request', 'follow_up', 'new_document'], true)) {
        Filament::setCurrentPanel('portal');
        test()->actingAs($s['account'], 'client');
    }

    return match ($path) {
        'deadline' => (function () use ($s): Deadline {
            $deadline = staffPushDeadline($s, 1);
            app(CheckDeadlines::class)->handle();

            return $deadline;
        })(),
        'new_request' => (function () use ($s, $m): ClientRequest {
            test()->livewire(MyRequests::class, ['record' => $s['matter']->getKey()])
                ->set('subject', 'Hỏi về '.$m['tiêu đề yêu cầu'])
                ->set('content', 'Văn phòng cho tôi hỏi: '.$m['nội dung yêu cầu'].'.')
                ->call('submitRequest')
                ->assertHasNoErrors();

            return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)
                ->where('matter_id', $s['matter']->id)->latest('id')->firstOrFail();
        })(),
        'follow_up' => (function () use ($s, $m): ClientRequest {
            $thread = ClientRequest::factory()->for($s['matter'])->create([
                'client_user_id' => $s['account']->id,
                'subject' => 'Hỏi về '.$m['tiêu đề yêu cầu'],
                'content' => 'Văn phòng cho tôi hỏi: '.$m['nội dung yêu cầu'].'.',
                'status' => ClientRequestStatus::Answered,
            ]);

            test()->livewire(MyRequests::class, ['record' => $s['matter']->getKey()])
                ->set('replies.'.$thread->id, 'Tôi hỏi thêm: '.$m['câu hỏi tiếp'].'.')
                ->call('submitReply', $thread->id)
                ->assertHasNoErrors();

            return $thread;
        })(),
        'new_document' => (function () use ($s, $m): Document {
            $item = MatterChecklistItem::factory()->for($s['matter'])->create([
                'name' => $m['tên đầu mục giấy tờ'],
                'status' => ChecklistItemStatus::Missing,
            ]);

            test()->livewire(SubmitDocument::class, ['record' => $s['matter']->getKey()])
                ->call('chooseItem', $item->getKey())
                ->set('data.file', staffPushPdf($m['tên tệp'].'.pdf'))
                ->call('submit')
                ->assertHasNoErrors();

            return Document::query()->withoutGlobalScope(ClientPortalScope::class)
                ->where('matter_id', $s['matter']->id)->sole();
        })(),
        'instalment' => (function () use ($s, $m): Instalment {
            $contract = Contract::factory()->for($s['matter'])->active()->create([
                'code' => $m['mã hợp đồng'],
                'total_amount' => (int) $m['số tiền'],
            ]);
            $instalment = Instalment::factory()->for($contract)->create([
                'name' => $m['tên đợt thu'],
                'note' => $m['ghi chú đợt thu'],
                'amount' => (int) $m['số tiền'],
                'due_date' => today()->subDays(3)->toDateString(),
            ]);
            app(RemindOverdueInstalments::class)->handle();

            return $instalment;
        })(),
    };
}

/**
 * Ai nhận thư (theo `Mail::fake()`; REQ-2: ai nhận `ClientRequestFollowUpAlert`) và ai nhận push
 * (theo `Notification::fake()`), id tăng dần.
 *
 * @param  list<User>  $users
 * @return array{mailed: list<int>, pushed: list<int>}
 */
function staffPushDelivered(string $path, array $users): array
{
    $mailable = staffPushMailable($path);
    $mailed = [];
    $pushed = [];

    foreach ($users as $user) {
        $told = $mailable === null
            ? Notification::sent($user, ClientRequestFollowUpAlert::class)->isNotEmpty()
            : Mail::sent($mailable, fn ($mail): bool => $mail->hasTo($user->email))->isNotEmpty();

        if ($told) {
            $mailed[] = $user->id;
        }

        if (Notification::sent($user, PushAlert::class)->isNotEmpty()) {
            $pushed[] = $user->id;
        }
    }

    sort($mailed);
    sort($pushed);

    return ['mailed' => $mailed, 'pushed' => $pushed];
}

/** Số `PushAlert` đã xếp cho từng người (theo `Notification::fake()`), theo thứ tự đưa vào. */
function staffPushCounts(User ...$users): array
{
    return array_map(fn (User $user): int => Notification::sent($user, PushAlert::class)->count(), $users);
}

/** Trang vụ việc, mở sẵn tab của `$relationManager` — đường dẫn tương đối như `data.url`. */
function staffPushTab(Matter $matter, string $relationManager): string
{
    return "/admin/matters/{$matter->id}?relation=".array_search($relationManager, MatterResource::getRelations(), true);
}

/** Path + query của một URL tuyệt đối. */
function staffPushRelative(string $url): string
{
    $query = parse_url($url, PHP_URL_QUERY);

    return parse_url($url, PHP_URL_PATH).($query === null ? '' : '?'.$query);
}

/**
 * Plan Task 9: "Khách gửi yêu cầu, khách hỏi tiếp vào luồng cũ, khách nộp giấy tờ, gói bàn giao sinh
 * xong: tập người nhận push bằng tập người nhận thư." Cộng mốc thời hạn và đợt thu quá hạn (phán
 * quyết (e)); gói bàn giao mang sang lúc gộp M7. Mỗi đường trên một vụ THƯỜNG và một vụ `restricted`
 * (Review Focus 3: manager, kế toán, trợ lý không xem được vụ thì không thư, không push; admin thay
 * họ ở đúng chỗ luật R3 nói), và mọi tài khoản đã vô hiệu — mọi người đều đã bật máy.
 *
 * Cùng test: push mang ĐÚNG chủ đề và bản ghi mà thư ghi vào nhật ký (header `X-VKCRM-Template` /
 * `X-VKCRM-Related`; REQ-2: luồng yêu cầu), mở đúng trang và tab (đợt thu: đúng nơi liên kết của
 * chính thư trỏ tới, theo người nhận), và payload không lộ chuỗi đánh dấu nào (Review Focus 2) — kể
 * cả câu hỏi khách vừa gõ, tên tệp vừa nộp, tên đợt và số tiền.
 *
 * Mutation probe (báo cáo Task 9): bỏ lời gọi `SendPushAlert` ở nơi gửi thư của một đường → đường
 * đó ĐỎ.
 */
it('pushes exactly the staff it mails, about the same record, with nothing of the case on the lock screen', function (string $path, bool $restricted) {
    Mail::fake();
    Notification::fake();
    $s = staffPushScenario($restricted);

    $related = staffPushAct($path, $s);

    $delivered = staffPushDelivered($path, staffPushEveryone($s));

    expect($delivered['pushed'])->toBe($delivered['mailed'])
        ->and($delivered['mailed'])->toBe(staffPushExpected($path, $s, $restricted));

    foreach (User::query()->whereKey($delivered['pushed'])->get() as $user) {
        $alert = Notification::sent($user, PushAlert::class)->sole();

        expect($alert->message->topic)->toBe(staffPushTopic($path)->value)
            ->and($alert->message->relatedType)->toBe($related->getMorphClass())
            ->and($alert->message->relatedId)->toBe($related->getKey());

        if ($path === 'follow_up') {
            Notification::assertSentTo($user, ClientRequestFollowUpAlert::class);
        } else {
            $mail = Mail::sent(staffPushMailable($path), fn ($mail): bool => $mail->hasTo($user->email))->sole();
            $headers = $mail->headers()->text;

            expect($alert->message->topic)->toBe($headers[OutboundHeaders::TEMPLATE])
                ->and($alert->message->relatedType.':'.$alert->message->relatedId)->toBe($headers[OutboundHeaders::RELATED]);
        }

        expect($alert->message->url)->toBe(match ($path) {
            'deadline' => staffPushTab($s['matter'], DeadlinesRelationManager::class),
            'new_request', 'follow_up' => staffPushTab($s['matter'], ClientRequestsRelationManager::class),
            'new_document' => staffPushTab($s['matter'], ChecklistRelationManager::class),
            'instalment' => staffPushRelative($mail->content()->with['linkUrl']),
        });

        $payload = json_encode($alert->toWebPush($user, $alert)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (staffPushMarkers() as $where => $marker) {
            expect(str_contains($payload, $marker))->toBeFalse("Payload của {$path} lộ {$where}");
        }
    }
})->with(staffPushPaths())->with([
    'vụ thường' => false,
    'vụ hạn chế' => true,
]);

/**
 * Plan Task 9: "Mốc hạn bậc 1 ngày của vụ `restricted`: admin nhận push, manager không nhận (M6.5
 * R3). Người phụ trách bị vô hiệu: push đi theo chuỗi dự phòng, đúng như thư." Người phụ trách MỐC ở
 * đây là luật sư phối hợp trong đội (không phải luật sư phụ trách vụ), đã bị vô hiệu — ô của họ do
 * luật sư phụ trách vụ thế chỗ (`CheckDeadlines::recipientsFor()`); khi cả hai đều vô hiệu, giám sát
 * của vụ nhận. Push đi đúng theo chuỗi đó, vì nó chỉ là người nhận của thư.
 */
it('follows the mail down the fallback chain when the person responsible has been deactivated', function (bool $restricted, int $daysLeft, bool $leadToo, Closure $expected) {
    Mail::fake();
    Notification::fake();
    $s = staffPushScenario($restricted);
    $s['associate']->update(['is_active' => false]);

    if ($leadToo) {
        $s['lead']->update(['is_active' => false]);
    }

    staffPushDeadline($s, $daysLeft, responsible: $s['associate']);
    app(CheckDeadlines::class)->handle();

    $delivered = staffPushDelivered('deadline', staffPushEveryone($s));
    $ids = array_map(fn (User $user): int => $user->id, $expected($s));
    sort($ids);

    expect($delivered['pushed'])->toBe($delivered['mailed'])
        ->and($delivered['mailed'])->toBe($ids)
        ->and(staffPushCounts($s['associate'], $s['manager'])[0])->toBe(0);

    if ($restricted) {
        expect(staffPushCounts($s['manager'], $s['admin']))->toBe([0, 1]);
    }
})->with([
    'vụ hạn chế, bậc 1 ngày: luật sư phụ trách vụ thế chỗ, cạnh admin (không quản lý)' => [true, 1, false, fn (array $s): array => [$s['lead'], $s['admin']]],
    'vụ thường, bậc 7 ngày: luật sư phụ trách vụ thế chỗ' => [false, 7, false, fn (array $s): array => [$s['lead']]],
    'vụ thường, bậc 7 ngày, luật sư phụ trách vụ cũng đã nghỉ: quản lý' => [false, 7, true, fn (array $s): array => [$s['manager']]],
]);

/**
 * Plan Task 9: "`CheckDeadlines` chạy hai lần: mỗi bậc một push." Một mốc đi hết đời nó — 7 ngày, 3
 * ngày, 1 ngày, quá hạn — mỗi ngày tác vụ chạy HAI lần (cron chạy trùng, người quản trị chạy tay):
 * người phụ trách nhận đúng bốn push, một cho mỗi bậc, như bốn thư. Chống trùng là
 * `reminders_sent` (M6 R3), không trí nhớ mới. Câu chữ và độ khẩn đi theo bậc; `tag` giữ nguyên
 * (chủ đề + mốc), nên tin mới thay tin cũ của cùng mốc trên màn hình.
 */
it('pushes once per tier however often CheckDeadlines runs', function () {
    Mail::fake();
    Notification::fake();
    $s = staffPushScenario();
    $deadline = staffPushDeadline($s, 7);

    foreach ([0, 4, 2, 2] as $days) {
        $this->travel($days)->days();
        app(CheckDeadlines::class)->handle();
        app(CheckDeadlines::class)->handle();
    }

    $alerts = Notification::sent($s['lead'], PushAlert::class)->values();

    expect($alerts)->toHaveCount(4)
        ->and(Mail::sent(DeadlineReminder::class, fn ($mail): bool => $mail->hasTo($s['lead']->email)))->toHaveCount(4)
        ->and($alerts->map(fn (PushAlert $alert): string => $alert->message->body)->all())->toBe([
            __('push.alerts.staff.deadline.upcoming'),
            __('push.alerts.staff.deadline.upcoming'),
            __('push.alerts.staff.deadline.imminent'),
            __('push.alerts.staff.deadline.overdue'),
        ])
        ->and($alerts->map(fn (PushAlert $alert): string => $alert->message->urgency)->all())->toBe(['normal', 'normal', 'high', 'high'])
        ->and($alerts->map(fn (PushAlert $alert): string => $alert->message->tag)->unique()->all())->toBe(['staff.deadline_reminder:'.$deadline->id]);
});

/**
 * Plan Task 9: "Mốc bậc 1 ngày và quá hạn có `urgency = high`; bậc 7 ngày có `normal`." Đi đường
 * thật — bậc do `CheckDeadlines::tierFor()` chọn, job thư chuyển nó cho push — chứ không gọi
 * `PushTopic` với một bậc tự chọn. TTL 24 giờ ở mọi bậc (R11).
 *
 * Mutation probe (báo cáo Task 9): job thư đưa một bậc cố định thay cho `$this->tierKey` → ĐỎ.
 */
it('marks the one-day and overdue deadline pushes urgent and the earlier tiers normal', function (int $daysLeft, DeadlineSeverity $severity, string $urgency) {
    Mail::fake();
    Notification::fake();
    $s = staffPushScenario();
    staffPushDeadline($s, $daysLeft, severity: $severity);

    app(CheckDeadlines::class)->handle();

    $alert = Notification::sent($s['lead'], PushAlert::class)->sole();

    expect($alert->toWebPush($s['lead'], $alert)->getOptions())->toBe(['TTL' => 86400, 'urgency' => $urgency]);
})->with([
    'bậc 14 ngày (mốc quan trọng)' => [14, DeadlineSeverity::Critical, 'normal'],
    'bậc 7 ngày' => [7, DeadlineSeverity::Normal, 'normal'],
    'bậc 3 ngày' => [3, DeadlineSeverity::Normal, 'normal'],
    'bậc 1 ngày' => [1, DeadlineSeverity::Normal, 'high'],
    'hết hạn hôm nay' => [0, DeadlineSeverity::Normal, 'high'],
    'đã quá hạn' => [-1, DeadlineSeverity::Normal, 'high'],
]);

/**
 * Job thư mốc hạn đưa bậc của nó cho `SendPushAlert`, mà `PushTopic::assertAccepts()` ném với một bậc
 * lạ (lỗi lập trình) — SAU khi thư đã đi, nên job hỏng, thử lại, rồi `failed()` rút bậc và rung
 * chuông "nhắc hạn thất bại" cho một lời nhắc đã tới nơi. Lưới: mọi bậc mà `CheckDeadlines::tierFor()`
 * có thể trả (mốc thường và mốc quan trọng, từ quá hạn tới ngoài mọi bậc) đúng bằng
 * `PushTopic::DEADLINE_TIERS` — thêm một bậc ở `CheckDeadlines` mà quên `PushTopic` là test này đỏ.
 */
it('knows every tier CheckDeadlines can hand to the reminder job', function () {
    $s = staffPushScenario();
    $tiers = [];

    foreach ([DeadlineSeverity::Normal, DeadlineSeverity::Critical] as $severity) {
        foreach (range(-3, 30) as $daysLeft) {
            $tiers[] = app(CheckDeadlines::class)->tierFor(staffPushDeadline($s, $daysLeft, severity: $severity));
        }
    }

    $tiers = array_values(array_unique(array_filter($tiers)));
    sort($tiers);
    $known = PushTopic::DEADLINE_TIERS;
    sort($known);

    expect($tiers)->toBe($known);
});

/**
 * Plan Task 9: "Payload của mốc hạn vụ `restricted` không lộ gì hơn payload của vụ thường." Hai vụ
 * mang cùng chuỗi đánh dấu, mỗi vụ một mốc bậc 1 ngày; payload mà luật sư phụ trách của mỗi vụ nhận
 * — bỏ đi id của mốc và của vụ — trùng nhau từng ký tự, và không chứa chuỗi đánh dấu nào.
 */
it('tells the lock screen no more about a restricted deadline than about an ordinary one', function () {
    Mail::fake();
    Notification::fake();
    $standard = staffPushScenario(false, '-T');
    $restricted = staffPushScenario(true, '-H');
    $standardDeadline = staffPushDeadline($standard, 1);
    $restrictedDeadline = staffPushDeadline($restricted, 1);

    app(CheckDeadlines::class)->handle();

    $payload = function (array $s, Deadline $deadline): string {
        $alert = Notification::sent($s['lead'], PushAlert::class)->sole();
        $json = json_encode($alert->toWebPush($s['lead'], $alert)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (staffPushMarkers() as $where => $marker) {
            expect(str_contains($json, $marker))->toBeFalse("Payload lộ {$where}");
        }

        return str_replace(
            ['staff.deadline_reminder:'.$deadline->id.'"', '/matters/'.$s['matter']->id.'?'],
            ['staff.deadline_reminder:{mốc}"', '/matters/{vụ}?'],
            $json,
        );
    };

    expect($payload($restricted, $restrictedDeadline))
        ->toBe($payload($standard, $standardDeadline))
        ->toContain('{mốc}')
        ->toContain('{vụ}');
});

/**
 * Transport CHỌN LỌC: hỏng cho đúng một địa chỉ, gửi được cho mọi địa chỉ khác — đi đường cấu hình
 * thật của Laravel, nên `OutboundLedgerTransport` ghi sổ thư như ở máy chủ thật (cùng lối
 * `ClientPushSelectiveFailTransport` của Task 8).
 */
class StaffPushSelectiveFailTransport implements TransportInterface
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
        return 'staff-push-selective-fail://';
    }
}

function staffPushSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.staff_push_selective_fail', ['transport' => 'staff_push_selective_fail']);
    Mail::extend('staff_push_selective_fail', fn (): TransportInterface => new StaffPushSelectiveFailTransport($failingAddress));

    return 'staff_push_selective_fail';
}

/** Dòng nhật ký `sent`/`failed` của kênh email cho một địa chỉ. */
function staffPushMailRows(string $email, OutboundStatus $status): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('channel', OutboundChannel::Email)
        ->where('recipient', $email)
        ->where('status', $status)
        ->count();
}

/**
 * Rút hàng đợi `database` bằng worker THẬT, mỗi lần MỘT job (`--once`) — cùng lý do
 * `clientPushDrainQueue()` của Task 8. Dừng khi không còn job nào tới hạn.
 */
function staffPushDrainQueue(): void
{
    for ($guard = 0; $guard < 20; $guard++) {
        if (! DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->exists()) {
            return;
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }
}

/**
 * Phán quyết (d) của controller: push đi cho những người mà CHÍNH lượt đó vừa gửi thư được; chống
 * trùng là sổ thư. Đường thật: hàng đợi `database`, worker thật, transport hỏng đúng địa chỉ của
 * người nhận thứ hai ở lượt đầu (quản lý ở mốc hạn, trợ lý ở yêu cầu/giấy tờ, kế toán ở đợt thu).
 *  - Lượt 1: thư của luật sư phụ trách đi → có push; thư của người thứ hai hỏng → KHÔNG push, job
 *    (listener) ném lại để hàng đợi thử lại.
 *  - Lượt 2 (sau backoff, SMTP đã sống): luật sư phụ trách đã có dòng `sent` → không thư thứ hai,
 *    KHÔNG push thứ hai; thư của người thứ hai đi → có push.
 *
 * Mutation probe (báo cáo Task 9): đưa nguyên `$recipients` vào `SendPushAlert` thay vì những người
 * vừa nhận thư → luật sư phụ trách nhận push lần hai ở lượt 2, ĐỎ; xếp push cho người nhận TRƯỚC khi
 * thư của họ đi → người thứ hai nhận push ở lượt 1, ĐỎ; đặt lời gọi push SAU lần ném lại → luật sư
 * phụ trách không bao giờ nhận push, ĐỎ.
 */
it('pushes each staff member once, right after their own mail went through, across a queue retry', function (string $path) {
    config(['queue.default' => 'database']);
    Notification::fake();
    $s = staffPushScenario();
    $second = match ($path) {
        'deadline' => $s['manager'],
        'new_request', 'new_document' => $s['assistant'],
        'instalment' => $s['accountant'],
    };
    config(['mail.default' => staffPushSelectiveFailMailer($second->email)]);

    staffPushAct($path, $s);

    // Tác vụ (hay request của khách) chỉ xếp job thư; chưa thư, chưa push.
    expect(staffPushCounts($s['lead'], $second))->toBe([0, 0]);

    staffPushDrainQueue();

    expect(staffPushCounts($s['lead'], $second))->toBe([1, 0])
        ->and(staffPushMailRows($s['lead']->email, OutboundStatus::Sent))->toBe(1)
        ->and(staffPushMailRows($second->email, OutboundStatus::Failed))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);

    config(['mail.default' => 'array']);
    $this->travel(61)->seconds();
    staffPushDrainQueue();

    expect(staffPushCounts($s['lead'], $second))->toBe([1, 1])
        ->and(staffPushMailRows($s['lead']->email, OutboundStatus::Sent))->toBe(1)
        ->and(staffPushMailRows($second->email, OutboundStatus::Sent))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with(staffPushMailPaths());

/**
 * Plan Task 9: "Không có push cho `staff.stale_matter` và `client.missing_documents` (R10). Có test
 * ghim điều đó, để một người về sau muốn thêm phải sửa test một cách có chủ ý." Đường thật của hai
 * tác vụ (`CheckStaleMatters` mốc 21 ngày, `RemindMissingDocuments`): thư ĐI (vế dương — tác vụ đã
 * chạy tới người nhận), mọi người nhận đã bật máy, và không một `PushAlert` nào được xếp.
 *
 * Mutation probe (báo cáo Task 9): thêm một lời gọi `SendPushAlert` vào job thư của một trong hai
 * (`SendStaleMatterMail`, `SendMissingDocumentsMail`) → ĐỎ.
 */
it('never pushes the reminders that R10 keeps to email: stale matters and missing documents', function () {
    Mail::fake();
    Notification::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $stale = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(22),
    ]);

    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $awaiting = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($awaiting)->status(ChecklistItemStatus::Missing)->create(['is_required' => true]);
    $item->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

    FakePushServer::device($lawyer, 'luat-su');
    FakePushServer::device($manager, 'quan-ly');
    FakePushServer::device($account, 'khach');

    app(CheckStaleMatters::class)->handle();
    app(RemindMissingDocuments::class)->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail): bool => $mail->hasTo($lawyer->email) && $mail->matter->is($stale));
    Mail::assertSent(StaleMatterReminder::class, fn ($mail): bool => $mail->hasTo($manager->email));
    Mail::assertSent(MissingDocuments::class, fn ($mail): bool => $mail->hasTo($account->email) && $mail->matter->is($awaiting));

    expect(Notification::sent($lawyer, PushAlert::class))->toBeEmpty()
        ->and(Notification::sent($manager, PushAlert::class))->toBeEmpty()
        ->and(Notification::sent($account, PushAlert::class))->toBeEmpty();
});
