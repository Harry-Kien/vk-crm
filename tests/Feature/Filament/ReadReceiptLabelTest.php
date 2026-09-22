<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Nhãn "Khách đã xem lúc …" / "Khách chưa xem" ở tab Tiến độ (SPEC §7.2, §4.18).
 *
 * `StageLogPaintingTest` và `ViewMatterTest` đã đo **màu** và **hai trạng thái** của nhãn này từ
 * M3, nhưng cả hai dựng biên bản bằng `StageLogView::factory()` — tức bằng tay. Cho tới M5 không
 * có cách nào khác, vì không có đường sản xuất nào ghi vào bảng đó.
 *
 * Tệp này đo thứ M5 Task 6 mới làm được: **nhãn đọc đúng bảng mà khách thật sự ghi vào**. Không
 * có nó, panel nội bộ có thể đọc một cột, một quan hệ hay một cách lọc khác với thứ
 * `RecordStageLogView` viết ra, và hai bên bàn sẽ nói hai chuyện khác nhau về cùng một dòng — với
 * một bảng tồn tại để làm **bằng chứng đã thông báo**, đó là hỏng đúng ở chỗ đắt nhất.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->sibling = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);
});

function progressTab(Matter $matter)
{
    Filament::setCurrentPanel('admin');

    return test()->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Khách mở trang chi tiết hồ sơ — đúng đường mà một biên bản ra đời (Task 4). */
function clientOpensMatter(ClientUser $viewer, Matter $matter): void
{
    Filament::setCurrentPanel('portal');

    test()->actingAs($viewer, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk();
}

// =========================================================================================
// NHÃN ĐỌC ĐÚNG BẢNG MÀ KHÁCH GHI VÀO
// =========================================================================================

/**
 * Hai vế trong cùng một test, và chúng khác nhau **chỉ ở việc khách có mở trang hay không**.
 */
it('flips from chua xem to da xem after the client actually opens the matter page', function () {
    $log = StageLog::factory()->for($this->matter)->published()->create([
        'published_at' => now()->subDay(),
        'public_content' => 'Toà đã nhận đơn khởi kiện.',
    ]);

    $this->actingAs($this->lawyer, 'web');

    progressTab($this->matter)->assertSee(__('matters.stage_log_fields.not_viewed'));

    $this->travelTo('2026-09-14 21:14:00');
    clientOpensMatter($this->clientUser, $this->matter);

    expect($log->views()->count())->toBe(1);

    $this->actingAs($this->lawyer, 'web');

    progressTab($this->matter)
        ->assertSee(__('matters.stage_log_fields.viewed_at', ['time' => '21:14', 'date' => '14/09']))
        ->assertDontSee(__('matters.stage_log_fields.not_viewed'));
});

/**
 * **Nhãn nói về KHÁCH HÀNG, không về một người.** Phán quyết 19/09/2026 (đọc theo `Client`) áp
 * cho `StageLogView` y như cho `ClientRequest`, nên một hồ sơ có hai tài khoản portal (SPEC §4.3
 * nêu ví dụ hai vợ chồng) chỉ cần MỘT người mở là nhãn chuyển. Vế âm là chính trạng thái ban đầu,
 * đo ngay trước đó.
 */
it('reads da xem when any one portal account of the client has opened it', function () {
    StageLog::factory()->for($this->matter)->published()->create(['published_at' => now()->subDay()]);

    $this->actingAs($this->lawyer, 'web');
    progressTab($this->matter)->assertSee(__('matters.stage_log_fields.not_viewed'));

    // Người THỨ HAI mở, không phải người gửi yêu cầu hay người đăng nhập đầu tiên.
    clientOpensMatter($this->sibling, $this->matter);

    $this->actingAs($this->lawyer, 'web');
    progressTab($this->matter)->assertDontSee(__('matters.stage_log_fields.not_viewed'));
});

/**
 * Dấu thời gian là của lần mở **ĐẦU TIÊN**, và nhãn phải kể lại đúng nó — một nhãn đọc "lần gần
 * nhất ai đó mở trang" là một bằng chứng khác hẳn, và nó yếu hơn: nó không trả lời được câu
 * "khách biết chuyện này từ bao giờ".
 *
 * **Cảnh phân biệt được phải có HAI biên bản, và nó phải đến từ HAI TÀI KHOẢN.** Cùng một tài
 * khoản mở lại lần thứ hai KHÔNG sinh thêm hàng nào (`RecordStageLogView` ghi một lần cho mỗi cặp
 * `(dòng, tài khoản)`), nên với một tài khoản thì "đầu" và "cuối" là cùng một hàng và test không
 * đo gì cả — đo được: bản đầu của test này dùng một tài khoản mở hai lần, và đổi
 * `orderBy('viewed_at')` thành `orderByDesc` vẫn để nó XANH.
 *
 * Hai tài khoản portal của cùng một khách hàng là cảnh THẬT (SPEC §4.3 nêu ví dụ hai vợ chồng),
 * và nó cũng là cảnh mà nhãn "nói về khách hàng, không về một người" có nghĩa.
 */
it('keeps showing the first opening by any account, not the most recent one', function () {
    $log = StageLog::factory()->for($this->matter)->published()->create(['published_at' => now()->subDays(8)]);

    $this->travelTo('2026-09-14 21:14:00');
    clientOpensMatter($this->clientUser, $this->matter);

    $this->travelTo('2026-09-20 08:30:00');
    clientOpensMatter($this->sibling, $this->matter);

    // Tiền đề của phép đo: hai biên bản thật, hai dấu thời gian khác nhau. Nếu câu này sai thì
    // phần dưới không phân biệt được "đầu" với "cuối".
    expect($log->views()->count())->toBe(2);

    $this->actingAs($this->lawyer, 'web');

    progressTab($this->matter)
        ->assertSee(__('matters.stage_log_fields.viewed_at', ['time' => '21:14', 'date' => '14/09']))
        ->assertDontSee(__('matters.stage_log_fields.viewed_at', ['time' => '08:30', 'date' => '20/09']));
});

/**
 * **Hợp đồng "lần mở ĐẦU TIÊN" cũng phải đúng trên nhánh KHÔNG eager-load.**
 *
 * Test ngay trên đi qua bảng, và bảng eager-load `views` theo `viewed_at` — nên nó ghim thứ tự
 * của `modifyQueryUsing()`, không ghim `readReceiptLabel()`. Đo được: đổi `orderBy('viewed_at')`
 * thành `orderByDesc` ở NHÁNH FALLBACK (dòng `$stageLog->views()->…` trong chính hàm nhãn) để cả
 * sáu test của tệp này XANH. Nhánh ấy không phải mã chết: mọi lời gọi `readReceiptLabel()` trên
 * một model chưa qua bảng đều đi vào nó — `ViewMatterTest` và `StageLogPaintingTest` gọi như vậy,
 * và một widget hay một bản xuất sau này cũng sẽ gọi như vậy.
 *
 * Nên test này gọi hàm TRỰC TIẾP trên một model vừa đọc lại từ cơ sở dữ liệu, khẳng định trước
 * rằng quan hệ CHƯA nạp, và dựng hai biên bản từ hai tài khoản (một tài khoản mở hai lần chỉ sinh
 * một hàng, nên "đầu" và "cuối" trùng nhau và phép đo mất nghĩa — cùng cái bẫy đã ghi ở test
 * trên).
 */
it('still reads the first opening when the label is called with the relation not loaded', function () {
    $log = StageLog::factory()->for($this->matter)->published()->create(['published_at' => now()->subDays(8)]);

    $this->travelTo('2026-09-14 21:14:00');
    clientOpensMatter($this->clientUser, $this->matter);

    $this->travelTo('2026-09-20 08:30:00');
    clientOpensMatter($this->sibling, $this->matter);

    $this->actingAs($this->lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $fresh = StageLog::query()->findOrFail($log->getKey());

    // Hai tiền đề, và cả hai đều bắt buộc: đúng nhánh fallback, và hai biên bản khác giờ nhau.
    expect($fresh->relationLoaded('views'))->toBeFalse()
        ->and($fresh->views()->count())->toBe(2)
        ->and($fresh->relationLoaded('views'))->toBeFalse();

    expect(StageLogsRelationManager::readReceiptLabel($fresh)['text'])
        ->toBe(__('matters.stage_log_fields.viewed_at', ['time' => '21:14', 'date' => '14/09']));
});

/**
 * **Gỡ một dòng tiến độ khỏi cổng KHÔNG xoá biên bản, và nhãn ở panel nội bộ vẫn kể lại nó.**
 *
 * Đo được và ghi lại vì câu hỏi này được nêu ra ở vòng hợp nhất theo chiều ngược: hàng
 * `stage_log_views` ở lại nguyên, và nhãn vẫn đọc "Khách đã xem lúc …". Đó là hành vi ĐÚNG — nhãn
 * nói một sự thật đã xảy ra về dòng này, và một bằng chứng biến mất khi văn phòng đổi ý về việc
 * công bố thì không còn là bằng chứng. Chiều kia thì có cắn, và nó cắn ở đúng chỗ nó phải cắn:
 * trong ngữ cảnh portal, `StageLogView::applyClientPortalConstraints()` là `whereHas('stageLog')`
 * nên cả dòng tiến độ lẫn biên bản của nó cùng biến khỏi tầm mắt của KHÁCH.
 */
it('keeps the receipt and the label after the office unpublishes the entry', function () {
    $log = StageLog::factory()->for($this->matter)->published()->create(['published_at' => now()->subDays(8)]);

    $this->travelTo('2026-09-14 21:14:00');
    clientOpensMatter($this->clientUser, $this->matter);

    $this->actingAs($this->lawyer, 'web');
    Filament::setCurrentPanel('admin');

    // Tiền đề: đã có biên bản và nhãn đang đọc nó.
    expect(StageLogsRelationManager::readReceiptLabel(StageLog::query()->findOrFail($log->getKey()))['text'])
        ->toBe(__('matters.stage_log_fields.viewed_at', ['time' => '21:14', 'date' => '14/09']));

    $log->update(['is_published' => false]);

    expect(StageLogView::withoutGlobalScope(ClientPortalScope::class)->count())->toBe(1)
        ->and(StageLogsRelationManager::readReceiptLabel(StageLog::query()->findOrFail($log->getKey()))['text'])
        ->toBe(__('matters.stage_log_fields.viewed_at', ['time' => '21:14', 'date' => '14/09']));
});

// =========================================================================================
// TÔ VÀNG QUÁ 5 NGÀY — cặp dương/âm, và nó đo ĐÚNG cái làm ra màu
// =========================================================================================

/**
 * `ViewMatterTest` đã ghim giá trị `highlighted`; ở đây đo thứ người dùng THẤY — biến màu thật sự
 * đi vào HTML. Không có vế này, `renderPublicContent()` bỏ hẳn nhánh màu đi vẫn xanh.
 */
it('paints the overdue unread label amber and leaves a fresh one plain', function () {
    $overdue = StageLog::factory()->published()->make([
        'published_at' => now()->subDays(6),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);
    $fresh = StageLog::factory()->published()->make([
        'published_at' => now()->subDays(2),
        'public_content' => 'Đã nộp đơn lên toà',
    ]);

    $overdueHtml = (string) StageLogsRelationManager::renderPublicContent($overdue->public_content, $overdue);
    $freshHtml = (string) StageLogsRelationManager::renderPublicContent($fresh->public_content, $fresh);

    expect(StageLogsRelationManager::readReceiptLabel($overdue)['highlighted'])->toBeTrue()
        ->and($overdueHtml)->toContain('var(--warning-600)')
        ->and(StageLogsRelationManager::readReceiptLabel($fresh)['highlighted'])->toBeFalse()
        ->and($freshHtml)->not->toContain('var(--warning-600)')
        // Vế dương của vế âm: nhãn vẫn được vẽ ra, chỉ là không tô vàng.
        ->and($freshHtml)->toContain(__('matters.stage_log_fields.not_viewed'));
});

/**
 * Ngưỡng 5 ngày của nhãn là **cùng một ngưỡng** với widget SPEC §7.1 mục 5. Hai con số rời nhau ở
 * hai tệp là hai con số sẽ lệch, và khi đó một dòng tô vàng trên tab Tiến độ sẽ không có mặt
 * trong hàng đợi gọi điện — hoặc ngược lại.
 */
it('uses the same five day threshold as the unseen updates widget', function () {
    expect(UnseenUpdatesWidget::UNSEEN_AFTER_DAYS)->toBe(5);

    $justPast = StageLog::factory()->published()->make(['published_at' => now()->subDays(6)]);
    $justUnder = StageLog::factory()->published()->make(['published_at' => now()->subDays(5)->addHour()]);

    expect(StageLogsRelationManager::readReceiptLabel($justPast)['highlighted'])->toBeTrue()
        ->and(StageLogsRelationManager::readReceiptLabel($justUnder)['highlighted'])->toBeFalse();
});

// =========================================================================================
// NHÃN CHỈ ĐỨNG TRÊN DÒNG ĐÃ CÔNG BỐ
// =========================================================================================

/**
 * Một dòng nháp chưa bao giờ ra tới khách, nên "Khách chưa xem" trên nó là một lời trách vô nghĩa
 * — và trên một bảng bằng chứng, một nhãn sai chỗ là một nhãn đọc sai được.
 */
it('never puts a read receipt label on an unpublished entry', function () {
    $draft = StageLog::factory()->for($this->matter)->internalOnly()->make();

    expect(StageLogsRelationManager::renderPublicContent($draft->public_content, $draft))->toBeNull();

    // Vế dương trên cùng một dữ liệu: đúng dòng đó, đã công bố, thì có nhãn.
    $draft->is_published = true;
    $draft->published_at = now()->subDay();

    expect((string) StageLogsRelationManager::renderPublicContent('Nội dung công bố', $draft))
        ->toContain(__('matters.stage_log_fields.not_viewed'));
});
