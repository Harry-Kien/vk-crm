<?php

namespace App\Filament\Admin\Resources\Clients\Tables;

use App\Enums\ClientType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('clients.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('clients.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('clients.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ClientType $state): string => $state->label()),
                TextColumn::make('phone')
                    ->label(__('clients.fields.phone'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('clients.fields.email'))
                    ->searchable(),
                // Đếm giới hạn theo listableBy(), giống getEloquentQuery(): không lộ số vụ việc
                // mà người xem không được thấy qua một con số ở cột này.
                TextColumn::make('matters_count')
                    ->label(__('clients.fields.matters_count'))
                    ->counts(['matters' => fn (Builder $query) => $query->listableBy(Auth::user())])
                    ->sortable(),
            ])
            ->defaultSort('code', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('clients.fields.type'))
                    ->options(fn () => collect(ClientType::cases())->mapWithKeys(fn (ClientType $type) => [$type->value => $type->label()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
