<?php

use App\Support\SensitivePropertyFilter;

/**
 * Task 20 — Controller decision: "ActivityLogPage properties viewer. Nó KHÔNG BAO GIỜ được hiện
 * `id_number` hay bất kỳ định danh cá nhân thô nào; hash thì được, miễn có nhãn. Lọc các khoá
 * nhạy cảm ĐỆ QUY, kể cả trong mảng lồng nhau, có test."
 *
 * `properties` của một dòng Activity là `Spatie\Activitylog\ActivityLogStatus`/collection lồng
 * nhau tuỳ ý (xem `ConflictCheckResult::toArray()` — `matches` là một mảng các mảng, mỗi phần tử
 * lại mang các khoá của chính nó), nên phép lọc phải đệ quy hết mọi tầng, không chỉ tầng ngoài
 * cùng.
 */
it('redacts a top-level id_number key', function () {
    $filtered = SensitivePropertyFilter::filter(['id_number' => '079012345678', 'name' => 'Nguyễn Văn A']);

    expect($filtered['id_number'])->toBe(__('activity.page.properties.redacted'))
        ->and($filtered['name'])->toBe('Nguyễn Văn A');
});

it('redacts id_number nested arbitrarily deep inside nested arrays', function () {
    $filtered = SensitivePropertyFilter::filter([
        'matches' => [
            [
                'party_name' => 'Trần Thị B',
                'found_party' => [
                    'id_number' => '079099999999',
                ],
            ],
        ],
    ]);

    expect($filtered['matches'][0]['found_party']['id_number'])->toBe(__('activity.page.properties.redacted'))
        ->and($filtered['matches'][0]['party_name'])->toBe('Trần Thị B');
});

/** Vế dương của "hash thì được, miễn có nhãn": id_number_hash không phải id_number thô. */
it('does not redact a labelled hash of the id number', function () {
    $filtered = SensitivePropertyFilter::filter(['id_number_hash' => 'sha256:abc123']);

    expect($filtered['id_number_hash'])->toBe('sha256:abc123');
});

it('leaves ordinary keys and scalar values untouched', function () {
    $filtered = SensitivePropertyFilter::filter(['guard' => 'web', 'ip' => '127.0.0.1', 'count' => 3]);

    expect($filtered)->toBe(['guard' => 'web', 'ip' => '127.0.0.1', 'count' => 3]);
});
