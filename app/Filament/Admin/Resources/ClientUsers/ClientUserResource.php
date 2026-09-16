<?php

namespace App\Filament\Admin\Resources\ClientUsers;

use App\Enums\Permission;
use App\Filament\Admin\Resources\ClientUsers\Pages\CreateClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Admin\Resources\ClientUsers\Pages\ListClientUsers;
use App\Filament\Admin\Resources\ClientUsers\Schemas\ClientUserForm;
use App\Filament\Admin\Resources\ClientUsers\Tables\ClientUsersTable;
use App\Models\ClientUser;
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
 * Cùng lý do như ClientResource: ClientUserPolicy::view chạy exists() cho mỗi bản ghi, nên
 * getEloquentQuery() diễn đạt luật đó một lần ở tầng truy vấn thay vì gọi can('view') theo dòng
 * (carry-forward review M2/M3, task 10) — ai có clientUser.manage thấy tất cả, còn lại chỉ thấy
 * tài khoản của khách thuộc vụ việc họ xem được.
 *
 * Cùng lý do canAccess() của ClientResource: ClientUserPolicy::viewAny() cố ý chỉ đúng bằng
 * clientUser.manage (ChildPolicyTest ghim ở M2), nhưng Filament tự gate cả trang danh sách bằng
 * canAccess() == canViewAny(). canAccess() dưới đây mở trang cho đúng nhóm mà
 * getEloquentQuery() thực sự trả về ít nhất một dòng, không đổi ý nghĩa của viewAny.
 */
class ClientUserResource extends Resource
{
    protected static ?string $model = ClientUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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

        if ($user->can(Permission::ClientUserManage->value)) {
            return $query;
        }

        return $query->whereHas('client.matters', fn (Builder $matterQuery) => $matterQuery->listableBy($user));
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
