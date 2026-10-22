<?php

namespace App\Filament\Admin\Resources\Clients\Tables;

use App\Enums\ClientType;
use App\Support\Search\LetterD;
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
    /** Ít nhất chừng này chữ số mới so theo chữ số — ít hơn thì khớp gần như mọi số. */
    private const PHONE_SEARCH_MIN_DIGITS = 4;

    /**
     * Rà soát toàn hệ thống 2026-10-22: tìm khách theo số điện thoại viết kiểu nào cũng được, như danh sách
     * Tiếp nhận (làn fa). `clients.phone` lưu nguyên cách nhân sự gõ ("0912 345 678", "+84 912…", "0912-…"),
     * nên so hai vế sau khi bỏ dấu cách, chấm, gạch, ngoặc, dấu "+": lấy chữ số của từ người gõ, bỏ số 0 đầu
     * (gọi nội hạt) và tiền tố 84 của số quốc tế, rồi tìm chuỗi con. Vẫn giữ cách tìm cũ trên chuỗi thô
     * (ô tìm có chữ, hay ít hơn {@see self::PHONE_SEARCH_MIN_DIGITS} chữ số).
     */
    public static function searchPhone(Builder $query, string $search): Builder
    {
        $column = $query->qualifyColumn('phone');
        $digits = ltrim((string) preg_replace('/\D+/', '', $search), '0');

        if (strlen($digits) >= 11 && str_starts_with($digits, '84')) {
            $digits = substr($digits, 2);
        }

        return $query->where(function (Builder $either) use ($column, $search, $digits): void {
            LetterD::searchable('phone')($either, $search);

            if (strlen($digits) >= self::PHONE_SEARCH_MIN_DIGITS) {
                $stripped = $column;

                foreach ([' ', '.', '-', '+', '(', ')'] as $character) {
                    $stripped = "replace({$stripped}, '{$character}', '')";
                }

                $either->orWhereRaw("{$stripped} like ?", ['%'.$digits.'%']);
            }
        });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('clients.fields.code'))
                    ->searchable(query: LetterD::searchable('code'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('clients.fields.name'))
                    ->searchable(query: LetterD::searchable('name'))
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('clients.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ClientType $state): string => $state->label()),
                TextColumn::make('phone')
                    ->label(__('clients.fields.phone'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchPhone($query, $search)),
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
                    // Task 2, vòng sửa 1 (Critical #1): `authorizeIndividualRecords('delete')` bắt
                    // MỖI bản ghi đã chọn đi qua `ClientPolicy::delete()` thật (qua `Gate::inspect()`
                    // thường, không qua đường không-nghiêm-ngặt của Filament) trước khi bị xoá — không
                    // có nó, `deleteAny()` (cổng thô, đã thêm ở ClientPolicy) chỉ quyết định nút có
                    // BẤM ĐƯỢC không, còn Filament mặc định xoá mọi dòng đã chọn mà không hỏi lại
                    // `delete()` cho từng dòng, tức bỏ qua thẳng luật "còn vụ đang mở" per-record. Xem
                    // `ClientPolicy::deleteAny()` và `Filament\Actions\Concerns\InteractsWithSelectedRecords`.
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
