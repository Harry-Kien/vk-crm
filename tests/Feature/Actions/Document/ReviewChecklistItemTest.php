<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Events\ChecklistItemRejected;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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
    ?array $documentIds = null,
): MatterChecklistItem {
    return app(ReviewChecklistItem::class)->handle(
        checklistItem: $item,
        actor: $actor,
        decision: $decision,
        rejectionReason: $rejectionReason,
        documentIds: $documentIds,
    );
}

/**
 * Một lần duyệt bị từ chối vì "không mở được mục này" — SPEC §10.10.
 *
 * Ba tình huống (không tồn tại, đã xoá khỏi danh mục, của hồ sơ người khác) phải ra đúng MỘT lớp
 * và đúng MỘT câu. Khẳng định câu chữ chứ không chỉ lớp: hai câu khác nhau trên cùng một lớp vẫn
 * là một cái máy dò, và người dò đọc câu chứ không đọc tên lớp.
 */
function expectReviewRefusal(Closure $call): void
{
    expect($call)->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
        ->toBe(__('checklist.review.item_unavailable')));
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

/**
 * checklist-06: lý do còn để nguyên chỗ trống `[tên …]` của một mẫu có sẵn — SPEC §6.7 in nguyên
 * văn ba mẫu kèm cặp ngoặc vuông làm chỗ trống người duyệt tự điền tay (docblock
 * `rejection_templates`). Bấm mẫu rồi gửi luôn mà quên sửa là gửi cho khách nguyên văn cặp ngoặc
 * đó — `ChecklistRelationManagerTest` đo đúng ca này qua màn hình thật.
 */
it('lý do còn để nguyên chỗ trống [tên …] của mẫu là lỗi xác thực', function () {
    $reason = 'File này là [tên tài liệu đã nộp], còn mục đang cần là Giấy chứng nhận quyền sử dụng đất.';

    try {
        reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason);
        $this->fail('Đáng lẽ phải ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['rejection_reason'][0])
            ->toBe(__('checklist.review.reason_placeholder'));
    }

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/** Cặp dương: cùng độ dài, cùng nội dung xung quanh, chỉ khác chỗ đã điền tay thay vì để `[tên`. */
it('lý do đã điền tay thay cho chỗ trống của mẫu thì được chấp nhận — cặp dương', function () {
    $reason = 'File này là ảnh mặt sau CCCD, còn mục đang cần là Giấy chứng nhận quyền sử dụng đất.';

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $reason)->rejection_reason)
        ->toBe($reason);
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
    //
    // `wrong_document` KHÔNG nằm trong tập này (checklist-06, M6.5 Task 17): nguyên văn SPEC §6.7
    // của mẫu đó còn mang cặp ngoặc vuông `[tên …]`, một chỗ trống người duyệt PHẢI tự điền tay,
    // và bản thân cặp ngoặc vuông giờ bị chặn ở `resolveRejectionReason()` — "bấm rồi gửi luôn"
    // không còn qua được cổng cho đúng mẫu đó, có chủ đích. Xem `it('lý do còn để nguyên chỗ
    // trống [tên …] của mẫu là lỗi xác thực')` cho nhánh chặn, và
    // `it('lý do đã điền tay thay cho chỗ trống của mẫu thì được chấp nhận — cặp dương')` cho cặp
    // dương của mẫu đó.
    $template = __("checklist.rejection_templates.{$key}");

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, $template)->rejection_reason)
        ->toBe($template);
})->with(['blurred', 'uncertified_copy']);

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

    expectReviewRefusal(fn () => reviewChecklistItem($this->item, $reviewer, ChecklistItemStatus::Accepted));

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

    expectReviewRefusal(fn () => reviewChecklistItem($this->item, $outsider, ChecklistItemStatus::Accepted));
});

it('kế toán không duyệt được', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expectReviewRefusal(fn () => reviewChecklistItem($this->item, $accountant, ChecklistItemStatus::Accepted));
});

it('không dùng phiên đăng nhập làm nguồn quyền', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($this->lawyer, 'web');

    expectReviewRefusal(fn () => reviewChecklistItem($this->item, $outsider, ChecklistItemStatus::Accepted));
});

// ---------------------------------------------------------------------------------------------
// Trạng thái bản ghi: cùng hạng với các cổng của `PublishDocument`, và đều là `DomainException`
// để màn hình Task 6 chỉ phải bắt một lớp.
// ---------------------------------------------------------------------------------------------

it('không duyệt được đầu mục của một hồ sơ đã xoá mềm', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();

    // Người này ĐÃ qua cổng quyền (quản trị viên thấy cả hồ sơ đã xoá mềm, cố ý, để còn khôi
    // phục được), nên câu trả lời của họ được phép nói ra chuyện gì đã xảy ra và cách sửa.
    expect(fn () => reviewChecklistItem($this->item->fresh(), $admin, ChecklistItemStatus::Accepted))
        ->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
            ->toBe(__('checklist.review.matter_unavailable')));
});

it('quản trị viên duyệt được đầu mục của một hồ sơ còn sống — cặp dương', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(reviewChecklistItem($this->item, $admin, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
});

it('ba tình huống "không mở được mục này" trả lời giống hệt nhau — SPEC §10.10', function (string $situation) {
    // Đã xoá khỏi danh mục / không còn tồn tại / thuộc hồ sơ người khác. Nếu ba tình huống này
    // trả lời khác nhau thì chính bộ ba câu trả lời đó là một cái máy dò: gửi một id bất kỳ, đọc
    // câu trả lời, biết bản ghi có thật hay không — cho một người SPEC §5 không cấp quyền nào
    // trên hồ sơ ấy. Cùng luật mà `AnswerDeniedPanelRequestsWithNotFound` đã áp cho cả panel.
    //
    // Khẳng định ĐÚNG CÂU, không chỉ đúng lớp: lớp là thứ mã nguồn thấy, câu chữ (và kèm theo
    // nó là kết cục HTTP) mới là thứ người dò thấy.
    $actor = $this->lawyer;

    match ($situation) {
        'đã xoá khỏi danh mục' => $this->item->delete(),
        'không còn tồn tại' => $this->item->forceDelete(),
        'của hồ sơ người khác' => $actor = User::factory()->withRole(Role::Lawyer)->create(),
        // Tình huống thứ tư, và là cái đắt nhất: đầu mục CÓ THẬT, hồ sơ của nó vừa bị xoá mềm,
        // người hỏi không có quyền gì trên hồ sơ đó. Câu "hồ sơ đã bị xoá nên không duyệt được"
        // chỉ với tới được khi đầu mục có thật, nên trả nó cho người ngoài là xác nhận cái id họ
        // vừa gõ là một id thật.
        'của hồ sơ người khác, đã bị xoá mềm' => (function () use (&$actor) {
            $actor = User::factory()->withRole(Role::Lawyer)->create();
            $this->matter->delete();
        })(),
    };

    expect(fn () => reviewChecklistItem($this->item, $actor, ChecklistItemStatus::Accepted))
        ->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
            ->toBe(__('checklist.review.item_unavailable')));
})->with([
    'đã xoá khỏi danh mục',
    'không còn tồn tại',
    'của hồ sơ người khác',
    'của hồ sơ người khác, đã bị xoá mềm',
]);

it('tin dòng dữ liệu thật chứ không tin đối tượng caller cầm trong tay', function () {
    // Đầu mục THẬT thuộc hồ sơ của $this->lawyer; đối tượng trong bộ nhớ khai rằng nó thuộc hồ sơ
    // của một luật sư khác, tức khai rằng người đang bấm nút có quyền trên nó.
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $outsider->id]);

    $tampered = $this->item->replicate();
    $tampered->id = $this->item->id;
    $tampered->exists = true;
    $tampered->matter_id = $otherMatter->id;

    expectReviewRefusal(fn () => reviewChecklistItem($tampered, $outsider, ChecklistItemStatus::Accepted));

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

// ---------------------------------------------------------------------------------------------
// Cổng trạng thái, bất đối xứng: NHẬN được từ mọi trạng thái, TỪ CHỐI thì phải có cái để từ chối.
// ---------------------------------------------------------------------------------------------

it('không từ chối được một đầu mục khách chưa nộp gì', function (ChecklistItemStatus $status) {
    // `rejection_reason` hiện NGUYÊN VĂN cho khách (SPEC §6.7, §8.3 mục 4) và một lần từ chối
    // còn bắn sự kiện dẫn tới email `client.document_rejected` của SPEC §9. Từ chối một đầu mục
    // `missing` là gửi cho khách câu "ảnh anh/chị gửi bị mờ" về một tấm ảnh họ chưa từng gửi.
    $this->item->update(['status' => $status]);

    expect(fn () => reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, reviewReason()))
        ->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
            ->toBe(__('checklist.review.nothing_to_reject', ['status' => $status->label()])));

    expect($this->item->fresh()->status)->toBe($status)
        ->and($this->item->fresh()->rejection_reason)->toBeNull();
})->with([
    'missing' => [ChecklistItemStatus::Missing],
    'not_applicable' => [ChecklistItemStatus::NotApplicable],
]);

it('không dispatch sự kiện báo khách khi lần từ chối đó bị chặn ở cổng trạng thái', function () {
    Event::fake([ChecklistItemRejected::class]);

    $this->item->update(['status' => ChecklistItemStatus::Missing]);

    expect(fn () => reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, reviewReason()))
        ->toThrow(ChecklistItemNotReviewable::class);

    Event::assertNotDispatched(ChecklistItemRejected::class);
});

it('từ chối được từ những trạng thái ĐÃ có tệp trên bàn — cặp dương', function (ChecklistItemStatus $status) {
    // `pending_review` là đường thường. `rejected` là lần sửa lại một câu lý do viết chưa rõ —
    // câu đó hiện thẳng cho khách nên phải sửa được. `accepted` là lần văn phòng nhận ra mình
    // duyệt nhầm, và đây là đường DUY NHẤT quay lại: SPEC không có thao tác "bỏ duyệt".
    $this->item->update(['status' => $status]);

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Rejected, reviewReason())->status)
        ->toBe(ChecklistItemStatus::Rejected);
})->with([
    'pending_review' => [ChecklistItemStatus::PendingReview],
    'rejected' => [ChecklistItemStatus::Rejected],
    'accepted' => [ChecklistItemStatus::Accepted],
]);

it('duyệt ĐẠT được từ mọi trạng thái, kể cả missing', function (ChecklistItemStatus $status) {
    // Bất đối xứng có chủ đích: khách mang giấy tờ ra tận văn phòng đưa tay là chuyện xảy ra
    // hằng ngày, và lúc đó đầu mục vẫn đang `missing`. Chặn nhánh này sẽ buộc trợ lý phải bịa ra
    // một lần nộp trên portal để rồi tự duyệt nó.
    $this->item->update(['status' => $status]);

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
})->with([
    'missing' => [ChecklistItemStatus::Missing],
    'pending_review' => [ChecklistItemStatus::PendingReview],
    'rejected' => [ChecklistItemStatus::Rejected],
    'not_applicable' => [ChecklistItemStatus::NotApplicable],
]);

// ---------------------------------------------------------------------------------------------
// SPEC §10.9 ở phía nhân sự: xem `ChecksAccountActive`.
// ---------------------------------------------------------------------------------------------

it('tài khoản nhân sự đã bị vô hiệu hoá thì không duyệt được', function () {
    // `canAccessPanel()` là chỗ DUY NHẤT đọc `is_active` cho tới hôm nay, nên nó chỉ chặn được
    // những lời gọi đi qua panel. Action này được viết để không đọc `auth()`, tức để gọi được
    // từ một job hay một lệnh console — và ở đó không có panel nào cả.
    $this->lawyer->update(['is_active' => false]);

    expectReviewRefusal(fn () => reviewChecklistItem($this->item, $this->lawyer->fresh(), ChecklistItemStatus::Accepted));

    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

// ---------------------------------------------------------------------------------------------
// R11 (M6.5 Task 17, checklist-04): duyệt gắn với đúng những tệp người duyệt đã THẤY.
// ---------------------------------------------------------------------------------------------

/** Một tài liệu nhóm A "khách cung cấp" thật, gắn vào $this->item — dùng cho các test R11. */
function reviewSubmittedDocument(MatterChecklistItem $item, int $version = 1): Document
{
    return Document::factory()->for($item->matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $item->getKey(),
        'version' => $version,
    ]);
}

it('currentDocumentIds đọc đúng tập tài liệu version mới nhất, không đọc nhóm B/C/D', function () {
    $old = reviewSubmittedDocument($this->item, version: 1);
    $latest = reviewSubmittedDocument($this->item, version: 2);
    Document::factory()->for($this->matter)->group(DocumentGroup::Authority)->create([
        'matter_checklist_item_id' => $this->item->id,
        'version' => 2,
    ]);

    expect(ReviewChecklistItem::currentDocumentIds($this->item->fresh()))->toBe([$latest->id])
        ->and(ReviewChecklistItem::currentDocumentIds($this->item->fresh()))->not->toContain($old->id);
});

it('duyệt khớp đúng tập tài liệu đã truyền — cặp dương', function () {
    $document = reviewSubmittedDocument($this->item);

    $item = reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted, documentIds: [$document->id]);

    expect($item->status)->toBe(ChecklistItemStatus::Accepted);
});

/**
 * Kịch bản đúng nguyên văn finding checklist-04: người duyệt mở hộp (tập tài liệu lúc đó chỉ có
 * `$document`), khách gửi thêm MỘT tệp nữa trong lúc hộp còn mở (không đổi trạng thái đầu mục,
 * vẫn `pending_review`), rồi người duyệt bấm "Đã nhận" với tập id CŨ. Action phải từ chối bằng
 * câu tiếng Việt, không âm thầm nhận một tệp chưa ai mở.
 */
it('từ chối khi tập tài liệu đã đổi giữa lúc mở hộp và lúc bấm lưu', function () {
    $document = reviewSubmittedDocument($this->item);

    // Khách gửi thêm một tệp trong lúc đầu mục còn `pending_review` — R10 (M6.5 Task 17): đó là
    // BỔ SUNG vào version đang chờ, không phải một version mới, nên `$newFile` mang CÙNG version
    // với `$document`. Sau lần gửi này, "tập tài liệu hiện tại" của đầu mục là CẢ HAI — đúng
    // hình dạng thật của bug gốc: người duyệt đang nhìn một hộp chỉ có `$document`.
    $newFile = reviewSubmittedDocument($this->item, version: 1);

    expect(fn () => reviewChecklistItem(
        $this->item,
        $this->lawyer,
        ChecklistItemStatus::Accepted,
        documentIds: [$document->id],
    ))->toThrow(fn (ChecklistItemNotReviewable $exception) => expect($exception->getMessage())
        ->toBe(__('checklist.review.documents_changed')));

    // Không ghi gì: đầu mục vẫn chờ đúng người duyệt mở lại.
    expect($this->item->fresh()->status)->toBe(ChecklistItemStatus::PendingReview)
        ->and($this->item->fresh()->reviewed_by)->toBeNull();

    // Không lẫn với tập tài liệu MỚI: cặp dương ngay dưới đây khẳng định lần duyệt tiếp theo với
    // tập ĐÚNG (gồm cả tệp mới) vẫn đi qua bình thường.
    expect(reviewChecklistItem(
        $this->item->fresh(),
        $this->lawyer,
        ChecklistItemStatus::Accepted,
        documentIds: [$document->id, $newFile->id],
    )->status)->toBe(ChecklistItemStatus::Accepted);
});

it('dòng audit ghi id các tài liệu đã duyệt', function () {
    $document = reviewSubmittedDocument($this->item);

    reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted, documentIds: [$document->id]);

    $activity = Activity::query()->where('event', 'checklist_item_reviewed')->latest('id')->first();

    expect($activity->properties->get('document_ids'))->toBe([$document->id]);
});

/**
 * `documentIds === null` là đường lùi cho caller KHÔNG quan sát hộp (job, console, test tầng
 * Action) — không phải một cách để bỏ qua luật. Không có cặp dương này, người đọc dễ tưởng nhầm
 * R11 là bắt buộc tuyệt đối ở tầng Action.
 */
it('bỏ qua lần so tài liệu khi caller không truyền documentIds — đường lùi có chủ đích', function () {
    reviewSubmittedDocument($this->item);
    reviewSubmittedDocument($this->item, version: 2);

    expect(reviewChecklistItem($this->item, $this->lawyer, ChecklistItemStatus::Accepted)->status)
        ->toBe(ChecklistItemStatus::Accepted);
});
