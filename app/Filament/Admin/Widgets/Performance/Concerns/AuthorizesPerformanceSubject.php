<?php

namespace App\Filament\Admin\Widgets\Performance\Concerns;

use App\Enums\Permission;
use App\Filament\Admin\Pages\TeamMember;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Kiểm quyền dùng chung của hai widget xu hướng (M13 Task 7, Review Focus 4). Một widget là một component
 * Livewire RIÊNG: request của nó (`$refresh`, một lần gọi tay, lần tải lười) không đi qua `boot()` của trang
 * {@see TeamMember}, và `getWidgetData()` của trang chỉ là giá trị khởi đầu. Vì vậy widget không tin trang
 * cha:
 *
 *  - {@see self::$subjectId} là `#[Locked]`: trình duyệt không đặt lại được (Livewire ném
 *    `CannotUpdateLockedPropertyException`). Đây là thuộc tính công khai DUY NHẤT mang id người; không
 *    thuộc tính công khai nào mang id vụ.
 *  - Hook `boot` của trait ({@see self::bootAuthorizesPerformanceSubject()}) hỏi cổng người ở MỌI request
 *    của widget — đọc lại người dùng từ CSDL mỗi lần: người xem mất quyền, người được xem rời danh sách hay
 *    bị xoá mềm khi trang còn mở thì request kế tiếp là 404. Ở lần mount, Livewire đã gán tham số
 *    `subjectId` vào thuộc tính công khai cùng tên TRƯỚC hook `boot` (đo ở Task 7: hook thấy id, `mount()`
 *    không bao giờ chạy cho người bị từ chối), nên hook cũng là cổng đầu tiên của lần mount;
 *    {@see self::mount()} hỏi lại lần nữa trước khi `ChartWidget::mount()` tính dữ liệu lần đầu — lớp thứ
 *    hai, không trông vào thứ tự đó của Livewire.
 *  - Cổng người là ĐÚNG {@see UserPolicy::viewPerformance()} (Task 1) — không viết luật xem thứ hai. Id
 *    không phải số nguyên dương, id không tồn tại, người đã xoá mềm, người ngoài danh sách R3 và người không
 *    được xem đi cùng MỘT `abort(404)`, như trang.
 *  - {@see self::canView()} tĩnh là lớp NGOÀI (`matter.view` hoặc `performance.viewAny`, cùng cổng trang
 *    của `TeamMember`), không thay cho kiểm tra theo người.
 */
trait AuthorizesPerformanceSubject
{
    #[Locked]
    public int $subjectId;

    /** Bộ nhớ đệm TRONG một request (thuộc tính `private` không được Livewire serialize). */
    private ?User $performanceSubject = null;

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);

        return $gate->allows(Permission::MatterView->value)
            || $gate->allows(Permission::PerformanceViewAny->value);
    }

    /**
     * Gán người được xem (từ `TeamMember::getWidgetData()`), hỏi cổng người, rồi mới để widget tính dữ liệu.
     * Tham số có mặc định chỉ vì chữ ký phải tương thích với `ChartWidget::mount()`; thiếu id là 404.
     */
    public function mount(?int $subjectId = null): void
    {
        $this->performanceSubject = $this->authorizedPerformanceSubject($subjectId ?? 0);
        $this->subjectId = $this->performanceSubject->getKey();

        parent::mount();
    }

    /**
     * Hook `boot` của Livewire cho trait này — mọi request của widget, kể cả lần mount (xem docblock trait).
     * `isset()` chỉ để an toàn khi một lần mount không mang `subjectId`: khi đó `mount()` trả 404.
     */
    public function bootAuthorizesPerformanceSubject(): void
    {
        if (isset($this->subjectId)) {
            $this->performanceSubject = $this->authorizedPerformanceSubject($this->subjectId);
        }
    }

    /**
     * Người đã qua cổng ở `mount()` hoặc ở hook `boot` của CHÍNH request này — hai chỗ đó luôn chạy trước
     * mọi lần vẽ, nên hàm này không hỏi lại: một lần hỏi thầm ở đây sẽ làm cho việc bỏ mất một trong hai cổng
     * không lộ ra ở test nào. `BuildPerformanceTrend` vẫn tự hỏi `viewPerformance` (phòng thủ).
     */
    protected function performanceSubject(): User
    {
        return $this->performanceSubject ??= User::query()->with(['roles.permissions', 'permissions'])->findOrFail($this->subjectId);
    }

    protected function performanceViewer(): User
    {
        $viewer = Auth::user();

        abort_unless($viewer instanceof User, 404);

        return $viewer;
    }

    /** Xem docblock trait — mọi lời từ chối là cùng một `abort(404)`. */
    private function authorizedPerformanceSubject(int $id): User
    {
        $viewer = Auth::user();
        $subject = User::query()->with(['roles.permissions', 'permissions'])->find($id);

        abort_unless(
            $viewer instanceof User
                && $subject instanceof User
                && Gate::forUser($viewer)->allows('viewPerformance', $subject),
            404,
        );

        return $subject;
    }
}
