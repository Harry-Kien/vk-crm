<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\UserPosition;
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

/** Không có cột nào cho password / two_factor_secret / two_factor_recovery_codes (ràng buộc task). */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('users.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('position')
                    ->label(__('users.fields.position'))
                    ->badge()
                    ->formatStateUsing(fn (UserPosition $state): string => $state->label()),
                TextColumn::make('phone')
                    ->label(__('users.fields.phone'))
                    ->searchable(),
                TextColumn::make('bar_number')
                    ->label(__('users.fields.bar_number'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('users.fields.is_active'))
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('users.fields.last_login_at'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('position')
                    ->label(__('users.fields.position'))
                    ->options(fn () => collect(UserPosition::cases())->mapWithKeys(fn (UserPosition $position) => [$position->value => $position->label()])),
                TernaryFilter::make('is_active')
                    ->label(__('users.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // M6.5 Task 4 (carry-over từ rà soát Task 2, C1-class hole): trước bản sửa này,
                // UserPolicy không định nghĩa deleteAny()/restoreAny()/forceDeleteAny() — một
                // ability KHÔNG có phương thức tương ứng được Filament coi là CHO PHÉP ở chế độ
                // không nghiêm ngặt (mặc định dự án), nên MỌI người vào được trang này bấm xoá
                // hàng loạt trót lọt, bỏ qua cả settings.manage lẫn luật R7 (còn việc dở dang, hoặc
                // là admin cuối cùng). authorizeIndividualRecords() là lớp phòng thủ THỨ HAI, độc
                // lập với ba ability "thô" kia: nó bắt MỖI bản ghi đã chọn đi qua đúng
                // UserPolicy::delete()/restore()/forceDelete() — không có nó, cổng thô chỉ quyết
                // định nút có bấm được không, còn Filament vẫn xử lý MỌI dòng đã chọn mà không hỏi
                // lại policy cho từng dòng. Cùng thành ngữ ClientsTable/ClientUsersTable.
                BulkActionGroup::make([
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
