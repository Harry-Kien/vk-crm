<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Models\MatterTypeStage;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Danh sách hồ sơ của cổng khách hàng — SPEC §8.2, §11 ("Cách ly dữ liệu giữa khách hàng",
 * "Ghi chú nội bộ"), §14 mục 4 và 5, và nguyên tắc 375px của tài liệu bộ công cụ §4.
 *
 * Đây là màn hình ĐẦU TIÊN khách nhìn thấy sau khi đăng nhập, nên mỗi khẳng định ở đây là một
 * khẳng định về thứ một người thật sẽ đọc trên điện thoại của họ.
 *
 * **Vì sao lái bằng `livewire()` chứ không bằng một `GET /portal`.** Không phải vì route thiếu
 * — từ khi `Filament\Pages\Dashboard` được gỡ khỏi `PortalPanelProvider`, `GET /portal` rơi
 * đúng vào trang này, và có một test ngay dưới ghim điều đó. Lái thẳng component là để mỗi
 * khẳng định ở đây đo ĐÚNG màn hình này chứ không đo kèm cả đường ống xác thực của panel — cái
 * đó đã có `LoginTest` đo — và vì đó là thành ngữ kế hoạch M5 chỉ định cho test màn hình
 * Filament. `livewire()` vẫn chạy đủ `mount()` và `render()`, tức đủ mọi thứ tệp này đo — kể cả
 * lần chuyển hướng một-hồ-sơ, thứ sống trong `mount()`.
 */
beforeEach(function () {
    Filament::setCurrentPanel('portal');

    $this->type = MatterType::factory()->create();
    // `label` mang một chuỗi đánh dấu duy nhất: SPEC §8.2 đòi `client_label`, và cách duy nhất
    // để biết màn hình không lỡ in nhãn nội bộ là cho hai nhãn khác nhau tới mức tìm được.
    $this->stage = MatterTypeStage::factory()->for($this->type)->create([
        'key' => 'filed',
        'label' => 'NHAN-NOI-BO-4K2P',
        'client_label' => 'Đã nộp hồ sơ lên toà',
        'sort_order' => 1,
    ]);

    $this->client = Client::factory()->create(['name' => 'Khách hàng A']);
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
});

/**
 * Một vụ việc đã ở trên cổng của khách hàng đang đăng nhập, dùng đúng loại và giai đoạn dựng ở
 * `beforeEach` để `client_label` có chỗ mà hiện ra.
 */
function portalMatter(array $attributes = []): Matter
{
    return Matter::factory()
        ->for(test()->client)
        ->for(test()->type, 'matterType')
        ->create(array_merge([
            'stage' => 'filed',
            'is_published_to_portal' => true,
            'last_client_update_at' => now()->subDays(2),
        ], $attributes));
}

/** Một vụ việc đã ở trên cổng, nhưng của một khách hàng khác. */
function foreignMatter(array $attributes = []): Matter
{
    return Matter::factory()
        ->for(Client::factory())
        ->for(test()->type, 'matterType')
        ->create(array_merge([
            'stage' => 'filed',
            'is_published_to_portal' => true,
        ], $attributes));
}

/**
 * Kết xuất thật của trang, dưới phiên của khách hàng đã dựng ở `beforeEach`.
 *
 * `showAll: true` là mặc định ở đây vì phần lớn test trong tệp này đo **danh sách**, và một khách
 * có đúng một hồ sơ thì {@see MyMatters::mount()} chuyển thẳng sang trang tiến độ (phán quyết
 * của chủ văn phòng, 21/09/2026). Đó chính là đường mà cái cờ `tat-ca` mở ra, nên dùng nó ở đây
 * không phải lách test — nó là đúng tình huống "khách bấm quay lại danh sách". Bản thân lần
 * chuyển hướng có nhóm test riêng ở cuối tệp.
 */
function renderMyMatters(array $parameters = []): string
{
    return test()->actingAs(test()->clientUser, 'client')
        ->livewire(MyMatters::class, array_merge(['showAll' => true], $parameters))
        ->html();
}

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 1: truy vấn
// =========================================================================================

it('lists the matters of the signed in client and never those of another client', function () {
    $mine = portalMatter(['title' => 'Tranh chấp hợp đồng thuê nhà']);
    $theirs = foreignMatter(['title' => 'HO-SO-CUA-KHACH-KHAC-9X7M']);

    $html = renderMyMatters();

    expect($html)->toContain($mine->code)
        ->and($html)->toContain('Tranh chấp hợp đồng thuê nhà')
        ->and($html)->not->toContain($theirs->code)
        ->and($html)->not->toContain('HO-SO-CUA-KHACH-KHAC-9X7M');
});

it('never lists a matter of the right client that is not published to the portal', function () {
    $published = portalMatter(['title' => 'Hồ sơ đang mở cho khách']);
    $unpublished = portalMatter([
        'is_published_to_portal' => false,
        'title' => 'HO-SO-CHUA-CONG-BO-2B8T',
    ]);

    $html = renderMyMatters();

    expect($html)->toContain($published->code)
        ->and($html)->not->toContain($unpublished->code)
        ->and($html)->not->toContain('HO-SO-CHUA-CONG-BO-2B8T');
});

it('drops a matter from the list the moment the office withdraws it', function () {
    $mine = portalMatter(['title' => 'Hồ sơ còn mở']);
    $withdrawn = portalMatter(['title' => 'HO-SO-DA-RUT-7V2K']);
    $withdrawn->delete();

    $html = renderMyMatters();

    expect($html)->toContain($mine->code)
        ->and($html)->not->toContain($withdrawn->code)
        ->and($html)->not->toContain('HO-SO-DA-RUT-7V2K');
});

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 2: policy, đo bằng một global scope RỖNG
// =========================================================================================

/**
 * Đúng hình dạng "ai đó quên một câu `where`": thay `ClientPortalScope` trên `Matter` bằng một
 * scope không làm gì, khẳng định tầng truy vấn GIỜ ĐÃ THỦNG, rồi khẳng định trang **vẫn** không
 * vẽ hồ sơ của khách khác.
 *
 * Phép đo này là toàn bộ lý do trang tự hỏi `Gate` cho từng thẻ.
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` mặc định trả `true` cho trang tuỳ
 * biến (ghi nhận của Task 2), nên middleware 404 chỉ đổi HÌNH DẠNG của một lần từ chối chứ không
 * bao giờ sinh ra một lần từ chối — nếu trang chỉ tin vào global scope thì nó không có tầng thứ
 * hai nào cả.
 */
it('still hides another clients matter when the portal scope forgets its rule', function () {
    $mine = portalMatter(['title' => 'Hồ sơ của chính khách']);
    $theirs = foreignMatter(['title' => 'RO-RI-KHI-QUEN-WHERE-5T1Q']);

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        $this->actingAs($this->clientUser, 'client');

        // Nếu vế này đỏ thì khẳng định bên dưới không đo tầng policy — nó chỉ đo lại tầng truy vấn.
        expect(Matter::find($theirs->id))->not->toBeNull();

        $html = renderMyMatters();

        expect($html)->not->toContain($theirs->code)
            ->and($html)->not->toContain('RO-RI-KHI-QUEN-WHERE-5T1Q')
            // Vế dương trong CÙNG ngữ cảnh thủng: trang không từ chối sạch mọi thứ.
            ->and($html)->toContain($mine->code);
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

// =========================================================================================
// NGHI THỨC BA TẦNG — tầng 3: serialize
// =========================================================================================

/**
 * SPEC §11 "Ghi chú nội bộ" và §4.6 (`description_internal` "không bao giờ ra portal").
 *
 * `internal_note` được canh Ở TẦNG SERIALIZE (ghi nhận của Task 2: `$log->internal_note` trong
 * một view Blade trả về giá trị thật), nên luật cho mọi màn hình cổng là **không nhắc tới nó**.
 * Trang này không nhắc: view chỉ nhận một mảng giá trị đã chọn sẵn, không nhận model nào.
 */
it('never renders an internal column of a matter or of its progress entries', function () {
    $matter = portalMatter([
        'title' => 'Hồ sơ có ghi chú nội bộ',
        'description_internal' => 'GHI-CHU-NOI-BO-MATTER-8H3D',
    ]);

    StageLog::factory()->for($matter)->published()->create([
        'public_content' => 'Toà đã nhận hồ sơ của anh/chị.',
        'internal_note' => 'GHI-CHU-NOI-BO-LOG-6R4W',
    ]);

    $html = renderMyMatters();

    expect($html)->toContain($matter->code)
        ->and($html)->not->toContain('GHI-CHU-NOI-BO-MATTER-8H3D')
        ->and($html)->not->toContain('GHI-CHU-NOI-BO-LOG-6R4W');
});

// =========================================================================================
// SPEC §11: "kể cả khi truyền tham số lọc thủ công"
// =========================================================================================

/**
 * Chữ "kể cả" của SPEC §11 là một test, không phải một lời hứa. Bề mặt sửa tham số thật của một
 * trang Livewire có hai cửa: tham số `mount()` và chuỗi truy vấn của request. Cả hai được thử,
 * và **vế dương nằm trong cùng một lần thử**: với đúng những tham số bị sửa ấy, hồ sơ của chính
 * khách vẫn hiện — nếu không, test này sẽ xanh y hệt khi trang vỡ và không vẽ gì cả.
 */
it('ignores hand made filter parameters on both the mount and the query string', function () {
    $mine = portalMatter(['title' => 'Hồ sơ của chính khách']);
    $theirs = foreignMatter(['title' => 'THAM-SO-TU-SUA-3J9L']);

    $tampered = [
        'client' => $theirs->client_id,
        'client_id' => $theirs->client_id,
        'matter' => $theirs->id,
        'record' => $theirs->id,
        'tableFilters' => ['client_id' => ['value' => $theirs->client_id]],
    ];

    $this->actingAs($this->clientUser, 'client');

    $viaMount = $this->livewire(MyMatters::class, array_merge(['showAll' => true], $tampered))->html();

    $viaQueryString = Livewire::withQueryParams(array_merge([MyMatters::SHOW_ALL_PARAMETER => 1], $tampered))
        ->test(MyMatters::class)
        ->html();

    foreach ([$viaMount, $viaQueryString] as $html) {
        expect($html)->not->toContain($theirs->code)
            ->and($html)->not->toContain('THAM-SO-TU-SUA-3J9L')
            ->and($html)->toContain($mine->code);
    }
});

// =========================================================================================
// SPEC §8.2: nhãn giai đoạn DỄ HIỂU
// =========================================================================================

it('shows the client label of the stage and never the internal one', function () {
    portalMatter();

    $html = renderMyMatters();

    expect($html)->toContain('Đã nộp hồ sơ lên toà')
        ->and($html)->not->toContain('NHAN-NOI-BO-4K2P');
});

it('says so in plain words when the matter sits on a stage the office has not configured', function () {
    portalMatter(['stage' => 'khong-co-trong-cau-hinh']);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.card.stage_unknown'))
        ->and($html)->not->toContain('khong-co-trong-cau-hinh');
});

// =========================================================================================
// SPEC §4.10 + đính chính 2026-09-16: thanh tiến độ `X/Y`
// =========================================================================================

/**
 * `X/Y` **không được tính ở đây**. Luật sống trong `App\Actions\Document\ChecklistProgress` (M4
 * chuyển nó vào Action đúng để M5 không viết lại lần thứ hai), nên bốn test dưới đây đo con số
 * mà trang HIỆN RA, và chúng chạy dưới `ClientPortalScope::actingAs()` vì vòng rà soát hợp nhất
 * M4 đã đo rằng cùng một hồ sơ cho hai con số khác nhau tuỳ guard nào đang mở — và con số của
 * KHÁCH mới là con số §4.10 gọi là trường hiển thị trên portal.
 */
it('counts only the required items when nothing optional carries a document', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Missing,
    ]);
    // Không bắt buộc, không tài liệu nào: không nằm ở cả hai vế (đính chính SPEC §4.10).
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::NotApplicable,
    ]);

    expect(ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters()))
        ->toContain(__('portal_matters.card.progress', ['submitted' => 1, 'total' => 2]));
});

it('pulls an optional item into both sides once it actually carries a document', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::Accepted,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    expect(ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters()))
        ->toContain(__('portal_matters.card.progress', ['submitted' => 2, 'total' => 2]));
});

it('leaves an optional item out of both sides when its only document is internal work product', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::Accepted,
    ]);

    // Nhóm D là hồ sơ công việc nội bộ: nó không bao giờ là bằng chứng khách đã nộp gì.
    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
    ]);

    expect(ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters()))
        ->toContain(__('portal_matters.card.progress', ['submitted' => 1, 'total' => 1]));
});

it('says the matter has nothing to track instead of drawing an empty bar', function () {
    portalMatter();

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.card.progress_empty'))
        ->and($html)->not->toContain(__('portal_matters.card.progress', ['submitted' => 0, 'total' => 0]));
});

// =========================================================================================
// SPEC §8.2: huy hiệu đỏ khi còn giấy tờ cần nộp — và hai màu còn lại, mỗi màu kèm CHỮ
// =========================================================================================

it('raises the red badge with a sentence when papers are still wanted from the client', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Missing,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh chụp bị mờ, không đọc được số trên giấy tờ.',
    ]);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.status.outstanding', ['count' => 2]))
        ->and($html)->toContain('var(--danger-600)');
});

it('counts a rejected optional item as something the client still has to do', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản sao chưa được chứng thực, xin anh/chị nộp lại bản có chứng thực.',
    ]);

    expect(renderMyMatters())->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
});

it('does not nag about an optional item nobody ever asked the client for', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::Missing,
    ]);

    $html = renderMyMatters();

    expect($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]))
        ->and($html)->toContain(__('portal_matters.status.settled'));
});

it('turns yellow with a sentence while the office is still checking what was sent', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::PendingReview,
    ]);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.status.waiting_office'))
        ->and($html)->toContain('var(--warning-600)')
        ->and($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
});

it('turns green with a sentence once every wanted paper is in', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.status.settled'))
        ->and($html)->toContain('var(--success-600)');
});

// =========================================================================================
// SPEC §8.2: ngày cập nhật gần nhất
// =========================================================================================

it('writes the last update as a date a person reads, and says so when there is none', function () {
    portalMatter([
        'title' => 'Hồ sơ đã có tin',
        'last_client_update_at' => now()->setDate(2026, 9, 14)->setTime(21, 14),
    ]);
    portalMatter(['title' => 'Hồ sơ chưa có tin', 'last_client_update_at' => null]);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.card.updated_at', ['date' => '14/09/2026']))
        ->and($html)->toContain(__('portal_matters.card.never_updated'));
});

it('puts the matter with the freshest news at the top', function () {
    $older = portalMatter(['last_client_update_at' => now()->subDays(30)]);
    $newer = portalMatter(['last_client_update_at' => now()->subDay()]);
    $never = portalMatter(['last_client_update_at' => null]);

    $html = renderMyMatters();

    expect(strpos($html, $newer->code))->toBeLessThan(strpos($html, $older->code))
        ->and(strpos($html, $older->code))->toBeLessThan(strpos($html, $never->code));
});

// =========================================================================================
// Toolchain §4: "không hiện bảng rỗng; trạng thái trống phải kèm hướng dẫn bước tiếp theo"
// =========================================================================================

it('answers an empty list with a next step and a way to reach the office', function () {
    $html = renderMyMatters();

    // Cả ĐƯỜNG liên hệ lẫn CÂU mời gọi: một lần đột biến chỉ đổi nhãn của nút mà giữ nguyên
    // `href` đã sống sót ở vòng probe đầu, nên hai vế phải được ghim riêng.
    expect($html)->toContain(__('portal_matters.empty.heading'))
        ->and($html)->toContain(__('portal_matters.empty.body'))
        ->and($html)->toContain('tel:'.config('vkcrm.brand.hotline'))
        ->and($html)->toContain(__('portal_matters.empty.hotline', ['phone' => config('vkcrm.brand.hotline')]))
        ->and($html)->toContain(config('vkcrm.brand.zalo'))
        ->and($html)->toContain(__('portal_matters.empty.zalo'))
        ->and($html)->not->toContain('data-portal-matter-card');
});

it('drops the empty state the moment there is a matter to show', function () {
    portalMatter();

    expect(renderMyMatters())->not->toContain(__('portal_matters.empty.heading'));
});

// =========================================================================================
// Toolchain §4: dùng được ở 375px — cấu trúc, đo được, không phải một ảnh chụp màn hình
// =========================================================================================

it('lays the cards out in one column with no table and no width wider than a phone', function () {
    portalMatter();
    portalMatter();

    $html = renderMyMatters();

    preg_match_all('/(?:min-)?width:\s*(\d+)px/', $html, $widths);

    expect($html)->not->toContain('<table')
        ->and($html)->toContain('flex-direction:column')
        ->and(substr_count($html, 'data-portal-matter-card'))->toBe(2)
        ->and(max([0, ...array_map('intval', $widths[1])]))->toBeLessThan(376);
});

it('gives every tap target of the empty state at least forty four pixels', function () {
    $html = renderMyMatters();

    preg_match_all('/<a\b[^>]*>/', $html, $anchors);

    $contactAnchors = array_values(array_filter(
        $anchors[0],
        fn (string $tag): bool => str_contains($tag, 'tel:') || str_contains($tag, 'zalo'),
    ));

    expect($contactAnchors)->toHaveCount(2);

    foreach ($contactAnchors as $tag) {
        expect($tag)->toContain('min-height:44px');
    }
});

it('paints with colour variables the panel actually registers', function () {
    $matter = portalMatter();
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Missing,
    ]);

    $html = renderMyMatters();

    expect(colourVariablesIn($html))->not->toBeEmpty()
        ->and(unregisteredColourVariables($html))->toBe([]);
});

// =========================================================================================
// Trang tự hỏi `Gate`, và nó là cổng của khách hàng chứ không của nhân sự
// =========================================================================================

/**
 * Vế âm ở đây phải được dựng cẩn thận, và lần đầu nó đã KHÔNG được: một `User::factory()` trần
 * không có vai trò nào, nên `MatterPolicy::viewAny` từ chối họ vì THIẾU QUYỀN và điều kiện
 * `instanceof ClientUser` không bao giờ được chạm tới — xoá điều kiện ấy đi mà bộ test vẫn xanh.
 * Đúng hình dạng "một test mà fixture của nó đoản mạch trước khi chạm tới điều kiện nó nêu tên".
 *
 * Nên nhân sự ở đây là một **quản trị viên đầy đủ quyền**: `viewAny` trả `true` cho họ, và thứ
 * duy nhất còn từ chối là câu "đây là cổng của khách hàng". Hai panel dùng chung cookie phiên
 * (xem `ClientPortalScope`), nên tình huống này không phải giả tưởng.
 */
it('asks the gate before letting anyone onto the page', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withRole(Role::Admin)->create();
    expect(Gate::forUser($admin)->allows('viewAny', Matter::class))->toBeTrue();

    $this->actingAs($this->clientUser, 'client');
    expect(MyMatters::canAccess())->toBeTrue();

    auth('client')->logout();

    $this->actingAs($admin, 'web');
    expect(MyMatters::canAccess())->toBeFalse();
});

/**
 * Không phải một test về cấu hình: `Filament\Auth\Pages\Login` chuyển hướng về
 * `Filament::getUrl()` sau khi khách nhập xong mã, nên ba khẳng định dưới đây cộng lại là câu
 * "màn hình đầu tiên sau khi đăng nhập là danh sách hồ sơ" của SPEC §8.2.
 *
 * `Dashboard::getNavigationSort()` vẫn được đọc dù trang đó không còn được đăng ký: nó là con
 * số mà bất kỳ ai đăng ký lại Dashboard sẽ mang theo, và `Panel::getRedirectUrl()` đọc mục điều
 * hướng ĐẦU TIÊN, nên thứ tự này không chỉ là chuyện hiển thị.
 */
it('claims the root of the portal so that it is the first screen after signing in', function () {
    expect(MyMatters::getRoutePath(Filament::getPanel('portal')))->toBe('/')
        ->and(MyMatters::getNavigationSort())->toBeLessThan(Dashboard::getNavigationSort())
        // Và route thật sự tồn tại, trỏ đúng vào đây: một `getRoutePath()` trả `/` mà bị một trang
        // khác ghi đè là một mục điều hướng gọi `route()` lên một tên không tồn tại, tức MỌI trang
        // cổng đã xác thực vỡ khi vẽ thanh bên. Đã xảy ra thật, nên nó có một dòng ở đây.
        ->and(Route::has('filament.portal.pages.my-matters'))->toBeTrue()
        ->and(MyMatters::getUrl(panel: 'portal'))->toBe(url('/portal'))
        ->and(Filament::getPanel('portal')->getUrl())->toBe(MyMatters::getUrl(panel: 'portal'))
        ->and(app('router')->getRoutes()->match(
            Request::create(url('/portal'), 'GET')
        )->getActionName())->toBe(MyMatters::class);
});

// =========================================================================================
// MỘT hồ sơ thì đi thẳng vào hồ sơ đó — phán quyết của chủ văn phòng, 21/09/2026
// =========================================================================================

/**
 * Phần lớn khách của văn phòng chỉ có một hồ sơ; với họ danh sách này là một màn hình hiện đúng
 * một thẻ rồi đợi họ chạm vào nó.
 *
 * `MatterProgress::getUrl()` được gọi chứ không phải một đường dẫn viết tay: trang kia sở hữu
 * `$slug` và hình dạng `{record}` của chính nó, và ở thời điểm viết tệp này nó còn chưa được
 * commit — nên ghim một chuỗi `/portal/ho-so/12` ở đây là ghim một thứ người khác được phép đổi.
 */
it('sends a client who has exactly one matter straight into it', function () {
    $only = portalMatter();

    $this->actingAs($this->clientUser, 'client')
        ->livewire(MyMatters::class)
        ->assertRedirect(MatterProgress::getUrl(['record' => $only->id]));
});

/**
 * Vế dương, và nó là điều kiện để test trên có nghĩa: hai hồ sơ thì danh sách được vẽ ra,
 * không chuyển hướng đi đâu cả.
 */
it('draws the list and redirects nowhere when there are two matters', function () {
    $first = portalMatter();
    $second = portalMatter();

    $component = $this->actingAs($this->clientUser, 'client')->livewire(MyMatters::class);

    $component->assertNoRedirect();

    expect($component->html())->toContain($first->code)
        ->and($component->html())->toContain($second->code);
});

/** Không hồ sơ nào thì màn hình này CÓ việc để làm, nên nó cũng không chuyển hướng. */
it('keeps a client with no matter on the page that tells them what to do next', function () {
    $component = $this->actingAs($this->clientUser, 'client')->livewire(MyMatters::class);

    $component->assertNoRedirect();

    expect($component->html())->toContain(__('portal_matters.empty.heading'));
});

/**
 * Luật gảy nhất của tính năng này: "một hồ sơ" là một hồ sơ **KHÁCH NHÌN THẤY ĐƯỢC**, đếm
 * trên chính danh sách đã đi qua `Gate`, không phải trên bảng `matters`. Ở đây khách có BA hồ sơ
 * mang đúng `client_id` của mình, nhưng hai trong số đó không được ra cổng — một chưa công bố, một
 * đã bị rút — nên đúng một thẻ còn lại, và họ phải được đưa tới ĐÚNG hồ sơ đó.
 *
 * Một bản cài đặt nào đó dùng `Matter::first()` sẽ đưa họ tới hồ sơ chưa công bố — và vì trang
 * kia tự hỏi `Gate`, kết quả sẽ là một khách hàng đăng nhập xong gặp ngay một trang 404.
 */
it('counts only the matters the client may actually see when it decides to redirect', function () {
    // Thứ tự tạo là một phần của phép đo: hai hồ sơ không được ra cổng được dựng TRƯỚC, nên
    // chúng mang id nhỏ hơn và một bản cài đặt kiểu `Matter::first()` sẽ vạch ra ngay. Dựng ngược
    // lại thì test này xanh với cả một bản cài đặt sai — đã đo bằng đột biến, và lần đầu nó sống sót.
    portalMatter(['is_published_to_portal' => false]);
    portalMatter()->delete();
    $visible = portalMatter(['title' => 'Hồ sơ đang mở']);

    $this->actingAs($this->clientUser, 'client')
        ->livewire(MyMatters::class)
        ->assertRedirect(MatterProgress::getUrl(['record' => $visible->id]));
});

/**
 * Và tầng thứ hai của cùng câu hỏi: với global scope đã bị làm rỗng, tầng truy vấn trả về
 * cả hồ sơ của khách khác — và hồ sơ ấy còn mang id nhỏ hơn. Chỉ có `Gate` của từng thẻ mới
 * loại nó ra, nên lần chuyển hướng vẫn phải đi về hồ sơ của chính khách.
 *
 * Nếu điều này sai thì hậu quả không phải một lỗ hổng dữ liệu — `MatterProgress` tự hỏi `Gate`
 * một lần nữa — mà là một khách hàng đăng nhập xong rồi gặp ngay một trang 404.
 */
it('never sends the client into a matter the gate would refuse', function () {
    $theirs = foreignMatter(['title' => 'CUA-KHACH-KHAC-1M6P']);
    $mine = portalMatter();

    Matter::addGlobalScope(ClientPortalScope::class, function (): void {});

    try {
        $this->actingAs($this->clientUser, 'client');

        expect(Matter::find($theirs->id))->not->toBeNull();

        $this->livewire(MyMatters::class)
            ->assertRedirect(MatterProgress::getUrl(['record' => $mine->id]));
    } finally {
        Matter::addGlobalScope(new ClientPortalScope);
    }
});

/**
 * Và lối quay lại phải mở được, nếu không một khách có đúng một hồ sơ không bao giờ nhìn thấy
 * màn hình này nữa. Hai cửa được thử: chuỗi truy vấn thật (thứ `MyMatters::getAllUrl()` dựng ra
 * cho trang chi tiết dùng) và tham số `mount()`.
 */
it('still shows the list to a one matter client who deliberately asked for it', function () {
    $only = portalMatter();

    $this->actingAs($this->clientUser, 'client');

    $viaQueryString = Livewire::withQueryParams([MyMatters::SHOW_ALL_PARAMETER => 1])->test(MyMatters::class);
    $viaQueryString->assertNoRedirect();

    $viaMount = $this->livewire(MyMatters::class, ['showAll' => true]);
    $viaMount->assertNoRedirect();

    expect($viaQueryString->html())->toContain($only->code)
        ->and($viaMount->html())->toContain($only->code)
        ->and(MyMatters::getAllUrl())->toContain(MyMatters::SHOW_ALL_PARAMETER);
});

/** Mã hồ sơ trên từng thẻ: trợ lý đọc nó ra qua điện thoại, nên nó không được biến mất. */
it('keeps the matter code on every card and makes the whole card the way in', function () {
    $first = portalMatter();
    $second = portalMatter();

    $html = renderMyMatters();

    expect($html)->toContain($first->code)
        ->and($html)->toContain($second->code)
        ->and($html)->toContain(MatterProgress::getUrl(['record' => $first->id]))
        ->and($html)->toContain(MatterProgress::getUrl(['record' => $second->id]));
});

// =========================================================================================
// Hiệu năng: `MatterPolicy::view` chạy một EXISTS cho MỖI thẻ (mang sang từ rà soát M2)
// =========================================================================================

/**
 * Ngưỡng ở đây là một phép tính, không phải một con số cho đẹp.
 *
 * Mỗi thẻ tiêu đúng **hai** truy vấn, và cả hai đều cố ý:
 *
 *  1. `MatterPolicy::view` — `ChecksPortalVisibility::visibleToPortal()` chạy một `EXISTS` cho
 *     mỗi lần gọi. Trang **phải** hỏi `Gate` cho từng bản ghi nó vẽ (`canAccess()` mặc định trả
 *     `true` cho trang tuỳ biến, nên không tầng nào khác hỏi hộ), nên truy vấn này không gộp
 *     được mà không đánh đổi chính tầng phòng thủ thứ hai.
 *  2. `ChecklistProgress::handle()` — luật `X/Y` của SPEC §4.10 đọc `matter_checklist_items`
 *     kèm bộ đếm tài liệu. Gộp nó vào truy vấn danh sách nghĩa là viết lại luật đếm lần thứ hai
 *     bên màn hình, đúng thứ M4 vừa dọn đi.
 *
 * Phần cố định là **ba**: danh sách hồ sơ (hai `withCount` nằm trong cùng câu lệnh), loại vụ
 * việc, và các giai đoạn của loại đó. Vậy `2N + 3`, tức 43 cho 20 thẻ.
 *
 * Hai khẳng định, vì mỗi cái bắt một hỏng khác nhau: **trần 50** bắt việc một ngày nào đó có
 * người thêm một truy vấn cố định thứ tư và thứ năm; **độ dốc đúng bằng 2** bắt thứ đáng sợ hơn
 * — một truy vấn mới mọc lên TRÊN TỪNG THẺ (một quan hệ chưa nạp sẵn, một `count()` trong view).
 * Chỉ có trần thì một hồi quy như vậy vẫn lọt ở 20 thẻ và nổ ở 200.
 */
it('does not turn twenty cards into hundreds of queries', function () {
    // Cố ý KHÔNG dùng `renderMyMatters()`: hàm đó bật cờ `showAll`, mà cờ ấy cho `mount()` thoát ra
    // sớm và không gọi `getCards()`. Đường thật của một lần tải trang gọi nó HAI lần — một ở
    // `mount()` để đếm thẻ, một ở view — nên phép đo này cũng là nhân chứng cho chỗ nhớ trong
    // `MyMatters::$cards`: không có nó thì độ dốc thành 4 chứ không phải 2.
    $measure = function (): int {
        test()->actingAs(test()->clientUser, 'client');

        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->livewire(MyMatters::class)->html();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    for ($i = 0; $i < 5; $i++) {
        portalMatter();
    }

    $five = $measure();

    for ($i = 0; $i < 15; $i++) {
        portalMatter();
    }

    $twenty = $measure();

    expect($twenty)->toBeLessThanOrEqual(50)
        ->and($twenty - $five)->toBe(2 * 15);
});
