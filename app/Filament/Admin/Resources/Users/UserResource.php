<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Quản trị nhân sự nội bộ — gated bằng settings.manage (UserPolicy), không có luật hiển thị
 * theo vụ việc như Client/ClientUser nên getEloquentQuery() không cần whereHas listableBy.
 * Không phơi mật khẩu hay hai lớp xác thực ở bất kỳ đâu trong resource này (form chỉ nhận mật
 * khẩu MỚI, không hiện lại; bảng không có cột nào cho hai trường đó).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Mỗi resource một hình riêng (nhân sự nội bộ): năm mục cùng một biểu
    // tượng thì biểu tượng không còn nói gì — xem NavigationIconsTest.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
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
