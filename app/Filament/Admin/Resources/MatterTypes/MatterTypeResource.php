<?php

namespace App\Filament\Admin\Resources\MatterTypes;

use App\Filament\Admin\Resources\MatterTypes\Pages\CreateMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\ListMatterTypes;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\ChecklistTemplatesRelationManager;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\StagesRelationManager;
use App\Filament\Admin\Resources\MatterTypes\Schemas\MatterTypeForm;
use App\Filament\Admin\Resources\MatterTypes\Tables\MatterTypesTable;
use App\Models\MatterType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Dữ liệu cấu hình (không phải dữ liệu vụ việc): chỉ settings.manage được ghi, ai cũng đọc (MatterTypePolicy). */
class MatterTypeResource extends Resource
{
    protected static ?string $model = MatterType::class;

    // Mỗi resource một hình riêng (dữ liệu cấu hình, không phải dữ liệu nghiệp vụ): năm mục cùng một biểu
    // tượng thì biểu tượng không còn nói gì — xem NavigationIconsTest.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    // Nhãn tiếng Việt không viết hoa từng chữ như mặc định của Filament.
    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('matter_types.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('matter_types.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return MatterTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MatterTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StagesRelationManager::class,
            // M6.5 Task 15 (finding intake-02/checklist-02/roles-06/spec-gap-04): trước đây
            // không có màn hình nào quản lý ChecklistTemplate — xem docblock lớp đó.
            ChecklistTemplatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatterTypes::route('/'),
            'create' => CreateMatterType::route('/create'),
            'edit' => EditMatterType::route('/{record}/edit'),
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
