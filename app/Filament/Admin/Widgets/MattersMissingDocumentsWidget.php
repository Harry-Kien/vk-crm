<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ChecklistItemStatus;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
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
 * 1. `closed_at` null — §6.9 "đang mở";
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
 * dùng chữ "đã có tài liệu", và nơi nhóm D đã bị loại ra
 * ({@see ChecklistRelationManager::progressFor()}).
 * Nên không có đường nào cho một ghi chú nội bộ nhóm D làm một đầu mục trông như đã nộp ở nơi
 * này mà chưa nộp ở nơi kia. Mọi đầu mục bắt buộc đều nằm trong `Y`, nên mỗi dòng đếm ở đây
 * cũng đúng là một phần tử của `Y` chưa vào `X`.
 *
 * **Đồng hồ: `COALESCE(reviewed_at, created_at)` của chính đầu mục.** SPEC không đặt tên cho mốc
 * bắt đầu của "tình trạng thiếu", và hai trạng thái có hai câu trả lời tự nhiên khác nhau. Với
 * `rejected`, `reviewed_at` là lúc văn phòng báo cho khách phải nộp lại — đúng lúc đồng hồ phải
 * chạy lại từ đầu, vì một giấy tờ vừa bị từ chối hôm qua thì khách chưa kịp thiếu. Với `missing`,
 * chưa ai duyệt lần nào nên `reviewed_at` là null, và mốc còn lại đúng nghĩa là `created_at`:
 * đầu mục được sao từ template lúc mở vụ việc (§4.10), tức là lúc văn phòng bắt đầu chờ giấy tờ
 * đó. `updated_at` KHÔNG được dùng, và câu này là chỗ duy nhất nói ra lý do: nó nhích vì những lý do chẳng liên quan gì tới việc khách
 * đã nộp hay chưa — sửa tên đầu mục chẳng hạn — và mỗi lần nhích là một hồ sơ tắc 60 ngày tự
 * đặt lại về 0, tức là widget im lặng ở đúng hồ sơ nó tồn tại để la lên. Cùng thành ngữ
 * `COALESCE` mà {@see StaleMattersWidget} dùng cho `last_client_update_at`.
 *
 * Ngưỡng 14 ngày chỉ dùng để chọn HỒ SƠ. Cột "Giấy tờ còn thiếu" đếm MỌI đầu mục bắt buộc chưa
 * nộp, kể cả cái mới thiếu hôm qua, vì người gọi điện cho khách cần biết phải xin bao nhiêu thứ
 * chứ không phải bao nhiêu thứ đã quá hạn.
 */
class MattersMissingDocumentsWidget extends TableWidget
{
    // Thứ tự SPEC §7.1: ngay sau "Tài liệu chờ duyệt" (-2).
    protected static ?int $sort = -1;

    /** Số ngày theo SPEC §6.9 bullet cuối. */
    private const STUCK_AFTER_DAYS = 14;

    private const OUTSTANDING_COUNT_ALIAS = 'outstanding_required_count';

    private const MISSING_SINCE_ALIAS = 'missing_since';

    /**
     * Các trạng thái §6.9 gọi là "còn thiếu": khách chưa nộp, hoặc đã nộp và bị trả lại.
     *
     * @var list<string>
     */
    private const OUTSTANDING_STATUSES = [
        ChecklistItemStatus::Missing->value,
        ChecklistItemStatus::Rejected->value,
    ];

    /** Gác như {@see StaleMattersWidget}: đây là danh sách hồ sơ, không phải con số thống kê. */
    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::MatterView->value);
    }

    /**
     * Một định nghĩa duy nhất của "đầu mục bắt buộc còn thiếu", dùng ở cả ba chỗ: điều kiện chọn
     * hồ sơ, bộ đếm, và mốc "thiếu từ". Viết ba lần là ba lần có thể lệch nhau.
     *
     * @param  Builder<MatterChecklistItem>  $query
     * @return Builder<MatterChecklistItem>
     */
    private static function outstandingItems(Builder $query): Builder
    {
        return $query
            ->where('is_required', true)
            ->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    /**
     * Truy vấn của widget, tách static để test được mà không dựng cả bảng Livewire.
     *
     * @return Builder<Matter>
     */
    public static function rowsFor(User $user): Builder
    {
        $clock = 'COALESCE(matter_checklist_items.reviewed_at, matter_checklist_items.created_at)';

        return Matter::query()
            ->listableBy($user)
            ->whereNull('matters.closed_at')
            ->where('matters.is_published_to_portal', true)
            ->whereHas(
                'checklistItems',
                fn (Builder $items): Builder => static::outstandingItems($items)
                    ->whereRaw($clock.' < ?', [now()->subDays(self::STUCK_AFTER_DAYS)]),
            )
            ->withCount([
                'checklistItems as '.self::OUTSTANDING_COUNT_ALIAS => fn (Builder $items): Builder => static::outstandingItems($items),
            ])
            ->withMin(
                ['checklistItems as '.self::MISSING_SINCE_ALIAS => fn (Builder $items): Builder => static::outstandingItems($items)],
                DB::raw($clock),
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
