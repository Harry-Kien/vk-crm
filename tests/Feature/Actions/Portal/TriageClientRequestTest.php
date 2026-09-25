<?php

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
 * CON thật: con giữ khoá `matters` (BEGIN + FOR UPDATE, CHƯA commit), báo hiệu bằng một tệp, đợi
 * một khoảng ngắn, rồi gỡ trợ lý khỏi `matter_user` và COMMIT — nhả khoá đúng lúc tiến trình CHA
 * (đang chạy `TriageClientRequest::assign()` THẬT trên một kết nối riêng) đang bị khoá đó chặn.
 *
 * Tiến trình CON dùng kết nối `mariadb` MẶC ĐỊNH sau khi `DB::purge()` (bắt buộc: sau `fork()`,
 * cha và con CHIA SẺ cùng một socket TCP bên dưới nếu không purge/kết nối lại — dùng chung sẽ
 * làm hỏng luồng giao thức của CẢ HAI). Tiến trình CHA đổi kết nối MẶC ĐỊNH sang một kết nối THỨ
 * HAI (`mariadb_b`, cùng cấu hình, một phiên CSDL độc lập) trước khi gọi `assign()` — vì Eloquent
 * dùng kết nối MẶC ĐỊNH hiện hành cho mọi model không tự khai `$connection`, đây là cách gọi
 * ĐÚNG mã sản phẩm không sửa đổi ("chạy `open()`/`assign()` trên một kết nối chỉ định" — phán
 * quyết cho phép cách này khi không tách được đường sản phẩm).
 */
it('reads fresh matter_user membership through the production assign() call, after waiting on a lock RemoveTeamMember holds (MariaDB, two connections)', function () {
    requireMariadbForLocking();

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        test()->markTestSkipped('Cần pcntl và posix để giả lập hai phiên CSDL thật đồng thời.');
    }

    config(['database.connections.mariadb_b' => config('database.connections.mariadb')]);

    // RefreshDatabase bọc kết nối MẶC ĐỊNH trong một transaction CHƯA COMMIT suốt bài test — một
    // tiến trình/kết nối KHÁC sẽ không thấy được dữ liệu dựng ở beforeEach() (matter/client/
    // lawyer/request) cho tới khi transaction đó COMMIT THẬT. Commit tường minh ở đây; dọn tay ở
    // khối `finally` vì RefreshDatabase không còn gì để tự rollback nữa (xem cuối hàm).
    DB::commit();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    DB::commit(); // addTeamMember() không tự mở transaction, nhưng gọi lại cho chắc nếu Eloquent lỡ mở.

    $matterId = $this->matter->id;
    $assistantId = $assistant->id;
    $lockHeldSignal = sys_get_temp_dir().'/vkcrm_mariadb_lock_test_'.getmypid().'.signal';
    @unlink($lockHeldSignal);

    $pid = pcntl_fork();

    if ($pid === -1) {
        test()->fail('pcntl_fork() thất bại — không giả lập được hai phiên CSDL đồng thời.');
    }

    if ($pid === 0) {
        // ---- TIẾN TRÌNH CON — đóng vai RemoveTeamMember đang giữ khoá `matters`. ----
        try {
            DB::purge('mariadb');
            DB::connection('mariadb')->beginTransaction();
            DB::connection('mariadb')->table('matters')->where('id', $matterId)->lockForUpdate()->first();

            touch($lockHeldSignal); // báo cho tiến trình cha: khoá đã được giữ, có thể thử xin khoá.

            usleep(400_000); // giữ khoá một khoảng đủ để cha CHẮC CHẮN đã bị chặn khi xin cùng khoá.

            DB::connection('mariadb')->table('matter_user')
                ->where('matter_id', $matterId)
                ->where('user_id', $assistantId)
                ->delete();

            DB::connection('mariadb')->commit(); // nhả khoá matters NGAY ĐÂY.
        } finally {
            // Dừng tiến trình con NGAY LẬP TỨC, không đi qua bất kỳ shutdown handler nào của
            // PHPUnit/Pest — tiếp tục chạy sẽ khiến tiến trình con cũng cố "chạy nốt" phần còn
            // lại của bộ test, nhân đôi output và làm hỏng tiến trình cha.
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    // ---- TIẾN TRÌNH CHA — đóng vai request thật gọi TriageClientRequest::assign(). ----
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

        expect($stillAssignable)->toBeFalse();
    } finally {
        DB::connection('mariadb_b')->rollBack();
        DB::purge('mariadb_b');
        DB::setDefaultConnection('mariadb');
        @unlink($lockHeldSignal);

        // Dọn tay mọi thứ đã COMMIT ở trên (xem lý do ở đầu hàm) — theo đúng thứ tự khoá ngoại
        // (matters trước, vì lead_lawyer_id là restrictOnDelete()).
        DB::table('matters')->where('id', $this->matter->id)->delete(); // cascade: matter_user, client_requests
        DB::table('client_users')->where('client_id', $this->client->id)->delete();
        DB::table('clients')->where('id', $this->client->id)->delete();
        DB::table('users')->whereIn('id', [$this->lawyer->id, $assistant->id])->delete();

        // Mở lại MỘT transaction để RefreshDatabase còn cái để rollback lúc `tearDown()` — không
        // có nó, `Connection::rollBack()` no-op (đúng, vô hại — xem `ManagesTransactions::
        // rollBack()`: `$toLevel = -1` thì return sớm) NHƯNG `RefreshDatabaseState::$migrated`
        // bị đặt lại `false` (điều kiện `! $connection->getPdo()->inTransaction()` ở
        // `beginDatabaseTransaction()`), khiến bài test KẾ TIẾP trong cùng lượt `bin/dev
        // test:mariadb` phải `migrate:fresh` lại từ đầu — không sai, chỉ chậm.
        DB::beginTransaction();
    }
})->group('mariadb-locking');
