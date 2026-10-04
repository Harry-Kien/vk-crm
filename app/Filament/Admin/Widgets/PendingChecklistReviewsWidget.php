<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\DocumentGroup;
use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §7.1 mục 3: "Tài liệu chờ duyệt — khách đã nộp, chưa ai xem".
 *
 * **Đơn vị của bảng này là ĐẦU MỤC DANH MỤC, không phải dòng `documents`**, dù tên widget ở SPEC
 * là "Tài liệu". Lý do đọc được từ chính hai vế của câu: "chưa ai xem" không phải một thuộc tính
 * của tệp — bảng `documents` không có cột nào ghi ai đã mở nó — mà là trạng thái
 * `pending_review` mà `SubmitClientDocument` (SPEC §6.6 bước 8) đặt lên đầu mục và
 * `ReviewChecklistItem` (§6.7) gỡ ra. Và nếu liệt kê theo `documents` thì một đầu mục đã nộp lại
 * ba lần sẽ hiện ba dòng, trong đó hai dòng là các bản cũ không còn ai phải duyệt: chuỗi
 * `version` của §6.6 bước 7 giữ nguyên bản cũ thay vì ghi đè. Một đầu mục đang chờ là đúng một
 * việc phải làm, nên nó là đúng một dòng.
 *
 * **Không lọc theo `closed_at`.** SPEC §7.1 mục 3 không nêu điều kiện đó (khác §6.4 và §6.9,
 * nơi SPEC nói thẳng "chưa đóng" / "đang mở"), và `MatterChecklistItemPolicy::review` vẫn cho
 * duyệt một đầu mục trên vụ việc đã đóng — nên dòng ở đây vẫn là một việc bấm được, không phải
 * một dòng chết. Một tệp khách nộp lên hồ sơ văn phòng tưởng đã xong còn đáng nhìn hơn bình
 * thường chứ không kém.
 *
 * Phạm vi đi qua `Matter::scopeListableBy()` như mọi danh sách khác, nên vụ việc `restricted`
 * chỉ hiện cho luật sư phụ trách và admin, và `whereHas('matter')` loại luôn vụ đã xoá mềm nhờ
 * global scope của `SoftDeletes`.
 */
class PendingChecklistReviewsWidget extends TableWidget
{
    // Thứ tự SPEC §7.1: sau "Hồ sơ quá hạn cập nhật" (-4) và AccountWidget (-3, của Filament).
    protected static ?int $sort = -2;

    /** Bí danh của mốc "khách nộp lúc" — xem {@see self::rowsFor()}. */
    private const SUBMITTED_AT_ALIAS = 'submitted_at';

    /**
     * Gác bằng `checklist.review`, không phải `matter.view`: đây là hàng chờ việc của người đi
     * duyệt. Ai không duyệt được thì mọi dòng ở đây chỉ là tiếng ồn.
     */
    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::ChecklistReview->value);
    }

    /**
     * Truy vấn của widget, tách static để test được mà không dựng cả bảng Livewire.
     *
     * "Chờ duyệt" là {@see MatterChecklistItem::scopeAwaitingReview()} (chuyển xuống model ở M13
     * Task 2, để cột N8 của "Theo dõi đội ngũ" đếm ĐÚNG tập này mà không chép điều kiện trạng thái).
     *
     * Mốc "khách nộp lúc" là `MAX(created_at)` của các tài liệu **nhóm A** gắn vào đầu mục. Nhóm
     * A là "khách cung cấp" (SPEC §4.11) bất kể ai bấm nút nộp, nên nó là lần nộp. Các nhóm khác
     * bị loại vì một tài liệu nhóm D — ghi chú công việc nội bộ — GẮN ĐƯỢC vào một đầu mục danh
     * mục (đó là việc hợp lệ, xem `App\Actions\Document\ChecklistProgress`), và nếu nó được
     * tính thì một ghi chú viết hôm nay sẽ làm một lần nộp từ chín ngày trước trông như vừa mới
     * đến — tức là đẩy đúng việc tồn lâu nhất xuống cuối hàng chờ.
     *
     * `withMax` áp global scope của `Document`, nên một bản đã xoá mềm không còn là mốc nộp.
     *
     * @return Builder<MatterChecklistItem>
     */
    public static function rowsFor(User $user): Builder
    {
        return MatterChecklistItem::query()
            ->awaitingReview()
            ->whereHas('matter', fn (Builder $matter): Builder => $matter->listableBy($user))
            ->withMax(
                ['documents as '.self::SUBMITTED_AT_ALIAS => fn (Builder $documents): Builder => $documents
                    ->where('group', DocumentGroup::ClientProvided->value)],
                'created_at',
            )
            ->with(['matter.client']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('widgets.pending_checklist_reviews.heading'))
            ->description(__('widgets.pending_checklist_reviews.description'))
            ->emptyStateHeading(__('widgets.pending_checklist_reviews.empty_state'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('matter.code')
                    ->label(__('widgets.pending_checklist_reviews.columns.code')),
                TextColumn::make('matter.client.name')
                    ->label(__('widgets.pending_checklist_reviews.columns.client')),
                TextColumn::make('name')
                    ->label(__('widgets.pending_checklist_reviews.columns.item'))
                    ->limit(60),
                TextColumn::make(self::SUBMITTED_AT_ALIAS)
                    ->label(__('widgets.pending_checklist_reviews.columns.submitted_at'))
                    ->dateTime('H:i d/m/Y')
                    ->placeholder(__('widgets.pending_checklist_reviews.never_submitted'))
                    ->sortable(),
            ])
            // Cũ nhất lên trước: hàng chờ duyệt là hàng đợi, và cái chờ lâu nhất là cái khách đã
            // đợi lâu nhất. Mốc rỗng không có nhánh riêng: `SubmitClientDocument` là đường DUY
            // NHẤT đặt `pending_review` và nó luôn gắn kèm một tài liệu nhóm A, nên một dòng
            // không mốc chỉ xuất hiện khi tài liệu đó đã bị xoá mềm sau lần nộp — hiếm, và nằm ở
            // đầu bảng (nơi người duyệt nhìn thấy) chứ không bị giấu xuống cuối.
            ->defaultSort(self::SUBMITTED_AT_ALIAS)
            ->recordActions([
                Action::make('open')
                    ->label(__('widgets.pending_checklist_reviews.open'))
                    ->url(fn (MatterChecklistItem $record): string => MatterResource::getUrl(
                        'view',
                        ['record' => $record->matter_id],
                        panel: 'admin',
                    )),
            ])
            ->paginated([5, 10, 25]);
    }

    /**
     * `canView()` đã chặn trước khi widget này render, nhưng `listableBy()` đòi một `User` tường
     * minh — cùng thành ngữ phòng thủ với {@see StaleMattersWidget}.
     */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
