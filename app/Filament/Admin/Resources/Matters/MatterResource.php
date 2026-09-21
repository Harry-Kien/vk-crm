<?php

namespace App\Filament\Admin\Resources\Matters;

use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Matters\Schemas\MatterForm;
use App\Filament\Admin\Resources\Matters\Schemas\MatterInfolist;
use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
use App\Models\Matter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Danh sách vụ việc có giới hạn theo vai trò (SPEC §5, §7.2). Đây là resource nhạy cảm nhất về
 * bảo mật trong panel admin: cả getEloquentQuery() (danh sách/bộ đếm) lẫn
 * getRecordRouteBindingEloquentQuery() (mở thẳng URL) đều phải áp `listableBy`, nếu không một
 * luật sư ngoài đội ngũ gõ đúng URL vẫn mở được vụ việc dù không thấy nó trong danh sách.
 *
 * Không có trang edit: sửa vụ việc chưa thuộc phạm vi M3. Trang create (`CreateMatter`) KHÔNG
 * dùng luồng `Model::create()` mặc định của Filament — nó gọi Action `OpenMatter`, vì mở một vụ
 * việc là bảy bước nghiệp vụ (kiểm tra xung đột lợi ích, sinh mã, dựng bên khách hàng, sao chép
 * danh mục hồ sơ, nhật ký) chứ không phải một lần ghi bảng. Trang chi tiết (`ViewMatter`) có sáu
 * tab — Tổng quan (infolist dưới đây), Tiến độ, Danh mục hồ sơ, Tài liệu, Các bên và Yêu cầu từ khách
 * (`getRelations()`); các tab Mốc thời hạn, Liên lạc và Nhật ký (M7) chưa xây.
 */
class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    // Mỗi resource một hình riêng (vụ việc — cán cân, mục dùng nhiều nhất của panel): năm mục cùng một biểu
    // tượng thì biểu tượng không còn nói gì — xem NavigationIconsTest.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('matters.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('matters.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return MatterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MatterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MattersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatters::route('/'),
            'create' => CreateMatter::route('/create'),
            'view' => ViewMatter::route('/{record}'),
        ];
    }

    /**
     * Thứ tự tab sau "Tổng quan", ĐÚNG thứ tự SPEC §7.2 liệt kê chúng: Tiến độ, Danh mục hồ sơ,
     * Tài liệu, Các bên, Yêu cầu từ khách. Hai tab giữa là của M4, tab cuối là của M5 Task 6;
     * Mốc thời hạn, Liên lạc và Nhật ký chưa xây.
     *
     * "Yêu cầu từ khách" nhảy qua Mốc thời hạn và Liên lạc — hai tab SPEC đặt trước nó — vì hai
     * tab kia thuộc M7 và một chỗ trống không giữ được thứ tự. Khi chúng được dựng, chúng chèn
     * vào TRƯỚC dòng cuối cùng ở đây.
     *
     * Thứ tự không phải chuyện thẩm mỹ: "Danh mục hồ sơ" (còn thiếu gì) đứng trước "Tài liệu"
     * (đã có gì) vì câu hỏi hằng ngày của trợ lý là câu thứ nhất, và SPEC viết chúng theo đúng
     * thứ tự đó.
     */
    public static function getRelations(): array
    {
        return [
            StageLogsRelationManager::class,
            ChecklistRelationManager::class,
            DocumentsRelationManager::class,
            PartiesRelationManager::class,
            // Tab "Yêu cầu từ khách" (SPEC §7.2) — hộp thư của vụ việc, M5 Task 6. Đứng sau
            // "Các bên" vì nó là việc đọc-và-trả-lời hằng ngày, không phải một phần của hồ sơ.
            ClientRequestsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->listableBy(Auth::user())
            ->with(['client', 'matterType.stages', 'leadLawyer', 'team']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->listableBy(Auth::user());
    }
}
