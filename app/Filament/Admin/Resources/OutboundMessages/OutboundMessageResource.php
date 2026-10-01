<?php

namespace App\Filament\Admin\Resources\OutboundMessages;

use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ViewOutboundMessage;
use App\Filament\Admin\Resources\OutboundMessages\Schemas\OutboundMessageInfolist;
use App\Filament\Admin\Resources\OutboundMessages\Tables\OutboundMessagesTable;
use App\Models\OutboundMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Nhật ký thư đi ra, CHỈ ĐỌC (SPEC §4.15, §7.4; M6.5 Task 13, findings `notify-8`/`spec-gap-07`).
 * Không có trang tạo/sửa/xoá. Nút "Gửi lại" trên dòng `failed` (M6 Task 10) không sửa dòng nào: nó
 * xếp hàng một lần gửi mới qua `App\Actions\Notification\ResendOutboundMessage`, chỉ admin bấm được
 * (`OutboundMessagePolicy::resend()`), và dòng hỏng được giữ nguyên làm bằng chứng.
 *
 * Cả hai truy vấn nền của resource này ({@see self::getEloquentQuery()} cho danh sách,
 * {@see self::getRecordRouteBindingEloquentQuery()} cho việc mở thẳng URL trang xem) đều áp
 * {@see OutboundMessage::scopeVisibleTo()} — đúng thành ngữ `MatterResource` đã dùng cho
 * `listableBy()`: thiếu một trong hai chỗ là một người ngoài phạm vi không thấy dòng trong danh
 * sách vẫn mở được thẳng URL trang xem và đọc lý do lỗi của vụ việc người đó không được xem.
 *
 * Quyền truy cập TRANG (`canAccess`, cho cả liệt kê lẫn 404 khi không đủ quyền) đến từ
 * `OutboundMessagePolicy::viewAny()` qua cơ chế tích hợp Policy mặc định của Filament — không cần
 * khai báo `canAccess()` riêng ở đây, giống `UserResource`/`MatterResource` (đối chiếu
 * `UserResourceTest`: một manager không có `settings.manage` nhận 404 ở trang index dù không có
 * `canAccess()` nào viết tay).
 */
class OutboundMessageResource extends Resource
{
    protected static ?string $model = OutboundMessage::class;

    // Mỗi resource một hình riêng — xem NavigationIconsTest; "gửi thư" là hình tự nhiên nhất và
    // chưa resource nào ở cấp Resource (không phải RelationManager) dùng nó.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('outbound.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('outbound.plural_label');
    }

    public static function table(Table $table): Table
    {
        return OutboundMessagesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OutboundMessageInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundMessages::route('/'),
            'view' => ViewOutboundMessage::route('/{record}'),
        ];
    }

    /**
     * Không còn `->with(['related' => ...])` (fix round 1): {@see OutboundMessage::relatedMatter()}
     * đọc thẳng bằng SQL trên bảng con thay vì qua quan hệ Eloquent `related()`, nên nạp trước
     * quan hệ đó không còn ích gì cho cột/link "Bản ghi liên quan" — xem docblock hàm đó cho lý
     * do (I1: quan hệ Eloquent tự áp `SoftDeletingScope`, làm nhãn và quyền lệch nhau trên một
     * dòng có mốc thời hạn đã xoá mềm).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(Auth::user());
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->visibleTo(Auth::user());
    }
}
