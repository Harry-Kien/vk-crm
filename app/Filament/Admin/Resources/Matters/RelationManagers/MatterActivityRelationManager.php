<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Filament\Admin\Pages\ActivityLogPage;
use App\Models\Matter;
use App\Support\ActivityOwningMatter;
use App\Support\ActivityReasonLabel;
use App\Support\SensitivePropertyFilter;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Nhật ký" của riêng một vụ việc (SPEC §7.2, M7 Task 8) — activity log lọc từ trang Nhật ký
 * hệ thống của M3 ({@see ActivityLogPage}). Chỉ đọc.
 *
 * # Ai thấy
 *
 * Trang hệ thống đòi `auditLog.view` (admin, trưởng phòng). Tab này mở thêm cho luật sư phụ trách
 * CỦA VỤ NÀY — `MatterPolicy::viewActivityLog`. Không trợ lý, không cộng sự, không kế toán. Cổng
 * hỏi ở BA chỗ: `canViewForRecord()` ẩn tab; `booted()` (lần mount) và `hydrate()` (mọi request
 * cập nhật Livewire, trước `hydrateCanAuthorizeAccess()` của Filament — thứ trả 403) trả **404**;
 * và modal "Xem chi tiết" hỏi lại lúc dựng nội dung.
 *
 * # Dòng nào
 *
 * Đúng luật của {@see ActivityOwningMatter} qua `scopeOwnedBy()` — chủ thể là vụ này, hoặc model
 * con của vụ này, hoặc `properties.matter_id` là vụ này — không có định nghĩa thứ hai; dòng TIỀN
 * (M9) chỉ với người có `billing.view`, cùng cổng của trang hệ thống (gộp M7 vào `main`). Bảng thay
 * truy vấn quan hệ `activities` (chỉ "chủ thể là vụ này") bằng truy vấn đó; Filament cũng phân giải
 * bản ghi của nút "Xem chi tiết" trên chính truy vấn ấy, nên không mở được dòng của vụ khác.
 *
 * Giá trị trong modal đi qua {@see SensitivePropertyFilter} như trang hệ thống. Một dòng
 * `conflict_check_run` mang mã các hồ sơ trùng — đúng ranh giới lộ thông tin mà SPEC §6.10 cho
 * phép người chạy kiểm tra xung đột thấy (`ConflictMatch`), không hơn.
 */
class MatterActivityRelationManager extends RelationManager
{
    /**
     * Quan hệ của `LogsActivity` trên `Matter`. Chỉ để Filament có một quan hệ hợp lệ; truy vấn
     * thật của bảng là `->query()` trong {@see self::table()}.
     */
    protected static string $relationship = 'activities';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('communications.activity_tab.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewActivityLog', $ownerRecord);
    }

    /**
     * Request cập nhật Livewire: `hydrate()` của component chạy TRƯỚC hook `hydrate` của các trait,
     * tức trước `CanAuthorizeAccess::hydrateCanAuthorizeAccess()` của Filament (thứ trả 403) —
     * nên câu trả lời là 404 (SPEC §10.10). Thuộc tính `ownerRecord` đã được nạp lại lúc này.
     */
    public function hydrate(): void
    {
        $this->abortUnlessAllowed();
    }

    /**
     * Lần mount đầu: `booted()` chạy sau `mount()`, khi `ownerRecord` đã có — và `mount()` của
     * `RelationManager` không tự hỏi `canViewForRecord()` (Filament chỉ hỏi ở hook `hydrate`).
     * `booted()` cũng chạy ở mọi request cập nhật, nhưng SAU `hydrate()` ở trên, nên ở đó nó chỉ
     * hỏi lại cùng câu.
     */
    public function booted(): void
    {
        $this->abortUnlessAllowed();
    }

    private function abortUnlessAllowed(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 404);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                /** @var Matter $matter */
                $matter = $this->getOwnerRecord();

                $query = Activity::query()->with(['causer']);

                // Người xem: dòng TIỀN (M9) chỉ hiện khi có `billing.view` (gộp M7 vào `main`).
                ActivityOwningMatter::scopeOwnedBy($query, $matter, Auth::user());

                return $query;
            })
            ->emptyStateHeading(__('communications.activity_tab.empty_state'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity.page.columns.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('event')
                    ->label(__('activity.page.columns.event'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null && Lang::has('activity.events.'.$state)
                        ? __('activity.events.'.$state)
                        : ($state ?? '—')),
                TextColumn::make('causer.name')
                    ->label(__('activity.page.columns.causer'))
                    ->default(__('activity.page.system_causer')),
            ])
            ->recordActions([
                Action::make('viewProperties')
                    ->label(__('activity.page.actions.view_properties'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modal()
                    ->modalHeading(__('activity.page.properties.modal_heading'))
                    ->authorize(fn (): bool => static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()))
                    ->modalContent(function (Activity $record) {
                        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 404);

                        // M13 Task 3: cùng nhãn lý do với trang Nhật ký hệ thống (`ActivityReasonLabel`).
                        return view('filament.admin.pages.activity-log-properties', [
                            'properties' => ActivityReasonLabel::apply(
                                $record->event,
                                SensitivePropertyFilter::filter($record->properties?->toArray() ?? []),
                            ),
                        ]);
                    })
                    ->modalSubmitAction(false),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
