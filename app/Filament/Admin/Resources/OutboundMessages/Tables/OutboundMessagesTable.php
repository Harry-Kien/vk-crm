<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Tables;

use App\Enums\OutboundStatus;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\OutboundMessage;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

/**
 * Bảng nhật ký thư (SPEC §4.15 "phải tra được ngay"), chỉ đọc — không có `->recordActions()`
 * nào ngoài `ViewAction` (không sửa/xoá/gửi lại).
 *
 * Không có cột/nội dung nào hiện thân thư hay số CCCD (Review Focus 1, ràng buộc riêng của Task
 * 13): `payload` chỉ từng được ghi với khoá `subject` ({@see \App\Actions\Notification\
 * RecordOutboundMessage::sending()}), không có khoá nào khác tồn tại để lộ.
 */
class OutboundMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('outbound.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('recipient')
                    ->label(__('outbound.fields.recipient'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('template')
                    ->label(__('outbound.fields.template'))
                    ->formatStateUsing(fn (string $state): string => static::templateLabel($state))
                    ->searchable(),
                // Tô đỏ `failed` (yêu cầu của brief Task 13) — cùng thành ngữ với
                // `MattersTable::lastClientUpdateColor()`: màu Filament có sẵn (`fi-color-danger`,
                // `fi-color-success`, `fi-color-gray`), không lớp Tailwind tự viết nào (CLAUDE.md:
                // không có bước dựng CSS).
                TextColumn::make('status')
                    ->label(__('outbound.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (OutboundStatus $state): string => $state->label())
                    ->color(fn (OutboundStatus $state): string => static::statusColor($state)),
                TextColumn::make('related')
                    ->label(__('outbound.fields.related'))
                    ->state(fn (OutboundMessage $record): string => static::relatedLabel($record))
                    ->url(fn (OutboundMessage $record): ?string => static::relatedUrl($record)),
                TextColumn::make('sent_at')
                    ->label(__('outbound.fields.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('outbound.fields.none'))
                    ->sortable(),
                TextColumn::make('error')
                    ->label(__('outbound.fields.error'))
                    ->limit(60)
                    ->wrap()
                    ->placeholder(__('outbound.fields.none'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('outbound.filters.status'))
                    ->options(fn (): array => collect(OutboundStatus::cases())
                        ->mapWithKeys(fn (OutboundStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('template')
                    ->label(__('outbound.filters.template'))
                    ->searchable()
                    ->options(fn (): array => OutboundMessage::query()
                        ->distinct()
                        ->orderBy('template')
                        ->pluck('template', 'template')
                        ->map(fn (string $template): string => static::templateLabel($template))
                        ->all()),
                // Danh sách vụ việc của bộ lọc PHẢI đi qua listableBy(), không phải mọi vụ việc
                // của văn phòng: chính ô chọn này là một nơi có thể rò rỉ mã/tiêu đề của một vụ
                // `restricted` nếu không lọc — dropdown hiện ra CÒN TRƯỚC khi query() chạy.
                SelectFilter::make('matter')
                    ->label(__('outbound.filters.matter'))
                    ->searchable()
                    ->options(fn (): array => Matter::query()
                        ->listableBy(Auth::user())
                        ->orderByDesc('id')
                        ->limit(200)
                        ->pluck('code', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->forMatter((int) $data['value'])
                        : $query),
                Filter::make('created_between')
                    ->label(__('outbound.filters.created_between'))
                    ->schema([
                        DatePicker::make('from')->label(__('outbound.filters.created_from')),
                        DatePicker::make('until')->label(__('outbound.filters.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Nhãn tiếng Việt khi đã khai báo (SPEC §9); mẫu chưa khai báo hiện nguyên khoá thô. */
    public static function templateLabel(string $template): string
    {
        return Lang::has("outbound.templates.$template") ? __("outbound.templates.$template") : $template;
    }

    public static function statusColor(OutboundStatus $status): string
    {
        return match ($status) {
            OutboundStatus::Failed => 'danger',
            OutboundStatus::Sent => 'success',
            OutboundStatus::Queued => 'gray',
        };
    }

    /**
     * Không phải mọi bản ghi liên quan đều là một vụ việc — {@see OutboundMessage::relatedMatter()}
     * trả `null` cho thư không gắn vụ việc nào (OTP cổng khách, thư nội bộ). Dòng đó vẫn hiện
     * được (chỉ admin thấy nó, xem `OutboundMessagePolicy::view()`), chỉ là không có mã vụ việc
     * để hiện.
     */
    public static function relatedLabel(OutboundMessage $record): string
    {
        return $record->relatedMatter()?->code ?? __('outbound.fields.no_related_record');
    }

    public static function relatedUrl(OutboundMessage $record): ?string
    {
        $matter = $record->relatedMatter();

        return $matter === null ? null : MatterResource::getUrl('view', ['record' => $matter], panel: 'admin');
    }
}
