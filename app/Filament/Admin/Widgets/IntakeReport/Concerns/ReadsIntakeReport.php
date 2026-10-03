<?php

namespace App\Filament\Admin\Widgets\IntakeReport\Concerns;

use App\Enums\IntakeStatus;
use App\Enums\Permission;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Intake\IntakeReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Phần chung của bốn widget "Bức tranh đầu vào" (M10 Task 6): cổng quyền và tập bản ghi được đếm.
 * Dùng kèm `InteractsWithPageFilters` (prop `$pageFilters`).
 *
 * # Cổng — tự hỏi lại, và 404 ngay cả khi widget được mount thẳng
 *
 * `canView()` đọc QUYỀN `intake.viewAny` qua `Gate::forUser()` — cùng cổng với
 * `IntakeReport::canAccess()`. Trang đã gác cổng, nhưng một widget là một component Livewire riêng
 * (tải lười, request riêng), nên nó tự hỏi lại: hook `boot` của trait chạy TRƯỚC `mount()` (nên trước
 * cả lần tính dữ liệu đầu tiên của `ChartWidget::mount()`) và trước MỌI request sau đó, và trả 404
 * — không 403 (SPEC §10.10) — cho người không qua cổng, kể cả người vừa bị rút quyền giữa hai request.
 *
 * # Tập bản ghi — MỘT định nghĩa cho cả bốn widget
 *
 * {@see self::intakesInReport()}: bản ghi tiếp nhận người xem THẤY ĐƯỢC
 * (`IntakeRequest::scopeVisibleTo()` — định nghĩa duy nhất của R9: bản ghi đã thành vụ `restricted`
 * mà người xem không xem được vụ thì không có trong số liệu của họ, và không câu "đã ẩn N bản ghi" nào
 * được in, SPEC §10.10), nhận liên hệ trong kỳ (`received_at`), và — nếu có chọn — do đúng người tiếp
 * nhận đó ghi (`created_by`). Bản ghi đã xoá mềm không tính (`SoftDeletes`); không màn hình nào xoá.
 *
 * **Bản ghi đã ẩn danh VẪN được đếm** (R7b giữ dòng, mã, nguồn, trạng thái, các mốc thời gian): không
 * điều kiện nào ở đây đọc một cột cá nhân, nên ẩn danh không đổi con số nào.
 *
 * {@see self::contactsInReport()} bỏ thêm bản ghi đã GỘP (`merged`): bản trùng của một người đã có bản
 * ghi khác (R4) — đếm nó là đếm cùng một người hai lần ở số liên hệ, ở mẫu số tỉ lệ chuyển đổi và ở
 * thời gian phản hồi. Chỉ widget "lý do không thành" đếm nó, thành một nhóm riêng.
 */
trait ReadsIntakeReport
{
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::IntakeViewAny->value);
    }

    /** Hook `boot` của Livewire cho trait này — xem docblock trait. */
    public function bootReadsIntakeReport(): void
    {
        abort_unless(static::canView(), 404);
    }

    protected function reportFilters(): IntakeReportFilters
    {
        return IntakeReportFilters::fromPageFilters($this->pageFilters);
    }

    /** Mọi bản ghi nhận trong kỳ mà người xem thấy được, kể cả bản trùng đã gộp — xem docblock trait. */
    protected function intakesInReport(): Builder
    {
        /** @var User $viewer `bootReadsIntakeReport()` đã bảo đảm. */
        $viewer = Auth::user();
        $filters = $this->reportFilters();

        return IntakeRequest::query()
            ->visibleTo($viewer)
            ->whereBetween('received_at', [$filters->from, $filters->to])
            ->when($filters->receiverId, fn (Builder $query, int $receiverId): Builder => $query->where('created_by', $receiverId));
    }

    /** {@see self::intakesInReport()} trừ bản trùng đã gộp: mỗi dòng là MỘT người liên hệ. */
    protected function contactsInReport(): Builder
    {
        return $this->intakesInReport()->where('status', '!=', IntakeStatus::Merged->value);
    }
}
