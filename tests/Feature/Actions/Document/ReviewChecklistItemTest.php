<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Events\ChecklistItemRejected;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->item = MatterChecklistItem::factory()->for($this->matter)
        ->status(ChecklistItemStatus::PendingReview)
        ->create();
});

/** Đúng 20 ký tự tiếng Việt có dấu = 40 byte: ngưỡng tối thiểu của SPEC §6.7, đếm bằng mb_strlen. */
function reviewReason(int $characters = 40): string
{
    return str_repeat('đ', $characters);
}

function reviewChecklistItem(
    MatterChecklistItem $item,
    User $actor,
    ChecklistItemStatus $decision,
    ?string $rejectionReason = null,
): MatterChecklistItem {
    return app(ReviewChecklistItem::class)->handle(
        checklistItem: $item,
        actor: $actor,
        decision: $decision,
        rejectionReason: $rejectionReason,
    );
}

// ---------------------------------------------------------------------------------------------
// SPEC §6.7: nhân sự chọn `accepted` hoặc `rejected`.
// ---------------------------------------------------------------------------------------------

it('duyệt đạt thì ghi trạng thái, người duyệt và thời điểm duyệt', function () {
    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted);

    expect($item->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->reviewed_by)->toBe($this->lawyer->id)
        ->and($item->reviewed_at)->not->toBeNull()
        ->and($this->item->fresh()->status)->toBe(ChecklistItemStatus::Accepted);
});

it('duyệt đạt xoá lý do từ chối cũ', function () {
    $this->item->update([
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa.',
    ]);

    // Lý do từ chối hiện thẳng cho khách (SPEC §6.7). Một đầu mục đã nhận đủ mà còn treo câu
    // "ảnh bị mờ" là một dòng nói dối, và người đọc nó là khách hàng.
    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted)->rejection_reason)
        ->toBeNull();
});

it('từ chối thì lưu nguyên văn lý do kèm người duyệt', function () {
    $reason = 'Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới ánh sáng tự nhiên.';

    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason);

    expect($item->status)->toBe(ChecklistItemStatus::Rejected)
        ->and($item->rejection_reason)->toBe($reason)
        ->and($item->reviewed_by)->toBe($this->lawyer->id)
        ->and($item->reviewed_at)->not->toBeNull();
});

it('lý do từ chối được cắt khoảng trắng thừa nhưng không bị Action thêm chữ nào', function () {
    $reason = reviewReason();

    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, "  {$reason}\n");

    // Câu này đi thẳng ra màn hình của khách, nên Action không được ghép thêm mã hồ sơ, id bản
    // ghi, tên người duyệt hay bất kỳ mẩu thông tin nội bộ nào vào nó.
    expect($item->rejection_reason)->toBe($reason);
});

// ---------------------------------------------------------------------------------------------
// SPEC §11 "Nghiệp vụ": từ chối checklist item không kèm lý do → lỗi xác thực.
// ---------------------------------------------------------------------------------------------

it('từ chối không kèm lý do là lỗi xác thực', function (?string $reason) {
    try {
        reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason);
        $this->fail('Đáng lẽ phải ném ValidationException.');
    } catch (ValidationException $exception) {
        // Khẳng định ĐÚNG CÂU, không chỉ đúng lớp exception: nhánh "chưa nhập gì" và nhánh "nhập
        // rồi nhưng quá ngắn" cùng ném `ValidationException`, nên một test chỉ kiểm lớp sẽ vẫn
        // xanh khi nhánh này bị xoá — và người dùng nhận câu "lý do mới có 0 ký tự".
        expect($exception->errors()['rejection_reason'][0])
            ->toBe(__('checklist.review.reason_required', ['min' => 20]));
    }

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
})->with(['không truyền' => [null], 'chuỗi rỗng' => [''], 'toàn khoảng trắng' => ["  \n\t "]]);

it('lý do từ chối ngắn hơn 20 ký tự bị từ chối, và câu lỗi nói ra còn thiếu bao nhiêu', function () {
    try {
        reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, str_repeat('a', 19));
        $this->fail('Đáng lẽ phải ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['rejection_reason'][0])
            ->toBe(__('checklist.review.reason_too_short', ['length' => 19, 'min' => 20]));
    }

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('lý do từ chối đúng 20 ký tự thì chấp nhận — cặp dương của ngưỡng', function () {
    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, str_repeat('a', 20))->status)
        ->toBe(ChecklistItemStatus::Rejected);
});

it('đếm 20 ký tự bằng mb_strlen chứ không bằng byte', function () {
    // 20 ký tự tiếng Việt có dấu = 40 byte. Một ngưỡng đếm byte sẽ cho chuỗi này qua khi nó mới
    // có 10 ký tự — bài học M3, và ở đây nó có nghĩa là khách nhận được một câu cụt lủn.
    expect(fn () => reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, str_repeat('đ', 19)))
        ->toThrow(ValidationException::class);

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, str_repeat('đ', 20))->status)
        ->toBe(ChecklistItemStatus::Rejected);
});

it('lý do kèm theo một lần duyệt ĐẠT bị bỏ qua chứ không lưu vào dòng khách đọc', function () {
    $item = reviewChecklistItem(
        $this->item,
        $this->lawyer,
        ChecklistItemStatus::Accepted,
        'Câu này không bao giờ được hiện cho khách vì đầu mục đã đạt.',
    );

    expect($item->rejection_reason)->toBeNull();
});

it('chỉ nhận hai kết quả duyệt, mọi trạng thái khác là lỗi xác thực', function (ChecklistItemStatus $decision) {
    try {
        reviewChecklistItem($this->item, $this->lawyer, $decision, reviewReason());
        $this->fail('Đáng lẽ phải ném ValidationException.');
    } catch (ValidationException $exception) {
        // Câu lỗi chỉ sang hai cái nút trên màn hình, nên nó phải gọi chúng đúng tên mà chúng
        // mang — và tên đó là `ChecklistItemStatus::label()`, không phải một chuỗi chép tay.
        expect($exception->errors()['status'][0])
            ->toContain(ChecklistItemStatus::Accepted->label())
            ->toContain(ChecklistItemStatus::Rejected->label());
    }

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
})->with([
    'missing' => [ChecklistItemStatus::Missing],
    'pending_review' => [ChecklistItemStatus::PendingReview],
    'not_applicable' => [ChecklistItemStatus::NotApplicable],
]);

// ---------------------------------------------------------------------------------------------
// Ba mẫu lý do ở SPEC §6.7, nguyên văn — giao diện Task 6 điền một chạm.
// ---------------------------------------------------------------------------------------------

it('ba mẫu lý do từ chối có nguyên văn trong lang/vi', function () {
    expect(__('checklist.rejection_templates'))->toBe([
        'blurred' => 'Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới ánh sáng tự nhiên, lấy trọn cả bốn góc trang.',
        'uncertified_copy' => 'Bản này là bản photo chưa chứng thực. Toà yêu cầu bản sao có chứng thực, anh/chị mang bản gốc ra Uỷ ban phường hoặc phòng công chứng để chứng thực giúp em.',
        'wrong_document' => 'File này là [tên tài liệu đã nộp], còn mục đang cần là [tên đầu mục]. Anh/chị kiểm tra lại giúp em nhé.',
    ]);
});

it('mỗi mẫu lý do bấm một cái là qua được ngưỡng độ dài', function (string $key) {
    // Một cái nút điền sẵn một câu rồi bị chính hệ thống từ chối là cái nút không ai bấm lần thứ
    // hai. Ràng buộc này nối hai tệp không đọc lẫn nhau, nên nó phải có một dòng test.
    $template = __("checklist.rejection_templates.{$key}");

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $template)->rejection_reason)
        ->toBe($template);
})->with(['blurred', 'uncertified_copy', 'wrong_document']);

// ---------------------------------------------------------------------------------------------
// Quyền: SPEC §5 `checklist.review`.
// ---------------------------------------------------------------------------------------------

it('trợ lý trong đội ngũ duyệt được — SPEC §6.7 viết cho đúng người này', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    expect(reviewChecklistItem($this->item, $assistant, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
});

it('người thấy và sửa được hồ sơ nhưng không có checklist.review thì không duyệt được', function () {
    // Không vai trò nào ở SPEC §5 có `matter.view` mà thiếu `checklist.review`, nên một test dùng
    // vai trò sẵn có sẽ xanh vì lý do KHÁC (không thấy hồ sơ) và cái cổng `checklist.review` trở
    // thành một điều kiện không ai kiểm. Nhân chứng phải dựng bằng cách cấp quyền thẳng cho một
    // tài khoản — đúng bài học từ vòng sửa Task 3.
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo([Permission::MatterView->value, Permission::MatterUpdate->value]);
    $this->matter->addTeamMember($reviewer, MatterRole::Assistant);

    expect($reviewer->can('view', $this->matter))->toBeTrue()
        ->and($reviewer->can('update', $this->matter))->toBeTrue();

    expect(fn () => reviewChecklistItem($this->item, $reviewer, ChecklistItemStatus::Accepted))
        ->toThrow(AuthorizationException::class);

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

it('cùng người đó có thêm checklist.review thì duyệt được — cặp dương', function () {
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo([
        Permission::MatterView->value,
        Permission::MatterUpdate->value,
        Permission::ChecklistReview->value,
    ]);
    $this->matter->addTeamMember($reviewer, MatterRole::Assistant);

    expect(reviewChecklistItem($this->item, $reviewer, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
});

it('luật sư ngoài đội ngũ không duyệt được', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => reviewChecklistItem($this->item, $outsider, ChecklistItemStatus::Accepted))
        ->toThrow(AuthorizationException::class);
});

it('kế toán không duyệt được', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => reviewChecklistItem($this->item, $accountant, ChecklistItemStatus::Accepted))
        ->toThrow(AuthorizationException::class);
});

it('không dùng phiên đăng nhập làm nguồn quyền', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($this->lawyer, 'web');

    expect(fn () => reviewChecklistItem($this->item, $outsider, ChecklistItemStatus::Accepted))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------------------------
// Trạng thái bản ghi: cùng hạng với các cổng của `PublishDocument`, và đều là `DomainException`
// để màn hình Task 6 chỉ phải bắt một lớp.
// ---------------------------------------------------------------------------------------------

it('không duyệt được đầu mục của một hồ sơ đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();

    expect(fn () => reviewChecklistItem($this->item->fresh(), $admin, ChecklistItemStatus::Accepted))
        ->toThrow(ChecklistItemNotReviewable::class);
});

it('quản trị viên duyệt được đầu mục của một hồ sơ còn sống — cặp dương', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(reviewChecklistItem($this->item, $admin, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
});

it('không duyệt được một đầu mục đã bị xoá khỏi danh mục', function () {
    $this->item->delete();

    expect(fn () => reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted))
        ->toThrow(ChecklistItemNotReviewable::class);
});

it('không duyệt được một đầu mục không còn tồn tại', function () {
    $this->item->forceDelete();

    expect(fn () => reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted))
        ->toThrow(ChecklistItemNotReviewable::class);
});

it('tin dòng dữ liệu thật chứ không tin đối tượng caller cầm trong tay', function () {
    // Đầu mục THẬT thuộc hồ sơ của $this->lawyer; đối tượng trong bộ nhớ khai rằng nó thuộc hồ sơ
    // của một luật sư khác, tức khai rằng người đang bấm nút có quyền trên nó.
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $outsider->id]);

    $tampered = $this->item->replicate();
    $tampered->id = $this->item->id;
    $tampered->exists = true;
    $tampered->matter_id = $otherMatter->id;

    expect(fn () => reviewChecklistItem($tampered, $outsider, ChecklistItemStatus::Accepted))
        ->toThrow(AuthorizationException::class);

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

// ---------------------------------------------------------------------------------------------
// Dấu vết và thông báo.
// ---------------------------------------------------------------------------------------------

it('ghi dòng nhật ký với actor tường minh, kể cả khi phiên đăng nhập là người khác', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted);

    $activity = Activity::query()->where('event', 'checklist_item_reviewed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->subject?->is($item))->toBeTrue()
        ->and($activity->properties->get('matter_id'))->toBe($this->matter->id)
        // `matters.client_id` là một cột sửa được, nên "đầu mục này của khách nào" suy qua hồ sơ
        // không ổn định qua thời gian — cùng lý lẽ với `PublishDocument`.
        ->and($activity->properties->get('client_id'))->toBe($this->client->id)
        ->and($activity->properties->get('status'))->toBe(ChecklistItemStatus::Accepted->value)
        ->and($activity->properties->get('rejection_reason'))->toBeNull();
});

it('dòng nhật ký của một lần từ chối mang theo đúng câu khách sẽ đọc', function () {
    $reason = reviewReason();

    reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason);

    $activity = Activity::query()->where('event', 'checklist_item_reviewed')->latest('id')->first();

    // `matter_checklist_items` chưa dùng `LogsActivity`, nên dòng này là dấu vết DUY NHẤT của
    // câu văn phòng đã nói với khách; một lần sửa lý do về sau sẽ ghi đè cột mà không ghi đè đây.
    expect($activity->properties->get('status'))->toBe(ChecklistItemStatus::Rejected->value)
        ->and($activity->properties->get('rejection_reason'))->toBe($reason);
});

it('dispatch sự kiện báo khách khi bị từ chối', function () {
    Event::fake([ChecklistItemRejected::class]);

    $reason = reviewReason();
    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason);

    Event::assertDispatched(
        ChecklistItemRejected::class,
        fn (ChecklistItemRejected $event) => $event->checklistItem->is($item),
    );
});

it('không dispatch sự kiện nào khi duyệt đạt', function () {
    Event::fake([ChecklistItemRejected::class]);

    reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted);

    // SPEC §9 chỉ có mẫu email `client.document_rejected`; không có mẫu nào cho một lần duyệt
    // đạt, và một email "giấy tờ của anh/chị đã đạt" cho mỗi đầu mục là thứ khách học cách bỏ qua.
    Event::assertNotDispatched(ChecklistItemRejected::class);
});
