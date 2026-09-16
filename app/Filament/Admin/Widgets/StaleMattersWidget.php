<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §7.1 mục 1: "Hồ sơ quá hạn cập nhật" — widget quan trọng nhất, đặt trên cùng (getSort()).
 * Scoping dùng đúng Matter::scopeListableBy() như MatterResource, nên một luật sư chỉ thấy hồ sơ
 * quá hạn của các vụ việc họ được xem, không phải toàn bộ văn phòng.
 */
class StaleMattersWidget extends TableWidget
{
    // SPEC §7.1: "widget quan trọng nhất, đặt trên cùng". Filament\Widgets\AccountWidget (đăng
    // ký sẵn trong AdminPanelProvider) có $sort = -3, nên phải thấp hơn -3 mới thực sự đứng trên
    // cùng (review fix round 1, minor D).
    protected static ?int $sort = -4;

    /** Số ngày quá hạn theo SPEC §6.4 / §7.1. */
    private const STALE_AFTER_DAYS = 14;

    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::MatterView->value);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('widgets.stale_matters.heading'))
            ->description(__('widgets.stale_matters.description'))
            /**
             * SPEC §6.4: vụ việc chưa đóng, ĐÃ công bố portal (`is_published_to_portal = true`),
             * và mốc cập nhật cuối cũ hơn 14 ngày. Fix round 2 review, minor finding: bản trước
             * dùng `whereNotNull('last_client_update_at')`, nên một vụ CHƯA TỪNG cập nhật cho
             * khách — đúng trường hợp xấu nhất mà widget này tồn tại để phát hiện — không bao giờ
             * lọt vào được, và thiếu hẳn điều kiện `is_published_to_portal`.
             *
             * §6.4 không nói rõ đồng hồ tính từ đâu khi CHƯA từng có `last_client_update_at`.
             * Chọn `stage_entered_at` (vào giai đoạn hiện tại từ lúc nào) làm mốc thay thế: đây là
             * thời điểm SPEC §6.4/§7.1 đã dùng cho khái niệm "kẹt ở một chỗ bao lâu" (xem docblock
             * `TransitionMatterStage`), và với một vụ vừa mở, `stage_entered_at` khớp `opened_at`
             * (Matter::booted() gán cả hai cùng lúc) — tức "đã mở bao lâu mà chưa hề báo cho
             * khách" cũng là dữ liệu SLA cần thấy. `COALESCE` viết bằng whereRaw vì Eloquent không
             * có helper so sánh hai cột theo kiểu "cột nào có giá trị thì dùng cột đó".
             */
            ->query(fn (): Builder => Matter::query()
                ->listableBy(static::currentUser())
                ->whereNull('closed_at')
                ->where('is_published_to_portal', true)
                ->whereRaw('COALESCE(last_client_update_at, stage_entered_at) < ?', [now()->subDays(self::STALE_AFTER_DAYS)])
                ->with(['client', 'matterType.stages', 'leadLawyer']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('widgets.stale_matters.columns.code')),
                TextColumn::make('client.name')
                    ->label(__('widgets.stale_matters.columns.client')),
                TextColumn::make('title')
                    ->label(__('widgets.stale_matters.columns.title'))
                    ->limit(60),
                TextColumn::make('stage')
                    ->label(__('widgets.stale_matters.columns.stage'))
                    ->badge()
                    ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage),
                TextColumn::make('leadLawyer.name')
                    ->label(__('widgets.stale_matters.columns.lead_lawyer')),
                TextColumn::make('last_client_update_at')
                    ->label(__('widgets.stale_matters.columns.last_client_update_at'))
                    ->since()
                    ->color('danger')
                    ->sortable(),
            ])
            ->defaultSort('last_client_update_at')
            ->recordActions([
                Action::make('view')
                    ->label(__('matters.label'))
                    ->url(fn (Matter $record): string => MatterResource::getUrl('view', ['record' => $record], panel: 'admin')),
            ])
            ->paginated([5, 10, 25]);
    }

    /**
     * canView() đã chặn trước khi widget này render cho ai không có matter.view, nhưng
     * listableBy() đòi một User tường minh — phòng thủ một lần nữa ở đây thay vì tin ambient
     * Auth::user() chắc chắn đúng kiểu.
     */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
