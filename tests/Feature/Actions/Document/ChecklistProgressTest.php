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

    $document = Document::factory()->for($matter)->group(DocumentGroup::ClientProvided)->create([
        'matter_checklist_item_id' => $optional->id,
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
 * `withCount` áp global scope của `Document`, nên dưới guard khách `ClientPortalScope` thu tập
 * đếm được về "đã công bố VÀ khách được xem", trong khi §4.10 định nghĩa `Y` bằng "có tài liệu
 * KHÔNG thuộc nhóm D". Đo được trên dữ liệu mẫu trước bản sửa này: nhân sự đọc `3/4`, khách đọc
 * `2/3` — cùng hồ sơ, cùng thời điểm, hai con số.
 *
 * Nhân chứng là một tài liệu nhóm B còn `internal_draft`: nó KHÔNG ra tới khách (đúng vòng đời
 * SPEC §4.11), nhưng nó là bằng chứng rằng đầu mục ấy không còn là một việc của khách. Đây là
 * hình dạng duy nhất phân biệt được hai cách đọc; một tài liệu nhóm A đã công bố thì cả hai
 * cách đọc đều đếm.
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

    Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::InternalDraft,
        'client_can_view' => false,
    ]);

    $staff = checklistProgress($matter);
    $portal = ClientPortalScope::actingAs($clientUser, fn (): array => checklistProgress($matter));

    expect($staff)->toBe(['submitted' => 3, 'total' => 4])
        ->and($portal)->toBe($staff);
});

/**
 * Cặp dương của test trên, và nó nói một điều test kia không nói: bỏ `ClientPortalScope` ra khỏi
 * phép đếm KHÔNG làm scope ấy mất tác dụng ở nơi nó có việc. Cùng `$clientUser`, cùng lúc, danh
 * sách tài liệu mà khách đọc được vẫn RỖNG — bản nháp nhóm B không ra tới khách.
 *
 * Không có khẳng định này, một bản sửa gỡ `RestrictedToClientPortal` khỏi `Document` cũng làm
 * test trên xanh.
 */
it('drops the portal scope inside the count without loosening what the client can read', function () {
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create();

    $optional = MatterChecklistItem::factory()->for($matter)->create([
        'is_required' => false,
        'status' => ChecklistItemStatus::Missing,
    ]);

    $draft = Document::factory()->for($matter)->group(DocumentGroup::Issued)->create([
        'matter_checklist_item_id' => $optional->id,
        'status' => DocumentStatus::InternalDraft,
        'client_can_view' => false,
    ]);

    ClientPortalScope::actingAs($clientUser, function () use ($matter, $draft): void {
        expect(checklistProgress($matter))->toBe(['submitted' => 0, 'total' => 1])
            ->and(Document::query()->whereKey($draft->getKey())->exists())->toBeFalse();
    });
});
