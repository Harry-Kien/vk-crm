<?php

use App\Enums\DocumentStoreStatus;
use App\Enums\Role;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\SystemHealth;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M14 Task 5 — dòng kho tài liệu trên dải sức khoẻ hệ thống (kế hoạch R9, R13)
|--------------------------------------------------------------------------
|
| Một dòng đỏ khi trạng thái kho khác `ok`, chỉ cho người có `settings.manage`; dòng lịch chạy tự
| động sẵn có giữ nguyên cho mọi nhân sự. Đo qua Livewire (widget render thật).
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    // Lịch chạy bình thường: dải lịch im lặng, nên mọi thứ dải hiện ra là của kho.
    SystemHealth::current()->forceFill(['last_schedule_run_at' => now()->subMinute()])->save();
});

function t5StoreStatus(?DocumentStoreStatus $status, ?string $detail = null): void
{
    SystemHealth::current()->forceFill([
        'document_store_status' => $status,
        'document_store_checked_at' => now()->subMinutes(5),
        'document_store_detail' => $detail,
    ])->save();
}

it('admin thấy dòng đỏ khi kho không ở trạng thái ok', function (DocumentStoreStatus $status) {
    t5StoreStatus($status, 'Thành viên lạ trên Shared Drive.');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    Livewire::test(SystemHealthWidget::class)
        ->assertSee(__('document_store.widget.heading', ['status' => $status->label()]))
        ->assertSee('Thành viên lạ trên Shared Drive.')
        ->assertSeeHtml('data-widget="document-store-health"');
})->with([DocumentStoreStatus::Degraded, DocumentStoreStatus::Unavailable, DocumentStoreStatus::Misconfigured]);

it('người không có settings.manage không thấy dòng kho, kể cả khi kho sập', function (Role $role) {
    t5StoreStatus(DocumentStoreStatus::Misconfigured, 'Thành viên lạ trên Shared Drive.');

    // Dải lịch hiện (chưa từng chạy) để widget có nội dung: dòng lịch giữ nguyên cho mọi nhân sự.
    SystemHealth::current()->forceFill(['last_schedule_run_at' => null])->save();

    $this->actingAs(User::factory()->withRole($role)->create(), 'web');

    Livewire::test(SystemHealthWidget::class)
        ->assertSee(__('widgets.system_health.never_ran'))
        ->assertDontSee('Thành viên lạ trên Shared Drive.')
        ->assertDontSeeHtml('data-widget="document-store-health"');
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('trạng thái ok hoặc chưa kiểm lần nào: không dòng kho', function (?DocumentStoreStatus $status) {
    t5StoreStatus($status, 'Không nên hiện.');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    expect((new SystemHealthWidget)->getViewData()['documentStore'])->toBeNull();
})->with(['ok' => DocumentStoreStatus::Ok, 'chưa kiểm' => null]);

it('dòng kho dùng biến màu Filament đã đăng ký và không có class viết tay', function () {
    t5StoreStatus(DocumentStoreStatus::Unavailable, 'Kho tạm thời không truy cập được.');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $markup = view('filament.admin.widgets.system-health', (new SystemHealthWidget)->getViewData())->render();

    expect($markup)->toContain('data-widget="document-store-health"')
        ->and(unregisteredColourVariables($markup))->toBe([])
        ->and($markup)->not->toContain('class=');
});

it('cả hai dải cùng hiện vẫn nằm trong MỘT phần tử gốc (Livewire cần một gốc)', function () {
    t5StoreStatus(DocumentStoreStatus::Misconfigured);
    SystemHealth::current()->forceFill(['last_schedule_run_at' => null])->save();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $markup = trim(view('filament.admin.widgets.system-health', (new SystemHealthWidget)->getViewData())->render());

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>'.$markup.'</body>');

    $roots = 0;
    foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) {
        $roots += $node->nodeType === XML_ELEMENT_NODE ? 1 : 0;
    }

    expect($roots)->toBe(1)
        ->and($markup)->toContain('data-widget="system-health"')
        ->and($markup)->toContain('data-widget="document-store-health"');
});
