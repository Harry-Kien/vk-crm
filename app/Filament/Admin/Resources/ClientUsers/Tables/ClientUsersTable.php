<?php

namespace App\Filament\Admin\Resources\ClientUsers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ClientUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.name')
                    ->label(__('client_users.fields.client'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('client_users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('client_users.fields.email'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label(__('client_users.fields.phone'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('client_users.fields.is_active'))
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('client_users.fields.last_login_at'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('client')
                    ->label(__('client_users.fields.client'))
                    ->relationship('client', 'name'),
                TernaryFilter::make('is_active')
                    ->label(__('client_users.fields.is_active')),
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
