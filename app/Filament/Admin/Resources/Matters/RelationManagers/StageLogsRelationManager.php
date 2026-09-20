<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Filament\Admin\Resources\Matters\Actions\AddUpdateAction;
use App\Filament\Admin\Resources\Matters\Actions\TransitionStageAction;
use App\Models\StageLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

/**
 * Tab "Tiến độ" (SPEC §7.2): dòng thời gian stage_logs, mới nhất trên cùng (Matter::stageLogs()
 * đã orderByDesc('occurred_at'), ->defaultSort() dưới đây chỉ để tường minh, không đổi hành vi).
 *
 * Hai nút *Chuyển giai đoạn* / *Thêm cập nhật* (SPEC §7.3) là CÙNG một Action nghiệp vụ
 * (TransitionMatterStage, xem docblock của nó — "Thêm cập nhật" chỉ là gọi Action đó với
 * to_stage = giai đoạn hiện tại): `TransitionStageAction` và `AddUpdateAction`
 * (`App\Filament\Admin\Resources\Matters\Actions\`) tự gate theo matter.transitionStage và tự
 * đóng gói schema/submit của chính mình — xem docblock của chúng và
 * `Concerns\BuildsStageUpdateSchema`.
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
                    // $this->getOwnerRecord() thay vì $record->matter->matterType: mọi dòng của bảng này
                    // cùng một Matter (chủ sở hữu của trang ViewRecord, tới từ
                    // MatterResource::getRecordRouteBindingEloquentQuery() — KHÔNG eager-load
                    // matterType.stages, khác getEloquentQuery() dùng cho danh sách). Đọc lại qua
                    // $record->matter thay vì $this->getOwnerRecord() vẫn đúng nhưng lazy-load lại
                    // Matter/MatterType MỘT LẦN CHO MỖI DÒNG (mỗi StageLog::matter() là một model rời,
                    // không dùng chung cache); $this->getOwnerRecord() chỉ chạm quan hệ matterType một
                    // lần cho CẢ bảng vì mọi dòng gọi trên cùng một instance Matter, quan hệ đã nạp
                    // được Eloquent cache lại từ lần truy cập đầu tiên.
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
                TransitionStageAction::make(),
                AddUpdateAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query)
                ->with(['views' => fn (HasMany $views): HasMany => $views->orderBy('viewed_at')]));
    }

    /**
     * Nền xám, nhãn "Nội bộ" (SPEC §7.2). Tách static để test được không cần dựng cả bảng.
     *
     * **Kiểu dáng viết thẳng bằng `style=`, không bằng lớp Tailwind, và đó là bắt buộc trong dự
     * án này.** Không có bước dựng CSS (CLAUDE.md: máy dev và máy chủ chỉ có PHP trong Docker),
     * panel nạp `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và tệp đó chỉ chứa
     * các lớp `fi-*` của chính Filament — không một lớp tiện ích Tailwind nào, kể cả những lớp
     * nhìn vô hại như `p-2` hay `text-sm`. Bản M3 của hàm này dùng
     * `bg-gray-100 dark:bg-gray-700/50`, nên cái nền mà SPEC §7.2 đòi CHƯA TỪNG hiện ra một lần
     * nào kể từ M3 trong khi mã trông như đã có. Đó không phải chuyện thẩm mỹ: cái nền chính là
     * thứ phân biệt "ghi chú nội bộ" với "đã gửi cho khách" trên màn hình luật sư gõ vào mỗi
     * ngày. `StageLogPaintingTest` giữ cho lỗi này không quay lại bằng cách đối chiếu từng lớp
     * CSS phát ra với chính tệp được phục vụ, thay vì khẳng định một chuỗi.
     *
     * Màu lấy bằng `color-mix` trên `--gray-500` của Filament chứ không phải một mã màu cứng:
     * nó trong suốt một phần nên phủ đúng lên cả nền sáng lẫn nền tối bằng MỘT luật. Đo trên
     * trình duyệt, bật rồi tắt lớp `.dark`: `color-mix(in srgb, var(--gray-500) 18%, transparent)`
     * cho cùng một giá trị ở cả hai chế độ, trong khi `var(--gray-100)` giữ nguyên
     * `oklch(0.967 0.001 286.375)` — bảng `--gray-*` của Filament KHÔNG tự đảo chiều, nên một ô
     * `--gray-100` đặc sẽ là một mảng gần trắng trên nền `oklch(0.141 0.005 285.823)` của chế độ
     * tối. Cùng thành ngữ `ChecklistRelationManager::progressBar()` đang dùng.
     */
    public static function renderInternalNote(?string $note): ?HtmlString
    {
        if (blank($note)) {
            return null;
        }

        return new HtmlString(sprintf(
            '<div style="border-radius:0.375rem;padding:0.5rem;font-size:0.875rem;'
            .'background-color:color-mix(in srgb, var(--gray-500) 18%%, transparent)">'
            .'<span style="font-weight:600">%s</span>'
            .'<p style="margin-top:0.25rem;white-space:pre-line">%s</p></div>',
            e(__('matters.stage_log_fields.internal_marker')),
            e($note),
        ));
    }

    /**
     * Nền trắng, nhãn trạng thái đọc (SPEC §7.2, §4.18). Chỉ hiện khi dòng đã công bố.
     *
     * "Nền trắng" ở đây là KHÔNG tô nền: ô này để lộ nền của chính dòng bảng, nên nó trắng ở chế
     * độ sáng và tối ở chế độ tối, còn cái phân biệt nó với ghi chú nội bộ là viền và việc thiếu
     * hẳn mảng xám. Một `background-color:#fff` cứng sẽ đúng ở một chế độ và sai ở chế độ kia.
     *
     * Nhãn "Khách chưa xem" quá 5 ngày phải TÔ VÀNG (SPEC §7.2). Bản M3 dùng `text-amber-600`,
     * một lớp cũng không có trong bảng kiểu dáng được phục vụ — xem {@see self::renderInternalNote()}
     * — nên màu cảnh báo đó chưa từng hiện ra, trên đúng cái nhãn nói rằng khách có thể chưa
     * nhận được thông báo nào. `--warning-600` là biến Filament tự đặt theo bảng màu của panel.
     */
    public static function renderPublicContent(?string $content, StageLog $record): ?HtmlString
    {
        if (! $record->is_published) {
            return null;
        }

        $receipt = static::readReceiptLabel($record);

        return new HtmlString(sprintf(
            '<div style="border-radius:0.375rem;padding:0.5rem;font-size:0.875rem;'
            .'border:1px solid color-mix(in srgb, var(--gray-500) 35%%, transparent)">'
            .'<p style="white-space:pre-line">%s</p><span style="%s">%s</span></div>',
            e($content ?? ''),
            $receipt['highlighted']
                ? 'font-weight:600;color:var(--warning-600)'
                : 'color:color-mix(in srgb, var(--gray-500) 90%, transparent)',
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
                // Chuỗi tiếng Việt (kể cả chữ "ngày" nối giữa hai mốc) nằm trong lang/vi/matters.php,
                // không nối chuỗi tiếng Việt trong class PHP (quy ước CLAUDE.md) — PHP chỉ truyền định
                // dạng giờ/ngày qua hai placeholder :time và :date.
                'text' => __('matters.stage_log_fields.viewed_at', [
                    'time' => $firstView->viewed_at->format('H:i'),
                    'date' => $firstView->viewed_at->format('d/m'),
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
