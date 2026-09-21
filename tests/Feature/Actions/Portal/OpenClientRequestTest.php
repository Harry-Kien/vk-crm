<?php

use App\Actions\Portal\OpenClientRequest;
use App\Enums\ClientRequestStatus;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Khách mở một cuộc trao đổi mới — SPEC §4.14, §8.3 mục 7.
 *
 * Hai thứ được đo ở đây và không đo ở đâu khác: **cổng quyền luôn đi kèm `Matter`** (nghĩa vụ
 * mang sang từ rà soát M4, thứ trước M5 không được ghi ở đâu cả), và **dòng nhật ký mang đúng
 * `causer` là một `ClientUser`** — kể cả khi một phiên nhân sự đang mở trong cùng trình duyệt.
 */
beforeEach(function () {
    $this->action = app(OpenClientRequest::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
});

/** Mọi yêu cầu trong bảng, kể cả của khách khác — đếm thật thay vì đếm qua scope. */
function allRequests(): Builder
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class);
}

it('opens a new thread on the matter with the status nobody has looked at yet', function () {
    $request = $this->action->handle(
        $this->matter,
        $this->clientUser,
        'Xin hỏi về ngày hoà giải',
        'Văn phòng cho tôi hỏi ngày hoà giải đã có chưa ạ.',
    );

    expect(allRequests()->count())->toBe(1)
        ->and($request->matter_id)->toBe($this->matter->id)
        ->and($request->client_user_id)->toBe($this->clientUser->id)
        ->and($request->subject)->toBe('Xin hỏi về ngày hoà giải')
        ->and($request->status)->toBe(ClientRequestStatus::New)
        ->and($request->assigned_to)->toBeNull()
        ->and($request->answered_at)->toBeNull();
});

/**
 * Vế dương của mọi test từ chối bên dưới: ô nhập được cắt khoảng trắng và LƯU đúng thứ đã đo.
 * Không có nó, một Action chỉ biết từ chối vẫn xanh hết.
 */
it('trims what it stores so a field of spaces is an empty field', function () {
    $request = $this->action->handle($this->matter, $this->clientUser, "  Hỏi về án phí \n", '  Nội dung thật  ');

    expect($request->subject)->toBe('Hỏi về án phí')
        ->and($request->content)->toBe('Nội dung thật');

    expect(fn () => $this->action->handle($this->matter, $this->clientUser, '     ', 'Có nội dung'))
        ->toThrow(ValidationException::class);
});

// =========================================================================================
// CỔNG QUYỀN — LUÔN KÈM `Matter`
// =========================================================================================

/**
 * **Nghĩa vụ mang sang từ rà soát M4, đo bằng chính lỗ hổng nó nói tới.**
 *
 * `ClientRequestPolicy::create()` trả `true` VÔ ĐIỀU KIỆN cho mọi `ClientUser` khi được hỏi
 * không kèm ngữ cảnh. Test này khẳng định hai điều cùng lúc: nhánh trống đúng là một cái cổng
 * luôn mở (nên một Action hỏi trống sẽ không chặn gì), và Action này KHÔNG hỏi trống — nó từ
 * chối đúng vụ việc mà nhánh trống cho qua.
 */
it('asks the create ability with the matter, because the empty branch lets everyone through', function () {
    $foreign = Matter::factory()->create(['is_published_to_portal' => true]);

    expect(Gate::forUser($this->clientUser)->allows('create', ClientRequest::class))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequest::class, $foreign]))->toBeFalse()
        ->and(fn () => $this->action->handle($foreign, $this->clientUser, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);
});

it('refuses a matter that is no longer published to the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => $this->action->handle($this->matter, $this->clientUser, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);
});

it('refuses a soft deleted matter', function () {
    $this->matter->delete();

    expect(fn () => $this->action->handle($this->matter, $this->clientUser, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);
});

it('refuses a deactivated portal account', function () {
    $this->clientUser->update(['is_active' => false]);

    expect(fn () => $this->action->handle($this->matter, $this->clientUser->fresh(), 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);
});

/**
 * Xoá mềm một tài khoản KHÔNG hạ cờ `is_active` — xem `ChecksAccountActive`. `$actor` đến từ
 * bên ngoài (một job chạy lại, một lệnh console), nên nó đọc được bằng `withTrashed()`.
 */
it('refuses a soft deleted portal account even though is_active is still true', function () {
    $this->clientUser->delete();
    $trashed = ClientUser::withTrashed()->findOrFail($this->clientUser->id);

    expect($trashed->is_active)->toBeTrue()
        ->and(fn () => $this->action->handle($this->matter, $trashed, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);
});

/**
 * SPEC §10.10: bốn tình huống từ chối không phân biệt được, ở LỚP lẫn ở CÂU CHỮ. So sánh lớp
 * thôi là chưa đủ — thứ người ngoài quan sát được là câu chữ.
 */
it('refuses every situation with one identical vietnamese sentence', function () {
    $foreign = Matter::factory()->create(['is_published_to_portal' => true]);
    $retracted = Matter::factory()->for($this->client)->create(['is_published_to_portal' => false]);
    $ghost = Matter::factory()->for($this->client)->make(['id' => 999999]);

    $messages = collect([$foreign, $retracted, $ghost])
        ->map(function (Matter $matter): string {
            try {
                $this->action->handle($matter, $this->clientUser, 'Chào', 'Nội dung');
            } catch (AuthorizationException $exception) {
                return $exception->getMessage();
            }

            return 'KHÔNG TỪ CHỐI';
        })
        ->unique();

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBe(__('requests.unavailable'))
        ->and($messages->first())->not->toBe('KHÔNG TỪ CHỐI');
});

/**
 * Tham số đi ra từ URL và KHÔNG được tin: Action đọc lại hàng thật, và **mọi giá trị nó ghi
 * xuống đều lấy từ bản đọc lại**, không từ đối tượng caller cầm trong tay.
 *
 * **Đo được, và phép đo chỉ ra đúng tầng nào đang giữ cái gì.** Một đối tượng bị sửa `client_id`
 * trong bộ nhớ KHÔNG mở được cửa nào — nhưng thứ chặn nó là cổng quyền, không phải lần đọc lại:
 * `MatterPolicy::releasedToPortal()` đọc thuộc tính (nên nó TIN giá trị đã bị sửa), còn
 * `visibleToPortal()` chạy lại truy vấn thật và từ chối. Thay lần đọc lại bằng `$target = $matter`
 * để cả bộ test xanh — một mutation probe đã chứng minh đúng điều đó.
 *
 * Nên nửa dưới của test này tồn tại: nó đo cái mà chỉ lần đọc lại giữ được, tức **giá trị được
 * ghi vào nhật ký**. Trên một vụ việc khách THẬT SỰ được xem, một `client_id` bị sửa trong bộ
 * nhớ đi thẳng vào dòng `Audit` nếu Action tin đối tượng — và dòng đó là bằng chứng, không phải
 * một trường hiển thị. Không có nửa này, lần đọc lại là một câu lệnh không test nào nhìn thấy.
 */
it('reads the matter back instead of trusting the object it was handed', function () {
    $foreign = Matter::factory()->create(['is_published_to_portal' => true]);
    $foreign->client_id = $this->client->id;

    expect(fn () => $this->action->handle($foreign, $this->clientUser, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(allRequests()->count())->toBe(0);

    // Cùng một kiểu sửa, nhưng trên một vụ việc khách được xem thật: lần ghi phải mang giá trị
    // của CƠ SỞ DỮ LIỆU.
    $tampered = Matter::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($this->matter->id);
    $tampered->client_id = 987654;

    $this->action->handle($tampered, $this->clientUser, 'Chào', 'Nội dung');

    $activity = Activity::query()->where('event', 'client_request_opened')->latest('id')->firstOrFail();

    expect($activity->properties['client_id'])->toBe($this->client->id)
        ->and($activity->properties['client_id'])->not->toBe(987654);
});

// =========================================================================================
// XÁC THỰC — ở Action, không chỉ ở ô nhập
// =========================================================================================

it('refuses a subject longer than the column, with a vietnamese sentence instead of a database error', function () {
    try {
        $this->action->handle($this->matter, $this->clientUser, str_repeat('a', 201), 'Nội dung');
        $this->fail('Đáng lẽ phải ném ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('subject')
            ->and($exception->errors()['subject'][0])
            ->toBe(__('requests.validation.subject_max', ['max' => 200]));
    }

    expect(allRequests()->count())->toBe(0);

    // Vế dương: đúng 200 ký tự thì lưu được — ranh giới nằm đúng ở cột của SPEC §4.14.
    expect($this->action->handle($this->matter, $this->clientUser, str_repeat('a', 200), 'Nội dung'))
        ->subject->toHaveLength(200);
});

it('refuses an empty subject and an empty body, each with its own sentence', function () {
    foreach ([['', 'Nội dung', 'subject'], ['Chủ đề', '', 'content']] as [$subject, $content, $field]) {
        try {
            $this->action->handle($this->matter, $this->clientUser, $subject, $content);
            $this->fail('Đáng lẽ phải ném ValidationException cho '.$field);
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field)
                ->and($exception->errors()[$field][0])
                ->toBe(__('requests.validation.'.$field.'_required'));
        }
    }

    expect(allRequests()->count())->toBe(0);
});

// =========================================================================================
// NHẬT KÝ — SPEC §10.6, nhánh guard `client`
// =========================================================================================

/**
 * **Vế phân biệt được, không phải vế dễ.** Một test chỉ khẳng định "causer là ClientUser" vẫn
 * xanh khi `causer:` tường minh bị xoá, vì `Audit::record()` rơi về `auth()`. Cảnh phân biệt
 * được hai cách cài đặt là một phiên NHÂN SỰ đang mở cùng lúc: helper ưu tiên guard `web`, nên
 * nếu Action không truyền `causer` thì yêu cầu của khách bị ghi tên một luật sư. Chuyện thường
 * ngày lúc demo và trên máy dùng chung — xem nhật ký thi công Task 1, nơi đúng hình dạng này
 * làm một probe sống sót.
 */
it('credits the client even while a staff session is open in the same browser', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff, 'web');
    $this->actingAs($this->clientUser, 'client');

    $request = $this->action->handle($this->matter, $this->clientUser, 'Chào', 'Nội dung');

    $activity = Activity::query()->where('event', 'client_request_opened')->latest('id')->firstOrFail();

    expect($activity->causer_type)->toBe($this->clientUser->getMorphClass())
        ->and($activity->causer_id)->toBe($this->clientUser->id)
        ->and($activity->subject_id)->toBe($request->id)
        ->and($activity->properties['matter_id'])->toBe($this->matter->id)
        ->and($activity->properties['client_id'])->toBe($this->client->id);
});

it('writes no log line at all when it refuses', function () {
    $foreign = Matter::factory()->create(['is_published_to_portal' => true]);

    expect(fn () => $this->action->handle($foreign, $this->clientUser, 'Chào', 'Nội dung'))
        ->toThrow(AuthorizationException::class)
        ->and(Activity::query()->where('event', 'client_request_opened')->count())->toBe(0);
});
