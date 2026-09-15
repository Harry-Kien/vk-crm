<?php

namespace App\Filament\Admin\Resources\Matters;

use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
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
 * Không có trang create/edit: mở vụ việc đi qua Action `OpenMatter` (Task 5). Trang chi tiết
 * (`ViewMatter`) có ba tab của M3 — Tổng quan (infolist dưới đây), Tiến độ và Các bên
 * (`getRelations()`); các tab M4/M6/M7 chưa xây.
 */
class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $hasTitleCaseModelLabel = false;

    public static function getModelLabel(): string
    {
        return __('matters.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('matters.plural_label');
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
            'view' => ViewMatter::route('/{record}'),
        ];
    }

    /** Thứ tự tab sau "Tổng quan" (SPEC §7.2, chỉ ba tab thuộc M3 — xem ViewMatter). */
    public static function getRelations(): array
    {
        return [
            StageLogsRelationManager::class,
            PartiesRelationManager::class,
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
