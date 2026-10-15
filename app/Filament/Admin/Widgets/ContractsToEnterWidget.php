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
 * (`contract.enter`: kế toán, quản trị viên): "Hồ sơ chưa có hợp đồng: n" và "Hợp đồng còn nháp: m",
 * mỗi ô là một liên kết tới trang {@see ContractEntry} với đúng bộ lọc trạng thái đó — để không ai còn
 * hỏi "thêm số tiền của hồ sơ ở đâu". Không đổi số liệu nào của các biểu đồ khác.
 *
 * **Đếm trên đúng tập dòng của trang**: `Matter::listableBy()` (vụ chưa xoá mềm; kế toán không có vụ
 * `restricted`), "chưa có hợp đồng" = chưa từng có hợp đồng nào, "còn nháp" = hợp đồng hiện hành còn
 * `draft` — cùng định nghĩa với bộ lọc của trang. Chỉ ĐẾM: không mã, không tên.
 *
 * **Ai thấy:** `contract.enter` VÀ mở được trang ({@see ContractEntry::canBeOpenedBy()}). Luật sư, trưởng
 * phòng (chỉ `contract.manage`) soạn hợp đồng ở tab tiền của vụ, trang chủ của họ giữ nguyên. `boot()`
 * trả 404 cho người khác, kể cả khi widget được mount thẳng (SPEC §10.10).
 *
 * Thứ tự 3: một số riêng (không trùng widget nào của trang chủ), sau các widget đang có.
 */
class ContractsToEnterWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

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

        $withoutContract = $listable()->whereDoesntHave('contracts')->count();
        $drafts = $listable()->whereHas('contract', fn (Builder $contract) => $contract->where('status', ContractStatus::Draft->value))->count();

        return [
            Stat::make(__('contract_entry.widget.without_contract'), (string) $withoutContract)
                ->color($withoutContract > 0 ? 'warning' : 'gray')
                ->url(ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_NONE]]]])),
            Stat::make(__('contract_entry.widget.drafts'), (string) $drafts)
                ->color($drafts > 0 ? 'info' : 'gray')
                ->url(ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractStatus::Draft->value]]]])),
        ];
    }
}
