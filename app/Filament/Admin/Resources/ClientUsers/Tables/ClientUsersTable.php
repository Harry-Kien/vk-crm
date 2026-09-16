<?php

namespace App\Filament\Admin\Resources\ClientUsers\Tables;

use App\Filament\Admin\Support\VisibleClientOptions;
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
                // Review fix round 1, Important #2: relationship('client', 'name') liệt kê toàn
                // bộ khách hàng văn phòng trong dropdown lọc, kể cả khách của những vụ việc actor
                // không xem được. Đổi sang options() dùng chung luật với ô "Khách hàng" của form
                // (VisibleClientOptions) — lọc theo cột client_id trực tiếp, không qua relationship.
                SelectFilter::make('client_id')
                    ->label(__('client_users.fields.client'))
                    ->options(fn (): array => VisibleClientOptions::forCurrentUser()),
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
