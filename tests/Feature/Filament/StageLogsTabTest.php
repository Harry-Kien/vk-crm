<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * `e2e/F1` (docs/audits/2026-09-24-quy-trinh.md, critical): `renderPublicContent()` trả `null`
 * cho một dòng StageLog CHƯA công bố (`is_published = false`) nhưng CÓ `public_content` — điều
 * xảy ra ở đúng đường mặc định, không phải một trường hợp biên: `BuildsStageUpdateSchema::
 * publicContentField()` tự điền sẵn mẫu giai đoạn (`matter_type_stages.client_description`) vào
 * ô công bố cho CẢ hai nút "Chuyển giai đoạn" và "Thêm cập nhật", bất kể công tắc Công bố có bật
 * hay không; và `publishToggleField()` khoá công tắc đó ở `false` khi vụ việc chưa bật cổng
 * khách (`is_published_to_portal = false` — mặc định của `OpenMatter`). Cột `public_content`
 * khai `->html()` (StageLogsRelationManager.php), nên khi Filament định dạng state xong mà kết
 * quả không phải `Htmlable`, nó gọi `Illuminate\Support\Str::sanitizeHtml(string $html)` — macro
 * này khai kiểu `string` cứng, nên `null` gây `TypeError`, Filament gói lại thành
 * `Illuminate\View\ViewException`. Lần cập nhật ĐẦU TIÊN trên MỌI vụ vừa mở bằng `OpenMatter`
 * (portal luôn tắt lúc mở) đi đúng đường này, và `StageLog` chặn cả sửa lẫn xoá nên tab không tự
 * hết được (xem `StageLog.php`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('renders the progress tab again after an unpublished update carries non-empty public_content', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->unpublished()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    // Đúng đường người dùng: mở modal "Thêm cập nhật" qua callTableAction() và GIỮ NGUYÊN nội
    // dung công bố tự điền sẵn (public_content) và công tắc Công bố bị khoá ở false — chỉ gõ
    // thêm ghi chú nội bộ, như một luật sư thật sẽ làm.
    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('addUpdate', data: [
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Đã gọi khách, hẹn tuần sau.',
    ])->assertHasNoTableActionErrors();

    $log = StageLog::query()->where('matter_id', $matter->id)->latest('id')->first();

    // Vế xác nhận đúng điều kiện kích hoạt của e2e/F1: dòng đã lưu, chưa công bố, và
    // public_content khác rỗng (mẫu giai đoạn tự điền, không phải người gõ).
    expect($log)->not->toBeNull()
        ->and($log->is_published)->toBeFalse()
        ->and($log->public_content)->not->toBeEmpty();

    // Render LẠI tab bằng một instance Livewire MỚI, như luật sư mở lại trang chi tiết vụ việc.
    // Đây là bước gãy thật của e2e/F1: lỗi không chỉ ở request lưu, mà ở MỌI lần tab được vẽ ra
    // sau đó — "Từ đó tab Tiến độ không render được nữa" (audit).
    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertOk()
        ->assertSee('Đã gọi khách, hẹn tuần sau.');
});

/**
 * Task 19, vòng sửa 1 (Important — I1): xoá mềm một giai đoạn mà chỉ LỊCH SỬ (`stage_logs`) còn
 * dùng là hành vi ĐƯỢC PHÉP (`MatterTypeStagePolicy::delete()` chỉ chặn hồ sơ ĐANG đứng và
 * `allowed_next`, không chặn lịch sử) — nhưng trước bản vá này, cột "Giai đoạn" của tab Tiến độ
 * rơi về `?? $record->to_stage` (khoá kỹ thuật thô, không phải câu tiếng Việt) cho đúng dòng lịch
 * sử đó, vì `MatterType::stage($key)` chỉ đọc các giai đoạn CÒN SỐNG.
 */
it('shows the Vietnamese label of a soft-deleted stage on the admin timeline, not its raw key', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->for($type)->atStage('collecting_documents')->create(['lead_lawyer_id' => $lawyer->id]);

    // Cột được vẽ ra là `to_stage` (formatStateUsing đọc $record->to_stage) — giai đoạn bị xoá
    // mềm PHẢI là đích của dòng chuyển (`to_stage`), không phải điểm xuất phát (`from_stage`).
    StageLog::factory()->for($matter)->transition('collecting_documents', 'drafting')->create();

    $draftingLabel = $type->stage('drafting')->label;
    $type->stage('drafting')->delete();

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter->fresh(),
        'pageClass' => ViewMatter::class,
    ])
        ->assertOk()
        ->assertSee($draftingLabel);
});
