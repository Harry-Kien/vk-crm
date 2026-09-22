<?php

use App\Actions\Portal\RecordStageLogView;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->action = app(RecordStageLogView::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->sibling = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
    $this->log = StageLog::factory()->for($this->matter)->published()->create();
});

/** Mọi biên bản trong bảng, kể cả của khách khác — dùng để đếm thật thay vì đếm qua scope. */
function allReceipts(): Builder
{
    return StageLogView::query()->withoutGlobalScope(ClientPortalScope::class);
}

it('writes one receipt with the time and the ip of the first view', function () {
    $receipt = $this->action->handle($this->log, $this->clientUser, '203.0.113.5');

    expect(allReceipts()->count())->toBe(1)
        ->and($receipt->stage_log_id)->toBe($this->log->id)
        ->and($receipt->client_user_id)->toBe($this->clientUser->id)
        ->and($receipt->ip)->toBe('203.0.113.5')
        ->and($receipt->viewed_at)->not->toBeNull();
});

/**
 * Hợp đồng số 2 của Action: `viewed_at` là dấu thời gian của lần đọc ĐẦU và không bao giờ bị ghi
 * đè. Một `updateOrCreate` ở chỗ đó vẫn cho một dòng duy nhất, nên phép đếm KHÔNG bắt được nó —
 * chỉ so sánh dấu thời gian mới bắt được.
 */
it('never moves viewed_at or ip on a later view', function () {
    $this->travelTo('2026-09-20 08:00:00');
    $first = $this->action->handle($this->log, $this->clientUser, '203.0.113.5');

    $this->travelTo('2026-09-27 21:14:00');
    $second = $this->action->handle($this->log, $this->clientUser, '198.51.100.9');

    expect(allReceipts()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->viewed_at->toDateTimeString())->toBe('2026-09-20 08:00:00')
        ->and($second->ip)->toBe('203.0.113.5');
});

/**
 * Hai tab của cùng một khách mở cùng một hồ sơ. Tab kia được chèn vào bảng ngay SAU câu `select`
 * mở đầu của `firstOrCreate()` và ngay TRƯỚC câu `insert` của nó — tức đúng cái cửa sổ mà một
 * lần `exists()` trước insert chỉ làm hẹp lại chứ không đóng được. Chỉ số
 * `unique(stage_log_id, client_user_id)` biến cửa sổ đó thành một lỗi trùng khoá, và kết quả
 * phải là: MỘT dòng, dòng của người tới trước, với dấu thời gian của người tới trước.
 *
 * **Vì sao móc vào `DB::listen` chứ không vào hook `creating` của model.** Đã thử bằng hook
 * `creating` và nó dựng ra một cảnh KHÁC hẳn: `Builder::createOrFirst()` gọi
 * `withSavepointIfNeeded()`, và bộ test chạy trong một transaction của `RefreshDatabase`, nên
 * có một savepoint mở quanh câu insert. Dòng chèn từ trong hook nằm BÊN TRONG savepoint đó và bị
 * cuốn đi cùng lần rollback — cả hai lần đọc lại đều trả `null` và ngoại lệ thoát ra ngoài. Đó
 * là một tình huống không tồn tại trong đời thật (ngoài transaction thì không có savepoint nào),
 * và nếu không nhìn ra thì nó sẽ bị chữa bằng cách sửa mã sản phẩm cho vừa một cái bẫy của bộ
 * test.
 *
 * # Cái bẫy THỨ HAI, và nó đã để test này im lặng trên đúng cái driver chạy thật
 *
 * Bản đầu nhận ra câu `select` bằng `str_contains($query->sql, 'from "stage_log_views"')`.
 * Dấu nháy kép là ngữ pháp của SQLite; MariaDB bọc định danh bằng dấu huyền, nên trên MariaDB
 * cái móc KHÔNG BAO GIỜ khớp, không có tab thứ hai nào được dựng, và test chết ở chính câu tiền
 * đề của nó ("Expecting null not to be null"). Nghĩa là cuộc đua hai tab — hợp đồng mà cả bảng
 * `stage_log_views` dựa lên — chưa một lần nào được đo trên driver mà production dùng. Mã sản
 * phẩm không sai; cái sai là một phép so chuỗi tự cột mình vào một ngữ pháp.
 *
 * Nên phép so bây giờ HỎI CHÍNH NGỮ PHÁP đang chạy (`Grammar::wrapTable()`) thay vì chép lại
 * một trong hai cách bọc: một bản chép tay của bảng người khác thì sẽ trôi, và bài học đó đã
 * phải trả giá hai vòng ở chỗ khác trong mốc này.
 *
 * # Ba việc được đo chứ không được suy ra
 *
 * `beforeExecuting` thấy MỌI câu lệnh, kể cả câu ném ngoại lệ; `DB::listen` chỉ thấy câu chạy
 * xong. Hiệu của hai danh sách chính là câu lệnh đã NỔ:
 *
 *  1. tab kia được chèn thật (`$competitorId`, và nó có mặt ở cả hai danh sách);
 *  2. câu `insert` của Action được THỬ nhưng không hoàn tất — tức lỗi trùng khoá đã xảy ra và đã
 *     bị bắt (`createOrFirst()` của framework bắt; xem docblock `RecordStageLogView::recordOnce()`);
 *  3. sau đó còn một câu `select` nữa trên bảng — lần ĐỌC LẠI — và nó chạy xong.
 */
it('keeps the first receipt when two tabs record the same entry at the same time', function () {
    $this->travelTo('2026-09-20 08:00:00');

    $competitorId = null;
    $logId = $this->log->id;
    $userId = $this->clientUser->id;

    // Ngữ pháp của CHÍNH kết nối đang chạy: `from "stage_log_views"` trên SQLite,
    // `from `stage_log_views`` trên MariaDB. Không tệp test nào viết lại hai cách bọc đó.
    $from = 'from '.DB::connection()->getQueryGrammar()->wrapTable('stage_log_views');

    /** @var list<string> $attempted mọi câu lệnh chạm bảng, KỂ CẢ câu sắp ném ngoại lệ */
    $attempted = [];
    /** @var list<string> $completed chỉ những câu chạy xong không lỗi */
    $completed = [];

    DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
        if (str_contains($query, 'stage_log_views')) {
            $attempted[] = $query;
        }
    });

    DB::listen(function ($query) use (&$competitorId, &$completed, $from, $logId, $userId): void {
        if (! str_contains($query->sql, 'stage_log_views')) {
            return;
        }

        $completed[] = $query->sql;

        if ($competitorId !== null || ! str_contains($query->sql, $from)) {
            return;
        }

        $competitorId = DB::table('stage_log_views')->insertGetId([
            'stage_log_id' => $logId,
            'client_user_id' => $userId,
            'viewed_at' => '2026-09-20 07:59:58',
            'ip' => '203.0.113.1',
            'created_at' => '2026-09-20 07:59:58',
            'updated_at' => '2026-09-20 07:59:58',
        ]);
    });

    $receipt = $this->action->handle($this->log, $this->clientUser, '203.0.113.2');

    $attemptedInserts = count(array_filter($attempted, fn (string $sql): bool => str_starts_with($sql, 'insert')));
    $completedInserts = count(array_filter($completed, fn (string $sql): bool => str_starts_with($sql, 'insert')));
    $completedSelects = count(array_filter($completed, fn (string $sql): bool => str_starts_with($sql, 'select')));

    // Tiền đề: móc đã bắn và tab kia đã có mặt trong bảng trước câu insert của Action.
    expect($competitorId)->not->toBeNull()
        // Hai câu insert được THỬ (tab kia, và Action), chỉ MỘT chạy xong: câu còn lại là lỗi
        // trùng khoá đã bị bắt. Không có nó thì bảng đã có hai dòng.
        ->and($attemptedInserts)->toBe(2)
        ->and($completedInserts)->toBe(1)
        // Hai câu select chạy xong: câu mở đầu của `firstOrCreate()`, và lần ĐỌC LẠI sau lỗi.
        ->and($completedSelects)->toBe(2)
        // Và kết quả: một dòng, dòng của người tới trước, giờ và IP của người tới trước.
        ->and(allReceipts()->count())->toBe(1)
        ->and($receipt->getKey())->toBe($competitorId)
        ->and($receipt->viewed_at->toDateTimeString())->toBe('2026-09-20 07:59:58')
        ->and($receipt->ip)->toBe('203.0.113.1');
});

it('writes a separate receipt for each portal account of the same client', function () {
    $this->action->handle($this->log, $this->clientUser, '203.0.113.5');
    $this->action->handle($this->log, $this->sibling, '203.0.113.6');

    expect(allReceipts()->count())->toBe(2)
        ->and(allReceipts()->pluck('client_user_id')->sort()->values()->all())
        ->toBe(collect([$this->clientUser->id, $this->sibling->id])->sort()->values()->all());
});

it('refuses an entry that has not been published to the portal', function () {
    $unpublished = StageLog::factory()->for($this->matter)->internalOnly()->create();

    expect(fn () => $this->action->handle($unpublished, $this->clientUser, '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

it('refuses an entry of another client', function () {
    $foreign = StageLog::factory()->for(Matter::factory()->create())->published()->create();

    expect(fn () => $this->action->handle($foreign, $this->clientUser, '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

it('refuses an entry of a matter that is no longer published to the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => $this->action->handle($this->log, $this->clientUser, '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

it('refuses a deactivated portal account', function () {
    $this->clientUser->update(['is_active' => false]);

    expect(fn () => $this->action->handle($this->log, $this->clientUser->fresh(), '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

/**
 * Xoá mềm một tài khoản KHÔNG hạ cờ `is_active` — hai cột nói hai chuyện khác nhau — nên
 * `accountIsActive()` phải hỏi cả hai. Trước vòng sửa này một `ClientUser` đã xoá mềm vẫn ghi
 * được biên bản, tức bảng bằng chứng nhận một dòng mang tên một tài khoản không còn tồn tại.
 *
 * Đối tượng được đọc lại bằng `withTrashed()`, đúng đường mà một job chạy lại hoặc một Action
 * gọi từ console sẽ đi: `$actor` đến từ bên ngoài, không từ `auth()`.
 */
it('refuses a soft deleted portal account even though is_active is still true', function () {
    $this->clientUser->delete();

    $trashed = ClientUser::withTrashed()->findOrFail($this->clientUser->id);

    expect($trashed->is_active)->toBeTrue()
        ->and($trashed->trashed())->toBeTrue()
        ->and(fn () => $this->action->handle($this->log, $trashed, '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

it('refuses an entry that does not exist', function () {
    $ghost = StageLog::factory()->for($this->matter)->published()->make(['id' => 999999]);

    expect(fn () => $this->action->handle($ghost, $this->clientUser, '203.0.113.5'))
        ->toThrow(AuthorizationException::class);
});

/**
 * SPEC §10.10: bốn tình huống từ chối phải không phân biệt được — ở LỚP lẫn ở CÂU CHỮ. So sánh
 * lớp thôi là chưa đủ; thứ người ngoài quan sát được là câu chữ (bài học Critical C1 của M4).
 */
it('refuses all four situations with one identical vietnamese sentence', function () {
    $this->matter->update(['is_published_to_portal' => false]);
    $hiddenMatterLog = $this->log;

    $unpublished = StageLog::factory()
        ->for(Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]))
        ->internalOnly()->create();
    $foreign = StageLog::factory()->for(Matter::factory()->create())->published()->create();
    $ghost = StageLog::factory()->make(['id' => 999999]);

    $messages = collect([$hiddenMatterLog, $unpublished, $foreign, $ghost])
        ->map(function (StageLog $log): string {
            try {
                $this->action->handle($log, $this->clientUser, '203.0.113.5');
            } catch (AuthorizationException $exception) {
                return $exception->getMessage();
            }

            return 'KHÔNG BỊ TỪ CHỐI';
        })
        ->unique()
        ->values();

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBe(__('matters.stage_log_views.unavailable'))
        ->and($messages->first())->not->toContain('unauthorized');
});

/**
 * Action không được đọc `auth()`. Với phiên portal của một khách hàng KHÁC đang mở, một lần ghi
 * hợp lệ vẫn phải hạ cánh — đây là chỗ `scopelessly()` và `setRelation('matter', ...)` trả tiền.
 */
it('records for the actor it is given even while another client has a portal session open', function () {
    $stranger = ClientUser::factory()->create();
    $this->actingAs($stranger, 'client');

    $receipt = $this->action->handle($this->log, $this->clientUser, '203.0.113.5');

    expect(allReceipts()->count())->toBe(1)
        ->and($receipt->client_user_id)->toBe($this->clientUser->id);
});

/** Vế dương của câu trên: phiên của người lạ không mở được cửa cho chính người lạ đó. */
it('still refuses the stranger whose portal session happens to be open', function () {
    $stranger = ClientUser::factory()->create();
    $this->actingAs($stranger, 'client');

    expect(fn () => $this->action->handle($this->log, $stranger, '203.0.113.5'))
        ->toThrow(AuthorizationException::class)
        ->and(allReceipts()->count())->toBe(0);
});

it('falls back to the request ip when the caller gives none', function () {
    $receipt = $this->action->handle($this->log, $this->clientUser);

    expect($receipt->ip)->not->toBeEmpty();
});

/**
 * Quy ước tham số ngữ cảnh tuỳ chọn (`DocumentPolicy::create`, `ClientRequestPolicy::create`):
 * một câu hỏi CÓ ngữ cảnh mà ngữ cảnh sai kiểu vẫn là một câu hỏi có ngữ cảnh, nên nó bị TỪ
 * CHỐI — không rơi xuống nhánh "không có ngữ cảnh" (thứ trả `true` cho giao diện), và không nổ
 * thành `TypeError`. Một mutation probe sống sót đã chỉ ra rằng trước test này không có gì ghim
 * điều đó.
 */
it('refuses a create question carrying the wrong kind of context, instead of falling back', function () {
    expect($this->clientUser->can('create', [StageLogView::class, $this->matter]))->toBeFalse()
        ->and($this->clientUser->can('create', [StageLogView::class, $this->clientUser]))->toBeFalse()
        // Vế dương, cùng một lời gọi với ngữ cảnh đúng, và nhánh không ngữ cảnh vẫn mở cho
        // giao diện.
        ->and($this->clientUser->can('create', [StageLogView::class, $this->log]))->toBeTrue()
        ->and($this->clientUser->can('create', StageLogView::class))->toBeTrue();
});

/**
 * Nhân sự không ghi được biên bản này, kể cả quản trị: giá trị của bảng là nó chứng minh KHÁCH
 * đã được cho xem, và một dòng do văn phòng tạo ra không phân biệt được với một dòng thật.
 */
it('never lets a staff account record a view receipt', function () {
    $admin = User::factory()->create();

    expect($admin->can('create', StageLogView::class))->toBeFalse()
        ->and($admin->can('create', [StageLogView::class, $this->log]))->toBeFalse()
        ->and($this->clientUser->can('create', [StageLogView::class, $this->log]))->toBeTrue();
});

/**
 * **Nghi thức ba tầng, đo trên chính Action.** Tầng truy vấn bị làm rỗng — đúng hình dạng "ai đó
 * quên một câu `where`" — và câu hỏi còn lại là: `StageLogViewPolicy::create()` có phải một tầng
 * riêng, hay nó chỉ chạy lại tầng truy vấn?
 *
 * Vòng đầu của Task 2 thì nó chỉ chạy lại: `create` trả `true` trên một dòng NHÁP và Action ghi
 * một biên bản khẳng định khách đã được cho xem một cập nhật văn phòng chưa công bố — bằng chứng
 * lộn ngược. Từ nay `is_published` được đọc thẳng trên bản ghi, nên dòng nháp bị từ chối kể cả
 * khi scope không còn nói gì.
 *
 * Vế dương nằm ngay trong cùng ngữ cảnh thủng: dòng ĐÃ công bố vẫn ghi được, nếu không thì phép
 * đo trên chỉ nói rằng mọi thứ đều bị từ chối.
 */
it('still refuses to record a draft entry when the stage log scope forgets its rule', function () {
    $draft = StageLog::factory()->for($this->matter)->internalOnly()->create();

    StageLog::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        // Tầng truy vấn đã thủng — nếu không thì khẳng định bên dưới không đo tầng policy.
        expect(ClientPortalScope::actingAs($this->clientUser, fn () => StageLog::find($draft->id)))
            ->not->toBeNull();

        expect(fn () => $this->action->handle($draft, $this->clientUser, '203.0.113.5'))
            ->toThrow(AuthorizationException::class)
            ->and(allReceipts()->count())->toBe(0);

        // Vế dương trong CÙNG ngữ cảnh thủng.
        $this->action->handle($this->log, $this->clientUser, '203.0.113.5');

        expect(allReceipts()->count())->toBe(1);
    } finally {
        StageLog::addGlobalScope(new ClientPortalScope);
    }
});
