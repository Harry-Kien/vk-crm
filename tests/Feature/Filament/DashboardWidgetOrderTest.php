<?php

use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
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
 * Các mục 2, 5 và 7 của SPEC §7.1 chưa tồn tại (cần mốc thời hạn, `stage_log_views` và heartbeat
 * — M6); test so sánh theo THỨ TỰ TƯƠNG ĐỐI nên nó không phải sửa khi chúng được thêm vào.
 */
it('orders the dashboard widgets the way SPEC 7.1 numbers them', function () {
    $sorts = [
        StaleMattersWidget::class => StaleMattersWidget::getSort(),
        PendingChecklistReviewsWidget::class => PendingChecklistReviewsWidget::getSort(),
        MattersMissingDocumentsWidget::class => MattersMissingDocumentsWidget::getSort(),
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
