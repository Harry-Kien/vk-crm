<?php

namespace App\Filament\Admin\Resources\Tasks;

use App\Enums\NavigationGroup;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Trang "Công việc" (`/admin/tasks`, làn tk, Task 1) — giao, nhận, xử lý việc nội bộ. Một trang danh
 * sách duy nhất ({@see ListTasks}): bốn tab (Chờ tôi nhận, Đang làm, Tôi đã giao, Tất cả), không trang
 * tạo/sửa/xem riêng — mọi thao tác là một khung nhỏ trên dòng, để người bận bấm ít nhất.
 *
 * # Phạm vi
 *
 * {@see self::getEloquentQuery()} = `Task::visibleTo($user)` — MỘT luật cho mọi tab, mọi bộ lọc, ô tìm kiếm
 * và huy hiệu đếm: việc của vụ `restricted` không bao giờ lộ ra với người không xem được vụ (mã, tiêu đề,
 * người làm). Trang mở cho mọi nhân sự (`TaskPolicy::viewAny`): kế toán thấy đúng việc chung giao cho mình.
 *
 * Không có trong tìm kiếm toàn cục của panel (`$isGloballySearchable = false`): tìm kiếm của trang đã đi
 * qua phạm vi trên, và một lối tìm thứ hai là một luật thứ hai phải giữ.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    // Mỗi resource một hình riêng (NavigationIconsTest) — tấm bảng có dấu tích: việc cần làm.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /**
     * Khu không nhóm trên cùng của menu, ngay dưới Bảng điều khiển (`-2`): việc hằng ngày đứng đầu, như
     * "My tasks" của các CRM chuyên nghiệp (gộp làn rv vào main, phán quyết controller). Không gán
     * nhóm — xem docblock {@see NavigationGroup}.
     */
    protected static ?int $navigationSort = -1;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('tasks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tasks.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('tasks.navigation_label');
    }

    /**
     * Kiểm toán màn hình 2026-10-21 (mục 9): nhãn model viết thường ("công việc" — câu chữ giữa dòng) nên breadcrumb
     * mặc định ra "công việc › Danh sách"; breadcrumb là một tên trang, viết hoa như mọi trang khác.
     */
    public static function getBreadcrumb(): string
    {
        return __('tasks.navigation_label');
    }

    /**
     * Đường dẫn mở thẳng khung "Xem" của MỘT việc trên tab `$tab` (kiểm toán màn hình 2026-10-21, mục 5) — mọi chuông,
     * thư, lời nhắc và thông báo đẩy về một việc trỏ về đây. Hai tham số `tableAction`/`tableActionRecord` là của
     * Filament (`InteractsWithActions::$defaultTableAction`): trang mở rồi tự gắn nút "Xem" lên dòng đó.
     *
     * Không mở một cửa nào mới: dòng được tìm trong `getEloquentQuery()` = `Task::visibleTo()` của NGƯỜI ĐANG XEM (tab
     * và bộ lọc mặc định bỏ qua lúc tìm dòng — `excludeQueryWhenResolvingRecord()`: việc đã đổi tab hay đã đóng vẫn
     * mở được), rồi `TaskPolicy::view` của nút "Xem". Người không (còn) xem được việc chỉ thấy trang Công việc, không
     * khung nào, không chữ nào của việc. Nút "Xem" có hộp thoại, nên Filament cho mở từ đường dẫn (nút không hộp thoại
     * như "Đã nhận" không bao giờ chạy từ một đường dẫn — `mountedFromUrl`).
     */
    public static function taskUrl(Task $task, string $tab): string
    {
        return self::getUrl('index', [
            'tab' => $tab,
            'tableAction' => 'view',
            'tableActionRecord' => $task->getKey(),
        ], panel: 'admin');
    }

    /**
     * Huy hiệu trên thanh điều hướng: số việc ĐANG CHỜ người đang đăng nhập bấm "Đã nhận" — con số duy
     * nhất cần một cú bấm của chính họ. Không có thì không hiện gì.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $count = Task::query()->visibleTo($user)->awaitingAcceptanceBy($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('tasks.tabs.awaiting');
    }

    /** Xem docblock lớp, mục "Phạm vi". */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        // SPEC §10.10: từ chối là 404, không 403 — cùng mã với một bản ghi không có.
        abort_unless($user instanceof User, 404);

        return parent::getEloquentQuery()->visibleTo($user);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
        ];
    }
}
