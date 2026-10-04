<?php

use App\Actions\Push\SendPushAlert;
use App\Enums\ChecklistItemStatus;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Filament\Admin\Pages\PushDevices as AdminPushDevices;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Portal\Pages\PushDevices as PortalPushDevices;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\StageLog;
use App\Models\User;
use App\Notifications\PushAlert;
use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePushServer;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 7 — `PushTopic`: nội dung đẩy của MỌI chủ đề (R10, R11; Review Focus 2)
|--------------------------------------------------------------------------
|
| Màn hình khoá không phải màn hình của văn phòng. Mỗi chủ đề được dựng qua ĐÚNG đường sản phẩm
| (`SendPushAlert` → `PushAlert::toWebPush()`), trên một vụ việc mà MỌI chỗ nhạy cảm mang một chuỗi
| đánh dấu: tiêu đề vụ, mã hồ sơ, tên khách, tên các bên, tiêu đề tài liệu, tên đầu mục giấy tờ, lý
| do từ chối, nội dung yêu cầu và câu trả lời, `internal_note`, tên toà, số thụ lý, tên mốc hạn, và
| (Task 9) tên đợt thu, ghi chú đợt, mã hợp đồng, số tiền.
| Không chuỗi nào được phép có mặt trong payload — kể cả ở `tag` hay `data.url`.
*/

beforeEach(function () {
    config(WebPushTestKeys::config());
    Notification::fake();
});

/** @return array<string, string> chỗ nhạy cảm => chuỗi đánh dấu */
function pushTopicMarkers(): array
{
    return [
        'tiêu đề vụ' => 'DAUVET-TIEUDE-VU',
        'mã hồ sơ' => 'DAUVET-MAHS',
        'tên khách' => 'DAUVET-TEN-KHACH',
        'tên các bên' => 'DAUVET-BEN-KIA',
        'tiêu đề tài liệu' => 'DAUVET-TIEUDE-TAILIEU',
        'tên đầu mục giấy tờ' => 'DAUVET-DAUMUC',
        'lý do từ chối' => 'DAUVET-LYDO-TUCHOI',
        'tiêu đề yêu cầu' => 'DAUVET-TIEUDE-YEUCAU',
        'nội dung yêu cầu' => 'DAUVET-NOIDUNG-YEUCAU',
        'nội dung trả lời' => 'DAUVET-TRALOI',
        'internal_note' => 'DAUVET-GHICHU-NOIBO',
        'nội dung công bố' => 'DAUVET-CONGBO',
        'tên toà' => 'DAUVET-TOA',
        'số thụ lý' => 'DAUVET-THULY',
        'tên mốc hạn' => 'DAUVET-MOC-HAN',
        // M12 Task 9 — `staff.instalment_overdue`: thư mang tên đợt, số tiền, mã hồ sơ, tên khách; push thì không.
        'tên đợt thu' => 'DAUVET-DOT-THU',
        'ghi chú đợt thu' => 'DAUVET-GHICHU-DOT',
        'mã hợp đồng' => 'DAUVET-MAHD',
        'số tiền' => '987654321',
    ];
}

/** Vụ việc mang chuỗi đánh dấu ở mọi chỗ nhạy cảm. */
function pushTopicMarkedMatter(): Matter
{
    $m = pushTopicMarkers();
    $client = Client::factory()->create(['name' => $m['tên khách']]);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'code' => $m['mã hồ sơ'],
        'title' => $m['tiêu đề vụ'],
        'court_name' => $m['tên toà'],
        'case_number' => $m['số thụ lý'],
    ]);
    MatterParty::factory()->for($matter)->create(['name' => $m['tên các bên']]);

    return $matter;
}

/**
 * Bản ghi liên quan đúng loại của chủ đề (cùng bản ghi mà THƯ tương ứng mang), trên vụ đã đánh dấu.
 */
function pushTopicRelated(PushTopic $topic, Matter $matter): ?Model
{
    $m = pushTopicMarkers();
    $account = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);
    $item = fn (): MatterChecklistItem => MatterChecklistItem::factory()->for($matter)->create([
        'name' => $m['tên đầu mục giấy tờ'],
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => $m['lý do từ chối'],
    ]);
    $thread = fn (): ClientRequest => ClientRequest::factory()->create([
        'matter_id' => $matter->id,
        'client_user_id' => $account->id,
        'subject' => $m['tiêu đề yêu cầu'],
        'content' => $m['nội dung yêu cầu'],
    ]);

    return match ($topic) {
        PushTopic::ClientStageUpdate => StageLog::factory()->create([
            'matter_id' => $matter->id,
            'internal_note' => $m['internal_note'],
            'public_content' => $m['nội dung công bố'],
        ]),
        PushTopic::ClientDocumentPublished => Document::factory()->create(['matter_id' => $matter->id, 'title' => $m['tiêu đề tài liệu']]),
        PushTopic::ClientDocumentRejected => $item(),
        PushTopic::ClientRequestAnswered => ClientRequestReply::factory()->create([
            'request_id' => $thread()->id,
            'content' => $m['nội dung trả lời'],
        ]),
        PushTopic::StaffDeadlineReminder => Deadline::factory()->create(['matter_id' => $matter->id, 'name' => $m['tên mốc hạn']]),
        PushTopic::StaffNewClientRequest => $thread(),
        PushTopic::StaffNewClientDocument => Document::factory()->pendingReview()->uploadedBy($account)->create([
            'matter_id' => $matter->id,
            'matter_checklist_item_id' => $item()->id,
            'title' => $m['tiêu đề tài liệu'],
        ]),
        PushTopic::StaffInstalmentOverdue => Instalment::factory()
            ->for(Contract::factory()->for($matter)->active()->create(['code' => $m['mã hợp đồng'], 'total_amount' => (int) $m['số tiền']]))
            ->create([
                'name' => $m['tên đợt thu'],
                'note' => $m['ghi chú đợt thu'],
                'amount' => (int) $m['số tiền'],
                'due_date' => today()->subDays(3)->toDateString(),
            ]),
        PushTopic::Test => null,
    };
}

/** Người nhận đúng panel của chủ đề (chủ đề "Gửi thử": cả hai), có một máy. */
function pushTopicRecipient(PushTopic $topic, Matter $matter, string $panel): User|ClientUser
{
    $recipient = $panel === 'portal'
        ? ClientUser::factory()->activated()->create(['client_id' => $matter->client_id])
        : User::factory()->create();

    FakePushServer::device($recipient, $topic->value.'-'.$recipient->getMorphClass().'-'.$recipient->getKey());

    return $recipient;
}

/**
 * Payload THẬT của một chủ đề — mảng mà kênh mã hoá rồi gửi (`toWebPush()->toArray()`), lấy từ
 * `PushAlert` mà `SendPushAlert` đã xếp.
 *
 * @return array<string, mixed>
 */
function pushTopicPayload(PushTopic $topic, User|ClientUser $recipient, ?Model $related, ?string $tier): array
{
    $queued = app(SendPushAlert::class)->handle(collect([$recipient]), $topic, $related, $tier);
    expect($queued)->toBe(1);

    $payload = null;
    Notification::assertSentTo($recipient, PushAlert::class, function (PushAlert $alert) use ($recipient, &$payload): bool {
        $payload = $alert->toWebPush($recipient, $alert)->toArray();

        return true;
    });

    return $payload;
}

/** @return array<string, array{0: PushTopic, 1: string, 2: ?string}> chủ đề × panel × bậc */
function pushTopicCasesWithPanel(): array
{
    $cases = [];

    foreach (PushTopic::cases() as $topic) {
        $panels = $topic === PushTopic::Test ? ['portal', 'admin'] : [$topic->panel()];
        $tiers = $topic === PushTopic::StaffDeadlineReminder ? PushTopic::DEADLINE_TIERS : [null];

        foreach ($panels as $panel) {
            foreach ($tiers as $tier) {
                $cases[$topic->value.' / '.$panel.($tier === null ? '' : ' / '.$tier)] = [$topic, $panel, $tier];
            }
        }
    }

    return $cases;
}

dataset('every push topic', fn () => pushTopicCasesWithPanel());

/**
 * Plan Task 7: "Payload của MỌI case `PushTopic` (dataset lặp qua `PushTopic::cases()`): chỉ có các
 * khoá của R11; `data.url` là đường dẫn tương đối nằm trong scope; không chuỗi đánh dấu nào của
 * Review Focus 2." Khoá của R11: `title` (tên văn phòng), `body` (một câu chung từ `lang/vi/push.php`),
 * `icon`, `badge`, `tag` (chủ đề + id bản ghi), `data.url` — không hơn.
 *
 * Mutation probe: thêm `->title($matter->code)` hay nối tiêu đề vụ vào `body` trong `PushTopic` → ĐỎ.
 */
it('builds a lock-screen-safe payload with only the R11 keys for every topic', function (PushTopic $topic, string $panel, ?string $tier) {
    $matter = pushTopicMarkedMatter();
    $related = pushTopicRelated($topic, $matter);
    $recipient = pushTopicRecipient($topic, $matter, $panel);

    $payload = pushTopicPayload($topic, $recipient, $related, $tier);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect(array_keys($payload))->toBe(['title', 'body', 'icon', 'badge', 'tag', 'data'])
        ->and(array_keys($payload['data']))->toBe(['url'])
        ->and($payload['title'])->toBe(config('vkcrm.brand.short_name'))
        ->and($payload['body'])->toBeString()->not->toBe('')
        ->and($payload['body'])->not->toStartWith('push.')
        ->and($payload['icon'])->toBe('/'.AppIcons::ANY[192])
        ->and($payload['badge'])->toBe('/'.AppIcons::BADGE)
        ->and($payload['tag'])->toBe($related === null ? $topic->value : $topic->value.':'.$related->getKey());

    $url = $payload['data']['url'];
    $scope = PwaPanels::path($panel);

    expect($url)->toStartWith('/')
        ->not->toStartWith('//')
        ->and(parse_url($url, PHP_URL_HOST))->toBeNull()
        ->and(parse_url($url, PHP_URL_SCHEME))->toBeNull()
        ->and(parse_url($url, PHP_URL_PATH) === $scope || str_starts_with((string) parse_url($url, PHP_URL_PATH), $scope.'/'))->toBeTrue();

    // `str_contains` + thông điệp, KHÔNG `->not->toContain($marker, $message)`: `toContain()` của Pest
    // nhận NHIỀU chuỗi cần tìm (variadic), nên đối số thứ hai là một chuỗi cần tìm nữa chứ không phải
    // thông điệp — bản phủ định khi đó luôn xanh (đột biến M09 của báo cáo Task 7 sống vì chính lỗi này).
    foreach (pushTopicMarkers() as $where => $marker) {
        expect(str_contains($encoded, $marker))->toBeFalse("Payload của {$topic->value} lộ {$where}");
    }
})->with('every push topic');

/**
 * Deep link của từng chủ đề (bảng R10, phán quyết (f) của controller cho `staff.new_client_document`):
 * khách về trang tiến độ hồ sơ (khối Tài liệu, khối Hồ sơ giấy tờ) hay trang yêu cầu của hồ sơ; nhân
 * sự về trang vụ việc, đúng tab. "Gửi thử" về trang "Thông báo trên điện thoại" của panel người nhận.
 */
it('points every topic at the right page and tab', function (PushTopic $topic, string $panel, Closure $expected) {
    $matter = pushTopicMarkedMatter();
    $related = pushTopicRelated($topic, $matter);
    $recipient = pushTopicRecipient($topic, $matter, $panel);
    $tier = $topic === PushTopic::StaffDeadlineReminder ? 'd7' : null;

    $url = pushTopicPayload($topic, $recipient, $related, $tier)['data']['url'];

    expect($url)->toBe($expected($matter));
})->with([
    'client.stage_update' => [PushTopic::ClientStageUpdate, 'portal', fn (Matter $m) => "/portal/ho-so/{$m->id}"],
    'client.document_published' => [PushTopic::ClientDocumentPublished, 'portal', fn (Matter $m) => "/portal/ho-so/{$m->id}#tai-lieu"],
    'client.document_rejected' => [PushTopic::ClientDocumentRejected, 'portal', fn (Matter $m) => "/portal/ho-so/{$m->id}#ho-so-giay-to"],
    'client.request_answered' => [PushTopic::ClientRequestAnswered, 'portal', fn (Matter $m) => "/portal/yeu-cau/{$m->id}"],
    'staff.deadline_reminder' => [PushTopic::StaffDeadlineReminder, 'admin', fn (Matter $m) => "/admin/matters/{$m->id}?relation=".array_search(DeadlinesRelationManager::class, MatterResource::getRelations(), true)],
    'staff.new_client_request' => [PushTopic::StaffNewClientRequest, 'admin', fn (Matter $m) => "/admin/matters/{$m->id}?relation=".array_search(ClientRequestsRelationManager::class, MatterResource::getRelations(), true)],
    'staff.new_client_document' => [PushTopic::StaffNewClientDocument, 'admin', fn (Matter $m) => "/admin/matters/{$m->id}?relation=".array_search(ChecklistRelationManager::class, MatterResource::getRelations(), true)],
    // Người nhận không mở được trang "Công nợ" (ở đây: tài khoản không vai trò) — tab tiền của vụ.
    'staff.instalment_overdue' => [PushTopic::StaffInstalmentOverdue, 'admin', fn (Matter $m) => "/admin/matters/{$m->id}?relation=".array_search(BillingRelationManager::class, MatterResource::getRelations(), true)],
    'push.test / portal' => [PushTopic::Test, 'portal', fn () => parse_url(PortalPushDevices::getUrl(panel: 'portal'), PHP_URL_PATH)],
    'push.test / admin' => [PushTopic::Test, 'admin', fn () => parse_url(AdminPushDevices::getUrl(panel: 'admin'), PHP_URL_PATH)],
]);

/**
 * M12 Task 9 (phán quyết (e)): đợt thu quá hạn về ĐÚNG nơi mà thư `staff.instalment_overdue` trỏ
 * (`App\Mail\Staff\InstalmentOverdue::link()`, theo người nhận) — trang "Công nợ" cho ai mở được nó
 * (admin, quản lý, kế toán; URL không mang id vụ nào, nên kế toán — không xem được trang vụ việc —
 * không nhận một đường dẫn tới chỗ họ bị 404), tab "Hợp đồng và thanh toán" của vụ cho luật sư.
 * `StaffEventPushTest` so thêm với chính liên kết của thư, trên đường thật.
 *
 * Mutation probe: bỏ nhánh `Receivables::canBeOpenedBy()` trong `PushTopic::url()` → ba dòng đầu ĐỎ.
 */
it('points an overdue instalment at the receivables page for whoever can open it, and at the billing tab otherwise', function (Role $role, bool $receivables) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $matter = pushTopicMarkedMatter();
    $instalment = pushTopicRelated(PushTopic::StaffInstalmentOverdue, $matter);
    $recipient = User::factory()->withRole($role)->create();
    FakePushServer::device($recipient, 'dot-thu-'.$role->value);

    $url = pushTopicPayload(PushTopic::StaffInstalmentOverdue, $recipient, $instalment, null)['data']['url'];

    expect($url)->toBe($receivables
        ? parse_url(Receivables::getUrl(panel: 'admin'), PHP_URL_PATH)
        : "/admin/matters/{$matter->id}?relation=".array_search(BillingRelationManager::class, MatterResource::getRelations(), true));
})->with([
    'kế toán' => [Role::Accountant, true],
    'quản lý' => [Role::Manager, true],
    'admin' => [Role::Admin, true],
    'luật sư' => [Role::Lawyer, false],
]);

/** Hai khối mà deep link của khách nhắm tới có mang đúng `id` trên trang tiến độ hồ sơ. */
it('has the two anchors the client deep links aim at on the progress page', function () {
    $matter = Matter::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    $html = $this->actingAs($account, 'client')->get("/portal/ho-so/{$matter->id}")->assertOk()->getContent();

    expect($html)->toContain('id="tai-lieu"')->toContain('id="ho-so-giay-to"');
});

/**
 * R11: TTL 24 giờ cho mốc hạn, 72 giờ cho các loại khác; `urgency` `high` cho mốc hạn bậc 1 ngày và
 * quá hạn, `normal` cho còn lại. "Gửi thử": một giờ — một tin thử tới sau ba ngày không thử được gì.
 *
 * Mutation probe: bỏ `'d1'` (hay `overdue`) khỏi `PushTopic::PRESSING_TIERS` → ĐỎ.
 */
it('sends deadlines with a 24 hour TTL and high urgency only at one day and overdue', function (PushTopic $topic, ?string $tier, int $ttl, string $urgency) {
    $matter = pushTopicMarkedMatter();
    $related = pushTopicRelated($topic, $matter);
    $recipient = pushTopicRecipient($topic, $matter, $topic->panel() ?? 'portal');

    app(SendPushAlert::class)->handle(collect([$recipient]), $topic, $related, $tier);

    Notification::assertSentTo($recipient, PushAlert::class, function (PushAlert $alert) use ($recipient, $ttl, $urgency): bool {
        return $alert->toWebPush($recipient, $alert)->getOptions() === ['TTL' => $ttl, 'urgency' => $urgency];
    });
})->with([
    'mốc hạn d14' => [PushTopic::StaffDeadlineReminder, 'd14', 86400, 'normal'],
    'mốc hạn d7' => [PushTopic::StaffDeadlineReminder, 'd7', 86400, 'normal'],
    'mốc hạn d3' => [PushTopic::StaffDeadlineReminder, 'd3', 86400, 'normal'],
    'mốc hạn d1' => [PushTopic::StaffDeadlineReminder, 'd1', 86400, 'high'],
    'mốc hạn quá hạn' => [PushTopic::StaffDeadlineReminder, 'overdue', 86400, 'high'],
    'tiến độ' => [PushTopic::ClientStageUpdate, null, 259200, 'normal'],
    'tài liệu mới' => [PushTopic::ClientDocumentPublished, null, 259200, 'normal'],
    'giấy tờ bị từ chối' => [PushTopic::ClientDocumentRejected, null, 259200, 'normal'],
    'trả lời yêu cầu' => [PushTopic::ClientRequestAnswered, null, 259200, 'normal'],
    'yêu cầu mới' => [PushTopic::StaffNewClientRequest, null, 259200, 'normal'],
    'giấy tờ khách nộp' => [PushTopic::StaffNewClientDocument, null, 259200, 'normal'],
    'đợt thu quá hạn' => [PushTopic::StaffInstalmentOverdue, null, 259200, 'normal'],
    'gửi thử' => [PushTopic::Test, null, 3600, 'normal'],
]);

/** Mức khẩn của mốc hạn nằm trong câu chữ (R11 cho phép "hôm nay", "đã quá hạn"): ba câu khác nhau. */
it('words a deadline alert by how pressing it is, without naming the deadline', function () {
    $matter = pushTopicMarkedMatter();
    $deadline = pushTopicRelated(PushTopic::StaffDeadlineReminder, $matter);
    $bodies = [];

    foreach (PushTopic::DEADLINE_TIERS as $tier) {
        $lawyer = pushTopicRecipient(PushTopic::StaffDeadlineReminder, $matter, 'admin');
        $bodies[$tier] = pushTopicPayload(PushTopic::StaffDeadlineReminder, $lawyer, $deadline, $tier)['body'];
    }

    expect($bodies['d14'])->toBe($bodies['d7'])->toBe($bodies['d3'])
        ->and($bodies['d1'])->not->toBe($bodies['d3'])
        ->and($bodies['overdue'])->not->toBe($bodies['d1'])
        ->and($bodies['overdue'])->not->toBe($bodies['d3']);
});

/**
 * R10 "Cố ý không đẩy": mã OTP (mã trên màn hình khoá là mã lộ), thư kích hoạt (chưa kích hoạt thì
 * chưa có máy), nhắc nộp giấy tờ định kỳ và nhắc hồ sơ chậm cập nhật (không gấp), và mọi thư báo lỗi
 * sao lưu (M8, `staff.backup_alert.*`: đọc trên máy tính). Thêm một trong số đó là sửa test này một
 * cách có chủ ý. Đường thật của hai thư định kỳ (thư đi, không push): `StaffEventPushTest`.
 *
 * Cũng là chỗ dựa của lập luận chống trùng ở `RecordOutboundPush`: `CheckStaleMatters::recentlyMailed()`
 * đọc sổ thư theo `template = staff.stale_matter` mà KHÔNG lọc người nhận — dòng push không bao giờ
 * mang mẫu đó.
 */
it('has no topic for the mails that are deliberately never pushed', function () {
    expect(array_map(fn (PushTopic $topic): string => $topic->value, PushTopic::cases()))
        ->not->toContain('client.otp')
        ->not->toContain('client.activation')
        ->not->toContain('client.missing_documents')
        ->not->toContain('staff.stale_matter')
        ->and(array_filter(PushTopic::cases(), fn (PushTopic $topic): bool => str_starts_with($topic->value, 'staff.backup_alert')))->toBe([]);
});

/** Dòng push hiện trong nhật ký thư dưới nhãn tiếng Việt của mẫu, như dòng thư (`outbound.templates`). */
it('gives every topic a template label in the outbound log', function (PushTopic $topic) {
    expect((array) __('outbound.templates'))->toHaveKey($topic->value);
})->with(PushTopic::cases());
