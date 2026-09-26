<?php

use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Hộp thư của văn phòng, phần không phải viết chữ: nhận, giao việc, đổi trạng thái (SPEC §7.2).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->action = app(TriageClientRequest::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);

    $this->request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);
});

function reloadThread(ClientRequest $request): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($request->getKey());
}

// =========================================================================================
// GIAO VIỆC — và "nhận" là cùng một động tác
// =========================================================================================

it('assigns the request and lifts it out of new in one move', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->action->assign($this->request, $this->lawyer, $assistant);

    expect(reloadThread($this->request)->assigned_to)->toBe($assistant->id)
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

/**
 * **Fix round 1, ruling — `last_activity_at` chỉ nhảy khi giao việc CŨNG đổi trạng thái.** Vế
 * dương: giao một luồng `new` (đẩy sang `in_progress`) đóng dấu hoạt động. Vế âm, TRONG CÙNG
 * test: giao LẠI một luồng đã `in_progress` cho người khác (không đổi trạng thái) — cột giữ
 * nguyên, dù việc giao vẫn thành công.
 */
it('stamps last_activity_at only when assigning also changes the status', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $secondAssistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($secondAssistant, MatterRole::Assistant);

    $this->travelTo('2026-09-21 09:00:00');
    $this->request->update(['last_activity_at' => '2026-09-20 00:00:00']);

    $this->travelTo('2026-09-21 10:00:00');
    $this->action->assign(reloadThread($this->request), $this->lawyer, $assistant);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress)
        ->and(reloadThread($this->request)->last_activity_at->toDateTimeString())->toBe('2026-09-21 10:00:00');

    $this->travelTo('2026-09-25 12:00:00');
    $this->action->assign(reloadThread($this->request), $this->lawyer, $secondAssistant);

    expect(reloadThread($this->request)->assigned_to)->toBe($secondAssistant->id)
        // Không đổi trạng thái ở lần giao thứ hai — cột GIỮ mốc của lần trước, không nhảy tới
        // '2026-09-25 12:00:00'.
        ->and(reloadThread($this->request)->last_activity_at->toDateTimeString())->toBe('2026-09-21 10:00:00');
});

/**
 * Chiều ngược lại KHÔNG tự động, và đó là luật chứ không phải một chỗ quên: `new` nghĩa là "chưa
 * ai trong văn phòng nhìn thấy", và một khi đã có người nhìn thì điều đó không thành chưa xảy ra
 * được nữa.
 */
it('never sends a request back to new when the assignee is taken away', function () {
    $this->action->assign($this->request, $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);

    $this->action->assign(reloadThread($this->request), $this->lawyer, null);

    expect(reloadThread($this->request)->assigned_to)->toBeNull()
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

it('leaves a status that is past new alone when assigning', function () {
    $this->request->update(['status' => ClientRequestStatus::Answered]);

    $this->action->assign(reloadThread($this->request), $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::Answered);
});

/**
 * Giao một yêu cầu cho người không mở được hồ sơ là đẩy nó vào một hàng đợi không ai nhìn thấy.
 * Vế dương ngay cạnh: một người TRONG đội ngũ thì nhận được.
 */
it('refuses an assignee who cannot open the matter, and accepts one who can', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    try {
        $this->action->assign($this->request, $this->lawyer, $outsider);
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('assigned_to')
            ->and($exception->errors()['assigned_to'][0])->toBe(__('requests.validation.assignee_cannot_open'));
    }

    expect(reloadThread($this->request)->assigned_to)->toBeNull();

    $this->action->assign($this->request, $this->lawyer, $this->lawyer);

    expect(reloadThread($this->request)->assigned_to)->toBe($this->lawyer->id);
});

/**
 * **Cổng "người được giao phải mở được hồ sơ" không đọc `users.is_active`, và đó là một lối
 * chôn yêu cầu.** `MatterPolicy::update` không hỏi cột đó ở đâu cả — chỗ DUY NHẤT đọc nó là
 * `User::canAccessPanel()`, và hàm đó không bao giờ chạy cho một người thứ ba. Nên một tài khoản
 * đã bị vô hiệu hoá đi lọt cổng, và vì "giao việc" cũng là "nhận", luồng rời luôn tab "chưa ai
 * nhận" và cổng khách bắt đầu nói "Văn phòng đang xem và chuẩn bị trả lời anh/chị" về một việc
 * không ai mở được nữa.
 *
 * Dòng `expect(Gate::forUser(...))` ở đầu test là phần ĐO: nó ghim rằng cổng cũ vẫn nói "được",
 * nên điều kiện mới không phải một câu thừa chép lại câu bên cạnh.
 */
it('refuses a deactivated assignee although the matter gate still lets them through, and accepts an active teammate', function () {
    $departed = User::factory()->withRole(Role::Manager)->create(['is_active' => false]);
    $this->matter->addTeamMember($departed, MatterRole::Assistant);

    expect(Gate::forUser($departed)->allows('update', $this->matter))->toBeTrue();

    try {
        $this->action->assign($this->request, $this->lawyer, $departed);
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('assigned_to')
            ->and($exception->errors()['assigned_to'][0])->toBe(__('requests.validation.assignee_cannot_open'));
    }

    expect(reloadThread($this->request)->assigned_to)->toBeNull()
        // "Giao việc" cũng là "nhận": nếu lần giao kia đi lọt thì trạng thái đã sang
        // `in_progress` và không có đường nào đưa nó về lại.
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::New);

    $active = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($active, MatterRole::Assistant);

    $this->action->assign($this->request, $this->lawyer, $active);

    expect(reloadThread($this->request)->assigned_to)->toBe($active->id)
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

/**
 * Cùng một lối chôn, bằng cột kia: một tài khoản đã xoá mềm. Người này cũng đi lọt
 * `MatterPolicy::update` (nó không hỏi `trashed()` của NGƯỜI, chỉ của vụ việc), và ở màn hình
 * còn tệ hơn — xem test "resolves a soft deleted assignee" ở
 * `ClientRequestsRelationManagerTest`.
 */
it('refuses a soft deleted assignee although the matter gate still lets them through', function () {
    $departed = User::factory()->withRole(Role::Manager)->create();
    $this->matter->addTeamMember($departed, MatterRole::Assistant);
    $departed->delete();

    expect(Gate::forUser($departed)->allows('update', $this->matter))->toBeTrue();

    try {
        $this->action->assign($this->request, $this->lawyer, $departed);
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors()['assigned_to'][0])->toBe(__('requests.validation.assignee_cannot_open'));
    }

    expect(reloadThread($this->request)->assigned_to)->toBeNull()
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::New);
});

// =========================================================================================
// ĐỔI TRẠNG THÁI
// =========================================================================================

it('stamps answered_at the first time the status lands on answered and never moves it again', function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Answered);

    $this->travelTo('2026-09-25 10:00:00');
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::InProgress);
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::Answered);

    expect(reloadThread($this->request)->answered_at->toDateTimeString())->toBe('2026-09-21 10:00:00');
});

/**
 * **Đúng MỘT bước bị chặn, và nó là bước mà docblock của lớp đã tuyên bố từ đầu**: `new` nghĩa
 * là "chưa ai trong văn phòng nhìn thấy", và một khi đã có người nhìn thì điều đó không thành
 * chưa xảy ra được nữa. Trước bản sửa này ô chọn bày ra cả bốn trạng thái và Action nhận hết —
 * docblock nói một đằng, mã làm một nẻo.
 */
it('refuses a move back to new once someone in the office has seen the thread', function () {
    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::InProgress);

    try {
        $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::New);
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('status')
            ->and($exception->errors()['status'][0])->toBe(__('requests.validation.cannot_return_to_new'));
    }

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);

    // Vế dương: một luồng CÒN `new` vẫn nhận `new`. Đó là một lần không-làm-gì (ô chọn được đổ
    // sẵn giá trị hiện tại và người dùng bấm Lưu), không phải một bước lùi.
    $untouched = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);

    $this->action->setStatus($untouched, $this->lawyer, ClientRequestStatus::New);

    expect(reloadThread($untouched)->status)->toBe(ClientRequestStatus::New);
});

it('lets a closed thread be reopened, because there is no transition matrix here', function () {
    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Closed);
    $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::InProgress);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::InProgress);
});

/**
 * **Fix round 1, minor — hợp đồng của `SetClientRequestStatusResult`, đo Ở TẦNG ACTION.** Test
 * qua màn hình (`ClientRequestsRelationManagerTest`, "unassigns a teammate...") đo câu thông báo
 * tiếng Việt; test này đo trực tiếp `setStatus()` TRẢ VỀ CÁI GÌ — không suy từ so sánh hai lần
 * đọc bên ngoài Action.
 */
it('reports exactly who it unassigned when reopening a closed thread they can no longer hold', function () {
    $holder = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Rời Đội']);
    $this->matter->addTeamMember($holder, MatterRole::Assistant);
    $this->request->update(['assigned_to' => $holder->id, 'status' => ClientRequestStatus::Closed]);
    // Chỉ dựng fixture — luồng đã ĐÓNG nên `OpenWork` không chặn lần gỡ này (đúng luật Task 3).
    app(RemoveTeamMember::class)->handle($this->matter, $this->lawyer, $holder);

    $result = $this->action->setStatus(reloadThread($this->request), $this->lawyer, ClientRequestStatus::InProgress);

    expect($result->thread->assigned_to)->toBeNull()
        ->and($result->unassignedAssignee)->not->toBeNull()
        ->and($result->unassignedAssignee->getKey())->toBe($holder->id)
        ->and($result->unassignedAssignee->name)->toBe('Trợ lý Rời Đội');

    // Vế dương: khi KHÔNG có ai bị gỡ, kết quả nói rõ điều đó bằng `null` — không một giá trị giả
    // nào khác cho màn hình lỡ đọc nhầm thành "có gỡ".
    $untouched = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);

    expect($this->action->setStatus($untouched, $this->lawyer, ClientRequestStatus::InProgress)->unassignedAssignee)
        ->toBeNull();
});

// =========================================================================================
// CỔNG QUYỀN
// =========================================================================================

it('refuses a staff member without matter update on both methods, and lets a team lawyer through', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => $this->action->assign($this->request, $accountant, $this->lawyer))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->action->setStatus($this->request, $accountant, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class)
        ->and(reloadThread($this->request)->status)->toBe(ClientRequestStatus::New);

    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::Closed);

    expect(reloadThread($this->request)->status)->toBe(ClientRequestStatus::Closed);
});

it('refuses a request on a matter the staff member cannot list', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    expect(fn () => $this->action->setStatus($this->request, $outsider, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class);
});

/**
 * **Ba lý do, một câu** (SPEC §10.10) — và ba lý do ĐỦ MẶT. Bản đầu của fixture này mang tên
 * "soft deleted matter" nhưng không xoá mềm vụ việc nào: hai trường hợp của nó là một tài khoản
 * bị vô hiệu hoá và một luồng không tồn tại, nên điều kiện mà `refuse()` quảng cáo to nhất chưa
 * bao giờ được chạm tới.
 */
it('refuses a soft deleted matter, a deactivated staff account and a thread that is not there, with the same sentence', function () {
    $deactivated = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $this->matter->addTeamMember($deactivated, MatterRole::Assistant);

    $buried = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    $onBuriedMatter = ClientRequest::factory()->for($buried)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);
    $buried->delete();

    $ghost = ClientRequest::factory()->for($this->matter)->make(['id' => 999999]);

    $messages = collect([
        [$onBuriedMatter, $this->lawyer],
        [$this->request, $deactivated],
        [$ghost, $this->lawyer],
    ])->map(function (array $case): string {
        [$thread, $actor] = $case;

        try {
            $this->action->setStatus($thread, $actor, ClientRequestStatus::Closed);
        } catch (AuthorizationException $exception) {
            return $exception->getMessage();
        }

        return 'KHÔNG TỪ CHỐI';
    })->unique();

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBe(__('requests.unavailable'));
});

/** Tham số đi ra từ URL và không được tin: Action đọc lại hàng thật. */
it('reads the thread back instead of trusting the object it was handed', function () {
    $foreign = ClientRequest::factory()
        ->for(Matter::factory()->create(['is_published_to_portal' => true]))
        ->create();
    $foreign->matter_id = $this->matter->id;

    expect(fn () => $this->action->setStatus($foreign, $this->lawyer, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class);
});

/**
 * Fix round 3, finding I3 residual — hàng rào cuối cùng của `open()`: đối chiếu `$matterId` (tính
 * SẴN, trước transaction, qua {@see TriageClientRequest::realMatterId()}) với `$thread->matter_id`
 * đọc dưới khoá. Hôm nay KHÔNG có đường công khai nào (`assign()`/`setStatus()`) làm hai giá trị
 * này lệch nhau — cả hai đều tính từ CÙNG một khoá chính `$request->getKey()`, và
 * `client_requests.matter_id` bất biến sau khi tạo — nên hàng rào này không đỏ được qua một kịch
 * bản người dùng thật. Gọi thẳng `open()` (private) qua `ReflectionMethod` với một `$matterId`
 * SAI CỐ Ý để đo đúng DÒNG MÃ đó, thay vì chỉ tin docblock.
 */
it('refuses when the pre-computed matter id disagrees with the freshly locked row, as a last-resort guard', function () {
    // Vụ SAI phải là một vụ việc THẬT, actor MỞ ĐƯỢC — nếu không, nhánh `$matter === null`
    // (hồ sơ không tồn tại) sẽ chặn trước và mutation probe không đo được ĐÚNG dòng đang cần đo:
    // đã tự bắt lỗi này khi viết test (id bịa `+999999` khớp `$matter === null`, không khớp
    // hàng rào đối chiếu).
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $method = new ReflectionMethod(TriageClientRequest::class, 'open');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($this->action, $this->request, $this->lawyer, $otherMatter->id))
        ->toThrow(AuthorizationException::class);

    // Đối chứng: đúng matter_id thật thì không bị chặn ở hàng rào này (thất bại ở đây sẽ đổ lỗi
    // sai cho test trên nếu ai đó sau này phá vỡ luồng chính chứ không phải hàng rào cuối).
    expect($method->invoke($this->action, $this->request, $this->lawyer, $this->request->matter_id))
        ->toBeArray();
});

// =========================================================================================
// NHẬT KÝ — causer là NHÂN SỰ, đo với phiên khách đang mở
// =========================================================================================

/**
 * Cảnh phân biệt được: một phiên KHÁCH đang mở trong cùng trình duyệt. `Audit::record()` ưu tiên
 * guard `web`, nên ở đây một lần bỏ `causer:` tường minh vẫn xanh — thứ làm nó ĐỎ là một lời gọi
 * không có phiên nào cả (một job, một lệnh console), nên test này chạy cả hai cảnh.
 */
it('credits the staff member in both a browser session and a session less call', function () {
    $this->actingAs($this->clientUser, 'client');

    $this->action->setStatus($this->request, $this->lawyer, ClientRequestStatus::InProgress);

    $withSession = Activity::query()->where('event', 'client_request_status_changed')->latest('id')->firstOrFail();

    expect($withSession->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and($withSession->causer_id)->toBe($this->lawyer->id)
        ->and($withSession->properties['from'])->toBe('new')
        ->and($withSession->properties['to'])->toBe('in_progress');

    // Không phiên nào cả: nhánh mà một `causer` suy ra từ `auth()` sẽ để trống.
    auth('client')->logout();
    auth('web')->logout();

    $this->action->assign(reloadThread($this->request), $this->lawyer, $this->lawyer);

    $sessionless = Activity::query()->where('event', 'client_request_assigned')->latest('id')->firstOrFail();

    expect($sessionless->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and($sessionless->causer_id)->toBe($this->lawyer->id)
        ->and($sessionless->properties['to'])->toBe($this->lawyer->id);
});

it('writes no log line at all when it refuses', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => $this->action->setStatus($this->request, $accountant, ClientRequestStatus::Closed))
        ->toThrow(AuthorizationException::class)
        ->and(Activity::query()->whereIn('event', [
            'client_request_status_changed',
            'client_request_assigned',
        ])->count())->toBe(0);
});

// =========================================================================================
// KHOÁ THẬT TRÊN MariaDB — fix round 3, finding I3 residual
// =========================================================================================

/**
 * Bắt buộc phải là MariaDB thật (không phải một kết nối phụ nào khác) — thứ đang đo là hành vi
 * READ VIEW của REPEATABLE READ, một khái niệm InnoDB không tồn tại trên SQLite (bộ test chạy
 * trên đó theo mặc định, không có khái niệm transaction isolation kiểu MVCC).
 */
function requireMariadbForLocking(): void
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    test()->markTestSkipped(
        'Cần chạy trên chính MariaDB (bin/dev test:mariadb): test này đo READ VIEW của '
        .'REPEATABLE READ InnoDB, một hành vi SQLite (mặc định bộ test) không có. Đang chạy '
        ."trên: [{$driver}]."
    );
}

/**
 * Fix round 3, finding I3 residual — người rà soát tái hiện được trên container MariaDB 11.8 của
 * dự án: `TriageClientRequest::open()` (bản round 2) đọc `matter_id` bằng một câu KHÔNG khoá làm
 * câu ĐẦU TIÊN bên trong transaction, TRƯỚC câu `lockForUpdate()` trên `matters`. Trên MariaDB,
 * mức cô lập REPEATABLE READ (mặc định InnoDB) cố định READ VIEW của một transaction tại LẦN ĐỌC
 * KHÔNG KHOÁ ĐẦU TIÊN — không phải tại `BEGIN`, và KHÔNG phải tại một câu đọc CÓ khoá
 * (`FOR UPDATE` luôn đọc dữ liệu MỚI NHẤT, không dùng READ VIEW). Hệ quả round 2: nếu
 * `RemoveTeamMember` gỡ và commit đúng lúc `assign()` đang ĐỢI khoá `matters` (bị chặn bởi chính
 * `RemoveTeamMember` đang giữ khoá đó), `assign()` được cấp khoá NGAY SAU khi `RemoveTeamMember`
 * commit — nhưng câu `matter_user` EXISTS phía sau (qua `Gate::forUser($assignee)->allows(
 * 'update', $matter)`) vẫn đọc theo READ VIEW CŨ (cố định TRƯỚC khi `RemoveTeamMember` commit,
 * bởi chính câu `value('matter_id')` không khoá kia) — nên nó vẫn "thấy" người vừa bị gỡ còn
 * trong đội ngũ, và phép gán đi qua.
 *
 * **Vì sao test này CẦN khoá THẬT (chặn thật), không chỉ một kịch bản tuần tự.** Nếu
 * `RemoveTeamMember` chạy và commit XONG HẲN trước khi `assign()` được gọi, MỌI câu đọc của
 * `assign()` — dù có mutation hay không — đều tự nhiên xảy ra SAU khi đã commit, và test sẽ
 * xanh trong CẢ HAI trường hợp (không phân biệt được lỗi cũ với bản đã sửa — đã tự đo bằng tay,
 * xem báo cáo). Cái bug này CHỈ hiện ra khi `assign()` PHẢI ĐỢI khoá đang bị `RemoveTeamMember`
 * giữ, rồi chạy tiếp NGAY SAU khi khoá đó được nhả — đúng nhịp một cuộc đua thật. Vì PHP một
 * luồng không "tạm dừng" được nửa chừng một lời gọi hàm, test này `pcntl_fork()` một tiến trình
 * CON thật: con giữ khoá `matters` (BEGIN + FOR UPDATE, CHƯA commit), báo hiệu bằng một tệp,
 * ĐỢI CHO TỚI KHI THẤY tiến trình CHA (đang chạy `TriageClientRequest::assign()` THẬT trên một
 * kết nối riêng) đang đứng ở câu `FOR UPDATE` trên `matters`, rồi mới gỡ trợ lý khỏi
 * `matter_user` và COMMIT — nhả khoá đúng lúc tiến trình cha đang bị khoá đó chặn.
 *
 * **Chờ được XÁC MINH, không đoán bằng thời gian (fix round 4, N2).** Con không `usleep()` một
 * khoảng cố định rồi hy vọng cha đã kịp tới chỗ khoá: trên một container chậm, khoảng đó có thể
 * hết trước khi cha kịp chạy tới, con commit sớm, và test xanh cả trên mã lỗi. Con hỏi
 * `information_schema.PROCESSLIST` cho tới khi phiên CSDL của cha (biết trước bằng
 * `CONNECTION_ID()`) đang thực thi một câu `... from `matters` ... for update` — câu đó không thể
 * xong khi con còn giữ khoá, nên thấy nó đang chạy nghĩa là cha ĐANG bị chặn (và mọi câu đọc
 * trước nó, gồm câu đọc sớm của lỗi round 2, đã chạy xong). Không dùng
 * `information_schema.INNODB_LOCK_WAITS`/`INNODB_TRX` (chính xác hơn) vì chúng đòi quyền toàn cục
 * `PROCESS`, người dùng `sail` không có và không nên có; `PROCESSLIST` thì một người dùng luôn
 * xem được các phiên của chính mình (MariaDB 11 không có `performance_schema.data_lock_waits`).
 * Hết 10 giây vẫn không thấy thì con NHẢ khoá mà KHÔNG gỡ ai, ghi "timeout" vào tệp kết quả, và
 * cha `fail()` test thành tiếng — không bao giờ xanh im lặng.
 *
 * Tiến trình CON mở một kết nối MỚI tên riêng (`mariadb_child`) và KHÔNG đụng tới các kết nối
 * thừa hưởng từ cha: sau `fork()`, cha và con CHIA SẺ cùng các socket TCP bên dưới; dùng hay
 * đóng (`DB::purge()` gửi `COM_QUIT`) một socket chung ở phía con sẽ làm hỏng phiên của CHA. Con
 * kết thúc bằng `SIGKILL` nên không destructor nào chạy trên các socket thừa hưởng. Tiến trình CHA
 * đổi kết nối MẶC ĐỊNH sang một kết nối THỨ HAI (`mariadb_b`, cùng cấu hình, một phiên CSDL độc
 * lập) trước khi gọi `assign()` — vì Eloquent dùng kết nối MẶC ĐỊNH hiện hành cho mọi model không
 * tự khai `$connection`, đây là cách gọi ĐÚNG mã sản phẩm không sửa đổi ("chạy `open()`/`assign()`
 * trên một kết nối chỉ định" — phán quyết cho phép cách này khi không tách được đường sản phẩm).
 *
 * **Dọn CSDL: bắt bài test KẾ TIẾP migrate lại (fix round 4, N1).** Con và cha ở hai phiên khác
 * nhau chỉ thấy dữ liệu của nhau khi nó được COMMIT THẬT, nên test phải `DB::commit()` cái
 * transaction `RefreshDatabase` bọc quanh nó — và từ lúc đó mọi thứ `beforeEach()`, factory,
 * seeder, observer ghi ra (vai trò, quyền, `model_has_roles`, loại vụ việc + các giai đoạn của
 * nó, `activity_log`...) nằm lại THẬT trong CSDL test, không rollback nào gỡ được. Dọn tay từng
 * bảng là một danh sách phải nhớ cập nhật mỗi khi một factory/observer ghi thêm một bảng mới —
 * round 3 đã quên đúng như vậy (còn lại một loại vụ việc thứ bảy làm "seeds six matter types" đỏ).
 * Nên test đặt `RefreshDatabaseState::$migrated = false` NGAY TRƯỚC lần commit đầu tiên: bài test
 * `RefreshDatabase` kế tiếp sẽ `migrate:fresh`, xoá SẠCH mọi bảng bất kể ai đã ghi gì — kể cả
 * khi test này hỏng giữa chừng. (Hook `tearDown()` của `RefreshDatabase` chỉ đặt cờ này về
 * `false`, không bao giờ về `true`, nên không gì ghi đè nó.)
 */
it('reads fresh matter_user membership through the production assign() call, after waiting on a lock RemoveTeamMember holds (MariaDB, two connections)', function () {
    requireMariadbForLocking();

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        test()->markTestSkipped('Cần pcntl và posix để giả lập hai phiên CSDL thật đồng thời.');
    }

    config([
        'database.connections.mariadb_b' => config('database.connections.mariadb'),
        'database.connections.mariadb_child' => config('database.connections.mariadb'),
    ]);

    // Từ đây CSDL test bị coi là bẩn: bài test RefreshDatabase kế tiếp sẽ `migrate:fresh` (xem
    // docblock, fix round 4 N1). Đặt TRƯỚC lần commit đầu tiên, không đợi tới `finally`, để cả
    // một lỗi xảy ra giữa commit và khối `try` bên dưới cũng không để lại dữ liệu cho test sau.
    RefreshDatabaseState::$migrated = false;

    // RefreshDatabase bọc kết nối MẶC ĐỊNH trong một transaction CHƯA COMMIT suốt bài test — một
    // tiến trình/kết nối KHÁC sẽ không thấy được dữ liệu dựng ở beforeEach() (matter/client/
    // lawyer/request) cho tới khi transaction đó COMMIT THẬT.
    DB::commit();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    DB::commit(); // addTeamMember() không tự mở transaction, nhưng gọi lại cho chắc nếu Eloquent lỡ mở.

    $matterId = $this->matter->id;
    $assistantId = $assistant->id;

    // Phiên CSDL mà `assign()` sẽ chạy trên đó — mở TRƯỚC khi fork để con biết trước id phiên cần
    // theo dõi. Con thừa hưởng socket này nhưng không bao giờ dùng hay đóng nó (xem docblock).
    $parentConnectionId = (int) DB::connection('mariadb_b')->selectOne('select connection_id() as id')->id;

    $filePrefix = sys_get_temp_dir().'/vkcrm_mariadb_lock_test_'.getmypid();
    $lockHeldSignal = $filePrefix.'.signal';
    $childOutcomeFile = $filePrefix.'.outcome';
    @unlink($lockHeldSignal);
    @unlink($childOutcomeFile);

    $pid = pcntl_fork();

    if ($pid === -1) {
        test()->fail('pcntl_fork() thất bại — không giả lập được hai phiên CSDL đồng thời.');
    }

    if ($pid === 0) {
        // ---- TIẾN TRÌNH CON — đóng vai RemoveTeamMember đang giữ khoá `matters`. ----
        $outcome = 'error: tiến trình con dừng trước khi ghi kết quả';

        try {
            $child = DB::connection('mariadb_child'); // kết nối MỚI, không dùng socket thừa hưởng.
            $child->beginTransaction();
            $child->table('matters')->where('id', $matterId)->lockForUpdate()->first();

            touch($lockHeldSignal); // báo cho tiến trình cha: khoá đã được giữ, có thể thử xin khoá.

            // Chờ XÁC MINH (N2): phiên của cha đang thực thi câu FOR UPDATE trên `matters` — câu
            // đó không xong được khi con còn giữ khoá, nên thấy nó nghĩa là cha đang bị chặn.
            $deadline = microtime(true) + 10.0;
            $parentIsBlocked = false;

            while (! $parentIsBlocked && microtime(true) < $deadline) {
                $parentIsBlocked = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->whereIn('COMMAND', ['Query', 'Execute']) // Execute: câu prepared phía máy chủ (PDO).
                    ->whereRaw("LOWER(INFO) LIKE '%from `matters`%for update%'")
                    ->exists();

                if (! $parentIsBlocked) {
                    usleep(10_000);
                }
            }

            if ($parentIsBlocked) {
                $child->table('matter_user')
                    ->where('matter_id', $matterId)
                    ->where('user_id', $assistantId)
                    ->delete();

                $child->commit(); // nhả khoá matters NGAY ĐÂY, trong lúc cha đang đợi nó.
                $outcome = 'removed-while-parent-blocked';
            } else {
                $lastSeen = $child->table('information_schema.PROCESSLIST')
                    ->where('ID', $parentConnectionId)
                    ->first(['COMMAND', 'STATE', 'INFO']);

                $child->rollBack(); // nhả khoá mà KHÔNG gỡ ai — cha sẽ fail() thành tiếng.
                $outcome = 'timeout: sau 10 giây không thấy phiên #'.$parentConnectionId
                    .' đợi khoá matters; phiên đó đang: '.json_encode($lastSeen, JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception->getMessage();
        } finally {
            file_put_contents($childOutcomeFile, $outcome);

            // Dừng tiến trình con NGAY LẬP TỨC, không đi qua bất kỳ shutdown handler/destructor
            // nào của PHPUnit/Pest/PDO — tiếp tục chạy sẽ khiến tiến trình con cũng cố "chạy nốt"
            // phần còn lại của bộ test, và destructor PDO sẽ đóng các socket chung với cha.
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    // ---- TIẾN TRÌNH CHA — đóng vai request thật gọi TriageClientRequest::assign(). ----
    $childReaped = false;

    try {
        $deadline = microtime(true) + 5.0;

        while (! file_exists($lockHeldSignal)) {
            if (microtime(true) > $deadline) {
                test()->fail('Tiến trình con không báo hiệu đã giữ khoá trong 5 giây — bỏ test, không đoán kết quả.');
            }

            usleep(10_000);
        }

        DB::setDefaultConnection('mariadb_b');

        $stillAssignable = null;

        try {
            // Gọi THẲNG production code, không sửa gì — câu `lockForUpdate()` đầu tiên bên trong
            // `open()` sẽ BỊ CHẶN THẬT ở đây cho tới khi tiến trình con commit (nhả khoá).
            $this->action->assign($this->request, $this->lawyer, $assistant);
            $stillAssignable = true; // không ném gì — đúng lỗi round 2: gán được cho người đã bị gỡ.
        } catch (ValidationException $exception) {
            $stillAssignable = false; // đúng: bị từ chối vì $assistant không còn mở được vụ việc.
        }

        pcntl_waitpid($pid, $status);
        $childReaped = true;

        $childOutcome = (string) @file_get_contents($childOutcomeFile);

        if ($childOutcome !== 'removed-while-parent-blocked') {
            test()->fail('Không dựng được cuộc đua cần đo — tiến trình con báo: ['.$childOutcome.'].');
        }

        expect($stillAssignable)->toBeFalse();
    } finally {
        if (! $childReaped) {
            pcntl_waitpid($pid, $status); // con luôn tự dừng trong ~10 giây (xem vòng chờ của nó).
        }

        DB::connection('mariadb_b')->rollBack();
        DB::purge('mariadb_b');
        DB::setDefaultConnection('mariadb');
        @unlink($lockHeldSignal);
        @unlink($childOutcomeFile);

        // Không dọn tay bảng nào: `RefreshDatabaseState::$migrated = false` ở trên khiến bài test
        // kế tiếp `migrate:fresh` (xem docblock, fix round 4 N1).
    }
})->group('mariadb-locking');
