<?php

namespace App\Filament\Admin\Resources\Clients;

use App\Enums\Permission;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Schemas\ClientForm;
use App\Filament\Admin\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

/**
 * ClientPolicy::view chạy một truy vấn exists() cho MỖI bản ghi (M2/M3 review, carry-forward
 * task 10): không được gọi can('view', $client) theo từng dòng ở đây. Thay vào đó,
 * getEloquentQuery() diễn đạt đúng luật đó MỘT LẦN ở tầng truy vấn — ai có client.manage thấy
 * tất cả, còn lại chỉ thấy khách của những vụ việc họ xem được (Matter::scopeListableBy) — nên
 * danh sách và can('view') luôn khớp nhau mà không tốn N truy vấn phụ.
 *
 * ClientPolicy::viewAny() cố ý chỉ đúng bằng client.manage (ChildPolicyTest ghim hành vi này từ
 * M2: $lead->can('viewAny', Client::class) phải là false — "viewAny" đúng nghĩa Laravel là "có
 * toàn quyền duyệt", không phải "có thể thấy một vài dòng"). Nhưng Filament tự gate CẢ TRANG
 * danh sách bằng resource-level canAccess() == canViewAny() (HasAuthorization trait), nên nếu
 * dừng ở đó, một luật sư không có client.manage sẽ bị 403 ngay khi mở trang, trước khi
 * getEloquentQuery() ở trên kịp lọc còn đúng khách của vụ việc họ xem được. canAccess() dưới đây
 * mở trang cho đúng nhóm mà getEloquentQuery() thực sự trả về ít nhất một dòng — tách "vào được
 * trang danh sách" (canAccess, việc của resource/UI) khỏi "được duyệt toàn bộ" (viewAny, việc
 * của policy), không đổi ý nghĩa của viewAny.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('clients.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clients.plural_label');
    }

    // SPEC §5: kế toán chỉ có matter.viewAny (danh sách vụ việc rút gọn), không có client.manage
    // lẫn matter.view — bảng quyền cho họ "—" ở cả hai cột liên quan đến khách hàng. Vì
    // matter.viewAny bỏ qua điều kiện team trong scopeListableBy (thấy MỌI vụ việc thường),
    // từng cho phép ở đây thì getEloquentQuery() trả về gần như toàn bộ khách hàng — số điện
    // thoại, email — cho một vai trò SPEC không cấp quyền đó (review fix round 1, Important #3).
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can(Permission::ClientManage->value) || $user->can(Permission::MatterView->value));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        // Chỉ User (nhân sự nội bộ) vào được panel admin; phòng thủ nếu guard trống thì không
        // thấy khách nào, thay vì gọi listableBy() với một actor không xác định.
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(Permission::ClientManage->value)) {
            return $query;
        }

        return $query->whereHas('matters', fn (Builder $matterQuery) => $matterQuery->listableBy($user));
    }

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
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
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
