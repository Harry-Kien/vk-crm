<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ContractStatus;
use App\Enums\Permission;
use App\Filament\Admin\Pages\ContractEntry;
use App\Models\Matter;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Hợp đồng cần nhập" (làn kt, 2026-10-15) — hai ô số trên trang chủ của người NHẬP hợp đồng
 * (`contract.enter`: kế toán, quản trị viên): "Hồ sơ cần nhập hợp đồng: n" và "Hợp đồng còn nháp: m",
 * mỗi ô là một liên kết tới trang {@see ContractEntry} với đúng bộ lọc trạng thái đó — để không ai còn
 * hỏi "thêm số tiền của hồ sơ ở đâu". Không đổi số liệu nào của các biểu đồ khác.
 *
 * **Đếm trên đúng tập dòng của trang**: `Matter::listableBy()` (vụ chưa xoá mềm; kế toán không có vụ
 * `restricted`). "Cần nhập hợp đồng" = {@see ContractEntry::whereNeedsContract()} — CHÍNH hàm của bộ lọc
 * mặc định của trang (làn fa3, 2026-10-16, mục 4: hồ sơ đang mở chưa có hợp đồng nào hoặc hợp đồng hiện
 * hành đã huỷ; trước bản sửa ô này đếm "chưa từng có hợp đồng", kể cả hồ sơ đã kết thúc, và bỏ hồ sơ có
 * hợp đồng đã huỷ). "Còn nháp" = hợp đồng hiện hành còn `draft`. Chỉ ĐẾM: không mã, không tên.
 *
 * **Ai thấy:** `contract.enter` VÀ mở được trang ({@see ContractEntry::canBeOpenedBy()}). Luật sư, trưởng
 * phòng (chỉ `contract.manage`) soạn hợp đồng ở tab tiền của vụ, trang chủ của họ giữ nguyên. `boot()`
 * trả 404 cho người khác, kể cả khi widget được mount thẳng (SPEC §10.10).
 *
 * Thứ tự 4: một số riêng (không trùng widget nào của trang chủ), sau các widget đang có — gộp với làn tk
 * (integ-4): "Việc cần xử lý của tôi" giữ 2, biểu đồ giai đoạn dời 3, nên ô này đứng cuối ở 4.
 */
class ContractsToEnterWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows(Permission::ContractEnter->value)
            && ContractEntry::canBeOpenedBy($user);
    }

    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    protected function getHeading(): ?string
    {
        return __('contract_entry.widget.heading');
    }

    protected function getDescription(): ?string
    {
        return __('contract_entry.widget.hint');
    }

    protected function getStats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $listable = fn (): Builder => Matter::query()->listableBy($user);

        $toEnter = ContractEntry::whereNeedsContract($listable())->count();
        $drafts = $listable()->whereHas('contract', fn (Builder $contract) => $contract->where('status', ContractStatus::Draft->value))->count();

        return [
            Stat::make(__('contract_entry.widget.to_enter'), (string) $toEnter)
                ->description(__('contract_entry.widget.to_enter_hint'))
                ->color($toEnter > 0 ? 'warning' : 'gray')
                ->url(ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_TO_ENTER]]]])),
            Stat::make(__('contract_entry.widget.drafts'), (string) $drafts)
                ->color($drafts > 0 ? 'info' : 'gray')
                ->url(ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractStatus::Draft->value]]]])),
        ];
    }
}
