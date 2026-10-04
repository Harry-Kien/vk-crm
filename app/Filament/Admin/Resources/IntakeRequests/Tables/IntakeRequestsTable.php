<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Tables;

use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\Permission;
use App\Models\IntakeRequest;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
 *
 * **Cột "Kết quả kiểm tra" và bộ lọc "Đỏ chờ trưởng phòng xử lý"** (rà soát cuối M10, vòng sửa 1 — FI5)
 * đọc `IntakeRequest::awaitsConflictResolution()` — cùng định nghĩa mà ô câu chuyện và chuyển đổi đọc —
 * không đọc mức của lần chạy gần nhất: một Đỏ dính, hay một lần gọi bị giữ vì lần gọi khác của cùng
 * người ra Đỏ, hiện "Đỏ — chờ trưởng phòng", không phải "Xanh". Bộ lọc là cách trưởng phòng/quản trị
 * tìm những bản chỉ họ mở khoá được (R1). Bản đã từ chối không bao giờ "chờ": từ chối là một cách xử lý
 * Đỏ, và nó giữ nhãn mức của lần chạy gần nhất như mọi bản đã từ chối khác (R8).
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
                    // Tên chỉ rỗng sau khi ẩn danh (bắt buộc lúc ghi) — M10 Task 7.
                    ->placeholder(__('intake.anonymise.placeholder'))
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
                    ->color(fn (?ConflictLevel $state, IntakeRequest $record): string => match (true) {
                        $state === ConflictLevel::Red, $record->awaitsConflictResolution() => 'danger',
                        $state === ConflictLevel::Yellow => 'warning',
                        $state === ConflictLevel::Green => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?ConflictLevel $state, IntakeRequest $record): string => $record->awaitsConflictResolution()
                        ? __('intake.check.badge_red_pending')
                        : ($state?->label() ?? '—')),
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
                Filter::make('red_pending')
                    ->label(__('intake.filters.red_pending'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->awaitingConflictResolution()),
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
