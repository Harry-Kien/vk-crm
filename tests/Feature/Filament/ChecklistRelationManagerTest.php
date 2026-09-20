<?php

use App\Actions\Document\ChecklistProgress;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Danh mục hồ sơ" (SPEC §7.2): thanh tiến độ `X/Y`, ba thao tác trên dòng, và ai được thấy
 * cái gì.
 *
 * Không test nào ở đây kiểm tra nghiệp vụ của `ReviewChecklistItem` /
 * `MarkChecklistItemNotApplicable` — hai Action đó đã có tệp test riêng, và LUẬT ĐẾM `X/Y` cũng
 * vậy kể từ khi nó rời sang `ChecklistProgress` (xem `ChecklistProgressTest`). Thứ được kiểm ở
 * đây là ba việc mà chỉ màn hình mới làm được sai: VẼ đúng con số Action trả về, hiện đúng nút
 * cho đúng người ở đúng trạng thái, và để lời từ chối của Action đến được mắt người dùng bằng
 * tiếng Việt.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function checklistManager(Matter $matter)
{
    return test()->livewire(ChecklistRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/**
 * Thanh tiến độ phải thật sự XUẤT HIỆN trên tab, không chỉ tính đúng trong Action: nếu
 * `->description()` bị gỡ, `ChecklistProgressTest` vẫn xanh và không ai biết con số đã biến mất
 * khỏi màn hình.
 */
it('renders the progress bar inside the checklist tab itself', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    MatterChecklistItem::factory()->count(2)->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->assertSee(__('checklist.tab.progress', ['submitted' => 2, 'total' => 3]));
});

/**
 * Cột "Số tài liệu" của bảng phải là ĐÚNG phép đếm mà mẫu số `Y` dùng — cùng một
 * `ChecklistProgress::countClientFacingDocuments()`, không phải một `withCount` thứ hai viết lại
 * ở `modifyQueryUsing()`. Không có test này, `modifyQueryUsing()` gỡ bộ đếm đi vẫn để mọi thứ
 * khác xanh và cột hiện ra rỗng trên màn hình.
 *
 * Cặp sinh đôi âm nằm ngay trong cùng test: một đầu mục mà tài liệu duy nhất là nhóm D đọc `0`,
 * đúng như nó không nằm trong mẫu số.
 */
it('counts the same documents in the table column as in the denominator', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $withClientFile = MatterChecklistItem::factory()->for($matter)->create(['is_required' => true]);
    $withInternalNoteOnly = MatterChecklistItem::factory()->for($matter)->create(['is_required' => true]);

    Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'matter_checklist_item_id' => $withClientFile->id,
    ]);
    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'matter_checklist_item_id' => $withInternalNoteOnly->id,
    ]);

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->assertTableColumnStateSet(ChecklistProgress::DOCUMENT_COUNT_ALIAS, 1, $withClientFile)
        ->assertTableColumnStateSet(ChecklistProgress::DOCUMENT_COUNT_ALIAS, 0, $withInternalNoteOnly);
});

it('renders the progress bar with the counted numbers, and a separate sentence when nothing is counted', function () {
    $matter = Matter::factory()->create();

    expect((string) ChecklistRelationManager::progressBar($matter))
        ->toContain(__('checklist.tab.progress_empty'));

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $bar = (string) ChecklistRelationManager::progressBar($matter);

    expect($bar)
        ->toContain(e(__('checklist.tab.progress', ['submitted' => 1, 'total' => 2])))
        ->toContain('width:50%');

    // Cùng phép đo mà `StageLogPaintingTest` dùng: `var(--primary-500)` chỉ tô được gì nếu
    // `FilamentColor` thật sự đăng ký sắc độ đó. Đây là biến màu duy nhất của nhánh M4 chưa có
    // phép đo nào đứng sau (vòng rà soát cuối M4).
    expect(colourVariablesIn($bar))->toContain('primary-500')
        ->and(unregisteredColourVariables($bar))->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// Ai thấy gì — SPEC §5, §11 "Quyền nội bộ".
// ---------------------------------------------------------------------------------------------

/**
 * SPEC §11 "Quyền nội bộ": kế toán không xem được nội dung hồ sơ — và nói cho đúng nơi luật đó
 * được cài, vì chỗ này dễ tin nhầm: **`ScopesToVisibleMatters` KHÔNG loại kế toán.** Kế toán có
 * `matter.viewAny` (SPEC §5 cho họ "danh sách rút gọn") nên `Matter::listableBy` nhận họ, đúng
 * như với tab Tiến độ từ M3. Thứ chặn họ là `MatterPolicy::view` ở cửa TRANG: `ViewMatter` trả
 * 404 nên không tab nào được dựng ra. Test này khẳng định đúng cái cửa đó, không khẳng định một
 * lớp bảo vệ không tồn tại.
 *
 * Cặp sinh đôi dương: một trợ lý trong đội ngũ mở được trang và ĐỌC ĐƯỢC đúng dòng đó.
 */
it('gives an accountant no checklist tab at all, while an assistant on the team reads it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterChecklistItem::factory()->for($matter)->create(['name' => 'Giấy khai sinh của con chung']);

    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertOk()
        ->assertSee(__('matters.tabs.checklist'));

    checklistManager($matter)->assertSee('Giấy khai sinh của con chung');
});

/**
 * Tầng thứ hai: `ScopesToVisibleMatters` chặn ngay trong chính relation manager, kể cả khi nó
 * được mount thẳng (bỏ qua `authorizeAccess()` của trang). Nhân chứng phải là một luật sư NGOÀI
 * đội ngũ — người mà `listableBy` thật sự loại — chứ không phải kế toán.
 */
it('keeps a matter outside the actors scope out of the relation managers own query', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterChecklistItem::factory()->for($matter)->create(['name' => 'Hợp đồng vay viết tay ngày 12/03']);

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($outsider, 'web');

    checklistManager($matter)->assertDontSee('Hợp đồng vay viết tay ngày 12/03');

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)->assertSee('Hợp đồng vay viết tay ngày 12/03');
});

/**
 * `checklist.review` (SPEC §5) là cổng của ba cái nút, và nó phải là cổng THẬT — không phải một
 * điều kiện luôn đúng nhờ một điều kiện khác.
 *
 * **Nhân chứng được dựng bằng cách cấp quyền thẳng cho một tài khoản, có chủ đích.** Bốn vai trò
 * có `matter.view` trong SPEC §5 đều có luôn `checklist.review`, còn kế toán thì thua ở
 * `canSeeMatter` từ trước — nên mọi nhân chứng theo VAI TRÒ sẽ bị từ chối vì một lý do khác, và
 * một test như vậy xanh kể cả khi cổng `checklist.review` bị xoá sạch. Cùng cái bẫy mà vòng sửa
 * rà soát Task 3 đã gặp trong `RegroupDocument`.
 *
 * Cặp sinh đôi nằm trong cùng test: cùng một người, cùng một dòng, chỉ thêm đúng một quyền.
 */
it('gates the three review buttons on checklist.review and nothing else', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    // HAI dòng, vì cổng trạng thái bất đối xứng: "Cần nộp lại" chỉ có nghĩa trên một dòng đang
    // chờ, "Không cần nộp" chỉ có nghĩa trên một dòng KHÔNG đang chờ. Dùng một dòng duy nhất cho
    // cả ba nút sẽ trộn cổng quyền với cổng trạng thái và cho ra một test không nói về cái nào.
    $waiting = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();
    $missing = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create();

    $member = User::factory()->create();
    $member->givePermissionTo([Permission::MatterView->value, Permission::MatterUpdate->value]);
    $matter->addTeamMember($member, MatterRole::Assistant);

    $this->actingAs($member, 'web');

    checklistManager($matter)
        // Dương: người này ĐỌC ĐƯỢC danh mục — lời từ chối bên dưới nói về quyền duyệt, không về
        // khả năng thấy hồ sơ.
        ->assertSee($waiting->name)
        ->assertActionHidden(TestAction::make('accept')->table($waiting))
        ->assertActionHidden(TestAction::make('reject')->table($waiting))
        ->assertActionHidden(TestAction::make('markNotApplicable')->table($missing));

    $member->givePermissionTo(Permission::ChecklistReview->value);

    checklistManager($matter)
        ->assertActionVisible(TestAction::make('accept')->table($waiting))
        ->assertActionVisible(TestAction::make('reject')->table($waiting))
        ->assertActionVisible(TestAction::make('markNotApplicable')->table($missing));
});

/**
 * Và cặp sinh đôi dương cho chính vai trò mà M4 cần chứng minh: một TRỢ LÝ trong đội ngũ duyệt
 * được. SPEC §5 cho trợ lý `checklist.review`, và nếu màn hình này chỉ mở cho luật sư thì cái tab
 * mất đúng người dùng thường xuyên nhất của nó.
 */
it('lets an assistant on the team review an item', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    checklistManager($matter)
        ->callAction(TestAction::make('accept')->table($item))
        ->assertHasNoActionErrors();

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->reviewed_by)->toBe($assistant->id);
});

// ---------------------------------------------------------------------------------------------
// Cổng trạng thái bất đối xứng — chép từ ReviewChecklistItem::guardDecisionAgainstState().
// ---------------------------------------------------------------------------------------------

it('offers accept from every state but offers reject only when something is waiting', function (ChecklistItemStatus $status, bool $canReject) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status($status)->create();

    $this->actingAs($lawyer, 'web');

    $component = checklistManager($matter)->assertActionVisible(TestAction::make('accept')->table($item));

    $canReject
        ? $component->assertActionVisible(TestAction::make('reject')->table($item))
        : $component->assertActionHidden(TestAction::make('reject')->table($item));
})->with([
    'missing — chưa có gì để từ chối' => [ChecklistItemStatus::Missing, false],
    'not_applicable — văn phòng vừa nói là không cần' => [ChecklistItemStatus::NotApplicable, false],
    'pending_review — đường thường' => [ChecklistItemStatus::PendingReview, true],
    'rejected — sửa lại một câu lý do viết chưa rõ' => [ChecklistItemStatus::Rejected, true],
    'accepted — văn phòng nhận ra mình duyệt nhầm' => [ChecklistItemStatus::Accepted, true],
]);

it('hides the not-applicable action while a client file is waiting, and shows it otherwise', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $waiting = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();
    $missing = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create();

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->assertActionHidden(TestAction::make('markNotApplicable')->table($waiting))
        ->assertActionVisible(TestAction::make('markNotApplicable')->table($missing));
});

// ---------------------------------------------------------------------------------------------
// Mỗi lần ghi đi qua đúng Action của nó.
// ---------------------------------------------------------------------------------------------

it('accepts an item through ReviewChecklistItem, recording the reviewer and an audit row', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create([
        'rejection_reason' => 'Lý do cũ còn sót lại từ một vòng từ chối trước đó.',
    ]);

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->callAction(TestAction::make('accept')->table($item))
        ->assertHasNoActionErrors();

    $item->refresh();

    expect($item->status)->toBe(ChecklistItemStatus::Accepted)
        ->and($item->reviewed_by)->toBe($lawyer->id)
        ->and($item->reviewed_at)->not->toBeNull()
        // Action xoá lý do cũ — một dòng đã nhận đủ mà còn treo câu chê là một dòng nói dối, và
        // khách là người đọc nó.
        ->and($item->rejection_reason)->toBeNull()
        ->and(Activity::query()->where('event', 'checklist_item_reviewed')->count())->toBe(1);
});

it('rejects an item through ReviewChecklistItem and stores the sentence the client will read', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->callAction(TestAction::make('reject')->table($item), data: [
            'rejection_reason' => __('checklist.rejection_templates.blurred'),
        ])
        ->assertHasNoActionErrors();

    $item->refresh();

    expect($item->status)->toBe(ChecklistItemStatus::Rejected)
        ->and($item->rejection_reason)->toBe(__('checklist.rejection_templates.blurred'));
});

it('marks an item not applicable through MarkChecklistItemNotApplicable', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create();

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->callAction(TestAction::make('markNotApplicable')->table($item))
        ->assertHasNoActionErrors();

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::NotApplicable);
});

// ---------------------------------------------------------------------------------------------
// Ba họ exception đều ra thành một câu tiếng Việt.
// ---------------------------------------------------------------------------------------------

/**
 * Họ `ValidationException`: Action ném một khoá TRẦN (`rejection_reason`), và một khoá trần không
 * khớp state path của modal đang mở nên Filament không hiện nó ở đâu cả. `ReportsActionFailures`
 * dịch khoá đó sang state path thật.
 *
 * **Payload phải ĐI LỌT schema rồi mới bị Action từ chối, và bản đầu của test này không làm được
 * thế.** Nó gửi 24 khoảng trắng với lập luận "schema đếm 24 ký tự và cho qua" — sai: luật
 * `required` của Laravel `trim()` chuỗi trước khi đếm, nên lần từ chối là của `Textarea::
 * required()`, Action không hề chạy, và test xanh mà không chứng minh gì về `ReportsActionFailures`.
 * Đo được bằng một mutation probe: xoá sạch nhánh `catch (ValidationException)` mà bộ test vẫn
 * xanh.
 *
 * Payload bây giờ là 19 ký tự thật kẹp giữa hai cặp khoảng trắng: 23 ký tự nên `required` và
 * `minLength(20)` của schema đều cho qua, còn `ReviewChecklistItem` `trim()` rồi đếm được 19 và
 * từ chối. Đó là một đường THẬT — một người dán lý do kèm khoảng trắng thừa, đúng ngay ranh giới.
 */
it('binds the Actions validation refusal to the reason field instead of losing it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->callAction(TestAction::make('reject')->table($item), data: [
            'rejection_reason' => '  '.str_repeat('a', 19).'  ',
        ])
        ->assertHasActionErrors(['rejection_reason']);

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/**
 * Họ `DomainException`: một tài khoản bị vô hiệu. `OpensChecklistItem` hỏi `accountIsActive()`
 * cùng vế với `Gate` và ném `ChecklistItemNotReviewable::unavailable()` — một `DomainException`
 * không ai bắt sẽ là lỗi 500, và thông điệp là một câu tiếng Việt viết sẵn cho đúng tình huống
 * này.
 *
 * `canAccessPanel()` chặn tài khoản này ở cửa panel, nên đường tới đây là một trang đã mở từ
 * trước — chính xác tình huống mà câu tiếng Việt kia được viết ra cho.
 */
it('turns a locked account into a Vietnamese notification instead of a 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lawyer, 'web');
    $lawyer->forceFill(['is_active' => false])->save();

    checklistManager($matter)->callAction(TestAction::make('accept')->table($item));

    Notification::assertNotified(__('actions.failed_title'));

    expect($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

// ---------------------------------------------------------------------------------------------
// Ba mẫu lý do của SPEC §6.7.
// ---------------------------------------------------------------------------------------------

/**
 * Ba mẫu phải là NGUYÊN VĂN SPEC §6.7, không diễn đạt lại: SPEC nói thẳng chúng tồn tại để trợ lý
 * không viết "không hợp lệ", và câu chữ là toàn bộ giá trị của chúng. Khẳng định từng chuỗi ở đây
 * để một lần "sửa cho gọn" về sau đỏ ngay.
 */
it('keeps the three SPEC rejection templates verbatim', function () {
    expect(__('checklist.rejection_templates.blurred'))
        ->toBe('Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới ánh sáng tự nhiên, lấy trọn cả bốn góc trang.')
        ->and(__('checklist.rejection_templates.uncertified_copy'))
        ->toBe('Bản này là bản photo chưa chứng thực. Toà yêu cầu bản sao có chứng thực, anh/chị mang bản gốc ra Uỷ ban phường hoặc phòng công chứng để chứng thực giúp em.')
        ->and(__('checklist.rejection_templates.wrong_document'))
        ->toBe('File này là [tên tài liệu đã nộp], còn mục đang cần là [tên đầu mục]. Anh/chị kiểm tra lại giúp em nhé.')
        ->and(array_keys(ChecklistRelationManager::rejectionTemplates()))
        ->toBe(['blurred', 'uncertified_copy', 'wrong_document']);
});

/**
 * "Bấm một cái là điền" (SPEC §6.7) — cái nút thật sự ghi vào ô lý do của modal đang mở, rồi lần
 * gửi tiếp theo lưu đúng câu đó. Không có test này thì ba cái nút có thể là ba cái nút không làm
 * gì và cả bộ test vẫn xanh.
 */
it('fills the reason box from a template button in one click', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create();

    $this->actingAs($lawyer, 'web');

    checklistManager($matter)
        ->mountAction(TestAction::make('reject')->table($item))
        ->callAction(TestAction::make('fill_uncertified_copy')->schemaComponent('rejection_templates'))
        ->assertActionDataSet(['rejection_reason' => __('checklist.rejection_templates.uncertified_copy')])
        ->callMountedAction();

    expect($item->refresh()->rejection_reason)
        ->toBe(__('checklist.rejection_templates.uncertified_copy'));
});
