<?php

namespace App\Filament\Admin\Resources\Matters\Tables;

use App\Enums\Permission;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MattersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('matters.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label(__('matters.fields.client'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('matterType.name')
                    ->label(__('matters.fields.matter_type'))
                    ->searchable()
                    ->sortable(),
                // Kế toán có matter.viewAny nhưng không có matter.view: danh sách rút gọn, ẩn
                // nội dung vụ việc (SPEC §5).
                TextColumn::make('title')
                    ->label(__('matters.fields.title'))
                    ->searchable()
                    ->visible(fn (): bool => (bool) Auth::user()?->can(Permission::MatterView->value)),
                TextColumn::make('summary_for_client')
                    ->label(__('matters.fields.summary_for_client'))
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => (bool) Auth::user()?->can(Permission::MatterView->value)),
                TextColumn::make('stage')
                    ->label(__('matters.fields.stage'))
                    ->badge()
                    ->formatStateUsing(fn (Matter $record): string => $record->currentStage()?->label ?? $record->stage)
                    ->color(fn (Matter $record): string => $record->currentStage()?->is_terminal ? 'success' : 'info'),
                TextColumn::make('leadLawyer.name')
                    ->label(__('matters.fields.lead_lawyer'))
                    ->searchable()
                    ->sortable(),
                // "12 ngày trước", tô vàng khi > 10 ngày, đỏ khi > 14 (SPEC §7.2).
                TextColumn::make('last_client_update_at')
                    ->label(__('matters.fields.last_client_update_at'))
                    ->since()
                    ->color(fn (Matter $record): ?string => static::lastClientUpdateColor($record))
                    ->sortable(),
                // "Còn phải thu" (M9 Task 7, cột tính lại bằng SQL-aggregate ở M9 Task 8, phán
                // quyết controller 1): ẩn HẲN với ai không có `billing.view` — không chỉ rỗng,
                // không có ở đó để dò — cùng lý do `title`/`summary_for_client` ẩn với kế toán
                // không có `matter.view`. Giá trị đọc thẳng cột `outstanding_balance_amount` mà
                // `self::modifyQueryUsing()` bên dưới đã tính SẴN trong CHÍNH câu truy vấn liệt kê
                // (một round-trip cho toàn bảng), không gọi lại `BillingSummary::
                // outstandingForMatter()` mỗi dòng như bản trước (N+1 — xem docblock hàm đó).
                TextColumn::make('outstanding_balance')
                    ->label(__('matters.fields.outstanding_balance'))
                    ->state(fn (Matter $record): string => Money::format((int) ($record->getAttribute('outstanding_balance_amount') ?? 0)))
                    ->visible(fn (): bool => (bool) Auth::user()?->can(Permission::BillingView->value))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->label(__('matters.filters.stage'))
                    ->options(fn (): array => MatterTypeStage::query()->orderBy('label')->pluck('label', 'key')->all()),
                SelectFilter::make('matter_type_id')
                    ->label(__('matters.filters.matter_type'))
                    ->relationship('matterType', 'name'),
                SelectFilter::make('lead_lawyer_id')
                    ->label(__('matters.filters.lead_lawyer'))
                    ->relationship('leadLawyer', 'name'),
                TernaryFilter::make('is_published_to_portal')
                    ->label(__('matters.filters.is_published_to_portal')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Cột thêm CHỈ khi người xem có `billing.view` — bản thân biểu thức SQL không lộ gì
            // (nó không đọc `confidentiality`), nhưng tính nó cho MỌI luật sư/trợ lý không có
            // `billing.view` là một phép JOIN tương quan thừa trên mỗi lần mở danh sách vụ việc,
            // đúng cỡ đa số người dùng hệ thống. `selectRaw()` không đụng gì tới điều kiện
            // `listableBy()` mà `MatterResource::getEloquentQuery()` đã áp — chỉ THÊM một cột.
            //
            // `matters.*` PHẢI đứng ngay trong CÙNG lời gọi `selectRaw()`: gọi `selectRaw()` lần
            // đầu trên một truy vấn chưa từng `select()` một cột nào (mặc định "columns" là
            // `null`, tức "SELECT *" NGẦM ĐỊNH của trình biên dịch) làm chính cái ngầm định đó
            // BIẾN MẤT — `Builder::addSelect()` (vendor) chỉ `$this->columns[] = $column` khi
            // `$this->columns` còn `null`, không tự thêm `*` trước — nên câu SELECT chỉ còn ĐÚNG
            // cột vừa thêm, mất cả khoá chính. Đo được: bản đầu gọi `selectRaw($expr.' as
            // outstanding_balance_amount')` một mình làm `ListRecords::getTableRecordKey()` ném
            // `TypeError` ("Return value must be of type string, null returned") trên MỌI dòng —
            // `$record->getKey()` đọc `id` mà câu SELECT không còn cột đó.
            ->modifyQueryUsing(fn (Builder $query): Builder => (bool) Auth::user()?->can(Permission::BillingView->value)
                ? $query->selectRaw('matters.*, '.BillingSummary::outstandingPerMatterExpression().' as outstanding_balance_amount')
                : $query);
    }

    /**
     * SPEC §7.2: "cập nhật gần nhất cho khách" tô vàng khi > 10 ngày, đỏ khi > 14 ngày. Tách
     * thành hàm tĩnh riêng (thay vì closure ẩn danh trong ->color()) để test được trực tiếp,
     * không phải dựng cả bảng Livewire chỉ để kiểm tra ba ngưỡng màu.
     */
    public static function lastClientUpdateColor(Matter $record): ?string
    {
        if (! $record->last_client_update_at) {
            return null;
        }

        $daysSinceUpdate = $record->last_client_update_at->diffInDays(now());

        return match (true) {
            $daysSinceUpdate > 14 => 'danger',
            $daysSinceUpdate > 10 => 'warning',
            default => null,
        };
    }
}
