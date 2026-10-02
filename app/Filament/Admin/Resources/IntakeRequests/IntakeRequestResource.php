<?php

namespace App\Filament\Admin\Resources\IntakeRequests;

use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Filament\Admin\Resources\IntakeRequests\Schemas\IntakeRequestForm;
use App\Filament\Admin\Resources\IntakeRequests\Tables\IntakeRequestsTable;
use App\Models\IntakeRequest;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Màn hình tiếp nhận (M10 Task 3): mỗi lần có người liên hệ văn phòng — gọi điện, nhắn Zalo, bước vào
 * văn phòng — là một bản ghi, và kiểm tra xung đột lợi ích chạy ở lần chạm đầu (R1).
 *
 * **Một luật "thấy được" cho danh sách VÀ cho URL bản ghi:** `IntakeRequest::scopeVisibleTo()` (R9 —
 * `intake.viewAny` thấy mọi bản ghi; người chỉ có `intake.create` thấy bản ghi mình ghi hoặc được
 * giao; kế toán không thấy gì; bản đã chuyển thành vụ `restricted` chỉ thấy với người xem được vụ đó).
 * `getEloquentQuery()` lọc danh sách, `getRecordRouteBindingEloquentQuery()` lọc việc resolve bản ghi
 * từ URL — nên vào thẳng URL một bản ghi không thấy được ra 404 ngay ở bước resolve (SPEC §10.10),
 * không phụ thuộc vào policy. Cả trang của kế toán bị từ chối bởi `canAccess()` = policy `viewAny`
 * (403), và middleware bền `AnswerDeniedPanelRequestsWithNotFound` đổi thành 404; mục điều hướng ẩn.
 *
 * **Không trang xem riêng, không xoá.** Trang sửa là trang làm việc của một bản ghi (danh tính, kiểm
 * tra, câu chuyện, các hành động); bản ghi đã xong việc hiện ở dạng chỉ đọc trên chính trang đó. Xoá
 * là ẩn danh (R7, Task 7) — không nút xoá, xoá hàng loạt hay khôi phục nào (policy cũng luôn từ chối).
 * Không `recordTitleAttribute`: tìm kiếm toàn cục không được lộ tên người liên hệ.
 */
class IntakeRequestResource extends Resource
{
    protected static ?string $model = IntakeRequest::class;

    // Mỗi resource một hình riêng — xem NavigationIconsTest. Ống nghe có mũi tên vào: một cuộc gọi đến.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowDownLeft;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('intake.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('intake.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return IntakeRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IntakeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntakeRequests::route('/'),
            'create' => CreateIntakeRequest::route('/create'),
            'edit' => EditIntakeRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::visibleOnly(parent::getEloquentQuery())->with(['assignee']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::visibleOnly(parent::getRecordRouteBindingEloquentQuery());
    }

    private static function visibleOnly(Builder $query): Builder
    {
        $user = Auth::user();

        return $user instanceof User ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }
}
