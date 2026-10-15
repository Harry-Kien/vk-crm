<?php

namespace App\Filament\Admin\Widgets\Tasks;

use App\Filament\Admin\Concerns\ProvidesSeatActions;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Khối "Hồ sơ chờ tôi nhận" của "Việc của tôi" (làn tk, Task 2) — trang của chính người đang đăng nhập
 * ({@see TeamMember::getHeaderWidgets()}), đứng ngay sau khối "Chờ tôi nhận" của việc (Task 1): mỗi dòng
 * là một ghế trong đội ngũ một vụ mà người xem vừa được giao (thêm vào đội ngũ, hay nhận bàn giao làm
 * luật sư phụ trách), chưa bấm "Đã nhận hồ sơ" hay "Trả lại". Hai nút ngay trên dòng
 * ({@see ProvidesSeatActions}).
 *
 * # Không tin trang chứa nó
 *
 * Component Livewire riêng: đọc người dùng từ phiên ở MỖI request, không nhận id người nào từ trang. Truy
 * vấn luôn là ghế của CHÍNH người đó, trên vụ đang mở họ còn xem được
 * (`MatterUser::scopeOnOpenMattersListableBy()` — vụ đã kết thúc, đã huỷ, hay vụ hạn chế họ không còn mở
 * được, không hiện).
 *
 * Chỉ hiện khi có ghế đang chờ ({@see self::canView()}) — người không có hồ sơ nào chờ nhận không thấy
 * thêm một khối trống. Không tải lười, không tự hiện trên bảng điều khiển.
 */
class MatterAssignmentsAwaitingMeWidget extends TableWidget
{
    use ProvidesSeatActions;
    use ReportsActionFailures;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', Matter::class)
            && self::seats($user)->exists();
    }

    /** Ghế đang chờ `$user` nhận, trên vụ họ còn xem được — MỘT định nghĩa cho khối và cho `canView()`. */
    public static function seats(User $user): Builder
    {
        return MatterUser::query()
            ->where('matter_user.user_id', $user->getKey())
            ->awaitingAcceptance()
            ->onOpenMattersListableBy($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('tasks.seat.my.heading'))
            ->description(__('tasks.seat.my.description'))
            ->query(function (): Builder {
                /** @var User $user */
                $user = Auth::user();

                return self::seats($user)
                    ->with(['matter', 'assigner'])
                    ->orderBy('matter_user.assigned_at')
                    ->orderBy('matter_user.id');
            })
            ->columns([
                TextColumn::make('matter.code')
                    ->label(__('tasks.seat.columns.matter'))
                    ->weight('bold')
                    ->url(fn (MatterUser $record): string => MatterResource::getUrl('view', ['record' => $record->matter_id], panel: 'admin')),
                TextColumn::make('matter.title')
                    ->label(__('tasks.seat.columns.title'))
                    ->wrap(),
                TextColumn::make('role_in_matter')
                    ->label(__('tasks.seat.columns.role'))
                    ->badge()
                    ->formatStateUsing(fn (MatterUser $record): string => $record->role_in_matter->label()),
                TextColumn::make('assigner.name')
                    ->label(__('tasks.seat.columns.assigner'))
                    ->placeholder('—'),
                TextColumn::make('assigned_at')
                    ->label(__('tasks.seat.columns.assigned_at'))
                    ->dateTime('H:i d/m/Y'),
            ])
            ->recordActions([
                $this->acceptSeatAction(fn (MatterUser $record): MatterUser => $record, fn (MatterUser $record): Matter => $record->matter),
                $this->declineSeatAction(fn (MatterUser $record): MatterUser => $record, fn (MatterUser $record): Matter => $record->matter),
            ])
            ->emptyStateHeading(__('tasks.seat.my.empty'))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
