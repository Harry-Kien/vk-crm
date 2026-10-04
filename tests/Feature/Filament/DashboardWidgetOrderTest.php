<?php

use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Filament\Admin\Widgets\UnansweredIntakesWidget;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use Filament\Widgets\AccountWidget;

/**
 * SPEC §7.1 đánh số các widget trang chủ và nói rõ "theo thứ tự", với mục 1 được gọi thẳng là
 * "widget quan trọng nhất, đặt trên cùng". Thứ tự đó là nghiệp vụ, không phải thẩm mỹ: cái đầu
 * tiên người ta nhìn thấy mỗi sáng quyết định việc gì được làm trước.
 *
 * Trước khi có test này, `getSort()` của hai widget bằng nhau (-1) nên chúng đứng theo thứ tự
 * Filament tình cờ nạp lớp — đo được trên trình duyệt: mục 6 hiện TRƯỚC mục 4. Một con số trùng
 * không gây lỗi gì cả, nên không có gì báo động.
 *
 * Mục 5 ra đời ở M5 Task 6 (`UnseenUpdatesWidget`) — `stage_log_views` tới M5 mới có dữ liệu
 * thật. Để nó có chỗ thì mục 6 phải dời từ `0` lên `1`: `$sort` của Filament là `?int`, nên giữa
 * `-1` và `0` không còn số nguyên nào. Đó là một lần ĐÁNH SỐ LẠI, không phải một thay đổi thứ tự,
 * và test này so sánh theo THỨ TỰ TƯƠNG ĐỐI nên nó đo đúng điều đó.
 *
 * Mục 2 ra đời ở M6.5 Task 14 (`UpcomingDeadlinesWidget`, `deadlines/F5`/`spec-gap-05`) — nó nằm
 * ĐÚNG giữa mục 1 và mục 3 (`$sort = -3`, theo con số brief giao), dù trùng với
 * `Filament\Widgets\AccountWidget` (cũng `-3`, không có mặt trong `$sorts` bên dưới nên không đụng
 * `array_unique`). Mục 7 vẫn chưa tồn tại (cần heartbeat — M7, và bản thân nó là một DẢI cảnh
 * báo chứ không phải một widget trong danh sách này — xem docblock `SystemHealthWidget`).
 *
 * "Liên hệ chưa ai gọi lại" ra đời ở M10 Task 5 (`UnansweredIntakesWidget`, SPEC §7.1 đính chính
 * 2026-09-24 của M10): đặt NGAY DƯỚI mục 3. Giữa `-2` (mục 3) và `-1` (mục 4) không còn số nguyên
 * nào, nên đánh số lại lần nữa: widget mới `-1`, mục 4 `-1` → `0`, mục 5 `0` → `1`, mục 6 `1` → `2`.
 * Thứ tự tương đối của các mục cũ không đổi — test này đo đúng điều đó.
 */
it('orders the dashboard widgets the way SPEC 7.1 numbers them', function () {
    $sorts = [
        StaleMattersWidget::class => StaleMattersWidget::getSort(),
        UpcomingDeadlinesWidget::class => UpcomingDeadlinesWidget::getSort(),
        PendingChecklistReviewsWidget::class => PendingChecklistReviewsWidget::getSort(),
        UnansweredIntakesWidget::class => UnansweredIntakesWidget::getSort(),
        MattersMissingDocumentsWidget::class => MattersMissingDocumentsWidget::getSort(),
        UnseenUpdatesWidget::class => UnseenUpdatesWidget::getSort(),
        MattersByStageWidget::class => MattersByStageWidget::getSort(),
    ];

    expect(array_values($sorts))->toBe(array_values(array_unique($sorts)))
        ->and(array_keys($sorts))->toBe(array_keys(collect($sorts)->sort()->all()));
});

/**
 * SPEC §7.1: mục 1 "đặt trên cùng". `Filament\Widgets\AccountWidget` do AdminPanelProvider đăng
 * ký có `$sort = -3`, nên "trên cùng" chỉ đúng khi số của mục 1 nhỏ hơn số đó.
 */
it('puts the most important widget above the account card Filament registers', function () {
    expect(StaleMattersWidget::getSort())->toBeLessThan(AccountWidget::getSort());
});
