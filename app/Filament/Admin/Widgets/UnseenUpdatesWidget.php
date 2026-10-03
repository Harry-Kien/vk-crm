<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Permission;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\StageLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §7.1 mục 5: *"Khách chưa xem cập nhật — dòng tiến độ đã công bố quá 5 ngày mà chưa có bản
 * ghi trong `stage_log_views`. Nghĩa là khách không nhận được email, hoặc không biết dùng portal
 * — cần gọi điện."*
 *
 * Widget này được kế hoạch M5 xếp vào Task 6 vì **giờ mới có dữ liệu**: `stage_log_views` chỉ
 * bắt đầu có hàng từ khi `MatterProgress` (Task 4) gọi `RecordStageLogView`. Trước M5 mọi dòng
 * đã công bố đều "chưa xem", nên widget sẽ là một danh sách toàn bộ lịch sử văn phòng.
 *
 * # Điều kiện "chưa xem" đọc CHÍNH XÁC là gì
 *
 * `whereDoesntHave('views')` — **không một tài khoản portal nào của khách hàng đó đã mở trang
 * chi tiết hồ sơ và được gửi dòng này về trình duyệt.** Hai chỗ dễ đọc sai, và cả hai quan trọng
 * vì luật sư đọc widget này để quyết định có nhấc điện thoại lên hay không:
 *
 *  - Biên bản là **của một tài khoản**, nhưng widget hỏi về **khách hàng**: một hồ sơ có hai tài
 *    khoản portal (SPEC §4.3 nêu ví dụ hai vợ chồng) thì chỉ cần MỘT người mở là dòng biến mất
 *    khỏi đây. Đúng với mục đích: câu hỏi là "khách hàng đã được báo chưa", không phải "từng
 *    người đã đọc chưa". Cùng cách đọc với nhãn "Khách đã xem" ở SPEC §7.2.
 *  - "Chưa xem" **không** nghĩa là "chưa đọc". Biên bản khẳng định đúng chừng này: *tài khoản
 *    portal đó đã mở trang chi tiết hồ sơ, và dòng này nằm trong trang được gửi tới trình duyệt
 *    của họ, vào thời điểm đó, từ địa chỉ IP đó.* Nó không khẳng định người đó đã cuộn tới, đã
 *    đọc hay đã hiểu. Định nghĩa đầy đủ ở docblock `App\Filament\Portal\Pages\MatterProgress`
 *    và `App\Actions\Portal\RecordStageLogView`. Vậy một dòng **mất** khỏi widget này khi khách
 *    mới chỉ MỞ TRANG — nên widget nói được "khách chưa từng nhìn thấy" và KHÔNG nói được "khách
 *    chưa hiểu".
 *
 * # Ba điều kiện SPEC không viết ra, và vì sao chúng vẫn ở đây
 *
 *  1. **Chỉ hồ sơ đang công bố lên cổng** (`is_published_to_portal = true`). Một dòng trên một
 *     hồ sơ đã bị gỡ khỏi cổng thì khách KHÔNG CÓ ĐƯỜNG NÀO mở ra được, nên nó sẽ nằm đây vĩnh
 *     viễn và không cú điện thoại nào làm nó biến mất — tức là nó làm hỏng đúng thứ widget tồn
 *     tại để làm, là một danh sách việc phải gọi. Cái giá, nói thẳng: nếu văn phòng công bố tiến
 *     độ rồi gỡ hồ sơ khỏi cổng, widget IM LẶNG về những dòng đó. Đó là một lỗi công bố, không
 *     phải một việc phải gọi điện, và nó cần một chỗ khác để kêu lên — ghi lại chứ không giấu.
 *  2. **Bỏ hồ sơ đã xoá mềm**, qua global scope của `SoftDeletes` trên `whereHas('matter')`.
 *  3. **Không lọc `closed_at`.** SPEC §7.1 mục 5 không nêu điều kiện đó (khác §6.4 và §6.9, nơi
 *     SPEC nói thẳng "chưa đóng"/"đang mở"), và một cập nhật cuối cùng trên một hồ sơ vừa đóng
 *     mà khách chưa từng nhìn thấy là đúng cuộc gọi đáng thực hiện nhất — thường nó là câu "việc
 *     của anh/chị đã xong".
 *
 * # Phạm vi và cách nó KHÔNG rò rỉ
 *
 * `Matter::scopeListableBy()` như mọi danh sách khác, nên một vụ việc `restricted` chỉ hiện cho
 * luật sư phụ trách và quản trị. Cột nội dung là `public_content` — thứ khách đã đọc được — và
 * **`internal_note` không được nhắc tới ở bất kỳ đâu trong lớp này**: đó là luật Task 2 đặt ra
 * cho mọi màn hình cổng, và widget này tuy ở panel nội bộ nhưng đọc cùng một bảng, nên cùng một
 * thói quen ở hai nơi là cách để thói quen đúng sống sót lần sửa sau.
 */
class UnseenUpdatesWidget extends TableWidget
{
    /**
     * SPEC §7.1 mục 5: sau "Hồ sơ thiếu giấy tờ quá 14 ngày" (mục 4, `-1`) và trước "Thống kê
     * nhanh" (mục 6). Mục 6 phải dời từ `0` lên `1` để chỗ này tồn tại — `$sort` của Filament là
     * `?int` và giữa `-1` và `0` không có số nguyên nào. Xem `MattersByStageWidget`.
     *
     * Con số phải KHÁC mọi widget đang có: hai widget cùng số thì chúng đứng theo thứ tự Filament
     * tình cờ nạp lớp, không gây lỗi gì cả, và không có gì báo động — M4 đã đo được đúng chuyện
     * đó trên trình duyệt (mục 6 hiện TRƯỚC mục 4). `DashboardWidgetOrderTest` giữ cho nó không
     * quay lại.
     *
     * M10 Task 5 đánh số lại lần nữa (widget "Liên hệ chưa ai gọi lại" chen vào ngay dưới mục 3):
     * mục 4 `-1` → `0`, mục này `0` → `1`, mục 6 `1` → `2`. Thứ tự tương đối không đổi.
     */
    protected static ?int $sort = 1;

    /** SPEC §7.1 mục 5 và §4.18: "quá 5 ngày". */
    public const UNSEEN_AFTER_DAYS = 5;

    /**
     * Gác bằng `matter.view`, cùng quyền với `StaleMattersWidget`: bảng này hiện mã hồ sơ, tên
     * khách hàng và nội dung đã công bố của một vụ việc cụ thể. Kế toán chỉ có `matter.viewAny`
     * (xem `MattersByStageWidget`, widget duy nhất mở rộng tới họ vì nó chỉ ĐẾM), nên họ không
     * thấy widget này.
     */
    public static function canView(): bool
    {
        return (bool) Auth::user()?->can(Permission::MatterView->value);
    }

    /**
     * Truy vấn của widget, tách static để test được mà không dựng cả bảng Livewire.
     *
     * `whereDoesntHave('views')` chạy **không** qua `ClientPortalScope`: dưới guard `web` scope
     * đó không kích hoạt (xem `ClientPortalScope::isActive()`), nên câu hỏi con đếm mọi biên bản
     * của mọi tài khoản portal — đúng cách đọc "theo khách hàng" mà docblock lớp mô tả. Một nhân
     * sự mở cùng lúc cả /admin lẫn /portal vẫn an toàn: scope đó ưu tiên guard `web`.
     *
     * @return Builder<StageLog>
     */
    public static function rowsFor(User $user): Builder
    {
        return StageLog::query()
            ->where('is_published', true)
            // `published_at`, không phải `occurred_at`: đồng hồ của SPEC §7.1 mục 5 đếm từ lúc
            // văn phòng ĐƯA TIN ra, không từ lúc chuyện xảy ra. Một dòng ghi lại một phiên toà
            // tháng trước nhưng vừa được công bố hôm nay thì khách mới có hai ngày để mở nó.
            ->whereNotNull('published_at')
            ->where('published_at', '<', now()->subDays(self::UNSEEN_AFTER_DAYS))
            ->whereDoesntHave('views')
            ->whereHas('matter', fn (Builder $matter): Builder => $matter
                ->listableBy($user)
                ->where('is_published_to_portal', true))
            ->with(['matter.client']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('requests.unseen_widget.heading'))
            ->description(__('requests.unseen_widget.description'))
            ->emptyStateHeading(__('requests.unseen_widget.empty_state'))
            ->query(fn (): Builder => static::rowsFor(static::currentUser()))
            ->columns([
                TextColumn::make('matter.code')
                    ->label(__('requests.unseen_widget.columns.code')),
                TextColumn::make('matter.client.name')
                    ->label(__('requests.unseen_widget.columns.client')),
                TextColumn::make('published_at')
                    ->label(__('requests.unseen_widget.columns.published_at'))
                    ->dateTime('H:i d/m/Y')
                    // Tô đỏ: mọi dòng ở đây đều đã quá hạn theo định nghĩa của truy vấn, nên màu
                    // không phải một kênh thông tin thứ hai mà là một lời nhấn. Chữ "quá 5 ngày"
                    // nằm ở `description` của bảng, nên màu không đứng một mình.
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('public_content')
                    ->label(__('requests.unseen_widget.columns.content'))
                    ->wrap()
                    ->limit(120),
            ])
            // Cũ nhất lên trước: đây là một hàng đợi gọi điện, và dòng khách chưa nhìn thấy lâu
            // nhất là dòng đáng gọi nhất.
            ->defaultSort('published_at')
            ->recordActions([
                Action::make('open')
                    ->label(__('requests.unseen_widget.open'))
                    ->url(fn (StageLog $record): string => MatterResource::getUrl(
                        'view',
                        ['record' => $record->matter_id],
                        panel: 'admin',
                    )),
            ])
            ->paginated([5, 10, 25]);
    }

    /**
     * `canView()` đã chặn trước khi widget này render, nhưng `listableBy()` đòi một `User` tường
     * minh — cùng thành ngữ phòng thủ với {@see StaleMattersWidget} và
     * {@see PendingChecklistReviewsWidget}.
     */
    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
