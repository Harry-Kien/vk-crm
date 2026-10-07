<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
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
use Illuminate\Support\Facades\Auth;
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

/**
 * Mọi `href` của một mục điều hướng mang đúng nhãn `$label`, đọc từ HTML Filament THẬT SỰ dựng
 * ra — không phải từ một lời gọi lại `getNavigationUrl()`, thứ chỉ hỏi mã nguồn xem nó nghĩ gì
 * về chính nó.
 *
 * Trả về một mảng chứ không một chuỗi vì Filament vẽ thanh bên hai lần (bản điện thoại và bản
 * màn hình rộng). Hai bản phải trỏ cùng một chỗ, và test khẳng định điều đó thay vì lặng lẽ lấy
 * bản đầu.
 *
 * @return list<string>
 */
function portalNavigationHrefs(string $html, string $label): array
{
    preg_match_all(
        '/<a\b[^>]*\bhref="([^"]*)"[^>]*>(?:(?!<\/a>).)*?'.preg_quote($label, '/').'/su',
        $html,
        $matches,
    );

    return array_map(
        fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5),
        $matches[1],
    );
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

/**
 * `portal/portal-2` (M6.5 Task 5): `summary_for_client` chỉ hiện được ở tab Tổng quan phía nội
 * bộ trước bản sửa này. §8.2 liệt kê thẻ hồ sơ như một trong hai nơi đặt trường này.
 */
it('shows summary_for_client on the matter card when it has content', function () {
    portalMatter(['summary_for_client' => 'TOM-TAT-THE-HO-SO-3F9L']);

    $html = renderMyMatters();

    expect($html)->toContain('TOM-TAT-THE-HO-SO-3F9L');
});

/** Cặp âm bắt buộc: rỗng thì thẻ không vẽ một dòng trống cho trường này. */
it('does not render a summary line on the card when summary_for_client is empty', function () {
    portalMatter(['summary_for_client' => null]);

    $html = renderMyMatters();

    expect($html)->not->toContain('data-portal-card-summary');
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
// Task 2 (`portal/portal-3`): khách hàng đã xoá mềm — Matter::applyClientPortalConstraints()
// =========================================================================================

/**
 * Trước bản sửa này, `applyClientPortalConstraints()` chỉ hỏi bảng `matters` (client_id,
 * is_published_to_portal, `deleted_at` CỦA CHÍNH VỤ VIỆC) — `clients.deleted_at` không được hỏi
 * ở đâu cả, nên xoá mềm khách hàng (EditClient → DeleteAction) không rút được vụ việc của họ
 * khỏi cổng: khách vẫn đăng nhập (điều kiện độc lập, xem `tests/Feature/Portal/LoginTest.php`) và
 * vẫn đọc được hồ sơ đã công bố của chính mình.
 *
 * Đo trực tiếp `Matter::query()` dưới ngữ cảnh cổng (`ClientPortalScope::actingAs()`), độc lập
 * với bất kỳ màn hình nào — mutation probe nhắm thẳng vào `whereHas('client')` của
 * `Matter::applyClientPortalConstraints()` (bỏ nó thì test này đỏ).
 */
it('empties Matter::query() under the portal scope once the parent client is soft deleted', function () {
    portalMatter(['title' => 'Hồ sơ của khách đã bị xoá']);

    $this->client->delete();

    $matters = ClientPortalScope::actingAs($this->clientUser, fn () => Matter::query()->get());

    expect($matters)->toBeEmpty();
});

/** Vế dương: cùng thiết lập đó, khách hàng CHƯA xoá thì vụ việc vẫn ra tới cổng như trước. */
it('still returns the matter under the portal scope when the client has not been deleted', function () {
    $matter = portalMatter(['title' => 'Hồ sơ của khách còn nguyên']);

    $matters = ClientPortalScope::actingAs($this->clientUser, fn () => Matter::query()->get());

    expect($matters->pluck('id'))->toContain($matter->id);
});

/** Và hệ quả trên chính màn hình này: danh sách rỗng ngay khi văn phòng xoá mềm khách hàng. */
it('drops every matter from the screen the moment the office soft deletes the client itself', function () {
    $matter = portalMatter(['title' => 'HO-SO-CUA-KHACH-DA-XOA-6M3P']);

    $this->client->delete();

    $html = renderMyMatters();

    expect($html)->not->toContain($matter->code)
        ->and($html)->not->toContain('HO-SO-CUA-KHACH-DA-XOA-6M3P');
});

/**
 * Và nó phải biến mất trên request KẾ TIẾP, không đợi khách tải lại trang bằng tay.
 *
 * Toàn bộ cổng này là Livewire: sau lần tải đầu, mọi thứ khách làm là một request **cập nhật**
 * dựng lại component từ một ảnh chụp đã được serialize. Thứ duy nhất giữ cho hồ sơ đã rút không
 * đi theo ảnh chụp ấy là từ khoá `private` trên {@see MyMatters::$cards} — Livewire chỉ serialize
 * thuộc tính `public`. Đổi nó thành `public` thì toàn bộ bộ test vẫn xanh (đo được, rà soát Task
 * 3), và một hồ sơ văn phòng vừa rút vẫn nằm trên màn hình khách, vì vòng `Gate` từng thẻ không
 * bao giờ chạy lại.
 *
 * Nên phép đo phải có đúng ba nhịp: **vẽ, rút, rồi một `$refresh`** — tức đúng hình dạng một
 * request cập nhật thật. Trang anh em `MatterProgress` ghim cùng bất biến này bằng cùng thiết bị
 * ("làm `$resolvedMatter` thành `public` sẽ đỏ một test có tên"); khi một trang ghim một bất
 * biến, trang anh em của nó thừa kế NGHĨA VỤ chứ không thừa kế giả định.
 */
it('drops a withdrawn matter on the very next livewire update, not only on a fresh page load', function () {
    $mine = portalMatter(['title' => 'Hồ sơ còn mở']);
    $withdrawn = portalMatter(['title' => 'HO-SO-DA-RUT-5Q8W']);

    $component = $this->actingAs($this->clientUser, 'client')
        ->livewire(MyMatters::class, ['showAll' => true]);

    // Vế dương, trong cùng một lần chạy: thẻ ấy CÓ ở đó trước khi văn phòng rút nó.
    expect($component->html())->toContain($withdrawn->code);

    $withdrawn->delete();

    $component->call('$refresh');

    expect($component->html())->not->toContain($withdrawn->code)
        ->and($component->html())->not->toContain('HO-SO-DA-RUT-5Q8W')
        ->and($component->html())->toContain($mine->code);
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

/**
 * Và cái hố rộng hơn cùng một hình dạng, cho tới vòng sửa này thì **không có gì ghim**: không
 * phải một giai đoạn bị gỡ, mà cả LOẠI vụ việc bị xoá mềm.
 *
 * `MyMatters::toCard()` cố ý đọc nhãn qua `matterType?->stage(...)` chứ không qua
 * `Matter::currentStage()`, và docblock ở đó nói thẳng lý do: `currentStage()` gọi
 * `$this->matterType->stage(...)` không có toán tử an toàn null, nên với một loại đã xoá mềm nó
 * là một lỗi 500 **trên màn hình đầu tiên của khách hàng**. Rà soát Task 3 đo được rằng đổi
 * sang `currentStage()` để cả bộ test xanh — tức lý lẽ ấy đúng nhưng không ai canh nó. Test này
 * là chỗ canh: một cú bấm `settings.manage` không được hạ cả cổng khách hàng.
 */
it('keeps the first screen standing when the office soft deletes the whole matter type', function () {
    $matter = portalMatter(['title' => 'Hồ sơ của loại vụ việc đã gỡ']);

    $this->type->delete();

    $html = renderMyMatters();

    expect($html)->toContain($matter->code)
        ->and($html)->toContain('Hồ sơ của loại vụ việc đã gỡ')
        ->and($html)->toContain(__('portal_matters.card.stage_unknown'))
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

/**
 * Huy hiệu và thanh tiến độ là HAI CÂU TRÊN CÙNG MỘT TẤM THẺ, nên chúng phải nói về cùng một tập
 * đầu mục. Ba test dưới đây đo cái thẻ ĐẦY ĐỦ, không đo một nửa của nó.
 *
 * Bản đầu của test này khẳng định đúng một vế — huy hiệu đỏ — trên một fixture mà thanh tiến độ
 * đồng thời nói "hồ sơ này chưa có giấy tờ nào anh/chị cần nộp". Hai câu ngược hẳn nhau, trên
 * cùng một thẻ, và test xanh. Rà soát Task 3 gọi tên nó: một khẳng định về nửa màn hình là một
 * khẳng định không nhìn thấy mâu thuẫn trên nửa kia.
 *
 * Fixture ở đây vì thế là trạng thái THẬT của một đầu mục bị từ chối: khách đã nộp một thứ gì
 * đó, nên có một tài liệu, và chính tài liệu ấy kéo đầu mục không bắt buộc vào mẫu số của SPEC
 * §4.10.
 */
it('counts a rejected optional item as something the client still has to do', function () {
    $matter = portalMatter();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản sao chưa được chứng thực, xin anh/chị nộp lại bản có chứng thực.',
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    $html = ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters());

    expect($html)->toContain(__('portal_matters.status.outstanding', ['count' => 1]))
        // Và cả tấm thẻ: thanh tiến độ nói về CÙNG đầu mục ấy, và câu "chưa có gì để nộp"
        // không được xuất hiện bên cạnh một huy hiệu đang đòi một tờ giấy.
        ->and($html)->toContain(__('portal_matters.card.progress', ['submitted' => 0, 'total' => 1]))
        ->and($html)->not->toContain(__('portal_matters.card.progress_empty'));
});

/**
 * Mâu thuẫn ở dạng thuần khiết nhất, và là thứ rà soát Task 3 đo được: một đầu mục KHÔNG bắt
 * buộc, bị từ chối, mà không còn một tài liệu nào ngoài nhóm D. `ChecklistProgress` cố ý để nó
 * ngoài **cả hai vế** của `X/Y` (đính chính SPEC §4.10 về mẫu số), nên thẻ in ra câu "chưa có
 * giấy tờ nào anh/chị cần nộp" — trong khi một bộ đếm huy hiệu viết riêng lại đếm nó và in một
 * huy hiệu đỏ đòi một tờ giấy, ngay bên dưới.
 *
 * Câu trả lời của vòng sửa này là một nguồn sự thật duy nhất, không phải một câu bị bịt đi:
 * huy hiệu đếm TRÊN ĐÚNG tập dòng mà `ChecklistProgress` gọi là `Y`. Hệ quả nghiệp vụ được nói
 * thẳng ra chứ không giấu: một đầu mục không bắt buộc mà khách chưa bao giờ nộp gì vào thì không
 * phải một việc đang chờ khách, kể cả khi ai đó ở văn phòng đã đánh dấu nó "bị từ chối".
 */
it('never says the matter has nothing to submit while a red badge asks for a paper', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Bản sao chưa được chứng thực.',
    ]);

    $html = ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters());

    expect($html)->toContain(__('portal_matters.card.progress_empty'))
        ->and($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
});

/**
 * Cùng một mâu thuẫn, ở phía bên kia: mẫu số KHÔNG rỗng, nhưng nó đã đếm xong mọi thứ. Thẻ nói
 * "Đã nộp 1/1" và, ngay dưới, "Còn 1 giấy tờ anh/chị cần nộp" — vì bộ đếm huy hiệu nhìn thấy một
 * dòng mà thanh tiến độ không nhìn thấy. Một con số đòi nhiều hơn số tờ giấy tồn tại là một con
 * số không một khách hàng nào đối chiếu được với bất kỳ thứ gì trên màn hình.
 */
it('never asks for a paper the progress line has already counted as done', function () {
    $matter = portalMatter();

    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Rejected,
        'rejection_reason' => 'Ảnh chụp bị mờ.',
    ]);

    $html = ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters());

    expect($html)->toContain(__('portal_matters.card.progress', ['submitted' => 1, 'total' => 1]))
        ->and($html)->toContain(__('portal_matters.status.settled'))
        ->and($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
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

/**
 * checklist-05, fix round 1 (C1): Y đếm CHỈ nhóm A. Một đầu mục không bắt buộc mà văn phòng tự
 * gắn một tài liệu nhóm B vào — dù bản đó đã đi hết vòng đời (`published`, `client_can_view =
 * true`) — KHÔNG được kéo vào `Y`: nó không phải một tài liệu KHÁCH nộp, nên nó không phải bằng
 * chứng "khách đã làm xong việc gì đó" theo nghĩa mẫu số này đếm.
 *
 * Trước phán quyết vòng sửa 1, test này khẳng định điều NGƯỢC LẠI (một tài liệu nhóm B đã công
 * bố kéo được đầu mục vào Y) — chính hình dạng mà finding C1 chỉ ra là sai: đầu mục vẫn `missing`
 * nên nó rơi vào "Giấy tờ chúng tôi còn chờ ở anh/chị" trong khi khách chỉ thấy một quyết định
 * nhóm B nằm ở khối "Tài liệu", không phải một việc phải làm.
 */
it('does not nag about an optional item even when the office has published a group B document for it', function () {
    $matter = portalMatter();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    $html = ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters());

    // Vế âm: đầu mục ấy KHÔNG nằm trong mẫu số — nếu Y vẫn đếm nhóm B, test này đỏ ở đúng dòng
    // dưới, không xanh vì một lý do khác.
    expect($html)->toContain(__('portal_matters.card.progress_empty'))
        ->and($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
});

/**
 * Người thừa kế của bài học cũ mà test trên từng ghim (`&& $item->is_required` trong bộ đếm huy
 * hiệu): giờ Y CHỈ đếm nhóm A, nên nhân chứng phải là một tài liệu nhóm A để đầu mục còn ở trong
 * Y — rồi mới đo được việc nó KHÔNG bị tính vào huy hiệu "còn X giấy tờ cần nộp" vì nó không bắt
 * buộc. Không có test này, vế `&& $item->is_required` không còn gì ghim (đo được: xoá nó đi mà
 * mọi test khác vẫn xanh, kể cả test C1 ở trên — item ấy đã rời Y từ trước khi tới bước đếm huy
 * hiệu).
 */
it('does not count an optional missing item toward the outstanding badge even while it stays in Y', function () {
    $matter = portalMatter();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false, 'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    $html = ClientPortalScope::actingAs($this->clientUser, fn () => renderMyMatters());

    // Vế dương: đầu mục CÓ nằm trong Y (mẫu số = 1, tử số vẫn 0 vì trạng thái còn `missing`) —
    // nếu không, test này xanh vì một lý do khác.
    expect($html)->toContain(__('portal_matters.card.progress', ['submitted' => 0, 'total' => 1]))
        ->and($html)->not->toContain(__('portal_matters.status.outstanding', ['count' => 1]));
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

/**
 * Bản đầu của test này khẳng định `max([0, ...$widths[1]]) < 376` trên một biểu thức chính quy
 * `(?:min-)?width:\s*(\d+)px` **không khớp lấy một lần** trong cả trang — mọi bề rộng ở view đều
 * là `%` hoặc `rem`. Biểu thức rỗng thì `max([0])` là `0`, và `0 < 376` đúng với mọi kết xuất, kể
 * cả một `width:125rem`. Rà soát Task 3 gọi nó là M6: một khẳng định không thể đỏ.
 *
 * Bản này đo thật. Nó gom **mọi** `width` và `min-width` — bất kể đơn vị — rồi quy về pixel
 * (`rem`/`em` theo gốc 16px của Filament) và đòi từng cái vừa trong một màn hình 375px. `max-width`
 * cố ý nằm ngoài: nó là một cái TRẦN, nên nó không bao giờ đẩy nội dung ra khỏi màn hình; chính
 * trạng thái rỗng dùng `max-width:34rem` để dòng chữ không dài quá trên máy tính bàn.
 *
 * Và vế `not->toBeEmpty()` là thứ giữ cho lần sửa này không lặp lại khuyết tật nó vừa sửa: nếu
 * một ngày cách viết style đổi và bộ trích không còn tìm thấy gì, test đỏ ngay ở đó thay vì âm
 * thầm khẳng định một điều kiện trên tập rỗng.
 */
it('lays the cards out in one column with no table and no width wider than a phone', function () {
    // Hai thẻ ĐỦ BỘ PHẬN, cố ý: thanh tiến độ (`width:…%`) và chấm màu của huy hiệu
    // (`width:0.625rem`) là hai chỗ duy nhất trang này khai một bề rộng, và một hồ sơ trần không
    // vẽ cái nào. Bản đầu của test này đo một trang trống rồi kết luận về bề rộng của nó.
    foreach ([portalMatter(), portalMatter()] as $matter) {
        MatterChecklistItem::factory()->for($matter)->create([
            'is_required' => true, 'status' => ChecklistItemStatus::Missing,
        ]);
    }

    $html = renderMyMatters();

    $widths = declaredWidthsInPixels($html);

    expect($html)->not->toContain('<table')
        ->and($html)->toContain('flex-direction:column')
        ->and(substr_count($html, 'data-portal-matter-card'))->toBe(2)
        ->and($widths)->not->toBeEmpty();

    foreach ($widths as $declaration => $pixels) {
        expect($pixels)->toBeLessThanOrEqual(375.0, "Khai báo `{$declaration}` rộng hơn màn hình 375px.");
    }
});

/**
 * Bộ trích của test trên, tách ra để đo được chính nó.
 *
 * Trả về `[khai báo nguyên văn => bề rộng quy ra pixel]`. Bề rộng tương đối (`%`, `auto`, `fit-content`,
 * `100%`…) không có một con số pixel nào đúng và cũng không bao giờ đẩy nội dung ra khỏi màn
 * hình, nên chúng được ghi nhận là `0.0`: chúng vẫn đếm vào vế "bộ trích có tìm thấy gì không",
 * mà không giả vờ đo một thứ chúng không nói.
 *
 * @return array<string, float>
 */
function declaredWidthsInPixels(string $html): array
{
    // `[;"\s]` phía trước là thứ loại `max-width` ra: ở đó chữ `width` đứng sau một dấu gạch nối.
    preg_match_all('/(?:^|[;"\s])((?:min-)?width:\s*([^;"\']+))/i', $html, $matches, PREG_SET_ORDER);

    $widths = [];

    foreach ($matches as [, $declaration, $value]) {
        $value = trim($value);

        $widths[trim($declaration)] = match (true) {
            (bool) preg_match('/^([\d.]+)px$/i', $value, $px) => (float) $px[1],
            (bool) preg_match('/^([\d.]+)r?em$/i', $value, $em) => (float) $em[1] * 16,
            (bool) preg_match('/^([\d.]+)pt$/i', $value, $pt) => (float) $pt[1] * 4 / 3,
            default => 0.0,
        };
    }

    return $widths;
}

/**
 * Và bộ trích ấy tự chịu một phép đo, vì một biểu thức chính quy hỏng là đúng cách mà khẳng định
 * trên kia đã mất nghĩa lần đầu. Bốn vế: đọc được pixel, quy đúng `rem`, BỎ QUA `max-width`, và
 * không giả vờ đo một bề rộng tương đối.
 */
it('measures widths instead of matching nothing', function () {
    $widths = declaredWidthsInPixels(
        '<div style="width:400px"></div>'
        .'<div style="display:flex;min-width:2rem"></div>'
        .'<div style="max-width:34rem"></div>'
        .'<div style="width:100%"></div>'
    );

    expect($widths)->toBe([
        'width:400px' => 400.0,
        'min-width:2rem' => 32.0,
        'width:100%' => 0.0,
    ]);
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
 * **Test này đo đúng thứ `canAccess()` bảo đảm, không nhiều hơn.** Rà soát Task 3 chứng minh
 * bằng ba lần đột biến rằng phương thức ấy chỉ còn một điều kiện thật sự từ chối được ai đó:
 * *không có ai đăng nhập trên guard `client`*. Guard `client` giải về provider `client_users`,
 * nên người dùng của nó luôn là một `ClientUser` hoặc `null`; và `MatterPolicy::viewAny` trả
 * `true` vô điều kiện cho `ClientUser`. Lời hứa cũ ở docblock — "không có nó thì một nhân sự
 * đang mở /admin đi thẳng vào được màn hình khách hàng" — là **sai**: nhân sự bị từ chối vì guard
 * `client` rỗng, không vì phép so kiểu.
 *
 * Nên hai vế dưới đây là hai câu đúng: nhân sự một mình → từ chối; khách → cho vào. Và vế thứ ba
 * là fixture mà docblock cũ viện tới nhưng chưa bao giờ dựng: **hai guard cùng mở trong một
 * phiên**. Hai panel dùng chung cookie phiên (xem `ClientPortalScope`), nên đó là một phiên có
 * thật, và điều phải đúng là câu trả lời đến từ guard `client` chứ không từ người đang mở /admin.
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
 * Fixture của chính câu docblock cũ nêu tên, dựng đủ lần này: một nhân sự đầy quyền trên guard
 * `web` VÀ một khách hàng trên guard `client`, cùng một phiên, cùng một lúc.
 *
 * Kết quả đúng không phải "từ chối" — khách hàng ấy có quyền xem hồ sơ của họ, và một trợ lý
 * đang mở /admin trên cùng trình duyệt không lấy đi quyền đó. Thứ phải đúng là câu trả lời **đến
 * từ đâu**: `canAccess()` hỏi guard `client` và chỉ guard đó, nên cả hai vế dưới đây cộng lại là
 * "màn hình này thuộc về ai đang đăng nhập ở cổng khách, bất kể ai đang đăng nhập ở panel kia".
 */
it('answers from the client guard even while a member of staff shares the session', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');
    expect(MyMatters::canAccess())->toBeFalse();

    $this->actingAs($this->clientUser, 'client');

    // Cả hai guard thật sự đang mở — nếu vế này đỏ thì fixture đã tự tháo mất tình huống nó dựng.
    expect(Auth::guard('web')->user()?->is($admin))->toBeTrue()
        ->and(Auth::guard('client')->user()?->is($this->clientUser))->toBeTrue()
        ->and(MyMatters::canAccess())->toBeTrue();

    auth('client')->logout();

    expect(Auth::guard('web')->user()?->is($admin))->toBeTrue()
        ->and(MyMatters::canAccess())->toBeFalse();
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

/**
 * Và lối quay lại phải là một thứ KHÁCH CHẠM VÀO ĐƯỢC, không phải một phương thức đẹp đẽ không
 * ai gọi.
 *
 * Rà soát Task 3 đo được đúng khuyết tật ấy: `getAllUrl()` không có nơi gọi nào trong `app/` lẫn
 * `resources/`, còn mục "Hồ sơ của tôi" trên thanh bên thì Filament dựng từ
 * `Page::getNavigationUrl()`, mà mặc định của nó là `getUrl()` — tức `/portal` trần. Một khách có
 * đúng MỘT hồ sơ — chính là người tính năng này được thiết kế cho — chạm vào mục ấy và bị
 * {@see MyMatters::mount()} ném thẳng về trang chi tiết họ vừa đứng. Mục điều hướng chết, và mọi
 * test của màn hình này lái component bằng `showAll` hoặc `withQueryParams` nên không cái nào
 * nhìn thấy.
 *
 * Nên phép đo ở đây ĐI THEO ĐƯỜNG LINK, không hỏi mã nguồn: một request HTTP thật, đọc `href`
 * ra khỏi HTML của thanh bên, rồi một request HTTP thật thứ hai vào đúng `href` đó. **200 chứ
 * không phải 302** là toàn bộ nội dung của khẳng định — một chuyển hướng ở đây nghĩa là khách
 * không bao giờ tới nơi.
 */
it('puts a link on the sidebar that really opens the list for a client with one matter', function () {
    $only = portalMatter();

    $this->actingAs($this->clientUser, 'client');

    // Vế dương: với đúng một hồ sơ, gốc cổng CHUYỂN HƯỚNG. Nếu dòng này đỏ thì khẳng định bên
    // dưới không còn đo gì cả — nó sẽ xanh với một `href` bất kỳ trỏ vào `/portal`.
    $this->get(url('/portal'))->assertRedirect(MatterProgress::getUrl(['record' => $only->id]));

    $page = $this->get(MyMatters::getAllUrl());
    $page->assertOk();

    $hrefs = portalNavigationHrefs($page->getContent(), __('portal_matters.navigation_label'));

    // Nếu vế này đỏ thì phép đo hỏng ở chỗ ĐỌC, không phải ở chỗ điều hướng — và một khẳng định
    // rỗng sẽ xanh mãi mãi. Đây đúng hình dạng khuyết tật M6 của cùng vòng rà soát.
    expect($hrefs)->not->toBeEmpty()
        ->and(array_values(array_unique($hrefs)))->toHaveCount(1);

    $this->get($hrefs[0])
        ->assertOk()
        ->assertSee(__('portal_matters.heading'), false);
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
// M6 Task 4 (`requests/REQ-4`, đính chính SPEC §9 2026-09-27) — huy hiệu "có trả lời mới"
// (App\Support\ClientRequestActivity). Dựng thẳng bằng factory, không qua
// OpenClientRequest/ReplyToClientRequest: những Action đó có tác dụng phụ khác (thư, thông báo)
// không liên quan tới huy hiệu này, và bộ test này không seed vai trò/quyền.
// =========================================================================================

/** Một dòng trả lời của NHÂN SỰ (`author_type = users`), không phải của khách. */
function staffReplyOn(ClientRequest $request, ?User $author = null): ClientRequestReply
{
    $author ??= User::factory()->create();

    return ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $author->getMorphClass(),
        'author_id' => $author->id,
    ]);
}

it('raises the new-reply badge when staff has answered after the clients last entry', function () {
    $matter = portalMatter();
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Answered,
    ]);
    staffReplyOn($request);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.card.new_reply'));
});

it('never raises the new-reply badge when nobody has written a request yet', function () {
    portalMatter();

    $html = renderMyMatters();

    expect($html)->not->toContain(__('portal_matters.card.new_reply'));
});

/** Huy hiệu tắt khi khách viết tiếp — tự động từ chính định nghĩa, không một điều kiện riêng. */
it('drops the new-reply badge once the client writes again after the staff answer', function () {
    $matter = portalMatter();
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::InProgress,
    ]);
    staffReplyOn($request);
    ClientRequestReply::factory()->for($request, 'request')->create([
        'author_type' => $this->clientUser->getMorphClass(),
        'author_id' => $this->clientUser->id,
    ]);

    $html = renderMyMatters();

    expect($html)->not->toContain(__('portal_matters.card.new_reply'));
});

/**
 * Huy hiệu tắt khi luồng đóng — mutation probe: bỏ `$request->status !== ClientRequestStatus::
 * Closed` khỏi `ClientRequestActivity::hasUnseenStaffReply()` — test này ĐỎ.
 */
it('drops the new-reply badge once the thread is closed', function () {
    $matter = portalMatter();
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Closed,
    ]);
    staffReplyOn($request);

    $html = renderMyMatters();

    expect($html)->not->toContain(__('portal_matters.card.new_reply'));
});

/** Đứng độc lập với ba màu tiến độ: một hồ sơ "đủ giấy tờ" vẫn có thể có trả lời mới. */
it('raises the new-reply badge alongside the settled progress tone, not instead of it', function () {
    $matter = portalMatter();
    MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => true, 'status' => ChecklistItemStatus::Accepted,
    ]);
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Answered,
    ]);
    staffReplyOn($request);

    $html = renderMyMatters();

    expect($html)->toContain(__('portal_matters.card.new_reply'))
        ->and($html)->toContain(__('portal_matters.status.settled'));
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
 * Phần cố định là **bảy**: danh sách hồ sơ, loại vụ việc, các giai đoạn của loại đó, các dòng
 * danh mục của cả trang, quan hệ `client` của cả trang (Task 2, vòng sửa 1, Important #2), quan
 * hệ `clientRequests` của cả trang (từ M6 Task 4, `requests/REQ-4` — huy hiệu "có trả lời mới",
 * xem docblock `App\Support\ClientRequestActivity`), và dòng lưu trữ `clientAccessArchive` của cả
 * trang (từ M7 Task 5). Truy vấn thứ tư (danh mục) là cái giá của vòng sửa I3 — huy hiệu và thanh
 * tiến độ nay đọc CÙNG một tập dòng, nên các dòng ấy về một lần cho cả trang thay vì được đếm lại
 * bằng hai `withCount` riêng. Truy vấn thứ năm (`client`) là cái giá của Task 2:
 * `MatterPolicy::view` giờ hỏi thêm "khách hàng chưa xoá mềm" (`releasedToPortal()`), và
 * `MyMatters::buildCards()` nạp sẵn `client` cho CẢ TRANG một lần để hàm đó đọc qua
 * `relationLoaded()` — miễn phí cho từng thẻ — thay vì một `EXISTS` mới trên MỖI thẻ (xem docblock
 * của `releasedToPortal()`). Truy vấn thứ sáu (`clientRequests`) là cái giá của M6 Task 4: không
 * hồ sơ nào trong fixture của test này có một `ClientRequest`, nên nhánh nạp lồng `.replies` không
 * hề chạy — Eloquent bỏ qua eager-load lồng khi tập model cha rỗng (`Builder::get()`:
 * `if (count($models) > 0) { … eagerLoadRelations … }`) — chỉ MỘT truy vấn thêm, không hai. Truy
 * vấn thứ bảy (`clientAccessArchive`) là cái giá của M7 Task 5, cùng hình dạng với `client`: điều
 * kiện thứ năm của `releasedToPortal()` ("khách chưa hết hạn tra cứu") đọc dòng lưu trữ đã nạp sẵn
 * cho cả trang, không một truy vấn nào cho mỗi thẻ (xem docblock
 * `MatterPolicy::clientAccessExpired()`). Tất cả là truy vấn CỐ ĐỊNH, không một truy vấn nào cho
 * mỗi thẻ, và khẳng định độ dốc ở dưới là thứ chứng minh điều đó. Vậy `2N + 7`, tức 47 cho 20 thẻ
 * (gộp M7 vào `main`: hai nhánh mỗi bên thêm đúng một truy vấn cố định thứ sáu, nên sau khi gộp là
 * bảy).
 *
 * Ba khẳng định, vì mỗi cái bắt một hỏng khác nhau: **phần cố định đúng bằng 7** bắt việc có
 * người thêm một truy vấn cố định thứ tám, và giữ cho con số trong docblock này là một con số
 * đo được chứ không một con số kể lại; **trần 50** để lại chỗ thở; **độ dốc đúng bằng 2** bắt thứ
 * đáng sợ hơn — một truy vấn mới mọc lên TRÊN TỪNG THẺ (một quan hệ chưa nạp sẵn, một `count()`
 * trong view). Chỉ có trần thì một hồi quy như vậy vẫn lọt ở 20 thẻ và nổ ở 200.
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
        ->and($twenty - $five)->toBe(2 * 15)
        ->and($five - (2 * 5))->toBe(7);
});
