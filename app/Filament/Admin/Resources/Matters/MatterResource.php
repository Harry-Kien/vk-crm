<?php

namespace App\Filament\Admin\Resources\Matters;

use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\CommunicationLogsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterActivityRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
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
 * Trang create (`CreateMatter`) KHÔNG dùng luồng `Model::create()` mặc định của Filament — nó
 * gọi Action `OpenMatter`, vì mở một vụ việc là bảy bước nghiệp vụ (kiểm tra xung đột lợi ích,
 * sinh mã, dựng bên khách hàng, sao chép danh mục hồ sơ, nhật ký) chứ không phải một lần ghi
 * bảng. Trang sửa (`EditMatter`, M6.5 Task 5) cũng vậy — gọi `App\Actions\Matter\
 * UpdateMatterDetails`, chỉ sửa năm cột SPEC §4.6 cho phép, không đụng `client_id`/
 * `matter_type_id`/`lead_lawyer_id`. Trang chi tiết (`ViewMatter`) có tab Tổng quan (infolist
 * dưới đây) và các tab của `getRelations()` — Đội ngũ, Tiến độ, Danh mục hồ sơ, Tài liệu, Các bên,
 * Yêu cầu từ khách, Mốc thời hạn, Liên lạc (M7 Task 8), Hợp đồng và thanh toán (M9) và Nhật ký
 * (M7 Task 8, luôn cuối cùng).
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
            'edit' => EditMatter::route('/{record}/edit'),
        ];
    }

    /**
     * Thứ tự tab sau "Tổng quan" (và "Đội ngũ" của M6.5, xem chú thích tại dòng của nó) theo SPEC
     * §7.2: Tiến độ, Danh mục hồ sơ, Tài liệu, Các bên, rồi Yêu cầu từ khách (M5 Task 6), Mốc thời
     * hạn (M6 Task 5), Liên lạc (M7 Task 8), Hợp đồng và thanh toán (M9 — đính chính SPEC §7.2) và
     * Nhật ký (M7 Task 8).
     *
     * Một chỗ lệch có biết: SPEC đặt Mốc thời hạn và Liên lạc TRƯỚC "Yêu cầu từ khách". Tab Mốc
     * thời hạn được dựng sau và nối vào cuối; M7 Task 8 không đảo lại hai dòng của milestone khác
     * (để lần gộp các làn song song không đụng nhau) mà nối Liên lạc ngay sau Mốc thời hạn. "Hợp
     * đồng và thanh toán" (M9) đứng sau mọi tab nội dung hồ sơ; "Nhật ký" đứng cuối cùng, đúng như
     * SPEC (gộp M7 vào `main`).
     *
     * Thứ tự không phải chuyện thẩm mỹ: "Danh mục hồ sơ" (còn thiếu gì) đứng trước "Tài liệu"
     * (đã có gì) vì câu hỏi hằng ngày của trợ lý là câu thứ nhất, và SPEC viết chúng theo đúng
     * thứ tự đó.
     */
    public static function getRelations(): array
    {
        return [
            // Tab "Đội ngũ" (M6.5 Task 3, R6) — đứng ngay sau Tổng quan vì đội ngũ là thứ quyết
            // định ai còn THẤY được các tab bên dưới (SPEC §4.7 `matter_user`): trước tab này
            // không có màn hình nào ghi vào đó ngoài lead do `Matter::created()` tự thêm.
            TeamRelationManager::class,
            StageLogsRelationManager::class,
            ChecklistRelationManager::class,
            DocumentsRelationManager::class,
            PartiesRelationManager::class,
            // Tab "Yêu cầu từ khách" (SPEC §7.2) — hộp thư của vụ việc, M5 Task 6. Đứng sau
            // "Các bên" vì nó là việc đọc-và-trả-lời hằng ngày, không phải một phần của hồ sơ.
            ClientRequestsRelationManager::class,
            // Tab "Mốc thời hạn" (SPEC §7.2), M6 Task 5 — màn hình DUY NHẤT tạo ra một hàng
            // `deadlines` (trước nó chỉ có `MatterSeeder`, tức dữ liệu mẫu), và vì thế là thứ
            // quyết định `CheckDeadlines` (Task 6) có dữ liệu ở văn phòng hay chỉ xanh trên máy
            // của lập trình viên.
            DeadlinesRelationManager::class,
            // Tab "Liên lạc" (SPEC §7.2), M7 Task 8 — ghi một cuộc gọi trong dưới 15 giây.
            CommunicationLogsRelationManager::class,
            // Tab "Hợp đồng và thanh toán" (SPEC §7.2 đính chính M9 Task 3), M9 Task 7 — đứng
            // sau mọi tab nội dung hồ sơ (chỉ trước "Nhật ký", luôn cuối cùng) vì nó là tab đầu
            // tiên về TIỀN trên trang vụ việc, một trục khác hẳn nội dung hồ sơ mà các tab trên
            // đọc. Cổng thật là `BillingRelationManager::canViewForRecord()`
            // (hỏi `viewAny` CÓ NGỮ CẢNH vụ việc qua `ChecksBillingAccess`, không phải bản mặc
            // định KHÔNG NGỮ CẢNH của `RelationManager` — xem docblock lớp đó), nên kế toán
            // (không có `matter.view`) không bao giờ mở được tới đây: màn hình của họ là trang
            // "Công nợ" (Task 8).
            BillingRelationManager::class,
            // Tab "Nhật ký" (SPEC §7.2), M7 Task 8 — luôn cuối cùng, như SPEC liệt kê; chỉ admin,
            // trưởng phòng và luật sư phụ trách của vụ thấy (`MatterPolicy::viewActivityLog`).
            MatterActivityRelationManager::class,
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
