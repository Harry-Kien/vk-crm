<?php

namespace App\Filament\Admin\Widgets;

use App\Actions\Document\ChecklistProgress;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SPEC §7.1 mục 4: "Hồ sơ thiếu giấy tờ quá 14 ngày — hồ sơ đang tắc vì khách chưa nộp".
 *
 * **Luật "thiếu giấy tờ" lấy nguyên từ SPEC §6.9, không tự nghĩ ra.** §6.9 mô tả job
 * `RemindMissingDocuments` và định nghĩa đúng tập hồ sơ mà widget này phải hiện: "Với mỗi matter
 * đang mở, đã công bố portal, còn item BẮT BUỘC ở trạng thái `missing` hoặc `rejected`"; bullet
 * cuối của cùng mục đó thêm ngưỡng — "nếu tình trạng thiếu kéo dài quá 14 ngày: thông báo cho
 * lead lawyer là hồ sơ đang đình trệ vì thiếu giấy tờ". Widget này là cái nhìn thấy được của
 * đúng thông báo đó, nên bốn điều kiện đi liền nhau, không tách rời:
 *
 * 1. `Matter::scopeOpen()` — §6.9 "đang mở" (R8, M6.5 Task 5: một định nghĩa dùng chung toàn
 *    hệ thống, ghi bởi `TransitionMatterStage`);
 * 2. `is_published_to_portal` — §6.9 "đã công bố portal". Chưa công bố thì khách không có đường
 *    nào để nộp, nên hồ sơ có tắc cũng không phải tắc vì khách;
 * 3. đầu mục `is_required = true` với `status` thuộc {`missing`, `rejected`};
 * 4. tình trạng đó đã kéo dài quá 14 ngày.
 *
 * **`pending_review` cố ý KHÔNG nằm trong tập.** §6.9 chỉ liệt kê `missing` và `rejected`, và
 * lý do đọc được ngay: khách đã nộp rồi, quả bóng đang ở sân văn phòng — hiện nó ở một widget
 * tên "khách chưa nộp" là đổ lỗi nhầm người. Đó cũng là chỗ widget này và thanh tiến độ `X/Y`
 * của SPEC §4.10 gặp nhau, và chúng phải trả lời giống nhau: câu hỏi "đầu mục này đã xong chưa"
 * ở CẢ HAI nơi chỉ đọc cột `status` và không hỏi bảng `documents` một câu nào. Chỗ duy nhất
 * `documents` tham gia vào phép đếm danh mục là định nghĩa tập `Y` ở §4.10 — nơi chính SPEC
 * dùng chữ "đã có tài liệu", và nơi nhóm D đã bị loại ra ({@see ChecklistProgress}). Nên không
 * có đường nào cho một ghi chú nội bộ nhóm D làm một đầu mục trông như đã nộp ở nơi
 * này mà chưa nộp ở nơi kia. Mọi đầu mục bắt buộc đều nằm trong `Y`, nên mỗi dòng đếm ở đây
 * cũng đúng là một phần tử của `Y` chưa vào `X`.
 *
 * **Đồng hồ: `COALESCE(reviewed_at, created_at)` của chính đầu mục** — định nghĩa và lý do (vì sao
 * không phải `updated_at`) nằm ở {@see ChecklistProgress::missingSinceSql()} kể từ M6 Task 8, vì
 * thư nhắc khách `RemindMissingDocuments` phải dùng đúng cùng mốc này. Cùng thành ngữ `COALESCE`
 * mà {@see StaleMattersWidget} dùng cho `last_client_update_at`.
 *
 * **Toàn bộ định nghĩa "còn thiếu" (điều kiện 1–3 ở trên, bộ đếm, đồng hồ) cũng đã chuyển sang
 * {@see ChecklistProgress}** (`mattersAwaitingClient()`, `outstandingRequired()`); widget chỉ còn
 * cộng thêm quyền xem (`listableBy()`), cột hiển thị và ngưỡng 14 ngày
 * ({@see ChecklistProgress::STUCK_AFTER_DAYS}).
 *
 * Ngưỡng 14 ngày chỉ dùng để chọn HỒ SƠ. Cột "Giấy tờ còn thiếu" đếm MỌI đầu mục bắt buộc chưa
 * nộp, kể cả cái mới thiếu hôm qua, vì người gọi điện cho khách cần biết phải xin bao nhiêu thứ
 * chứ không phải bao nhiêu thứ đã quá hạn.
 */
class MattersMissingDocumentsWidget extends TableWidget
{
    // Thứ tự SPEC §7.1: sau "Tài liệu chờ duyệt" (-2) và "Liên hệ chưa ai gọi lại" (M10, -1) — dời
    // -1 → 0 ở M10 Task 5, một lần ĐÁNH SỐ LẠI (xem DashboardWidgetOrderTest).
    protected static ?int $sort = 0;

    private const OUTSTANDING_COUNT_ALIAS = 'outstanding_required_count';

    private const MISSING_SINCE_ALIAS = 'missing_since';

    /** Gác như {@see StaleMattersWidget}: đây là danh sách hồ sơ, không phải con số thống kê. */
    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::MatterView->value);
    }

    /**
     * Truy vấn của widget, tách static để test được mà không dựng cả bảng Livewire.
     *
     * Ba điều kiện §6.9 (đang mở, đã công bố portal, còn đầu mục bắt buộc thiếu quá 14 ngày), bộ
     * đếm và mốc "thiếu từ" đều lấy từ {@see ChecklistProgress} — cùng định nghĩa với thư nhắc
     * khách của `RemindMissingDocuments`, không viết lại ở đây.
     *
     * @return Builder<Matter>
     */
    public static function rowsFor(User $user): Builder
    {
        return ChecklistProgress::mattersAwaitingClient(
            Matter::query()->listableBy($user),
            ChecklistProgress::STUCK_AFTER_DAYS,
        )
            ->withCount([
                'checklistItems as '.self::OUTSTANDING_COUNT_ALIAS => fn (Builder $items): Builder => ChecklistProgress::outstandingRequired($items),
            ])
            ->withMin(
                ['checklistItems as '.self::MISSING_SINCE_ALIAS => fn (Builder $items): Builder => ChecklistProgress::outstandingRequired($items)],
                DB::raw(ChecklistProgress::missingSinceSql()),
            )
            ->with(['client', 'leadLawyer']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('widgets.matters_missing_documents.heading'))
            ->description(__('widgets.matters_missing_documents.description'))
            ->emptyStateHeading(__('widgets.matters_missing_documents.empty_state'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('code')
                    ->label(__('widgets.matters_missing_documents.columns.code')),
                TextColumn::make('client.name')
                    ->label(__('widgets.matters_missing_documents.columns.client')),
                TextColumn::make('title')
                    ->label(__('widgets.matters_missing_documents.columns.title'))
                    ->limit(60),
                TextColumn::make('leadLawyer.name')
                    ->label(__('widgets.matters_missing_documents.columns.lead_lawyer')),
                TextColumn::make(self::OUTSTANDING_COUNT_ALIAS)
                    ->label(__('widgets.matters_missing_documents.columns.outstanding'))
                    ->badge()
                    ->color('danger'),
                TextColumn::make(self::MISSING_SINCE_ALIAS)
                    ->label(__('widgets.matters_missing_documents.columns.missing_since'))
                    ->dateTime('d/m/Y')
                    ->since()
                    ->sortable(),
            ])
            // Tắc lâu nhất lên trước.
            ->defaultSort(self::MISSING_SINCE_ALIAS)
            ->recordActions([
                Action::make('open')
                    ->label(__('widgets.matters_missing_documents.open'))
                    ->url(fn (Matter $record): string => MatterResource::getUrl('view', ['record' => $record], panel: 'admin')),
            ])
            ->paginated([5, 10, 25]);
    }

    /** Cùng thành ngữ phòng thủ với {@see StaleMattersWidget::currentUser()}. */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
