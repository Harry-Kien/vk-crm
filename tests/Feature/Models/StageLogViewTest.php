<?php

use App\Exceptions\StageLogViewImmutable;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;

/**
 * **Biên bản "khách đã xem" là bằng chứng, và model là chỗ nó được canh.**
 *
 * `StageLogViewPolicy` không khai báo `update` lẫn `delete`, nên `Gate` từ chối cả hai — nhưng
 * `Gate` chỉ canh những đường CÓ HỎI nó. Một Action, một job, một lệnh console hay một lần dọn
 * dữ liệu gọi thẳng Eloquent thì không ai hỏi, và trước vòng sửa này cả hai đường đó đều đi lọt:
 * `viewed_at` dời được sang năm sau với một địa chỉ IP khác, và cả dòng xoá được.
 *
 * Cùng thiết bị với `StageLogTest` cho `StageLog::booted()` (SPEC §4.8, nhật ký chỉ thêm).
 */
beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->create(['client_id' => $this->client->id]);
    $this->matter = Matter::factory()->for($this->client)->create(['is_published_to_portal' => true]);
    $this->log = StageLog::factory()->for($this->matter)->published()->create();

    $this->receipt = StageLogView::factory()->create([
        'stage_log_id' => $this->log->id,
        'client_user_id' => $this->clientUser->id,
        'viewed_at' => '2026-09-20 08:00:00',
        'ip' => '203.0.113.5',
    ]);
});

/** Không qua `ClientPortalScope` — để đọc lại TRẠNG THÁI THẬT của bảng, không trạng thái đã lọc. */
function receiptRow(int $id): ?object
{
    return DB::table('stage_log_views')->find($id);
}

it('never lets viewed_at or ip be overwritten on a receipt', function () {
    expect(fn () => $this->receipt->update(['viewed_at' => now()->addYear(), 'ip' => '8.8.8.8']))
        ->toThrow(StageLogViewImmutable::class);

    $row = receiptRow($this->receipt->id);

    expect($row->viewed_at)->toStartWith('2026-09-20 08:00:00')
        ->and($row->ip)->toBe('203.0.113.5');
});

it('never lets a receipt be deleted', function () {
    expect(fn () => $this->receipt->delete())->toThrow(StageLogViewImmutable::class)
        ->and(receiptRow($this->receipt->id))->not->toBeNull();
});

/**
 * Câu chữ bằng tiếng Việt, từ `lang/vi/exceptions.php` — không một thông điệp tiếng Anh mặc định
 * nào của framework lọt ra ngoài (SPEC §8).
 */
it('refuses in vietnamese, from the language file', function () {
    expect(fn () => $this->receipt->delete())
        ->toThrow(StageLogViewImmutable::class, __('exceptions.stage_log_view_immutable'));

    expect(__('exceptions.stage_log_view_immutable'))->not->toBe('exceptions.stage_log_view_immutable');
});

/**
 * Vế dương, và nó là điều kiện để hai vế âm trên có nghĩa: cái chốt chặn SỬA và XOÁ, chứ không
 * chặn luôn việc GHI — ghi lần đầu chính là việc của bảng này.
 */
it('still lets a receipt be written for the first time', function () {
    $sibling = ClientUser::factory()->create(['client_id' => $this->client->id]);

    $fresh = ClientPortalScope::actingAs($this->clientUser, fn () => StageLogView::create([
        'stage_log_id' => $this->log->id,
        'client_user_id' => $sibling->id,
        'viewed_at' => now(),
        'ip' => '203.0.113.6',
    ]));

    expect(receiptRow($fresh->id))->not->toBeNull();
});
