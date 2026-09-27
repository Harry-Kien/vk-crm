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

/**
 * Fix round 1 (I1, ruling): "the activity viewer must not show raw contact data" — trước bản sửa
 * này, `SensitivePropertyFilter` chỉ chặn `id_number` và các bí mật, còn một lần sửa khách hàng
 * ghi THÔ `phone`/`email`/`address` vào `properties.attributes`/`properties.old` (xem
 * `Client::getActivitylogOptions()` — `logOnly(['type', 'name', 'phone', 'email', 'address',
 * 'representative_name'])`).
 */
it('masks a phone number, keeping the first 2 and last 3 digits', function () {
    $filtered = SensitivePropertyFilter::filter(['phone' => '0912345678']);

    expect($filtered['phone'])->toBe('09•••678');
});

/** Định dạng có dấu cách/ngoặc/dấu cộng thật (SPEC "dữ liệu người thật gõ") vẫn chỉ giữ CHỮ SỐ. */
it('masks a phone number written with real-world punctuation, counting digits only', function () {
    $filtered = SensitivePropertyFilter::filter(['phone' => '(+84) 912 345 678']);

    expect($filtered['phone'])->toBe('84•••678');
});

it('fully masks a phone number too short to safely reveal both ends', function () {
    $filtered = SensitivePropertyFilter::filter(['phone' => '123']);

    expect($filtered['phone'])->toBe('•••');
});

it('masks an email, keeping the first letter and the domain', function () {
    $filtered = SensitivePropertyFilter::filter(['email' => 'nam@luatvukhang.com']);

    expect($filtered['email'])->toBe('n•••@luatvukhang.com');
});

it('masks an address, keeping the first word followed by an ellipsis', function () {
    $filtered = SensitivePropertyFilter::filter(['address' => '123 Đường Láng, Đống Đa, Hà Nội']);

    expect($filtered['address'])->toBe('123…');
});

/** Đúng phát hiện: "Client edits log raw phone, email and address into properties.attributes / .old". */
it('masks phone, email and address recursively inside attributes/old diff structures', function () {
    $filtered = SensitivePropertyFilter::filter([
        'attributes' => ['phone' => '0912345678', 'email' => 'nam@luatvukhang.com', 'address' => 'Số 1 Bến Nghé'],
        'old' => ['phone' => '0987654321', 'email' => 'cu@luatvukhang.com', 'address' => 'Số 2 Bến Nghé'],
    ]);

    expect($filtered['attributes']['phone'])->toBe('09•••678')
        ->and($filtered['attributes']['email'])->toBe('n•••@luatvukhang.com')
        ->and($filtered['attributes']['address'])->toBe('Số…')
        ->and($filtered['old']['phone'])->toBe('09•••321')
        ->and($filtered['old']['email'])->toBe('c•••@luatvukhang.com')
        ->and($filtered['old']['address'])->toBe('Số…');
});

/** Ruling: "Key matching is case-insensitive". */
it('blocks and masks keys regardless of case', function () {
    $filtered = SensitivePropertyFilter::filter([
        'ID_NUMBER' => '079012345678',
        'Phone' => '0912345678',
        'EMAIL' => 'nam@luatvukhang.com',
        'Address' => '123 Đường Láng',
    ]);

    expect($filtered['ID_NUMBER'])->toBe(__('activity.page.properties.redacted'))
        ->and($filtered['Phone'])->toBe('09•••678')
        ->and($filtered['EMAIL'])->toBe('n•••@luatvukhang.com')
        ->and($filtered['Address'])->toBe('123…');
});

/** Vế dương giữ nguyên qua case khác: id_number_hash viết hoa vẫn không bị chặn. */
it('does not redact a differently-cased labelled hash of the id number', function () {
    $filtered = SensitivePropertyFilter::filter(['ID_NUMBER_HASH' => 'sha256:abc123']);

    expect($filtered['ID_NUMBER_HASH'])->toBe('sha256:abc123');
});
