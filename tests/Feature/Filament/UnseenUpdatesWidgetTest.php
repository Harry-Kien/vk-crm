<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * SPEC §7.1 mục 5 — "Khách chưa xem cập nhật".
 *
 * Mỗi điều kiện của truy vấn có **một cặp**: một dòng nằm trong widget và một dòng chỉ khác nó ở
 * đúng điều kiện đang đo. Một test chỉ khẳng định "dòng X không có ở đây" xanh y hệt khi truy vấn
 * trả về rỗng, và khi đó nó không đo gì cả.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->sibling = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
});

/** Dòng đã công bố cách đây `$days` ngày. */
function agedLog(Matter $matter, int $days, string $content): StageLog
{
    return StageLog::factory()->for($matter)->published()->create([
        'published_at' => now()->subDays($days),
        'public_content' => $content,
    ]);
}

function unseenRows(User $user): array
{
    return UnseenUpdatesWidget::rowsFor($user)->pluck('public_content')->all();
}

// =========================================================================================
// NGƯỠNG 5 NGÀY — cặp dương/âm
// =========================================================================================

it('lists a published update nobody has opened for more than five days, and not one from yesterday', function () {
    agedLog($this->matter, 9, 'Chín ngày chưa ai mở');
    agedLog($this->matter, 1, 'Một ngày chưa ai mở');

    expect(unseenRows($this->lawyer))->toBe(['Chín ngày chưa ai mở']);
});

it('measures the clock from published_at, not from occurred_at', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        // Chuyện xảy ra từ lâu, nhưng văn phòng mới đưa tin ra hôm qua: khách mới có một ngày.
        'occurred_at' => now()->subDays(40),
        'published_at' => now()->subDay(),
        'public_content' => 'Vừa công bố hôm qua',
    ]);

    StageLog::factory()->for($this->matter)->published()->create([
        'occurred_at' => now()->subDay(),
        'published_at' => now()->subDays(9),
        'public_content' => 'Công bố chín ngày trước',
    ]);

    expect(unseenRows($this->lawyer))->toBe(['Công bố chín ngày trước']);
});

// =========================================================================================
// BIÊN BẢN ĐÃ XEM — và nó đọc theo KHÁCH HÀNG, không theo từng tài khoản
// =========================================================================================

it('drops a row as soon as any portal account of that client has a receipt for it', function () {
    $seen = agedLog($this->matter, 9, 'Đã có người mở');
    $unseen = agedLog($this->matter, 9, 'Chưa ai mở');

    // Biên bản của tài khoản THỨ HAI — nếu widget đọc theo từng tài khoản thì dòng này vẫn nằm
    // lại, và test đỏ.
    StageLogView::factory()->for($seen, 'stageLog')->create(['client_user_id' => $this->sibling->id]);

    expect(unseenRows($this->lawyer))->toBe(['Chưa ai mở'])
        ->and($unseen->views()->count())->toBe(0);
});

// =========================================================================================
// CÁC ĐIỀU KIỆN CÒN LẠI — mỗi cái một cặp
// =========================================================================================

it('never lists an unpublished entry', function () {
    agedLog($this->matter, 9, 'Đã công bố');
    StageLog::factory()->for($this->matter)->internalOnly()->create([
        'public_content' => 'Nháp chưa công bố',
    ]);

    expect(unseenRows($this->lawyer))->toBe(['Đã công bố']);
});

/**
 * Điều kiện SPEC không viết ra, và cái giá của nó được ghi ở docblock widget: một dòng trên một
 * hồ sơ đã bị gỡ khỏi cổng thì khách KHÔNG CÓ ĐƯỜNG NÀO mở ra, nên nó sẽ nằm đây vĩnh viễn và
 * không cú điện thoại nào làm nó biến mất.
 */
it('never lists an entry on a matter that is no longer on the portal', function () {
    agedLog($this->matter, 9, 'Hồ sơ còn trên cổng');

    $retracted = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => false,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    agedLog($retracted, 9, 'Hồ sơ đã gỡ khỏi cổng');

    expect(unseenRows($this->lawyer))->toBe(['Hồ sơ còn trên cổng']);
});

it('never lists an entry on a soft deleted matter', function () {
    agedLog($this->matter, 9, 'Hồ sơ còn hiệu lực');

    $deleted = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
    agedLog($deleted, 9, 'Hồ sơ đã xoá mềm');
    $deleted->delete();

    expect(unseenRows($this->lawyer))->toBe(['Hồ sơ còn hiệu lực']);
});

/**
 * SPEC §7.1 mục 5 KHÔNG nêu `closed_at`, khác §6.4 và §6.9 nơi SPEC nói thẳng "chưa đóng". Một
 * cập nhật cuối cùng trên một hồ sơ vừa đóng mà khách chưa từng nhìn thấy là đúng cuộc gọi đáng
 * thực hiện nhất — thường nó là câu "việc của anh/chị đã xong".
 */
it('still lists an entry on a closed matter, because SPEC does not exclude one', function () {
    $this->matter->update(['closed_at' => now()->subDay()]);
    agedLog($this->matter, 9, 'Hồ sơ đã đóng');

    expect(unseenRows($this->lawyer))->toBe(['Hồ sơ đã đóng']);
});

// =========================================================================================
// PHẠM VI THEO `listableBy` — nhánh âm VÀ cặp sinh đôi dương của nó
// =========================================================================================

/**
 * Vụ việc `restricted` (SPEC §4.6) chỉ hiện cho luật sư phụ trách và quản trị. Cả hai vế đo trên
 * CÙNG một dòng dữ liệu, nên chênh lệch duy nhất giữa chúng là người đang hỏi.
 */
it('hides a restricted matters row from a lawyer off the team and shows it to the lead lawyer', function () {
    $restricted = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);
    agedLog($restricted, 9, 'Nội dung hồ sơ hạn chế');

    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(unseenRows($outsider))->toBe([])
        ->and(unseenRows($this->lawyer))->toBe(['Nội dung hồ sơ hạn chế']);
});

/**
 * Cặp sinh đôi dương của test trên, trên một vụ việc **thường**: `scopeListableBy()` cho một luật
 * sư đọc đúng những vụ có tên họ trong `matter_user` (SPEC §5 — vai luật sư không có
 * `matter.viewAny`), nên chênh lệch duy nhất giữa hai vế là một dòng trong bảng đội ngũ.
 *
 * Vế này cần thiết chứ không thừa: nếu widget vô tình hẹp lại theo một điều kiện khác (ví dụ
 * `lead_lawyer_id`), test `restricted` bên trên vẫn xanh vì luật sư ở đó CŨNG là luật sư phụ
 * trách.
 */
it('shows a normal matters row once the lawyer is on its team, and not before', function () {
    $other = Matter::factory()->create(['is_published_to_portal' => true]);
    agedLog($other, 9, 'Hồ sơ thường của đội khác');

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(unseenRows($lawyer))->not->toContain('Hồ sơ thường của đội khác');

    $other->addTeamMember($lawyer, MatterRole::Assistant);

    expect(unseenRows($lawyer))->toContain('Hồ sơ thường của đội khác');
});

// =========================================================================================
// AI THẤY WIDGET
// =========================================================================================

it('shows the widget to someone with matter.view and hides it from an accountant', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($this->lawyer, 'web');
    expect(UnseenUpdatesWidget::canView())->toBeTrue();

    $this->actingAs($accountant, 'web');
    expect(UnseenUpdatesWidget::canView())->toBeFalse();
});

// =========================================================================================
// THỨ TỰ SPEC §7.1, VÀ MỘT CON SỐ KHÔNG TRÙNG
// =========================================================================================

/**
 * M4 đo được trên trình duyệt rằng hai widget cùng `$sort` đứng theo thứ tự Filament tình cờ nạp
 * lớp — không lỗi, không cảnh báo, và mục 6 hiện trước mục 4. Con số của widget này phải khác
 * mọi con số đang dùng, và phải nằm ĐÚNG giữa mục 4 và mục 6.
 */
it('takes a sort number of its own, between item 4 and item 6 of SPEC 7.1', function () {
    $others = [
        StaleMattersWidget::getSort(),
        PendingChecklistReviewsWidget::getSort(),
        MattersMissingDocumentsWidget::getSort(),
        MattersByStageWidget::getSort(),
    ];

    expect($others)->not->toContain(UnseenUpdatesWidget::getSort())
        ->and(UnseenUpdatesWidget::getSort())
        ->toBeGreaterThan(MattersMissingDocumentsWidget::getSort())
        ->toBeLessThan(MattersByStageWidget::getSort());
});

// =========================================================================================
// WIDGET THẬT SỰ VẼ RA ĐƯỢC
// =========================================================================================

/**
 * Truy vấn đúng mà bảng không vẽ ra thì không ai gọi điện cho ai. Vế âm trong cùng test: một
 * dòng đã có người xem không được lên bảng.
 */
it('renders the rows it found, and not the ones it did not', function () {
    agedLog($this->matter, 9, 'Chưa ai mở dòng này');
    $seen = agedLog($this->matter, 9, 'Dòng này đã có người mở');
    StageLogView::factory()->for($seen, 'stageLog')->create(['client_user_id' => $this->clientUser->id]);

    $this->actingAs($this->lawyer, 'web');

    $this->livewire(UnseenUpdatesWidget::class)
        ->assertSee('Chưa ai mở dòng này')
        ->assertDontSee('Dòng này đã có người mở');
});

it('says what to do instead of showing an empty table when there is nothing to call about', function () {
    $this->actingAs($this->lawyer, 'web');

    $this->livewire(UnseenUpdatesWidget::class)
        ->assertSee(__('requests.unseen_widget.empty_state'));
});

/** SPEC §11: không một chuỗi `internal_note` nào lọt ra, kể cả ở panel nội bộ nơi nó hợp lệ. */
it('prints the published content and never the internal note', function () {
    StageLog::factory()->for($this->matter)->published()->create([
        'published_at' => now()->subDays(9),
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
        'internal_note' => 'GHI-CHU-NOI-BO-W3K1',
    ]);

    $this->actingAs($this->lawyer, 'web');

    $this->livewire(UnseenUpdatesWidget::class)
        ->assertSee('Toà đã nhận đơn khởi kiện.')
        ->assertDontSee('GHI-CHU-NOI-BO-W3K1');
});
