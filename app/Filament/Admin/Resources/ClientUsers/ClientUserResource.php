<?php

namespace App\Filament\Admin\Resources\ClientUsers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Resources\ClientUsers\Schemas\ClientUserForm;
use App\Filament\Admin\Resources\ClientUsers\Tables\ClientUsersTable;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\ClientVisibility;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

/**
 * Cùng lý do như ClientResource: ClientUserPolicy::view chạy exists() cho mỗi bản ghi, nên
 * getEloquentQuery() diễn đạt luật đó một lần ở tầng truy vấn thay vì gọi can('view') theo dòng
 * (carry-forward review M2/M3, task 10) — ai có client.manage thấy tất cả, còn lại chỉ thấy tài
 * khoản của khách thuộc vụ việc họ xem được.
 *
 * Task 2 (`roles/roles-02`): mốc "thấy tất cả" trước đây là `clientUser.manage` — quyền Lawyer
 * cũng có mà không kèm biên giới đội ngũ, nên bảng này từng lộ tên/email/điện thoại của MỌI tài
 * khoản cổng trong văn phòng cho một luật sư, kể cả khách của vụ hạn chế họ không được xem. Đổi
 * sang `client.manage`, đúng mốc `ClientResource::getEloquentQuery()` đã dùng, và đúng mốc
 * `ClientUserPolicy::view()` giờ cũng dùng — ba chỗ đọc "ai thấy tài khoản cổng của khách nào"
 * không còn lệch nhau.
 *
 * Final review X3 (A-I2): luật đó giờ nằm DUY NHẤT ở `ClientVisibility` — ngoài admin, người xem
 * phải với tới được khách hàng VÀ `view` được mọi vụ `restricted` chưa xoá của khách, kể cả khi có
 * `client.manage` (xem `ClientVisibility::canManagePortalAccountsOf()`).
 *
 * Cùng lý do canAccess() của ClientResource: ClientUserPolicy::viewAny() cố ý chỉ đúng bằng
 * clientUser.manage (ChildPolicyTest ghim ở M2), nhưng Filament tự gate cả trang danh sách bằng
 * canAccess() == canViewAny(). canAccess() dưới đây mở trang cho đúng nhóm mà
 * getEloquentQuery() thực sự trả về ít nhất một dòng, không đổi ý nghĩa của viewAny.
 */
class ClientUserResource extends Resource
{
    protected static ?string $model = ClientUser::class;

    // Mỗi resource một hình riêng (tài khoản ĐĂNG NHẬP portal, không phải bản thân khách hàng): năm mục cùng một biểu
    // tượng thì biểu tượng không còn nói gì — xem NavigationIconsTest.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('client_users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('client_users.plural_label');
    }

    // SPEC §5: kế toán chỉ có matter.viewAny, không có clientUser.manage lẫn matter.view — cùng
    // lý do ClientResource::canAccess() (review fix round 1, Important #3): matter.viewAny bỏ
    // qua điều kiện team, từng cho lọt gần như toàn bộ tài khoản portal cho một vai trò không
    // được cấp quyền đó.
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can(Permission::ClientUserManage->value) || $user->can(Permission::MatterView->value));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        // Final review X3: cùng MỘT luật với ClientUserPolicy::view/update/unlockLogin/create —
        // ClientVisibility::canManagePortalAccountsOf(), nói bằng truy vấn.
        if ($user->hasRole(Role::Admin->value)) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('client_id'), ClientVisibility::portalManageableClientQuery($user)->select('clients.id'));
    }

    public static function form(Schema $schema): Schema
    {
        return ClientUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientUsersTable::configure($table);
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
            'index' => ListClientUsers::route('/'),
            'create' => CreateClientUser::route('/create'),
            'edit' => EditClientUser::route('/{record}/edit'),
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
