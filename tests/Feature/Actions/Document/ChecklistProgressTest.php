<?php

use App\Actions\Document\ChecklistProgress;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterType;
use App\Support\Scopes\ClientPortalScope;

/**
 * Luật đếm `Đã nộp X / Y` của SPEC §4.10 và đính chính 2026-09-16 của nó.
 *
 * Những test này trước đây nằm ở `ChecklistRelationManagerTest`, cùng chỗ với luật. Chúng theo
 * luật sang `app/Actions/` khi luật rời khỏi relation manager của panel /admin — xem docblock
 * `ChecklistProgress` cho lý do. Cái ở lại bên kia là việc VẼ: `progressBar()` và việc thanh
 * tiến độ thật sự xuất hiện trên tab.
 */
function checklistProgress(Matter $matter): array
{
    return app(ChecklistProgress::class)->handle($matter);
}

/**
 * **Con số này đã từng đọc "Đã nộp 5/3".** `MatterSeeder` đánh dấu mọi đầu mục KHÔNG bắt buộc là
 * `not_applicable` và không gắn tài liệu nào; mẫu số của SPEC §4.10 là "số item bắt buộc cộng số
 * item không bắt buộc ĐÃ CÓ TÀI LIỆU", nên những dòng đó không nằm trong mẫu số — nhưng bản kế
 * hoạch đầu cho tử số đếm chúng. Danh mục `DS` có 3 mục bắt buộc và 2 mục không bắt buộc, nên vụ
 * việc `DS` trong dữ liệu mẫu hiện ra một thanh tiến độ lớn hơn chính mẫu số của nó — trên đúng
 * con số mà M5 đưa lên thẻ hồ sơ của khách.
 *
 * Test chạy trên DỮ LIỆU MẪU THẬT (`DatabaseSeeder`), không trên một fixture dựng tay mô phỏng
 * nó: cái sai nằm ở chỗ seeder và luật đếm gặp nhau, và một fixture chép lại bằng tay sẽ chép
 * theo cách hiểu của người viết test chứ không phải hình dạng dữ liệu thật.
 */
it('counts the demo DS matter as 3/3 where the old rule read 5/3', function () {
    $this->seed();

    $dsType = MatterType::query()->where('code', 'DS')->firstOrFail();

    // Luật CŨ, viết ra ở đây nguyên văn để test này so được hai cách đọc với nhau thay vì so một
    // cách đọc với một con số chép tay: "X = số đầu mục có status thuộc {accepted,
    // not_applicable}", không nhìn tới bảng documents.
    $oldRuleSubmitted = fn (Matter $matter): int => $matter->checklistItems()
        ->whereIn('status', [ChecklistItemStatus::Accepted->value, ChecklistItemStatus::NotApplicable->value])
        ->count();

    $dsMatters = Matter::query()->where('matter_type_id', $dsType->id)->get();

    expect($dsMatters)->not->toBeEmpty();

    // Hồ sơ DS mà luật cũ đọc RA NGOÀI mẫu số của chính nó. Tìm bằng cách CHẠY luật cũ trên dữ
    // liệu mẫu thật, không bằng cách đoán vụ nào — seeder đổi thì test vẫn tìm đúng vụ, và nếu
    // một ngày không còn vụ nào như vậy thì `expect(...)->not->toBeNull()` bên dưới đỏ và người
    // sửa seeder biết là mình vừa gỡ mất nhân chứng của luật này.
    $overflowing = $dsMatters->first(fn (Matter $matter): bool => $oldRuleSubmitted($matter)
        > checklistProgress($matter)['total']);

    expect($overflowing)->not->toBeNull();

    $progress = checklistProgress($overflowing);

    // Con số đo được trên dữ liệu mẫu hôm nay: 3 mục bắt buộc, cộng 2 mục không bắt buộc đã được
    // đánh dấu "không cần nộp" và không có tài liệu nào. Luật cũ cho 5 trên mẫu số 3.
    expect($oldRuleSubmitted($overflowing))->toBe(5)
        ->and($progress)->toBe(['submitted' => 3, 'total' => 3]);

    // Và luật mới giữ `X ≤ Y` trên MỌI hồ sơ của dữ liệu mẫu, không chỉ trên vụ vừa tìm được —
    // đó mới là phát biểu đầy đủ của phán quyết, và nó không phụ thuộc vào hình dạng seeder.
    Matter::query()->withTrashed()->get()->each(function (Matter $matter): void {
        ['submitted' => $submitted, 'total' => $total] = checklistProgress($matter);

        expect($submitted)->toBeLessThanOrEqual($total);
    });
});

/**
 * Nửa còn lại của SPEC §4.10: một đầu mục KHÔNG bắt buộc đã có tài liệu thì VÀO mẫu số. Cặp sinh
 * đôi dương của test trên — không có nó, một cài đặt chỉ đếm mục bắt buộc cũng xanh.
 */
it('pulls an optional item into the denominator once it has a client-facing document', function () {
    $matter = Matter::factory()->create();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Accepted,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 0]);

    // `status: Published` + `client_can_view: true` — đúng bộ mặc định thật của nhóm A
    // (`StoresDocumentFile::defaultsFor()`), không phải mặc định TRẦN của factory (vốn là
    // `internal_draft`/`false`, đúng hình dạng một tài liệu KHÔNG hiện cho khách).
    $document = Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 1, 'total' => 1]);

    // Và một tài liệu đã xoá thì thôi không còn là "đã có tài liệu": `withCount` đi qua global
    // scope của `Document`, nên `SoftDeletingScope` loại dòng vừa xoá. Khẳng định ra thay vì để
    // nó nằm trong một câu bình luận — dòng đó không còn trong hồ sơ, nên đầu mục rời mẫu số.
    $document->delete();

    expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 0]);
});

/**
 * **Một ghi chú công việc nội bộ không được kéo một đầu mục vào danh sách việc của khách.** Một
 * tài liệu nhóm D gắn được vào một đầu mục danh mục và đó là việc hợp lệ; nếu phép đếm "đã có tài
 * liệu" tính cả nó, thì một đầu mục không bắt buộc mà khách chưa hề đụng tới sẽ xuất hiện trên
 * thanh tiến độ của chính khách, kèm trạng thái "chưa nộp".
 */
it('keeps an optional item out of the denominator when its only document is group D', function () {
    $matter = Matter::factory()->create();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::Internal)->create([
        'matter_checklist_item_id' => $optional->id,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 0]);
});

/**
 * `X` đếm BÊN TRONG `Y`, không đếm toàn bảng: một mục không bắt buộc, không tài liệu, đã đánh dấu
 * `not_applicable` không được cộng vào tử số. Đây chính là hình dạng đã sinh ra "5/3".
 */
it('never counts a settled item that is not in the denominator', function () {
    $matter = Matter::factory()->create();

    MatterChecklistItem::factory()->count(3)->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);

    MatterChecklistItem::factory()->count(2)->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::NotApplicable,
    ]);

    $progress = checklistProgress($matter);

    expect($progress)->toBe(['submitted' => 3, 'total' => 3])
        ->and($progress['submitted'])->toBeLessThanOrEqual($progress['total']);
});

// ---------------------------------------------------------------------------------------------
// Cùng một hồ sơ, cùng một thời điểm, hai guard — MỘT con số.
// ---------------------------------------------------------------------------------------------

/**
 * **Cặp sinh đôi còn thiếu của cả bộ test trên: không test nào ở đây từng chạy dưới guard
 * `client`.** Và đó chính là guard mà SPEC §4.10 nói tới — `X/Y` là "trường tính toán hiển thị
 * trên portal".
 *
 * Sau bản sửa checklist-05, luật đếm ("đã có tài liệu" = tài liệu khách ĐỌC ĐƯỢC —
 * `client_can_view` + `published` + khác nhóm D) trùng khớp gần như nguyên vẹn với chính
 * `ClientPortalScope::applyClientPortalConstraints()` của `Document`, nên trên một hồ sơ đã công
 * bố hai cách đọc không còn tình huống nào để lệch nhau nữa — đó là một hệ quả TỐT của bản sửa
 * (một nguồn sự thật, không phải hai công thức tình cờ ra cùng một số). Test này giữ lại tính
 * chất "hai guard, một con số" như một hồi quy, không còn như một nhân chứng cho một lỗ hổng cụ
 * thể.
 */
it('reads the same X/Y under the client guard as under the staff guard', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create();

    MatterChecklistItem::factory()->count(3)->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    $staff = checklistProgress($matter);
    $portal = ClientPortalScope::actingAs($clientUser, fn (): array => checklistProgress($matter));

    expect($staff)->toBe(['submitted' => 3, 'total' => 4])
        ->and($portal)->toBe($staff);
});

/**
 * `withCount` áp global scope của `Document`, và phép đếm tự bỏ nó ra một cách tường minh
 * (`withoutGlobalScope(ClientPortalScope::class)`) để con số không phụ thuộc guard nào TÌNH CỜ
 * đang mở khi `handle()` được gọi — kể cả khi hồ sơ CHƯA lên portal, thứ mà `Y` của SPEC §4.10
 * không hề nói tới ("và hồ sơ đã công bố"). Không có lần bỏ scope này, gọi `handle()` trong lúc
 * một phiên khách khác đang mở (`ClientPortalScope::actingAs()` lồng nhau, hoặc job chạy dưới
 * guard client) sẽ cho một con số phụ thuộc NGỮ CẢNH thay vì phụ thuộc DỮ LIỆU.
 *
 * Nhân chứng: một hồ sơ CHƯA công bố lên portal (`is_published_to_portal = false`) mang một tài
 * liệu nhóm A đã `published`/`client_can_view`. Đọc dưới guard NỘI BỘ (không `actingAs` nào),
 * đầu mục tuỳ chọn vẫn được kéo vào `Y` — đúng luật, vì luật không nói gì về cờ công bố của hồ
 * sơ. Xoá `withoutGlobalScope()` thì `whereHas('matter')` của `ClientPortalScope` lặng lẽ chặn
 * chính EXISTS đó (`Matter::applyClientPortalConstraints()` đòi `is_published_to_portal = true`)
 * — không phải vì `ClientPortalScope::isActive()` báo `true` (nó vẫn `false`, không ai đăng nhập
 * guard `client`), mà vì global scope của `Document` là một class LUÔN được ĐĂNG KÝ trên
 * builder; `withoutGlobalScope()` gỡ chính điều kiện đó khỏi câu SQL, không gỡ một lần "đang bật
 * hay tắt".
 */
it('drops the portal scope inside the count even when nobody is on the client guard', function () {
    $matter = Matter::factory()->unpublished()->create();

    MatterChecklistItem::factory()->count(3)->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Accepted,
    ]);

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 3, 'total' => 4]);
});

/**
 * **checklist-05.** Văn phòng gắn một quyết định nhóm C (`internal_draft`, `client_can_view =
 * false`) vào một đầu mục tuỳ chọn: đầu mục đó KHÔNG được kéo vào `Y`. Trước bản sửa này, luật
 * đếm chỉ hỏi "khác nhóm D", nên tài liệu này (nhóm C, không phải D) VẪN kéo đầu mục vào mẫu số
 * — tăng mẫu số từ 3 lên 4 — trong khi trạng thái đầu mục vẫn `missing`: khách bị đòi đúng thứ
 * văn phòng đã có trong tay mà họ lại không nhìn thấy.
 *
 * Cặp sinh đôi dương nằm ngay trong `'pulls an optional item into the denominator once it has a
 * client-facing document'` phía trên: một tài liệu nhóm A đã công bố VẪN kéo được đầu mục vào
 * `Y`, nên luật ở đây không phải "bỏ mọi tài liệu ngoài nhóm A" mà là "chỉ tính tài liệu khách
 * ĐỌC ĐƯỢC" — một quyết định nhóm B đã đi hết vòng đời và được công bố cũng qua được (xem test kế
 * tiếp).
 */
it('does not pull an optional item into the denominator for an internal_draft group C decision', function () {
    $matter = Matter::factory()->create();

    MatterChecklistItem::factory()->count(3)->for($matter)->create([
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::Authority)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::InternalDraft,
        'client_can_view' => false,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 3]);
});

/**
 * Fix round 1 (C1, critical): một quyết định nhóm B/C đã đi hết vòng đời (`published`,
 * `client_can_view = true`) VẪN KHÔNG kéo đầu mục vào `Y`. Phán quyết của chủ nhiệm sau lượt rà
 * soát đầu: `Y` đếm CHỈ nhóm A — không phải "tài liệu khách đọc được" như bản sửa trước đó đọc.
 * Một quyết định nhóm B/C, dù đã công bố, vẫn là tài liệu VĂN PHÒNG đưa ra, không phải tài liệu
 * KHÁCH nộp; đếm nó vào mẫu số tái lập đúng lỗi mà finding checklist-05 gốc mô tả — khách bị đòi
 * một thứ họ không hề tạo ra, chỉ khác là lần này đã công bố nên `client_can_view = true` không
 * còn phân biệt được với một tài liệu nhóm A thật.
 *
 * Đây là test bị lật so với vòng sửa trước (từng khẳng định `total => 1`) — chính hình dạng mà
 * review vòng 1 chỉ ra là sai.
 */
it('does not pull an optional item into the denominator even once a published group B document reaches the client', function () {
    $matter = Matter::factory()->create();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::Published,
        'client_can_view' => true,
    ]);

    expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 0]);
});
