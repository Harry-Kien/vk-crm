<?php

use App\Enums\Permission;
use App\Enums\Role;

/**
 * Làn kt (2026-10-15) — tài liệu nguồn sự thật nói đúng quyền mới `contract.enter`: SPEC §5 (bổ sung có
 * ngày, bảng quyền đúng với `Role::permissions()`), QUY-TRINH (các bước "Kế toán nhập hợp đồng"), hai sổ
 * nâng cấp (chạy lại `db:seed --force` để vai kế toán nhận quyền).
 */
function ktDoc(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** Đoạn trích dẫn `>` của SPEC bắt đầu từ tiêu đề bổ sung làn kt, tới hết khối trích dẫn. */
function ktSpecAddition(): string
{
    $spec = ktDoc('docs/SPEC.md');
    $from = strpos($spec, '> **Bổ sung 2026-10-15 (làn kt — kế toán nhập hợp đồng).**');

    expect($from)->not->toBeFalse();

    $to = strpos($spec, "\n\n", $from);

    return substr($spec, $from, ($to === false ? strlen($spec) : $to) - $from);
}

it('adds contract.enter to SPEC §5 with a row that matches the roles holding it', function () {
    $addition = ktSpecAddition();

    preg_match('/^> \| `contract\.enter` [^|]*\|(.+)\|\s*$/mu', $addition, $row);
    expect($row)->toHaveCount(2);

    $cells = array_map('trim', explode('|', $row[1]));
    $columns = [Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant, Role::Sales];

    expect($cells)->toHaveCount(count($columns));

    foreach ($columns as $index => $role) {
        expect($cells[$index] === '✓')->toBe(in_array(Permission::ContractEnter, $role->permissions(), true), "cột {$role->value}");
    }
});

it('says in SPEC §5 what contract.enter covers and what stays on contract.manage', function () {
    $addition = preg_replace('/\s+/u', ' ', ktSpecAddition());

    foreach (['`ContractPolicy::create`', '`updateDraft`', '`delete`', '`activate`', '`ContractPolicy::update`', 'vẫn chỉ `contract.manage`', 'không** mở được nội dung hồ sơ', 'trang vụ việc vẫn 404', 'một dòng chuông', '`RolesAndPermissionsSeeder`'] as $phrase) {
        expect($addition)->toContain($phrase);
    }
});

it('walks the accountant through entering a contract in QUY-TRINH', function () {
    $guide = ktDoc('docs/QUY-TRINH.md');
    $from = strpos($guide, '### Kế toán nhập hợp đồng');
    $to = strpos($guide, '### Kế toán ghi tiền');

    expect($from)->not->toBeFalse()->and($to)->toBeGreaterThan($from);

    $section = preg_replace('/\s+/u', ' ', substr($guide, $from, $to - $from));

    foreach (['**Hợp đồng**', '**Nhập hợp đồng**', 'Khách đã ký — kích hoạt luôn', '**Sửa bản nháp**', '**Kích hoạt (đã ký)**', '**Xoá bản nháp**', '**Ghi khoản thu**', '`contract.enter`'] as $phrase) {
        expect($section)->toContain($phrase);
    }

    // Các nhãn mà tài liệu dẫn là đúng nhãn của màn hình.
    expect(__('contract_entry.navigation_label'))->toBe('Hợp đồng')
        ->and(__('contract_entry.actions.enter'))->toBe('Nhập hợp đồng')
        ->and(__('contract_entry.actions.save_and_activate'))->toBe('Khách đã ký — kích hoạt luôn')
        ->and(__('contract_entry.actions.update_draft'))->toBe('Sửa bản nháp')
        ->and(__('contract_entry.actions.activate'))->toBe('Kích hoạt (đã ký)')
        ->and(__('contract_entry.actions.delete_draft'))->toBe('Xoá bản nháp')
        ->and(__('contract_entry.actions.record_payment'))->toBe('Ghi khoản thu');
});

it('tells both upgrade runbooks to rerun the seeder for contract.enter', function () {
    $guide = preg_replace('/\s+/u', ' ', ktDoc('docs/CAI-DAT.md'));
    $readme = preg_replace('/\s+/u', ' ', ktDoc('README.md'));

    expect($guide)->toContain('bản "Kế toán nhập hợp đồng" (làn kt) thêm quyền `contract.enter` cho vai kế toán')
        ->and($readme)->toContain('thêm **quyền nhập hợp đồng** `contract.enter` cho vai kế toán');
});
