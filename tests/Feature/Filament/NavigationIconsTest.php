<?php

use Filament\Facades\Filament;
use Filament\Resources\Resource;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/**
 * Thanh điều hướng bên trái là chỗ người dùng nhắm chuột theo HÌNH chứ không đọc chữ sau tuần đầu
 * tiên. Năm resource của M3 đều giữ nguyên `Heroicon::OutlinedRectangleStack` do
 * `make:filament-resource` sinh ra, nên năm mục trông y hệt nhau và cái biểu tượng không còn nói
 * gì — tức là nó chỉ tốn chỗ.
 *
 * Test phát biểu luật, không liệt kê icon nào thuộc về resource nào: mỗi resource một hình riêng.
 */
it('gives every admin resource its own navigation icon', function () {
    $icons = collect(Filament::getPanel('admin')->getResources())
        ->mapWithKeys(fn (string $resource): array => [
            $resource => ($resource::getNavigationIcon() instanceof BackedEnum)
                ? $resource::getNavigationIcon()->value
                : (string) $resource::getNavigationIcon(),
        ]);

    expect($icons)->not->toBeEmpty()
        ->and($icons->filter(fn (string $icon): bool => $icon === '')->all())->toBe([]);

    $duplicated = $icons->duplicates()->all();

    expect($duplicated)->toBe([]);
});

/** Cặp dương: luật trên chỉ có nghĩa nếu thật sự có nhiều hơn một resource để so sánh. */
it('has more than one resource registered in the admin panel', function () {
    expect(count(Filament::getPanel('admin')->getResources()))->toBeGreaterThan(1)
        ->and(Filament::getPanel('admin')->getResources())
        ->each->toBeIn(array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource): bool => is_subclass_of($resource, Resource::class),
        ));
});
