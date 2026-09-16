<?php

namespace App\Filament\Admin\Resources\MatterTypes\RelationManagers;

use App\Models\MatterTypeStage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Giai đoạn là dữ liệu con của loại vụ việc, không phải quan hệ nhiều-nhiều: không có
 * associate/dissociate, xoá loại vụ việc kéo theo xoá giai đoạn (cascadeOnDelete).
 *
 * `key` không còn unique ở DB (M1 để lại lỗi: unique tính cả dòng đã xoá mềm, MariaDB không có
 * unique một phần) — duy nhất được kiểm tra ở đây, chỉ tính các dòng còn sống của cùng loại vụ
 * việc, để xoá mềm một giai đoạn rồi tạo lại cùng `key` không bị chặn.
 */
class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matter_types.stages.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label(__('matter_types.stage_fields.key'))
                    ->required()
                    ->maxLength(40)
                    ->scopedUnique(
                        model: MatterTypeStage::class,
                        ignoreRecord: true,
                        modifyQueryUsing: fn (Builder $query, RelationManager $livewire): Builder => $query
                            ->where('matter_type_id', $livewire->getOwnerRecord()->getKey())
                            ->whereNull('deleted_at'),
                    ),
                TextInput::make('label')
                    ->label(__('matter_types.stage_fields.label'))
                    ->required()
                    ->maxLength(120),
                TextInput::make('client_label')
                    ->label(__('matter_types.stage_fields.client_label'))
                    ->required()
                    ->maxLength(120),
                Textarea::make('client_description')
                    ->label(__('matter_types.stage_fields.client_description'))
                    ->columnSpanFull(),
                TextInput::make('sort_order')
                    ->label(__('matter_types.stage_fields.sort_order'))
                    ->numeric()
                    ->integer()
                    ->default(0)
                    ->required(),
                Toggle::make('is_terminal')
                    ->label(__('matter_types.stage_fields.is_terminal'))
                    ->default(false),
                Select::make('allowed_next')
                    ->label(__('matter_types.stage_fields.allowed_next'))
                    ->multiple()
                    ->options(function (RelationManager $livewire, ?MatterTypeStage $record): array {
                        return $livewire->getOwnerRecord()->stages()
                            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                            ->pluck('label', 'key')
                            ->all();
                    })
                    ->columnSpanFull(),
                TextInput::make('default_next_update_days')
                    ->label(__('matter_types.stage_fields.default_next_update_days'))
                    ->numeric()
                    ->integer()
                    ->default(14)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('key')
                    ->label(__('matter_types.stage_fields.key'))
                    ->searchable(),
                TextColumn::make('label')
                    ->label(__('matter_types.stage_fields.label'))
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label(__('matter_types.stage_fields.sort_order'))
                    ->sortable(),
                IconColumn::make('is_terminal')
                    ->label(__('matter_types.stage_fields.is_terminal'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]));
    }
}
