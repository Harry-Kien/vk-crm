<?php

use App\Actions\Mcp\Read\KeysetOrder;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Support\Scopes\ClientPortalScope;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — `App\Actions\Mcp\Read\KeysetOrder`
|--------------------------------------------------------------------------
| Phân trang theo (cột, id) của các tool danh sách: đi hết danh sách bằng trang cỡ nào cũng ra đủ
| mọi dòng, mỗi dòng một lần, đúng thứ tự — kể cả khi cột có giá trị trùng và giá trị rỗng, theo cả
| hai chiều. "Cột rỗng đứng đâu" là quy ước của CSDL, nên test chạy trên CSDL thật của bộ test
| (SQLite, và MariaDB qua `test:mariadb`). Các tool chỉ dùng chiều tăng dần trên một cột không rỗng
| (`deadlines.due_date`), nên nhánh "tăng dần + cột rỗng" chỉ đo được ở đây.
*/

beforeEach(function () {
    $matter = Matter::factory()->create();
    $base = now()->subDay()->startOfMinute();

    // Ba dòng rỗng, ba giá trị trùng nhau, xen kẽ theo id.
    foreach ([null, 2, 0, null, 2, 5, 1, null, 0, 2, 3] as $minutes) {
        ClientRequest::factory()->create(['matter_id' => $matter->id])
            ->forceFill(['last_activity_at' => $minutes === null ? null : $base->copy()->addMinutes($minutes)])
            ->save();
    }
});

/** @return list<int> id theo thứ tự đi qua các trang cỡ `$size` */
function keysetWalk(KeysetOrder $order, int $size): array
{
    $seen = [];
    $after = null;
    $guard = 0;

    do {
        $page = $order->page(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class), $size, $after);
        $seen = [...$seen, ...array_map(fn (ClientRequest $request): int => (int) $request->id, $page->rows)];
        $after = $page->next;
    } while ($after !== null && ++$guard < 100);

    return $seen;
}

/** @return list<int> thứ tự tăng dần mong đợi: rỗng trước (NULL nhỏ nhất), rồi giá trị, rồi id */
function keysetAscending(): array
{
    return ClientRequest::query()->get()
        ->sortBy(fn (ClientRequest $request): array => [
            $request->last_activity_at === null ? 0 : 1,
            $request->last_activity_at?->getTimestamp() ?? 0,
            (int) $request->id,
        ])
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->values()
        ->all();
}

it('tăng dần: cột rỗng đứng đầu, rồi theo giá trị, cùng giá trị thì theo id; trang cỡ nào cũng đủ, không trùng, đúng thứ tự', function () {
    $order = new KeysetOrder('client_requests', 'last_activity_at', descending: false);
    $expected = keysetAscending();

    expect($expected)->toHaveCount(11);

    foreach ([1, 2, 3, 4, 25] as $size) {
        expect(keysetWalk($order, $size))->toBe($expected, "trang {$size}");
    }
});

it('giảm dần: đúng ngược thứ tự tăng dần — cột rỗng đứng cuối; trang cỡ nào cũng đủ, không trùng', function () {
    $order = new KeysetOrder('client_requests', 'last_activity_at', descending: true);
    $expected = array_reverse(keysetAscending());

    foreach ([1, 2, 3, 4, 25] as $size) {
        expect(keysetWalk($order, $size))->toBe($expected, "trang {$size}");
    }
});

it('limit bị kẹp vào [1, 25]; trang cuối không có vị trí tiếp', function () {
    $order = new KeysetOrder('client_requests', 'last_activity_at', descending: true);

    foreach ([0, -5] as $tooSmall) {
        $page = $order->page(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class), $tooSmall, null);

        expect($page->rows)->toHaveCount(1)
            ->and($page->next)->not->toBeNull();
    }

    expect(KeysetOrder::clamp(100))->toBe(25)
        ->and($order->page(ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class), 100, null)->next)->toBeNull();
});
