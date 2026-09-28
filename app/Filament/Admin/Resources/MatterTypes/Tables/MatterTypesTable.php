<?php

namespace App\Filament\Admin\Resources\MatterTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MatterTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('matter_types.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('matter_types.fields.name'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('matter_types.fields.is_active'))
                    ->boolean(),
                TextColumn::make('stages_count')
                    ->label(__('matter_types.fields.stages_count'))
                    ->counts('stages')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('matter_types.fields.sort_order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Task 19 (controller decision, C1-class bulk-action hole): không có
                    // authorizeIndividualRecords(), MatterTypePolicy::deleteAny() (cổng thô, mới
                    // thêm) chỉ quyết định nút có bấm được không — Filament vẫn xoá MỌI dòng đã
                    // chọn mà không hỏi lại delete() cho từng dòng, bỏ qua thẳng luật "không còn
                    // hồ sơ nào dùng" per-record (cùng lý do ClientsTable).
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords('forceDelete'),
                    RestoreBulkAction::make()
                        ->authorizeIndividualRecords('restore'),
                ]),
            ]);
    }
}
