<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Models\StageLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Tab "Tiến độ" (SPEC §7.2): dòng thời gian stage_logs, mới nhất trên cùng (Matter::stageLogs()
 * đã orderByDesc('occurred_at'), ->defaultSort() dưới đây chỉ để tường minh, không đổi hành vi).
 *
 * Hai nút *Chuyển giai đoạn* / *Thêm cập nhật* là CÙNG một Action (TransitionMatterStage, xem
 * docblock của nó — "Thêm cập nhật" chỉ là gọi Action đó với to_stage = giai đoạn hiện tại), nên
 * cùng gate theo matter.transitionStage. Task 9 mới xây form thật đằng sau hai nút này; ở đây mỗi
 * nút chỉ mở một Notification giữ chỗ — đây là "seam" bàn giao cho Task 9: thay thân ->action()
 * bằng ->schema(TransitionStageForm)->action(fn (array $data) => app(TransitionMatterStage::class)
 * ->handle(...)), giữ nguyên tên action ('transitionStage' / 'addUpdate') và điều kiện ->visible()
 * để hai test đã có (assistant không thấy nút) không cần sửa.
 */
class StageLogsRelationManager extends RelationManager
{
    use ScopesToVisibleMatters;

    protected static string $relationship = 'stageLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.progress');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_stage')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('matters.stage_log_fields.occurred_at'))
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('to_stage')
                    ->label(__('matters.stage_log_fields.to_stage'))
                    ->badge()
                    // $this->getOwnerRecord() (đã nạp sẵn matterType.stages ở MatterResource::getEloquentQuery())
                    // thay vì $record->matter->matterType: mọi dòng của bảng này cùng một Matter, nên đọc lại
                    // qua $record tự tải lại quan hệ N lần (mỗi dòng một truy vấn) một cách vô ích.
                    ->formatStateUsing(fn (StageLog $record): string => $this->getOwnerRecord()->matterType->stage($record->to_stage)?->label ?? $record->to_stage),
                TextColumn::make('internal_note')
                    ->label(__('matters.stage_log_fields.internal_note'))
                    ->html()
                    ->wrap()
                    ->formatStateUsing(fn (?string $state): ?HtmlString => static::renderInternalNote($state)),
                TextColumn::make('public_content')
                    ->label(__('matters.stage_log_fields.public_content'))
                    ->html()
                    ->wrap()
                    ->formatStateUsing(fn (?string $state, StageLog $record): ?HtmlString => static::renderPublicContent($state, $record)),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                Action::make('transitionStage')
                    ->label(__('matters.actions.transition_stage'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('primary')
                    ->size(Size::Large)
                    ->visible(fn (): bool => Gate::allows('transitionStage', $this->getOwnerRecord()))
                    // Seam Task 9 — xem docblock lớp.
                    ->action(fn () => Notification::make()
                        ->title(__('matters.actions.transition_stage_placeholder'))
                        ->info()
                        ->send()),
                Action::make('addUpdate')
                    ->label(__('matters.actions.add_update'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('gray')
                    ->size(Size::Large)
                    ->visible(fn (): bool => Gate::allows('transitionStage', $this->getOwnerRecord()))
                    // Seam Task 9 — xem docblock lớp.
                    ->action(fn () => Notification::make()
                        ->title(__('matters.actions.add_update_placeholder'))
                        ->info()
                        ->send()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with(['views' => fn (HasMany $views): HasMany => $views->orderBy('viewed_at')]));
    }

    /** Nền xám, nhãn "Nội bộ" (SPEC §7.2). Tách static để test được không cần dựng cả bảng. */
    public static function renderInternalNote(?string $note): ?HtmlString
    {
        if (blank($note)) {
            return null;
        }

        return new HtmlString(sprintf(
            '<div class="rounded-md bg-gray-100 dark:bg-gray-700/50 p-2 text-sm"><span class="font-semibold">%s</span><p class="mt-1 whitespace-pre-line">%s</p></div>',
            e(__('matters.stage_log_fields.internal_marker')),
            e($note),
        ));
    }

    /** Nền trắng, nhãn trạng thái đọc (SPEC §7.2, §4.18). Chỉ hiện khi dòng đã công bố. */
    public static function renderPublicContent(?string $content, StageLog $record): ?HtmlString
    {
        if (! $record->is_published) {
            return null;
        }

        $receipt = static::readReceiptLabel($record);

        return new HtmlString(sprintf(
            '<div class="rounded-md border p-2 text-sm"><p class="whitespace-pre-line">%s</p><span class="%s">%s</span></div>',
            e($content ?? ''),
            $receipt['highlighted'] ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-gray-500',
            e($receipt['text']),
        ));
    }

    /**
     * SPEC §4.18: "Khách đã xem lúc HH:mm ngày dd/mm" (lần xem ĐẦU TIÊN, của bất kỳ client_user
     * nào của vụ việc — bảng stage_log_views ghi bằng chứng đã thông báo, không phải thống kê) hoặc
     * "Khách chưa xem", tô vàng (highlighted) khi chưa xem quá 5 ngày kể từ lúc công bố.
     *
     * @return array{text: string, highlighted: bool}
     */
    public static function readReceiptLabel(StageLog $stageLog): array
    {
        // Dùng quan hệ đã nạp sẵn (table() eager-load 'views' theo viewed_at, tránh N+1 khi bảng
        // hiện nhiều dòng); truy vấn trực tiếp chỉ khi gọi hàm này đứng riêng (ví dụ test đơn vị
        // trên một model chưa/ không qua bảng).
        $firstView = $stageLog->relationLoaded('views')
            ? $stageLog->views->first()
            : $stageLog->views()->orderBy('viewed_at')->first();

        if ($firstView !== null) {
            return [
                'text' => __('matters.stage_log_fields.viewed_at', [
                    'when' => $firstView->viewed_at->format('H:i').' ngày '.$firstView->viewed_at->format('d/m'),
                ]),
                'highlighted' => false,
            ];
        }

        $daysSincePublished = $stageLog->published_at?->diffInDays(now()) ?? 0;

        return [
            'text' => __('matters.stage_log_fields.not_viewed'),
            'highlighted' => $daysSincePublished > 5,
        ];
    }
}
