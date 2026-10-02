<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Tables;

use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\Permission;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Danh sách tiếp nhận (M10 Task 3). Hàng đã lọc theo người xem ở `IntakeRequestResource::getEloquentQuery()`.
 *
 * **Thứ tự mặc định: chưa ai phản hồi lên trước, ai chờ lâu nhất ở trên cùng** (kế hoạch: "mặc định
 * 'chưa phản hồi' lên trước"; R5 — thời gian phản hồi lần đầu là con số đổi được thành tiền). "Chưa
 * phản hồi" = `status = new` (`IntakeStatus::New` là trạng thái DUY NHẤT chưa có phản hồi). Các bản
 * còn lại theo thời điểm nhận, mới nhất trước. Biểu thức CASE chạy giống nhau trên SQLite và MariaDB.
 *
 * **Không cột nào mang lý do từ chối hay câu chuyện** (R8: lý do xung đột chỉ người có `intake.viewAny`
 * thấy, và chỉ trên trang của bản ghi). Trạng thái `declined` hiện bằng nhãn trung tính "Văn phòng từ
 * chối" cho mọi người. Không hành động hàng loạt nào: xoá là ẩn danh (R7).
 */
class IntakeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('intake.fields.code'))
                    ->searchable(),
                TextColumn::make('contact_name')
                    ->label(__('intake.fields.contact_name'))
                    ->searchable(),
                TextColumn::make('contact_phone')
                    ->label(__('intake.fields.contact_phone')),
                TextColumn::make('source')
                    ->label(__('intake.fields.source'))
                    ->formatStateUsing(fn (IntakeSource $state): string => $state->label()),
                TextColumn::make('status')
                    ->label(__('intake.fields.status'))
                    ->badge()
                    ->color(fn (IntakeStatus $state): string => $state === IntakeStatus::New ? 'warning' : 'gray')
                    ->formatStateUsing(fn (IntakeStatus $state): string => $state->label()),
                TextColumn::make('conflict_level')
                    ->label(__('intake.fields.conflict_level'))
                    ->badge()
                    ->color(fn (?ConflictLevel $state): string => match ($state) {
                        ConflictLevel::Red => 'danger',
                        ConflictLevel::Yellow => 'warning',
                        ConflictLevel::Green => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?ConflictLevel $state): string => $state?->label() ?? '—'),
                TextColumn::make('assignee.name')
                    ->label(__('intake.fields.assigned_to'))
                    ->placeholder('—'),
                TextColumn::make('received_at')
                    ->label(__('intake.fields.received_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [IntakeStatus::New->value])
                ->orderByRaw('CASE WHEN status = ? THEN received_at END ASC', [IntakeStatus::New->value])
                ->orderByDesc('received_at'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('intake.fields.status'))
                    ->options(fn (): array => collect(IntakeStatus::cases())
                        ->mapWithKeys(fn (IntakeStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('source')
                    ->label(__('intake.fields.source'))
                    ->options(fn (): array => collect(IntakeSource::cases())
                        ->mapWithKeys(fn (IntakeSource $source): array => [$source->value => $source->label()])
                        ->all()),
                SelectFilter::make('assigned_to')
                    ->label(__('intake.fields.assigned_to'))
                    ->options(fn (): array => static::assigneeOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Người có thể được giao một bản ghi tiếp nhận: nhân sự đang hoạt động có `intake.create` (cùng
     * luật `ValidatesIntakeIdentity`). Dùng cho bộ lọc và ô "Người phụ trách" của form.
     *
     * @return array<int, string>
     */
    public static function assigneeOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->permission(Permission::IntakeCreate->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
