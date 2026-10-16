<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Widgets\ContractsToEnterWidget;
use App\Filament\Admin\Widgets\MattersByStageWidget;

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

    // Vòng sửa 1 (I1): hợp đồng hiện hành đã huỷ — hộp thoại nhập hiện số đã thu, chỉ nhập phần còn phải thu.
    expect($section)->toContain('Hồ sơ có hợp đồng **đã huỷ**')
        ->and($section)->toContain('hộp thoại hiện sẵn số đã thu trên hợp đồng đã huỷ — chỉ nhập **phần còn phải thu**');

    // Các nhãn mà tài liệu dẫn là đúng nhãn của màn hình.
    expect(__('contract_entry.navigation_label'))->toBe('Hợp đồng')
        ->and(__('contract_entry.actions.enter'))->toBe('Nhập hợp đồng')
        ->and(__('contract_entry.actions.save_and_activate'))->toBe('Khách đã ký — kích hoạt luôn')
        ->and(__('contract_entry.actions.update_draft'))->toBe('Sửa bản nháp')
        ->and(__('contract_entry.actions.activate'))->toBe('Kích hoạt (đã ký)')
        ->and(__('contract_entry.actions.delete_draft'))->toBe('Xoá bản nháp')
        ->and(__('contract_entry.actions.record_payment'))->toBe('Ghi khoản thu');
});

/**
 * Rà soát cuối F1: bước 3 của QUY-TRINH gọi đúng nút gửi của hộp thoại — "Lưu và kích hoạt" khi công tắc
 * bật, "Lưu nháp" khi tắt — và nói đúng ai không thấy vụ hạn chế (kế toán), không nói "vụ hạn chế không có
 * ở đây" cho mọi người.
 */
it('names the real submit buttons of the entry dialog in QUY-TRINH step 3', function () {
    $guide = ktDoc('docs/QUY-TRINH.md');
    $from = strpos($guide, '### Kế toán nhập hợp đồng');
    $to = strpos($guide, '### Kế toán ghi tiền');
    $section = preg_replace('/\s+/u', ' ', substr($guide, $from, $to - $from));

    expect($section)->toContain('bấm **'.__('contract_entry.actions.submit_activate').'**')
        ->and($section)->toContain('bấm **'.__('contract_entry.actions.submit_draft').'**')
        ->and($section)->toContain('Kế toán không thấy vụ hạn chế')
        ->and($section)->not->toContain('Vụ hạn chế không có ở đây')
        ->and($section)->not->toContain('rồi lưu —');
});

/**
 * Rà soát cuối F2: SPEC §7.1 liệt kê widget trang chủ "theo thứ tự", mỗi làn thêm widget ghi một đính
 * chính có ngày. Ô "Hợp đồng cần nhập" có đính chính của làn kt nói ai thấy, đứng ở đâu, hai số đếm gì —
 * và các điều đó khớp mã.
 */
it('records the "Hợp đồng cần nhập" home tile in SPEC §7.1 with who sees it, where it stands and what it counts', function () {
    $spec = ktDoc('docs/SPEC.md');
    $from = strpos($spec, '### 7.1 Trang chủ');
    $to = strpos($spec, '### 7.2 Resource `Matter`');

    expect($from)->not->toBeFalse()->and($to)->toBeGreaterThan($from);

    $section = substr($spec, $from, $to - $from);
    $at = strpos($section, '**Đính chính 2026-10-15 (làn kt');

    expect($at)->not->toBeFalse();

    $entry = preg_replace('/\s+/u', ' ', substr($section, $at));

    foreach ([
        '**"'.__('contract_entry.widget.heading').'"**',
        '"'.__('contract_entry.widget.without_contract').'"',
        '"'.__('contract_entry.widget.drafts').'"',
        '`contract.enter`',
        'kế toán và quản trị viên',
        'Luật sư và trưởng phòng',
        '`Matter::listableBy`',
        'chưa từng có hợp đồng nào',
        'hợp đồng hiện hành còn nháp',
        'hợp đồng hiện hành đã huỷ',
        'chỉ đếm',
        'đứng cuối',
        '"Thống kê nhanh"',
    ] as $phrase) {
        expect($entry)->toContain($phrase);
    }

    // Khớp mã: ai giữ contract.enter, và thứ tự đứng sau mọi widget trang chủ khác.
    $holders = array_values(array_filter(Role::cases(), fn (Role $role): bool => in_array(Permission::ContractEnter, $role->permissions(), true)));
    $sort = fn (string $widget): ?int => (new ReflectionProperty($widget, 'sort'))->getValue();

    expect($holders)->toBe([Role::Admin, Role::Accountant])
        ->and($sort(ContractsToEnterWidget::class))->toBeGreaterThan($sort(MattersByStageWidget::class));

    foreach (glob(app_path('Filament/Admin/Widgets/*.php')) as $file) {
        $class = 'App\\Filament\\Admin\\Widgets\\'.basename($file, '.php');

        if ($class !== ContractsToEnterWidget::class && class_exists($class) && property_exists($class, 'sort') && $sort($class) !== null) {
            expect($sort($class))->toBeLessThan($sort(ContractsToEnterWidget::class), $class);
        }
    }
});

it('tells both upgrade runbooks to rerun the seeder for contract.enter', function () {
    $guide = preg_replace('/\s+/u', ' ', ktDoc('docs/CAI-DAT.md'));
    $readme = preg_replace('/\s+/u', ' ', ktDoc('README.md'));

    expect($guide)->toContain('bản "Kế toán nhập hợp đồng" (làn kt) thêm quyền `contract.enter` cho vai kế toán')
        ->and($readme)->toContain('thêm **quyền nhập hợp đồng** `contract.enter` cho vai kế toán');
});
